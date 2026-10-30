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

use Config;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Logbymail\PluginConfig;
use GlpiPlugin\Logbymail\UserLists;
use User;

/**
 * Plugin integration in GLPI: hooks, settings storage, update and uninstall.
 */
final class LifecycleTest extends IntegrationTestCase
{
    public function testHooksAreRegistered(): void
    {
        global $PLUGIN_HOOKS;

        $this->assertSame('config', $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['logbymail'] ?? null);
        $this->assertIsCallable($PLUGIN_HOOKS[Hooks::ITEM_PURGE]['logbymail'][User::class] ?? null);
    }

    public function testSettingsAreStoredInGlpi(): void
    {
        PluginConfig::save([PluginConfig::ALLOWED_DOMAINS => "example.com\nexample.org", PluginConfig::THROTTLE_MAX_ACCOUNT => '7']);

        $config = PluginConfig::load();
        $this->assertSame(['example.com', 'example.org'], $config->getAllowedDomains());
        $this->assertSame(7, $config->getThrottleMaxPerAccount());
    }

    public function testUpdateKeepsTheSettingsAndAddsTheNewOnes(): void
    {
        // Settings of a previous version: brute-force settings missing, e-mail sign-in disabled.
        Config::deleteConfigurationValues(PluginConfig::CONTEXT, [PluginConfig::THROTTLE_ENABLED, PluginConfig::THROTTLE_MAX_ACCOUNT]);
        PluginConfig::save([PluginConfig::ENABLED => '0']);

        PluginConfig::install();

        $stored = Config::getConfigurationValues(PluginConfig::CONTEXT);
        $this->assertSame('0', $stored[PluginConfig::ENABLED]);
        $this->assertSame(PluginConfig::DEFAULTS[PluginConfig::THROTTLE_ENABLED], $stored[PluginConfig::THROTTLE_ENABLED]);
        $this->assertSame(PluginConfig::DEFAULTS[PluginConfig::THROTTLE_MAX_ACCOUNT], $stored[PluginConfig::THROTTLE_MAX_ACCOUNT]);
    }

    public function testUninstallRemovesAllTheSettings(): void
    {
        PluginConfig::uninstall();
        $this->assertSame([], Config::getConfigurationValues(PluginConfig::CONTEXT));

        PluginConfig::install();
        $this->assertCount(count(PluginConfig::DEFAULTS), Config::getConfigurationValues(PluginConfig::CONTEXT));
    }

    public function testPurgedUserIsRemovedFromTheUsersLists(): void
    {
        global $DB;

        $user  = $this->createUserWithEmails('lbm_nora', [['nora@example.com']]);
        $other = $this->createUserWithEmails('lbm_otto', [['otto@example.com']]);
        PluginConfig::save([
            PluginConfig::INCLUDED_USERS => PluginConfig::encodeUserIds([$user->getID(), $other->getID()]),
            PluginConfig::EXCLUDED_USERS => PluginConfig::encodeUserIds([$user->getID()]),
        ]);
        $this->assertSame([$user->getID()], UserLists::filterExisting($DB, [$user->getID(), 999999]));

        // Purged through GLPI: the plugin hook cleans both lists.
        $this->deleteItem(User::class, $user->getID(), true);

        $config = PluginConfig::load();
        $this->assertSame([$other->getID()], $config->getIncludedUserIds());
        $this->assertSame([], $config->getExcludedUserIds());
    }
}
