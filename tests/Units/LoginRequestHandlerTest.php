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

namespace GlpiPlugin\Logbymail\Tests\Units;

use GlpiPlugin\Logbymail\LoginRequestHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class LoginRequestHandlerTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    private function buildRequest(string $method, string $path, array $headers = []): Request
    {
        $server = ['HTTP_HOST' => 'glpi.example.com'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('http://glpi.example.com' . $path, $method, [], [], [], $server);
    }

    protected function tearDown(): void
    {
        unset($_POST[LoginRequestHandler::LOGIN_FIELD]);
        parent::tearDown();
    }

    public function testOnlyLoginFormPostsAreCandidates(): void
    {
        $_POST[LoginRequestHandler::LOGIN_FIELD] = 'john@example.com';

        $this->assertSame('john@example.com', LoginRequestHandler::extractIdentifier($this->buildRequest('POST', '/front/login.php')));
        $this->assertNull(LoginRequestHandler::extractIdentifier($this->buildRequest('GET', '/front/login.php')));
        $this->assertNull(LoginRequestHandler::extractIdentifier($this->buildRequest('POST', '/front/user.form.php')));
        $this->assertNull(LoginRequestHandler::extractIdentifier($this->buildRequest('POST', '/front/login.php.bak')));
        $this->assertNull(LoginRequestHandler::extractIdentifier($this->buildRequest('POST', '/apirest.php/initSession')));
    }

    public function testNonEmailOrNonStringIdentifiersAreIgnored(): void
    {
        $request = $this->buildRequest('POST', '/front/login.php');

        $_POST[LoginRequestHandler::LOGIN_FIELD] = 'jdoe';
        $this->assertNull(LoginRequestHandler::extractIdentifier($request));

        $_POST[LoginRequestHandler::LOGIN_FIELD] = ['john@example.com'];
        $this->assertNull(LoginRequestHandler::extractIdentifier($request));

        unset($_POST[LoginRequestHandler::LOGIN_FIELD]);
        $this->assertNull(LoginRequestHandler::extractIdentifier($request));
    }

    public function testCrossSiteRequestsAreIgnored(): void
    {
        $this->assertTrue(LoginRequestHandler::isSameOriginRequest($this->buildRequest('POST', '/front/login.php', ['Sec-Fetch-Site' => 'same-origin'])));
        $this->assertTrue(LoginRequestHandler::isSameOriginRequest($this->buildRequest('POST', '/front/login.php', ['Sec-Fetch-Site' => 'none'])));
        $this->assertFalse(LoginRequestHandler::isSameOriginRequest($this->buildRequest('POST', '/front/login.php', ['Sec-Fetch-Site' => 'cross-site'])));
        $this->assertFalse(LoginRequestHandler::isSameOriginRequest($this->buildRequest('POST', '/front/login.php', ['Sec-Fetch-Site' => 'same-site'])));
        $this->assertTrue(LoginRequestHandler::isSameOriginRequest($this->buildRequest('POST', '/front/login.php', ['Origin' => 'http://glpi.example.com'])));
        $this->assertFalse(LoginRequestHandler::isSameOriginRequest($this->buildRequest('POST', '/front/login.php', ['Origin' => 'https://evil.example.net'])));
        $this->assertFalse(LoginRequestHandler::isSameOriginRequest($this->buildRequest('POST', '/front/login.php', ['Origin' => 'null'])));
        $this->assertTrue(LoginRequestHandler::isSameOriginRequest($this->buildRequest('POST', '/front/login.php')));
    }
}
