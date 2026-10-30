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

use GlpiPlugin\Logbymail\AuditLog;
use GlpiPlugin\Logbymail\EmailLoginResolver;
use GlpiPlugin\Logbymail\LoginRequestHandler;
use GlpiPlugin\Logbymail\LoginThrottle;
use GlpiPlugin\Logbymail\PluginConfig;
use Symfony\Component\HttpFoundation\Request;

/**
 * Login form handling (the submitted e-mail address is replaced by the login), sign-in log
 * and brute-force protection with the real GLPI cache.
 */
final class SignInTest extends IntegrationTestCase
{
    public function tearDown(): void
    {
        unset($_POST[LoginRequestHandler::LOGIN_FIELD]);
        parent::tearDown();
    }

    /**
     * @param array<string, string> $settings
     */
    private function submit(string $login, array $settings = []): array
    {
        global $DB;

        $config  = PluginConfig::fromArray($settings);
        $request = Request::create('http://glpi.example.com/front/login.php', 'POST', [LoginRequestHandler::LOGIN_FIELD => $login]);
        $_POST[LoginRequestHandler::LOGIN_FIELD] = $login;

        $account = (new LoginRequestHandler($config, new EmailLoginResolver($DB, $config)))->process($request, $login);

        return [$account, $_POST[LoginRequestHandler::LOGIN_FIELD], $request->request->get(LoginRequestHandler::LOGIN_FIELD)];
    }

    public function testSubmittedAddressIsReplacedByTheLogin(): void
    {
        $this->createUserWithEmails('lbm_kate', [['kate@example.com']]);

        [$account, $posted, $request_value] = $this->submit('Kate@Example.com');
        $this->assertSame('lbm_kate', $account);
        $this->assertSame('lbm_kate', $posted);
        $this->assertSame('lbm_kate', $request_value);
    }

    public function testLoginAndRefusedAddressesAreLeftUntouched(): void
    {
        $this->createUserWithEmails('lbm_liam', [['liam@example.com']]);

        $this->assertSame(['lbm_liam', 'lbm_liam', 'lbm_liam'], $this->submit('lbm_liam'));
        $this->assertSame(['nobody@example.com', 'nobody@example.com', 'nobody@example.com'], $this->submit('nobody@example.com'));
        // E-mail sign-in disabled in the settings
        $this->assertSame(['liam@example.com', 'liam@example.com', 'liam@example.com'], $this->submit('liam@example.com', [PluginConfig::ENABLED => '0']));
    }

    public function testSignInAttemptsAreLogged(): void
    {
        $this->createUserWithEmails('lbm_mona', [['mona@example.com']]);

        $this->submit('mona@example.com');
        $this->submit('nobody@example.com');

        $log = (string) file_get_contents(GLPI_LOG_DIR . '/' . AuditLog::LOG_NAME . '.log');
        $this->assertStringContainsString('result=resolved identifier="mona@example.com" login="lbm_mona"', $log);
        $this->assertStringContainsString('result=unknown_email identifier="nobody@example.com"', $log);
    }

    public function testBruteForceProtectionWithTheGlpiCache(): void
    {
        $this->assertNull(LoginThrottle::fromConfig(PluginConfig::fromArray([PluginConfig::THROTTLE_ENABLED => '0'])));

        $throttle = LoginThrottle::fromConfig(PluginConfig::fromArray([
            PluginConfig::THROTTLE_MAX_ACCOUNT => '3',
            PluginConfig::THROTTLE_MAX_IP      => '0',
        ]));
        $this->assertInstanceOf(LoginThrottle::class, $throttle);

        // Unique login: the GLPI cache is not rolled back between tests.
        $login = 'lbm_throttle_' . bin2hex(random_bytes(6));
        $this->assertSame([], $throttle->recordFailure($login, '192.0.2.10'));
        $this->assertSame([], $throttle->recordFailure($login, '192.0.2.10'));
        $this->assertSame(['account'], $throttle->recordFailure($login, '192.0.2.10'));
        $this->assertGreaterThan(0, $throttle->getRemainingLockTime($login, '192.0.2.10'));
        $this->assertSame(0, $throttle->getRemainingLockTime($login, '192.0.2.11'));

        // Read back by a new instance: the counters are really stored in the GLPI cache.
        $other_instance = LoginThrottle::fromConfig(PluginConfig::fromArray([]));
        $this->assertInstanceOf(LoginThrottle::class, $other_instance);
        $this->assertGreaterThan(0, $other_instance->getRemainingLockTime($login, '192.0.2.10'));
    }
}
