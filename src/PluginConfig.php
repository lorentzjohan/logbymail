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

use function Safe\preg_match;
use function Safe\preg_split;

/**
 * Plugin settings, stored in the GLPI `glpi_configs` table (context "plugin:logbymail").
 */
final class PluginConfig
{
    public const CONTEXT = 'plugin:logbymail';

    public const ENABLED             = 'enabled';
    public const ONLY_DEFAULT_EMAIL  = 'only_default_email';
    public const ONLY_DYNAMIC_EMAIL  = 'only_dynamic_email';
    public const ALLOWED_DOMAINS     = 'allowed_domains';
    public const REPLACE_LOGIN_LABEL = 'replace_login_label';
    public const LOG_ENABLED         = 'log_enabled';

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
    ];

    /**
     * Maximum number of allowed domains, to keep the setting readable and the checks cheap.
     */
    public const MAX_DOMAINS = 50;

    /**
     * @param array<string, string> $values
     */
    private function __construct(private readonly array $values) {}

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
     * @return list<string>
     */
    public static function parseDomains(string $raw, array &$invalid = []): array
    {
        $invalid = [];
        $domains = [];

        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($parts as $part) {
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
