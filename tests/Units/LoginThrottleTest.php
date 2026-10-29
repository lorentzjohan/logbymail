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

namespace GlpiPlugin\Logbymail\Tests\Units;

use DateInterval;
use GlpiPlugin\Logbymail\LoginThrottle;
use GlpiPlugin\Logbymail\PluginConfig;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class LoginThrottleTest extends TestCase
{
    private int $now = 1_000_000;

    private function throttle(CacheInterface $cache, int $per_account = 3, int $per_ip = 5): LoginThrottle
    {
        return new LoginThrottle($cache, $per_account, $per_ip, 900, 600, fn(): int => $this->now);
    }

    private static function cache(): CacheInterface
    {
        return new class implements CacheInterface {
            /** @var array<string, mixed> */
            public array $data = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->data[$key] ?? $default;
            }

            public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
            {
                if (preg_match('/[{}()\/\\\\@:]/', $key) === 1) {
                    throw new \InvalidArgumentException('Invalid PSR-16 key ' . $key);
                }
                $this->data[$key] = $value;
                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->data[$key]);
                return true;
            }

            public function clear(): bool
            {
                $this->data = [];
                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                return [];
            }

            public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
            {
                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                return true;
            }

            public function has(string $key): bool
            {
                return isset($this->data[$key]);
            }
        };
    }

    public function testAccountIsLockedAfterTheLimit(): void
    {
        $throttle = $this->throttle(self::cache());

        $this->assertSame([], $throttle->recordFailure('jdoe', '192.0.2.10'));
        $this->assertSame([], $throttle->recordFailure('JDoe ', '192.0.2.10'));
        $this->assertSame(0, $throttle->getRemainingLockTime('jdoe', '192.0.2.10'));
        $this->assertSame(['account'], $throttle->recordFailure('jdoe', '192.0.2.10'));
        $this->assertSame(600, $throttle->getRemainingLockTime('jdoe', '192.0.2.10'));

        // Same account from another computer: not locked.
        $this->assertSame(0, $throttle->getRemainingLockTime('jdoe', '192.0.2.99'));

        // Lockout ends.
        $this->now += 601;
        $this->assertSame(0, $throttle->getRemainingLockTime('jdoe', '192.0.2.10'));
    }

    public function testFailuresOutsideTheWindowAreForgotten(): void
    {
        $throttle = $this->throttle(self::cache());

        $throttle->recordFailure('jdoe', '192.0.2.10');
        $throttle->recordFailure('jdoe', '192.0.2.10');
        $this->now += 901;
        $this->assertSame([], $throttle->recordFailure('jdoe', '192.0.2.10'));
        $this->assertSame(0, $throttle->getRemainingLockTime('jdoe', '192.0.2.10'));
    }

    public function testSuccessResetsTheAccountCounterOnly(): void
    {
        $throttle = $this->throttle(self::cache(), 3, 4);

        $throttle->recordFailure('jdoe', '192.0.2.10');
        $throttle->recordFailure('jdoe', '192.0.2.10');
        $throttle->recordSuccess('jdoe', '192.0.2.10');
        $this->assertSame([], $throttle->recordFailure('jdoe', '192.0.2.10'));

        // The IP counter kept the 3 failures: the 4th one locks the IP.
        $this->assertSame(['ip'], $throttle->recordFailure('alice', '192.0.2.10'));
        $this->assertSame(600, $throttle->getRemainingLockTime('bob', '192.0.2.10'));
    }

    public function testPasswordSprayingLocksTheIp(): void
    {
        $throttle = $this->throttle(self::cache(), 3, 5);

        foreach (['a', 'b', 'c', 'd'] as $login) {
            $this->assertSame([], $throttle->recordFailure($login . '@example.com', '192.0.2.10'));
        }
        $this->assertSame(['ip'], $throttle->recordFailure('e@example.com', '192.0.2.10'));
        $this->assertSame(600, $throttle->getRemainingLockTime('anybody', '192.0.2.10'));
        $this->assertSame(0, $throttle->getRemainingLockTime('anybody', '192.0.2.11'));
    }

    public function testIpLimitCanBeDisabled(): void
    {
        $throttle = $this->throttle(self::cache(), 3, 0);

        foreach (range(1, 20) as $i) {
            $throttle->recordFailure('user' . $i, '192.0.2.10');
        }
        $this->assertSame(0, $throttle->getRemainingLockTime('user99', '192.0.2.10'));
    }

    public function testSettingsAreBounded(): void
    {
        $config = PluginConfig::fromArray([
            PluginConfig::THROTTLE_MAX_ACCOUNT => '0',
            PluginConfig::THROTTLE_MAX_IP      => 'abc',
            PluginConfig::THROTTLE_WINDOW      => '99999',
        ]);
        $this->assertTrue($config->isThrottleEnabled());
        $this->assertSame(1, $config->getThrottleMaxPerAccount());
        $this->assertSame(30, $config->getThrottleMaxPerIp());
        $this->assertSame(1440, $config->getThrottleWindowMinutes());
        $this->assertSame(15, $config->getThrottleLockoutMinutes());
    }
}
