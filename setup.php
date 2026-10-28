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

use Glpi\Plugin\Hooks;
use GlpiPlugin\Logbymail\LoginPageCustomizer;
use GlpiPlugin\Logbymail\LoginRequestHandler;

/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define('PLUGIN_LOGBYMAIL_VERSION', '1.0.0');

// Minimal GLPI version, inclusive: GLPI 11.x
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define('PLUGIN_LOGBYMAIL_MIN_GLPI_VERSION', '11.0.0');

// Maximum GLPI version, exclusive: every GLPI 12.x release is accepted
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define('PLUGIN_LOGBYMAIL_MAX_GLPI_VERSION', '13.0.0');

// Minimal PHP version, inclusive (same as GLPI 11; GLPI 12 itself requires PHP 8.3)
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define('PLUGIN_LOGBYMAIL_MIN_PHP_VERSION', '8.2.0');

/**
 * Init hooks of the plugin.
 * REQUIRED
 *
 * Called on every request, so it must stay cheap:
 * - the e-mail resolution only runs on a POST to /front/login.php;
 * - the login page customization is only registered for unauthenticated sessions.
 */
function plugin_init_logbymail(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['logbymail'] = 'config';

    // Translate an e-mail address typed in the login form into the matching GLPI login,
    // before GLPI's own authentication process (and 2FA) runs.
    LoginRequestHandler::handleCurrentRequest();

    // "Login or e-mail" label on the login page. Registered on POST_INIT, once the
    // plugin translations are loaded.
    if (LoginPageCustomizer::isNeededForCurrentRequest()) {
        $PLUGIN_HOOKS[Hooks::POST_INIT]['logbymail'] = [LoginPageCustomizer::class, 'registerHooks'];
    }
}

/**
 * Get the name and the version of the plugin
 * REQUIRED
 *
 * @return array{
 *      name: string,
 *      version: string,
 *      author: string,
 *      license: string,
 *      homepage: string,
 *      requirements: array{
 *          glpi: array{
 *              min: string,
 *              max: string,
 *          },
 *          php: array{
 *              min: string,
 *          }
 *      }
 * }
 */
function plugin_version_logbymail(): array
{
    return [
        'name'           => 'LogByMail',
        'version'        => PLUGIN_LOGBYMAIL_VERSION,
        'author'         => 'LORENTZ Johan',
        'license'        => 'MIT',
        'homepage'       => 'https://github.com/lorentzjohan',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_LOGBYMAIL_MIN_GLPI_VERSION,
                'max' => PLUGIN_LOGBYMAIL_MAX_GLPI_VERSION,
            ],
            'php'  => [
                'min' => PLUGIN_LOGBYMAIL_MIN_PHP_VERSION,
            ],
        ],
    ];
}

/**
 * Check pre-requisites before install
 * OPTIONAL
 */
function plugin_logbymail_check_prerequisites(): bool
{
    if (!extension_loaded('filter')) {
        echo 'The PHP "filter" extension is required by the LogByMail plugin.';
        return false;
    }

    return true;
}

/**
 * Check configuration process
 * OPTIONAL
 *
 * @param bool $verbose Whether to display message on failure. Defaults to false.
 */
function plugin_logbymail_check_config(bool $verbose = false): bool
{
    // Default settings are written on install and the plugin works out of the box.
    return true;
}
