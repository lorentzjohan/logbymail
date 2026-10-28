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

/**
 * Diagnostic shown on the configuration page: addresses owned by several users.
 *
 * These addresses are refused at sign-in ({@see ResolutionStatus::AMBIGUOUS_EMAIL}).
 */
final class SharedEmails
{
    public function __construct(private readonly DBmysql $db) {}

    /**
     * @return array{
     *     items: list<array{email: string, users: list<array{id: int, name: string, fullname: string, url: string}>}>,
     *     truncated: bool
     * }
     */
    public function find(int $limit = 100): array
    {
        $emails_table = UserEmail::getTable();
        $users_table  = User::getTable();

        $join = [
            $users_table => [
                'ON' => [
                    $emails_table => 'users_id',
                    $users_table  => 'id',
                ],
            ],
        ];
        $where = [
            "$users_table.is_deleted" => 0,
            'NOT'                     => ["$emails_table.email" => null],
            "$emails_table.email"     => ['<>', ''],
        ];

        // Grouping uses the column collation (case insensitive), like the sign-in check.
        $shared = [];
        $iterator = $this->db->request([
            'SELECT'         => [
                "$emails_table.email",
                'COUNT DISTINCT' => "$emails_table.users_id AS owners",
            ],
            'FROM'           => $emails_table,
            'INNER JOIN'     => $join,
            'WHERE'          => $where,
            'GROUPBY'        => "$emails_table.email",
            'HAVING'         => ['owners' => ['>', 1]],
            'ORDERBY'        => "$emails_table.email",
            'LIMIT'          => $limit + 1,
        ]);
        foreach ($iterator as $row) {
            $shared[] = (string) $row['email'];
        }

        $truncated = count($shared) > $limit;
        $shared    = array_slice($shared, 0, $limit);
        if ($shared === []) {
            return ['items' => [], 'truncated' => false];
        }

        $items = [];
        foreach ($shared as $email) {
            $items[strtolower($email)] = ['email' => $email, 'users' => []];
        }

        $iterator = $this->db->request([
            'SELECT'     => [
                "$emails_table.email",
                "$users_table.id",
                "$users_table.name",
                "$users_table.realname",
                "$users_table.firstname",
            ],
            'DISTINCT'   => true,
            'FROM'       => $emails_table,
            'INNER JOIN' => $join,
            'WHERE'      => $where + ["$emails_table.email" => $shared],
            'ORDERBY'    => ["$users_table.name"],
        ]);
        foreach ($iterator as $row) {
            $key = strtolower((string) $row['email']);
            if (!isset($items[$key])) {
                // Same address with a different case: attach it to the group found above.
                foreach (array_keys($items) as $candidate) {
                    if (strcasecmp($candidate, $key) === 0) {
                        $key = $candidate;
                        break;
                    }
                }
                if (!isset($items[$key])) {
                    continue;
                }
            }

            $users_id = (int) $row['id'];
            if (isset($items[$key]['users'][$users_id])) {
                continue;
            }
            $items[$key]['users'][$users_id] = [
                'id'       => $users_id,
                'name'     => (string) $row['name'],
                'fullname' => trim(((string) $row['realname']) . ' ' . ((string) $row['firstname'])),
                'url'      => User::getFormURLWithID($users_id),
            ];
        }

        $result = [];
        foreach ($items as $item) {
            $result[] = ['email' => $item['email'], 'users' => array_values($item['users'])];
        }

        return ['items' => $result, 'truncated' => $truncated];
    }
}
