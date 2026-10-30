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

use Config;
use JsonException;
use UnexpectedValueException;

use function Safe\preg_match;
use function Safe\preg_split;

/**
 * Plugin settings, stored in the GLPI `glpi_configs` table (context "plugin:logbymail").
 */
final readonly class PluginConfig
{
    public const CONTEXT = 'plugin:logbymail';

    public const ENABLED             = 'enabled';

    public const ONLY_DEFAULT_EMAIL  = 'only_default_email';

    public const ONLY_DYNAMIC_EMAIL  = 'only_dynamic_email';

    public const ALLOWED_DOMAINS     = 'allowed_domains';

    public const REPLACE_LOGIN_LABEL = 'replace_login_label';

    public const LOG_ENABLED         = 'log_enabled';

    public const INCLUDED_USERS      = 'included_users';

    public const EXCLUDED_USERS      = 'excluded_users';

    public const THROTTLE_ENABLED     = 'throttle_enabled';

    public const THROTTLE_MAX_ACCOUNT = 'throttle_max_per_account';

    public const THROTTLE_MAX_IP      = 'throttle_max_per_ip';

    public const THROTTLE_WINDOW      = 'throttle_window_minutes';

    public const THROTTLE_LOCKOUT     = 'throttle_lockout_minutes';

    /**
     * Default values, applied on install for the missing settings.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        self::ENABLED             => '1',
        self::ONLY_DEFAULT_EMAIL  => '0',
        self::ONLY_DYNAMIC_EMAIL  => '0',
        self::ALLOWED_DOMAINS     => '',
        self::REPLACE_LOGIN_LABEL => '1',
        self::LOG_ENABLED         => '1',
        self::INCLUDED_USERS      => '[]',
        self::EXCLUDED_USERS      => '[]',
        self::THROTTLE_ENABLED     => '1',
        self::THROTTLE_MAX_ACCOUNT => '5',
        self::THROTTLE_MAX_IP      => '30',
        self::THROTTLE_WINDOW      => '15',
        self::THROTTLE_LOCKOUT     => '15',
    ];

    /**
     * Allowed range of the numeric throttling settings: [min, max].
     *
     * @var array<string, array{int, int}>
     */
    public const THROTTLE_RANGES = [
        self::THROTTLE_MAX_ACCOUNT => [1, 100],
        self::THROTTLE_MAX_IP      => [0, 1000],
        self::THROTTLE_WINDOW      => [1, 1440],
        self::THROTTLE_LOCKOUT     => [1, 1440],
    ];

    /**
     * Maximum number of allowed domains, to keep the setting readable and the checks cheap.
     */
    public const MAX_DOMAINS = 50;

    /**
     * Maximum number of users in each of the included / excluded lists.
     */
    public const MAX_LISTED_USERS = 1000;

    /**
     * @param array<string, string> $values
     */
    private function __construct(private array $values) {}

    public static function load(): self
    {
        $stored = Config::getConfigurationValues(self::CONTEXT, array_keys(self::DEFAULTS));

        $values = self::DEFAULTS;
        foreach (array_keys(self::DEFAULTS) as $name) {
            if (isset($stored[$name]) && is_scalar($stored[$name])) {
                $values[$name] = (string) $stored[$name];
            }
        }

        return new self($values);
    }

    /**
     * Build a configuration from explicit values (used by tests).
     *
     * @param array<string, string> $values
     */
    public static function fromArray(array $values): self
    {
        return new self(array_merge(self::DEFAULTS, array_intersect_key($values, self::DEFAULTS)));
    }

    public function isEnabled(): bool
    {
        return $this->values[self::ENABLED] === '1';
    }

    public function onlyDefaultEmail(): bool
    {
        return $this->values[self::ONLY_DEFAULT_EMAIL] === '1';
    }

    public function onlyDynamicEmail(): bool
    {
        return $this->values[self::ONLY_DYNAMIC_EMAIL] === '1';
    }

    public function replaceLoginLabel(): bool
    {
        return $this->values[self::REPLACE_LOGIN_LABEL] === '1';
    }

    public function isLogEnabled(): bool
    {
        return $this->values[self::LOG_ENABLED] === '1';
    }

    public function isThrottleEnabled(): bool
    {
        return $this->values[self::THROTTLE_ENABLED] === '1';
    }

    /** Failed sign-ins allowed per account and per client IP within the window. */
    public function getThrottleMaxPerAccount(): int
    {
        return $this->getBoundedInt(self::THROTTLE_MAX_ACCOUNT);
    }

    /** Failed sign-ins allowed per client IP within the window, all accounts together (0 = no limit). */
    public function getThrottleMaxPerIp(): int
    {
        return $this->getBoundedInt(self::THROTTLE_MAX_IP);
    }

    public function getThrottleWindowMinutes(): int
    {
        return $this->getBoundedInt(self::THROTTLE_WINDOW);
    }

    public function getThrottleLockoutMinutes(): int
    {
        return $this->getBoundedInt(self::THROTTLE_LOCKOUT);
    }

    /**
     * Integer value of a throttling setting, kept within its allowed range (the default value
     * is used when the stored value is not an integer).
     */
    private function getBoundedInt(string $setting): int
    {
        [$min, $max] = self::THROTTLE_RANGES[$setting];
        $value       = $this->values[$setting];
        $number      = ctype_digit($value) ? (int) $value : (int) self::DEFAULTS[$setting];

        return max($min, min($max, $number));
    }

    /**
     * Users allowed to sign in with their e-mail address.
     *
     * @return list<int> Empty list = every user is allowed (other rules still apply).
     *
     * @throws UnexpectedValueException When the stored list is unreadable (fail closed).
     */
    public function getIncludedUserIds(): array
    {
        return $this->decodeUserIds($this->values[self::INCLUDED_USERS], self::INCLUDED_USERS);
    }

    /**
     * Users never allowed to sign in with their e-mail address (takes priority over inclusion).
     *
     * @return list<int>
     *
     * @throws UnexpectedValueException When the stored list is unreadable (fail closed).
     */
    public function getExcludedUserIds(): array
    {
        return $this->decodeUserIds($this->values[self::EXCLUDED_USERS], self::EXCLUDED_USERS);
    }

    /**
     * Normalizes a list of user ids: positive integers only, no duplicates, sorted.
     *
     * @return list<int>
     */
    public static function normalizeUserIds(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }

        $normalized = [];
        foreach ($ids as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $id = (int) $id;
                if ($id > 0) {
                    $normalized[$id] = $id;
                }
            }
        }

        sort($normalized);

        return $normalized;
    }

    /**
     * @param list<int> $ids
     */
    public static function encodeUserIds(array $ids): string
    {
        return json_encode(self::normalizeUserIds($ids), JSON_THROW_ON_ERROR);
    }

    /**
     * An unreadable list must never be read as "empty": an empty exclusion list would let
     * excluded users in, an empty inclusion list would let everybody in. The exception
     * makes the login handler fall back to the native GLPI login (no e-mail sign-in).
     *
     * @return list<int>
     */
    private function decodeUserIds(string $json, string $setting): array
    {
        try {
            $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new UnexpectedValueException(sprintf('The "%s" setting is not valid JSON.', $setting), 0, $jsonException);
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new UnexpectedValueException(sprintf('The "%s" setting is not a list of user ids.', $setting));
        }

        return self::normalizeUserIds($decoded);
    }

    /**
     * @return list<string> Lowercase domains, empty list = every domain is allowed.
     */
    public function getAllowedDomains(): array
    {
        return self::parseDomains($this->values[self::ALLOWED_DOMAINS]);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * Parse a list of domains separated by new lines, spaces, commas or semicolons.
     *
     * @param list<string> $invalid Receives the entries that are not valid domain names.
     *
     * @param-out list<string> $invalid
     *
     * @return list<string>
     */
    public static function parseDomains(string $raw, array &$invalid = []): array
    {
        $invalid = [];
        $domains = [];

        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($parts as $part) {
            if (!is_string($part)) {
                continue;
            }

            $domain = strtolower(ltrim(trim($part), '@'));
            if ($domain === '') {
                continue;
            }

            if (!self::isValidDomain($domain)) {
                $invalid[] = $part;
                continue;
            }

            $domains[$domain] = $domain;
        }

        return array_values($domains);
    }

    private static function isValidDomain(string $domain): bool
    {
        return strlen($domain) <= 253
            && str_contains($domain, '.')
            && preg_match('/^[a-z0-9.-]+$/', $domain) === 1
            && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /**
     * Save the given settings (unknown keys are ignored).
     *
     * @param array<string, string> $values
     */
    public static function save(array $values): void
    {
        Config::setConfigurationValues(self::CONTEXT, array_intersect_key($values, self::DEFAULTS));
    }

    public static function install(): void
    {
        $stored  = Config::getConfigurationValues(self::CONTEXT, array_keys(self::DEFAULTS));
        $missing = array_diff_key(self::DEFAULTS, $stored);

        if ($missing !== []) {
            Config::setConfigurationValues(self::CONTEXT, $missing);
        }
    }

    public static function uninstall(): void
    {
        Config::deleteConfigurationValues(self::CONTEXT, array_keys(self::DEFAULTS));
    }
}
