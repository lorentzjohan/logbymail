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

use Auth;
use GlpiPlugin\Logbymail\PluginConfig;
use GlpiPlugin\Logbymail\ResolutionStatus;
use Session;
use User;

/**
 * E-mail address resolution against the real GLPI tables (users and their e-mail addresses),
 * and GLPI accepting the resolved login.
 */
final class EmailResolutionTest extends IntegrationTestCase
{
    public function testUniqueAddressIsResolvedAndAcceptedByGlpi(): void
    {
        $user = $this->createUserWithEmails('lbm_jdoe', [['Jane.Doe@example.com']]);

        $resolution = $this->resolver()->resolve('  jane.doe@EXAMPLE.com ');
        $this->assertSame(ResolutionStatus::RESOLVED, $resolution->status);
        $this->assertSame('lbm_jdoe', $resolution->login);

        // GLPI's own authentication refuses a wrong password...
        $this->logOut();
        $this->assertFalse((new Auth())->login($resolution->login, 'wrong password', true));
        $this->assertFalse(Session::getLoginUserID());

        // ... and opens the session of the right account with the user's password.
        $this->assertTrue((new Auth())->login($resolution->login, self::PASSWORD, true));
        $this->assertSame($user->getID(), Session::getLoginUserID());
    }

    public function testInactiveAccountIsStillRefusedByGlpi(): void
    {
        $this->createUserWithEmails('lbm_inactive', [['inactive@example.com']], ['is_active' => 0]);

        $resolution = $this->resolver()->resolve('inactive@example.com');
        $this->assertSame(ResolutionStatus::RESOLVED, $resolution->status);

        // GLPI's own rules still apply: no session for an inactive account.
        $this->logOut();
        (new Auth())->login((string) $resolution->login, self::PASSWORD, true);
        $this->assertFalse(Session::getLoginUserID());
    }

    public function testInvalidOrUnknownIdentifiers(): void
    {
        $resolver = $this->resolver();

        $this->assertSame(ResolutionStatus::NOT_AN_EMAIL, $resolver->resolve('lbm_jdoe')->status);
        $this->assertSame(ResolutionStatus::NOT_AN_EMAIL, $resolver->resolve("someone@example.com\nX")->status);
        $this->assertSame(ResolutionStatus::NOT_AN_EMAIL, $resolver->resolve(str_repeat('a', 250) . '@example.com')->status);
        $this->assertSame(ResolutionStatus::UNKNOWN_EMAIL, $resolver->resolve('nobody@example.com')->status);
    }

    public function testSharedAddressIsRefused(): void
    {
        $this->createUserWithEmails('lbm_alice', [['alice@example.com'], ['shared@example.com', false]]);
        $bob = $this->createUserWithEmails('lbm_bob', [['SHARED@example.com']]);

        $resolution = $this->resolver()->resolve('shared@example.com');
        $this->assertSame(ResolutionStatus::AMBIGUOUS_EMAIL, $resolution->status);
        $this->assertSame(2, $resolution->owners_count);

        // A user in the trash does not count.
        $this->updateItem(User::class, $bob->getID(), ['is_deleted' => 1]);
        $resolution = $this->resolver()->resolve('shared@example.com');
        $this->assertSame(ResolutionStatus::RESOLVED, $resolution->status);
        $this->assertSame('lbm_alice', $resolution->login);
    }

    public function testLoginEqualToTheAddressKeepsTheNativeLogin(): void
    {
        $this->createUserWithEmails('helpdesk@example.com', []);
        $this->createUserWithEmails('lbm_dave', [['helpdesk@example.com']]);

        $this->assertSame(ResolutionStatus::LOGIN_EXISTS, $this->resolver()->resolve('helpdesk@example.com')->status);
    }

    public function testDuplicatedLoginIsRefused(): void
    {
        global $DB;

        $this->createUserWithEmails('lbm_frank', [['frank@example.com']]);
        $this->assertSame(ResolutionStatus::RESOLVED, $this->resolver()->resolve('frank@example.com')->status);

        // Same login from another authentication source (LDAP): GLPI could pick either account.
        $DB->insert(User::getTable(), ['name' => 'lbm_frank', 'authtype' => Auth::LDAP, 'auths_id' => 999, 'is_active' => 1]);
        $this->assertSame(ResolutionStatus::AMBIGUOUS_LOGIN, $this->resolver()->resolve('frank@example.com')->status);
    }

    public function testKindOfAddress(): void
    {
        $this->createUserWithEmails('lbm_carol', [['carol.main@example.com', true, true], ['carol@example.com', false, false]]);

        $only_default = $this->resolver([PluginConfig::ONLY_DEFAULT_EMAIL => '1']);
        $this->assertSame(ResolutionStatus::NOT_ELIGIBLE, $only_default->resolve('carol@example.com')->status);
        $this->assertSame(ResolutionStatus::RESOLVED, $only_default->resolve('carol.main@example.com')->status);

        $only_dynamic = $this->resolver([PluginConfig::ONLY_DYNAMIC_EMAIL => '1']);
        $this->assertSame(ResolutionStatus::NOT_ELIGIBLE, $only_dynamic->resolve('carol@example.com')->status);
        $this->assertSame(ResolutionStatus::RESOLVED, $only_dynamic->resolve('carol.main@example.com')->status);
    }

    public function testAllowedDomains(): void
    {
        $this->createUserWithEmails('lbm_erin', [['erin@example.com'], ['erin@sub.example.com', false]]);
        $this->createUserWithEmails('lbm_gina', [['gina@example.org']]);

        $resolver = $this->resolver([PluginConfig::ALLOWED_DOMAINS => "example.com"]);
        $this->assertSame(ResolutionStatus::RESOLVED, $resolver->resolve('erin@example.com')->status);
        $this->assertSame(ResolutionStatus::DOMAIN_NOT_ALLOWED, $resolver->resolve('erin@sub.example.com')->status);
        $this->assertSame(ResolutionStatus::DOMAIN_NOT_ALLOWED, $resolver->resolve('gina@example.org')->status);
    }

    public function testIncludedAndExcludedUsers(): void
    {
        $henry = $this->createUserWithEmails('lbm_henry', [['henry@example.com']]);
        $ivan  = $this->createUserWithEmails('lbm_ivan', [['ivan@example.com']]);

        $included = $this->resolver([PluginConfig::INCLUDED_USERS => PluginConfig::encodeUserIds([$henry->getID()])]);
        $this->assertSame(ResolutionStatus::RESOLVED, $included->resolve('henry@example.com')->status);
        $this->assertSame(ResolutionStatus::USER_NOT_INCLUDED, $included->resolve('ivan@example.com')->status);

        $excluded = $this->resolver([PluginConfig::EXCLUDED_USERS => PluginConfig::encodeUserIds([$henry->getID()])]);
        $this->assertSame(ResolutionStatus::USER_EXCLUDED, $excluded->resolve('henry@example.com')->status);
        $this->assertSame(ResolutionStatus::RESOLVED, $excluded->resolve('ivan@example.com')->status);

        // Exclusion takes priority over inclusion.
        $both = $this->resolver([
            PluginConfig::INCLUDED_USERS => PluginConfig::encodeUserIds([$henry->getID(), $ivan->getID()]),
            PluginConfig::EXCLUDED_USERS => PluginConfig::encodeUserIds([$henry->getID()]),
        ]);
        $this->assertSame(ResolutionStatus::USER_EXCLUDED, $both->resolve('henry@example.com')->status);
        $this->assertSame(ResolutionStatus::RESOLVED, $both->resolve('ivan@example.com')->status);
    }
}
