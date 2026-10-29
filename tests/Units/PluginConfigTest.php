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

use GlpiPlugin\Logbymail\PluginConfig;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class PluginConfigTest extends TestCase
{
    public function testUsersListsAreEmptyByDefault(): void
    {
        $config = PluginConfig::fromArray([]);
        $this->assertSame([], $config->getIncludedUserIds());
        $this->assertSame([], $config->getExcludedUserIds());
    }

    public function testNormalizeUserIds(): void
    {
        $this->assertSame([2, 7, 15], PluginConfig::normalizeUserIds(['15', 7, '7', 2, '0', -3, 'abc', '1.5', null, ['9']]));
        $this->assertSame([], PluginConfig::normalizeUserIds('7'));
        $this->assertSame([], PluginConfig::normalizeUserIds(null));
    }

    public function testUsersListsRoundTrip(): void
    {
        $config = PluginConfig::fromArray([
            PluginConfig::INCLUDED_USERS => PluginConfig::encodeUserIds([9, 3, 3]),
            PluginConfig::EXCLUDED_USERS => PluginConfig::encodeUserIds([3]),
        ]);
        $this->assertSame([3, 9], $config->getIncludedUserIds());
        $this->assertSame([3], $config->getExcludedUserIds());
    }

    public function testUnreadableExcludedListFailsClosed(): void
    {
        $config = PluginConfig::fromArray([PluginConfig::EXCLUDED_USERS => '{broken']);

        $thrown = false;
        try {
            $config->getExcludedUserIds();
        } catch (UnexpectedValueException) {
            $thrown = true;
        }
        $this->assertTrue($thrown);
    }

    public function testNonListIncludedValueFailsClosed(): void
    {
        $config = PluginConfig::fromArray([PluginConfig::INCLUDED_USERS => '{"a": 1}']);

        $thrown = false;
        try {
            $config->getIncludedUserIds();
        } catch (UnexpectedValueException) {
            $thrown = true;
        }
        $this->assertTrue($thrown);
    }
}
