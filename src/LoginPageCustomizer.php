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

use Glpi\Kernel\Kernel;
use Glpi\Plugin\Hooks;
use Session;
use Throwable;

/**
 * Replaces the "Login" label of the login form with "Login or e-mail".
 *
 * Pure cosmetic: a meta tag carries the translated label and a small script applies it
 * on the GLPI login form only. Nothing is added on authenticated pages.
 */
final class LoginPageCustomizer
{
    public const META_NAME = 'logbymail-login-label';

    /**
     * Paths that display the GLPI login form.
     */
    private const LOGIN_PAGE_PATHS = ['/', '/index.php'];

    /**
     * Cheap pre-check done in `plugin_init`, without any database access: only the login page of
     * an unauthenticated session needs the label. Other anonymous requests (REST API, inventory,
     * CalDAV, translations...) do not read the plugin settings.
     */
    public static function isNeededForCurrentRequest(): bool
    {
        /** @var Kernel|null $kernel */
        global $kernel;

        if (isCommandLine() || Session::getLoginUserID() !== false || !($kernel instanceof Kernel)) {
            return false;
        }

        $path = rtrim($kernel->getMainRequest()->getPathInfo(), '/');

        return in_array($path === '' ? '/' : $path, self::LOGIN_PAGE_PATHS, true);
    }

    /**
     * POST_INIT hook: plugin translations are loaded at this point.
     */
    public static function registerHooks(): void
    {
        global $PLUGIN_HOOKS;

        try {
            $config = PluginConfig::load();
            if (!$config->isEnabled() || !$config->replaceLoginLabel()) {
                return;
            }

            $PLUGIN_HOOKS[Hooks::ADD_HEADER_TAG_ANONYMOUS_PAGE]['logbymail'] = [
                [
                    'tag'        => 'meta',
                    'properties' => [
                        'name'    => self::META_NAME,
                        'content' => __('Login or email', 'logbymail'),
                    ],
                ],
            ];
            $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT_ANONYMOUS_PAGE]['logbymail'] = ['js/login-label.js'];
        } catch (Throwable $e) {
            global $PHPLOGGER;
            $PHPLOGGER->error(
                sprintf('LogByMail: login page customization skipped: %s', $e->getMessage()),
                ['exception' => $e]
            );
        }
    }
}
