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

use Config;
use Glpi\Toolbox\IPUtilities;
use Session;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Small compatibility layer between GLPI 11.x and GLPI 12.x.
 *
 * Differences handled here:
 *  - CSRF: GLPI 11 validates a `_glpi_csrf_token` field on every POST, GLPI 12 relies on the
 *    `Sec-Fetch-Site` / `Origin` headers and deprecates the token API;
 *  - re-authentication of sensitive pages (Config itemtype): GLPI 12 only;
 *  - trusted reverse proxies (`GLPI_TRUSTED_REVERSE_PROXIES`, `Glpi\Toolbox\IPUtilities`): GLPI 12 only.
 */
final class GlpiCompat
{
    /**
     * Whether the running GLPI expects a CSRF token in submitted forms (GLPI < 12).
     */
    public static function usesCsrfTokens(): bool
    {
        return version_compare(GLPI_VERSION, '12.0.0-dev', '<');
    }

    /**
     * CSRF token to put in a form, or an empty string when GLPI does not use tokens anymore.
     */
    public static function getCsrfToken(): string
    {
        if (!self::usesCsrfTokens()) {
            return '';
        }

        // Deprecated in GLPI 12, only called on GLPI 11.
        // @phpstan-ignore staticMethod.deprecated
        return Scalar::toString(Session::getNewCSRFToken());
    }

    /**
     * Requires a recent re-authentication, like GLPI 12 does for its own setup pages.
     * GLPI 11 has no re-authentication mechanism: nothing to do.
     */
    public static function checkConfigReAuthentication(): void
    {
        // Always true on GLPI 12, always false on GLPI 11.
        // @phpstan-ignore function.alreadyNarrowedType, function.impossibleType
        if (method_exists(Config::class, 'checkReAuthenticationOrRedirect')) {
            Config::checkReAuthenticationOrRedirect();
        }
    }

    /**
     * Client IP address, for logging purposes only.
     */
    public static function getClientIp(Request $request): ?string
    {
        // GLPI 12 trusts the reverse proxies declared in GLPI_TRUSTED_REVERSE_PROXIES, but only
        // after the plugins are initialized. Apply the same configuration now, so that the client
        // IP is the same during the plugin init and at the end of the request.
        // The class only exists in GLPI 12.
        // @phpstan-ignore class.notFound
        $helper = IPUtilities::class;
        if (
            // Always true on GLPI 12, always false on GLPI 11.
            // @phpstan-ignore function.alreadyNarrowedType, function.impossibleType
            class_exists($helper) && method_exists($helper, 'configureTrustedProxies')
            && defined('GLPI_TRUSTED_REVERSE_PROXIES') && defined('GLPI_REVERSE_PROXY_HEADERS')
        ) {
            $helper::configureTrustedProxies(GLPI_TRUSTED_REVERSE_PROXIES, GLPI_REVERSE_PROXY_HEADERS);
        }

        try {
            return $request->getClientIp();
        } catch (Throwable) {
            // Conflicting forwarded headers
            return null;
        }
    }
}
