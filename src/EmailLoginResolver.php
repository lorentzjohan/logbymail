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

use DBmysql;
use User;
use UserEmail;

use function Safe\preg_match;

/**
 * Resolves an e-mail address typed in the login form into the login of its owner.
 *
 * Security rules (any failed rule leaves the identifier untouched):
 *  1. the identifier must be a syntactically valid address of at most 254 characters,
 *     without control characters;
 *  2. its domain must be allowed (when a list of allowed domains is configured);
 *  3. no GLPI login may be literally equal to the address (native login wins);
 *  4. the address must belong to exactly ONE user who is not in the trash, whatever
 *     the kind of address (default or not, synchronized or not) — shared addresses
 *     are never usable;
 *  5. the matching address must be of an allowed kind (default / synchronized),
 *     when these restrictions are enabled;
 *  6. the login of the owner must itself be unique in GLPI, so that the native
 *     authentication cannot land on another account sharing the same login.
 *
 * Every query uses the GLPI query builder (bound values, no string concatenation).
 */
final class EmailLoginResolver
{
    /**
     * RFC 5321 limit for a forward path.
     */
    public const MAX_IDENTIFIER_LENGTH = 254;

    public function __construct(
        private readonly DBmysql $db,
        private readonly PluginConfig $config,
    ) {}

    public function resolve(string $raw_identifier): Resolution
    {
        $identifier = trim($raw_identifier);

        if (!self::isEmailAddress($identifier)) {
            return Resolution::refused(ResolutionStatus::NOT_AN_EMAIL, $identifier);
        }

        $allowed_domains = $this->config->getAllowedDomains();
        if ($allowed_domains !== [] && !in_array(self::getDomain($identifier), $allowed_domains, true)) {
            return Resolution::refused(ResolutionStatus::DOMAIN_NOT_ALLOWED, $identifier);
        }

        if ($this->countUsersByLogin($identifier) > 0) {
            return Resolution::refused(ResolutionStatus::LOGIN_EXISTS, $identifier);
        }

        $addresses = $this->findAddresses($identifier);

        // Owners are counted on the database comparison (case and accent insensitive collation),
        // which is broader than the strict comparison below: an address that merely looks like
        // another user's one is enough to make it ambiguous.
        $owners = array_unique(array_column($addresses, 'users_id'));
        if (count($owners) === 0) {
            return Resolution::refused(ResolutionStatus::UNKNOWN_EMAIL, $identifier);
        }
        if (count($owners) > 1) {
            return Resolution::refused(ResolutionStatus::AMBIGUOUS_EMAIL, $identifier, count($owners));
        }

        // Single owner: at least one of their addresses must strictly match (ASCII case-insensitive)
        // and be of an allowed kind.
        $owner_row = null;
        $strict_match_found = false;
        foreach ($addresses as $address) {
            if (strcasecmp(trim((string) $address['email']), $identifier) !== 0) {
                continue;
            }
            $strict_match_found = true;

            if ($this->config->onlyDefaultEmail() && (int) $address['is_default'] !== 1) {
                continue;
            }
            if ($this->config->onlyDynamicEmail() && (int) $address['is_dynamic'] !== 1) {
                continue;
            }

            $owner_row = $address;
            break;
        }

        if (!$strict_match_found) {
            return Resolution::refused(ResolutionStatus::UNKNOWN_EMAIL, $identifier);
        }
        if ($owner_row === null) {
            return Resolution::refused(ResolutionStatus::NOT_ELIGIBLE, $identifier, 1);
        }

        $login = (string) $owner_row['name'];
        if ($login === '') {
            return Resolution::refused(ResolutionStatus::UNKNOWN_EMAIL, $identifier, 1);
        }

        if ($this->countUsersByLogin($login) !== 1) {
            return Resolution::refused(ResolutionStatus::AMBIGUOUS_LOGIN, $identifier, 1);
        }

        return Resolution::resolved($identifier, $login, (int) $owner_row['users_id']);
    }

    /**
     * Strict syntactic check: no control character, RFC length limit, PHP e-mail validation
     * (ASCII only, internationalized addresses are not accepted).
     */
    public static function isEmailAddress(string $identifier): bool
    {
        return $identifier !== ''
            && strlen($identifier) <= self::MAX_IDENTIFIER_LENGTH
            && preg_match('/[\x00-\x20\x7F]/', $identifier) === 0
            && filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function getDomain(string $email): string
    {
        $at_position = strrpos($email, '@');

        return $at_position === false ? '' : strtolower(substr($email, $at_position + 1));
    }

    private function countUsersByLogin(string $login): int
    {
        $result = $this->db->request([
            'COUNT' => 'cpt',
            'FROM'  => User::getTable(),
            'WHERE' => ['name' => $login],
        ])->current();

        return (int) ($result['cpt'] ?? 0);
    }

    /**
     * Addresses equal to the identifier (database collation), owned by users not in the trash.
     *
     * @return list<array{users_id: int|string, email: ?string, is_default: int|string, is_dynamic: int|string, name: ?string}>
     */
    private function findAddresses(string $identifier): array
    {
        $emails_table = UserEmail::getTable();
        $users_table  = User::getTable();

        $iterator = $this->db->request([
            'SELECT'     => [
                "$emails_table.users_id",
                "$emails_table.email",
                "$emails_table.is_default",
                "$emails_table.is_dynamic",
                "$users_table.name",
            ],
            'FROM'       => $emails_table,
            'INNER JOIN' => [
                $users_table => [
                    'ON' => [
                        $emails_table => 'users_id',
                        $users_table  => 'id',
                    ],
                ],
            ],
            'WHERE'      => [
                "$emails_table.email"     => $identifier,
                "$users_table.is_deleted" => 0,
            ],
        ]);

        $addresses = [];
        foreach ($iterator as $row) {
            $addresses[] = $row;
        }

        return $addresses;
    }
}
