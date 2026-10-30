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

namespace GlpiPlugin\Logbymail\Tests\Integration;

use Glpi\Tests\DbTestCase;
use GlpiPlugin\Logbymail\EmailLoginResolver;
use GlpiPlugin\Logbymail\PluginConfig;
use User;
use UserEmail;

/**
 * Base class of the integration tests: they run inside GLPI, on its test database
 * (each test is rolled back), with the plugin installed and activated.
 */
abstract class IntegrationTestCase extends DbTestCase
{
    protected const PASSWORD = 'Logbymail-Test-1';

    /**
     * Creates an active local user with the given e-mail addresses.
     *
     * @param list<array{0: string, 1?: bool, 2?: bool}> $emails [address, is default, is dynamic]
     * @param array<string, mixed> $fields
     */
    protected function createUserWithEmails(string $login, array $emails, array $fields = []): User
    {
        $user = $this->createItem(
            User::class,
            array_merge(['name' => $login, 'password' => self::PASSWORD, 'password2' => self::PASSWORD, 'is_active' => 1], $fields),
            ['password', 'password2'],
        );
        $this->assertInstanceOf(User::class, $user);

        foreach ($emails as $email) {
            $this->addEmail($user, $email[0], $email[1] ?? true, $email[2] ?? false);
        }

        return $user;
    }

    protected function addEmail(User $user, string $email, bool $is_default = true, bool $is_dynamic = false): void
    {
        global $DB;

        // Direct insert: the GLPI form logic would reset the "dynamic" flag of a manual addition.
        $DB->insert(UserEmail::getTable(), [
            'users_id'   => $user->getID(),
            'email'      => $email,
            'is_default' => (int) $is_default,
            'is_dynamic' => (int) $is_dynamic,
        ]);
    }

    /**
     * @param array<string, string> $settings
     */
    protected function resolver(array $settings = []): EmailLoginResolver
    {
        global $DB;

        return new EmailLoginResolver($DB, PluginConfig::fromArray($settings));
    }
}
