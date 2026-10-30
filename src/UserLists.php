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
use Throwable;
use User;

/**
 * Included / excluded users lists: validation and housekeeping.
 */
final class UserLists
{
    /**
     * Keeps only the ids of users that exist in GLPI.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public static function filterExisting(DBmysql $db, array $ids): array
    {
        $ids = PluginConfig::normalizeUserIds($ids);
        if ($ids === []) {
            return [];
        }

        $existing = [];
        $iterator = $db->request([
            'SELECT' => ['id'],
            'FROM'   => User::getTable(),
            'WHERE'  => ['id' => $ids],
        ]);
        foreach ($iterator as $row) {
            if (is_array($row)) {
                $existing[] = Scalar::toInt($row['id'] ?? 0);
            }
        }

        return PluginConfig::normalizeUserIds($existing);
    }

    /**
     * `item_purge` hook: a purged user is removed from both lists.
     */
    public static function onUserPurge(User $user): void
    {
        try {
            $users_id = (int) $user->getID();
            if ($users_id <= 0) {
                return;
            }

            $config  = PluginConfig::load();
            $changes = [];
            foreach (
                [
                    PluginConfig::INCLUDED_USERS => $config->getIncludedUserIds(),
                    PluginConfig::EXCLUDED_USERS => $config->getExcludedUserIds(),
                ] as $setting => $ids
            ) {
                if (in_array($users_id, $ids, true)) {
                    $changes[$setting] = PluginConfig::encodeUserIds(array_values(array_diff($ids, [$users_id])));
                }
            }

            if ($changes !== []) {
                PluginConfig::save($changes);
            }
        } catch (Throwable $throwable) {
            global $PHPLOGGER;
            $PHPLOGGER->error(
                sprintf('LogByMail: could not remove purged user from the users lists: %s', $throwable->getMessage()),
                ['exception' => $throwable],
            );
        }
    }
}
