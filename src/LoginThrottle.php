<?php

/**
 * -------------------------------------------------------------------------
 * LogByMail plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 LORENTZ Johan
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/lorentzjohan
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Logbymail;

use Closure;
use Psr\SimpleCache\CacheInterface;

/**
 * Brute-force protection of the GLPI web login form (login or e-mail address).
 *
 * Two counters of failed sign-ins, kept in the GLPI cache:
 *  - per account AND per client IP: blocks password guessing on one account without letting
 *    anybody lock a user out from another computer;
 *  - per client IP (optional): blocks "password spraying" over many accounts from one address.
 *
 * When a counter reaches its limit within the time window, new attempts are refused before
 * GLPI checks any password, until the lockout ends. A successful sign-in resets the account
 * counter of that IP.
 */
final readonly class LoginThrottle
{
    private const KEY_PREFIX = 'logbymail_throttle_';

    public const SCOPE_ACCOUNT = 'account';

    public const SCOPE_IP      = 'ip';

    public function __construct(
        private CacheInterface $cache,
        private int $max_per_account,
        private int $max_per_ip,
        private int $window_seconds,
        private int $lockout_seconds,
        private ?Closure $clock = null,
    ) {}

    /**
     * Throttle configured from the plugin settings, or null when the protection is disabled
     * or the GLPI cache is unavailable.
     */
    public static function fromConfig(PluginConfig $config): ?self
    {
        /** @var CacheInterface $GLPI_CACHE */
        global $GLPI_CACHE;

        if (!$config->isThrottleEnabled()) {
            return null;
        }

        return new self(
            $GLPI_CACHE,
            $config->getThrottleMaxPerAccount(),
            $config->getThrottleMaxPerIp(),
            $config->getThrottleWindowMinutes() * 60,
            $config->getThrottleLockoutMinutes() * 60,
        );
    }

    public function getLockoutSeconds(): int
    {
        return $this->lockout_seconds;
    }

    /**
     * Seconds before a new attempt is allowed, 0 when not locked.
     */
    public function getRemainingLockTime(string $login, ?string $ip): int
    {
        $now       = $this->now();
        $remaining = 0;
        foreach (array_keys($this->getCounters($login, $ip)) as $key) {
            $locked_until = $this->read($key)['locked_until'] ?? 0;
            if ($locked_until > $now) {
                $remaining = max($remaining, $locked_until - $now);
            }
        }

        return $remaining;
    }

    /**
     * Counts a failed sign-in.
     *
     * @return list<string> Scopes (account, ip) locked by this failure.
     */
    public function recordFailure(string $login, ?string $ip): array
    {
        $now    = $this->now();
        $locked = [];
        foreach ($this->getCounters($login, $ip) as $key => $limit) {
            $counter = $this->read($key);
            if ($counter === null || $counter['first'] + $this->window_seconds <= $now) {
                $counter = ['count' => 0, 'first' => $now, 'locked_until' => 0];
            }

            $counter['count']++;
            if ($counter['count'] >= $limit && $counter['locked_until'] <= $now) {
                $counter['locked_until'] = $now + $this->lockout_seconds;
                $locked[] = str_starts_with($key, self::KEY_PREFIX . self::SCOPE_IP) ? self::SCOPE_IP : self::SCOPE_ACCOUNT;
            }

            $this->cache->set($key, $counter, max($this->window_seconds, $this->lockout_seconds) + 60);
        }

        return $locked;
    }

    /**
     * A successful sign-in resets the account counter for this IP (not the IP counter: a valid
     * account must not allow to reset a password spraying counter).
     */
    public function recordSuccess(string $login, ?string $ip): void
    {
        $this->cache->delete($this->accountKey($login, $ip));
    }

    /**
     * @return array<string, int> Cache key => limit
     */
    private function getCounters(string $login, ?string $ip): array
    {
        $counters = [$this->accountKey($login, $ip) => $this->max_per_account];
        if ($this->max_per_ip > 0 && $ip !== null && $ip !== '') {
            $counters[self::KEY_PREFIX . self::SCOPE_IP . '_' . $this->hashKey($ip)] = $this->max_per_ip;
        }

        return $counters;
    }

    /**
     * Keys only use characters allowed by PSR-16 (the login may contain "@", ":", "/"...).
     */
    private function accountKey(string $login, ?string $ip): string
    {
        return self::KEY_PREFIX . self::SCOPE_ACCOUNT . '_' . $this->hashKey(mb_strtolower(trim($login)) . '|' . ($ip ?? ''));
    }

    /**
     * Hexadecimal digest used to build cache keys (fixed length, PSR-16 safe characters, no
     * login or IP address stored in clear in the cache). SHA-256: no weak algorithm, even
     * for a non-security use.
     */
    private function hashKey(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * @return array{count: int, first: int, locked_until: int}|null
     */
    private function read(string $key): ?array
    {
        $value = $this->cache->get($key);
        if (!is_array($value) || !isset($value['count'], $value['first'], $value['locked_until'])) {
            return null;
        }

        return [
            'count'        => Scalar::toInt($value['count']),
            'first'        => Scalar::toInt($value['first']),
            'locked_until' => Scalar::toInt($value['locked_until']),
        ];
    }

    private function now(): int
    {
        return $this->clock instanceof Closure ? Scalar::toInt(($this->clock)()) : time();
    }
}
