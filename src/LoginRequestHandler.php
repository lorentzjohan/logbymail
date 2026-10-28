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
use Plugin;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function Safe\parse_url;
use function Safe\preg_match;

/**
 * Hooks the GLPI web login form.
 *
 * GLPI 12 has no "pre-login" hook: `front/login.php` reads `$_POST['login_name']` and hands it
 * to `Auth::login()`. Plugins are initialized before this legacy script runs, so the submitted
 * identifier can be translated here, before any authentication step. Everything that follows
 * (password check against the right source, LDAP, account state, 2FA prompt / enforcement,
 * remember me, events log) is GLPI's untouched native process.
 */
final class LoginRequestHandler
{
    public const LOGIN_FIELD = 'login_name';

    /**
     * Same resolution as GLPI's legacy router: `/front/login.php` and `/front/login.php/...`.
     */
    private const LOGIN_PATH_PATTERN = '#^/front/login\.php(?:/.*)?$#D';

    public function __construct(
        private readonly PluginConfig $config,
        private readonly EmailLoginResolver $resolver,
    ) {}

    /**
     * Entry point, called from `plugin_init_logbymail()`.
     *
     * It must never throw: an exception raised during a plugin init makes GLPI deactivate the
     * plugin. On any unexpected error, the login form keeps its native behaviour.
     */
    public static function handleCurrentRequest(): void
    {
        /** @var Kernel|null $kernel */
        global $kernel, $DB;

        try {
            if (isCommandLine() || !($kernel instanceof Kernel)) {
                return;
            }

            $request = $kernel->getMainRequest();
            $raw_identifier = self::extractIdentifier($request);
            if ($raw_identifier === null) {
                return;
            }

            $config = PluginConfig::load();
            if (!$config->isEnabled() || !Plugin::isPluginActive('logbymail')) {
                return;
            }

            (new self($config, new EmailLoginResolver($DB, $config)))->process($request, $raw_identifier);
        } catch (Throwable $e) {
            global $PHPLOGGER;
            $PHPLOGGER->error(
                sprintf('LogByMail: e-mail resolution skipped after an unexpected error: %s', $e->getMessage()),
                ['exception' => $e]
            );
        }
    }

    /**
     * Returns the identifier submitted on the login form, or null when the request is not a
     * candidate (wrong method or path, cross-site request, no "@" in the identifier...).
     *
     * No database access is done here: this runs on every request.
     */
    public static function extractIdentifier(Request $request): ?string
    {
        if ($request->getRealMethod() !== Request::METHOD_POST) {
            return null;
        }

        if (preg_match(self::LOGIN_PATH_PATTERN, $request->getPathInfo()) !== 1) {
            return null;
        }

        if (!self::isSameOriginRequest($request)) {
            // GLPI rejects it later anyway (CheckCsrfListener), do not even look at the database.
            return null;
        }

        // The legacy login script reads the superglobal, this is the value that matters.
        $identifier = $_POST[self::LOGIN_FIELD] ?? null;
        if (!is_string($identifier) || !str_contains($identifier, '@')) {
            return null;
        }

        return $identifier;
    }

    /**
     * Same rules as GLPI 12 header-based CSRF protection (`Sec-Fetch-Site`, then `Origin`).
     * On GLPI 11, the token-based CSRF check of GLPI still applies afterwards.
     */
    public static function isSameOriginRequest(Request $request): bool
    {
        $sec_fetch_site = $request->headers->get('Sec-Fetch-Site');
        if ($sec_fetch_site !== null) {
            return in_array($sec_fetch_site, ['same-origin', 'none'], true);
        }

        $origin = $request->headers->get('Origin');
        $host   = $request->headers->get('Host');
        if ($origin !== null && $host !== null) {
            try {
                $origin_parts = parse_url($origin);
            } catch (Throwable) {
                return false;
            }
            if (!is_array($origin_parts) || !isset($origin_parts['host']) || $origin_parts['host'] === '') {
                return false;
            }
            $origin_host = $origin_parts['host'] . (isset($origin_parts['port']) ? ':' . $origin_parts['port'] : '');

            return strcasecmp($origin_host, $host) === 0;
        }

        // Not a browser request.
        return true;
    }

    public function process(Request $request, string $raw_identifier): void
    {
        $resolution = $this->resolver->resolve($raw_identifier);

        if ($resolution->isResolved()) {
            self::replaceSubmittedLogin($request, (string) $resolution->login);
        }

        if ($this->config->isLogEnabled() && $resolution->status->isAuditable()) {
            AuditLog::record($resolution, GlpiCompat::getClientIp($request));
        }
    }

    private static function replaceSubmittedLogin(Request $request, string $login): void
    {
        $_POST[self::LOGIN_FIELD] = $login;
        if (isset($_REQUEST[self::LOGIN_FIELD])) {
            $_REQUEST[self::LOGIN_FIELD] = $login;
        }
        // Keep the Symfony request consistent with the superglobals.
        $request->request->set(self::LOGIN_FIELD, $login);
    }
}
