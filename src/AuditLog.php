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

use Toolbox;

use function Safe\preg_replace;

/**
 * Writes e-mail sign-in attempts to `files/_log/logbymail.log`.
 *
 * One `key=value` line per attempt, easy to read and to parse (grep, fail2ban...).
 * The password is never available to the plugin and is never written.
 */
final class AuditLog
{
    public const LOG_NAME = 'logbymail';

    public static function record(Resolution $resolution, ?string $ip): void
    {
        $line = sprintf(
            'result=%s identifier="%s" login="%s" users_id=%d owners=%d ip=%s',
            $resolution->status->value,
            self::sanitize($resolution->identifier),
            self::sanitize($resolution->login ?? ''),
            $resolution->users_id ?? 0,
            $resolution->owners_count,
            self::sanitize($ip ?? 'unknown'),
        );

        Toolbox::logInFile(self::LOG_NAME, $line . "\n", true, false);
    }

    /**
     * Attempt refused because the account or the IP is locked.
     */
    public static function recordBlocked(string $login, ?string $ip, int $retry_after): void
    {
        self::write(sprintf(
            'result=blocked login="%s" retry_after=%d ip=%s',
            self::sanitize($login),
            $retry_after,
            self::sanitize($ip ?? 'unknown'),
        ));
    }

    /**
     * Lockout started by a failed sign-in.
     *
     * @param list<string> $scopes "account" and/or "ip"
     */
    public static function recordLockout(string $login, ?string $ip, array $scopes, int $duration): void
    {
        self::write(sprintf(
            'result=locked scope=%s login="%s" duration=%d ip=%s',
            implode(',', $scopes),
            self::sanitize($login),
            $duration,
            self::sanitize($ip ?? 'unknown'),
        ));
    }

    private static function write(string $line): void
    {
        Toolbox::logInFile(self::LOG_NAME, $line . "\n", true, false);
    }

    /**
     * Prevents log forging: no control characters, no double quotes, bounded length.
     */
    private static function sanitize(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
        $value = str_replace('"', "'", $value);

        return mb_substr($value, 0, EmailLoginResolver::MAX_IDENTIFIER_LENGTH);
    }
}
