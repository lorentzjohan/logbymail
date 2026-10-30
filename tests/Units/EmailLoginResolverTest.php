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

use GlpiPlugin\Logbymail\EmailLoginResolver;
use GlpiPlugin\Logbymail\PluginConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailLoginResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function identifiersProvider(): iterable
    {
        yield 'simple address'            => ['john.doe@example.com', true];
        yield 'uppercase address'         => ['John.Doe@EXAMPLE.com', true];
        yield 'plain login'               => ['jdoe', false];
        yield 'empty'                     => ['', false];
        yield 'no domain'                 => ['jdoe@', false];
        yield 'two at signs'              => ['a@b@example.com', false];
        yield 'new line'                  => ["john@example.com\nX", false];
        yield 'NUL byte'                  => ["john@example.com\0", false];
        yield 'space inside'              => ['john doe@example.com', false];
        yield 'too long'                  => [str_repeat('a', 250) . '@example.com', false];
        yield 'non ASCII'                 => ['user@exämple.com', false];
    }

    #[DataProvider('identifiersProvider')]
    public function testIsEmailAddress(string $identifier, bool $expected): void
    {
        $this->assertSame($expected, EmailLoginResolver::isEmailAddress($identifier));
    }

    public function testGetDomain(): void
    {
        $this->assertSame('example.com', EmailLoginResolver::getDomain('John@Example.COM'));
        $this->assertSame('', EmailLoginResolver::getDomain('jdoe'));
    }

    public function testParseDomains(): void
    {
        $invalid = [];
        $this->assertSame(
            ['example.com', 'example.org'],
            PluginConfig::parseDomains("Example.com\n@example.org, example.com;", $invalid),
        );
        $this->assertSame([], $invalid);

        $this->assertSame(['example.com'], PluginConfig::parseDomains("example.com localhost <b>x</b>.com", $invalid));
        $this->assertSame(['localhost', '<b>x</b>.com'], $invalid);

        $this->assertSame([], PluginConfig::parseDomains('', $invalid));
        $this->assertSame([], $invalid);
    }

    public function testDefaultConfiguration(): void
    {
        $config = PluginConfig::fromArray([]);
        $this->assertTrue($config->isEnabled());
        $this->assertFalse($config->onlyDefaultEmail());
        $this->assertFalse($config->onlyDynamicEmail());
        $this->assertSame([], $config->getAllowedDomains());
        $this->assertTrue($config->replaceLoginLabel());
        $this->assertTrue($config->isLogEnabled());
    }
}
