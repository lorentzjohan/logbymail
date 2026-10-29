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

use Glpi\Application\View\TemplateRenderer;
use Glpi\Kernel\Kernel;
use Plugin;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
            $submitted_login = self::extractLogin($request);
            if ($submitted_login === null) {
                return;
            }

            $config = PluginConfig::load();
            if (!Plugin::isPluginActive('logbymail')) {
                return;
            }

            $handler = new self($config, new EmailLoginResolver($DB, $config));
            try {
                $account = $handler->process($request, $submitted_login);
            } catch (Throwable $e) {
                // E-mail sign-in unavailable (for example unreadable users lists): the brute-force
                // protection must still apply, on the submitted login.
                global $PHPLOGGER;
                $PHPLOGGER->error(
                    sprintf('LogByMail: e-mail resolution skipped after an unexpected error: %s', $e->getMessage()),
                    ['exception' => $e]
                );
                $account = trim($submitted_login);
            }
            $handler->throttle($request, $account);
        } catch (Throwable $e) {
            global $PHPLOGGER;
            $PHPLOGGER->error(
                sprintf('LogByMail: sign-in handling skipped after an unexpected error: %s', $e->getMessage()),
                ['exception' => $e]
            );
        }
    }

    /**
     * Returns the login submitted on the login form (login or e-mail address), or null when the
     * request is not a sign-in attempt (wrong method or path, cross-site request, empty login).
     *
     * No database access is done here: this runs on every request.
     */
    public static function extractLogin(Request $request): ?string
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
        $login = $_POST[self::LOGIN_FIELD] ?? null;
        if (!is_string($login) || trim($login) === '') {
            return null;
        }

        return $login;
    }

    /**
     * Returns the identifier submitted on the login form when it may be an e-mail address
     * (contains "@"), or null.
     */
    public static function extractIdentifier(Request $request): ?string
    {
        $login = self::extractLogin($request);

        return $login !== null && str_contains($login, '@') ? $login : null;
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

    /**
     * Translates an e-mail address into the matching login when possible.
     *
     * @return string Login of the account the attempt targets (used by the brute-force protection).
     */
    public function process(Request $request, string $submitted_login): string
    {
        $login = trim($submitted_login);
        if (!$this->config->isEnabled() || !str_contains($login, '@')) {
            return $login;
        }

        $resolution = $this->resolver->resolve($login);

        if ($resolution->isResolved()) {
            $login = (string) $resolution->login;
            self::replaceSubmittedLogin($request, $login);
        }

        if ($this->config->isLogEnabled() && $resolution->status->isAuditable()) {
            AuditLog::record($resolution, GlpiCompat::getClientIp($request));
        }

        return $login;
    }

    /**
     * Refuses the attempt while the account (or the IP) is locked, otherwise records its result
     * once GLPI has handled it.
     */
    private function throttle(Request $request, string $account): void
    {
        $throttle = LoginThrottle::fromConfig($this->config);
        if ($throttle === null) {
            return;
        }

        $ip        = GlpiCompat::getClientIp($request);
        $log       = $this->config->isLogEnabled();
        $remaining = $throttle->getRemainingLockTime($account, $ip);
        if ($remaining > 0) {
            if ($log) {
                AuditLog::recordBlocked($account, $ip, $remaining);
            }
            self::denyLockedAttempt($request, $remaining);
        }

        // GLPI checks the password after the plugins are initialized: the result is only known
        // at the end of the request.
        register_shutdown_function(static function () use ($throttle, $account, $ip, $log): void {
            self::recordOutcome($throttle, $account, $ip, $log);
        });
    }

    /**
     * Outcome of a sign-in attempt handled by GLPI:
     *  - first factor accepted (session opened, or two-factor prompt pending): success;
     *  - HTTP 400 without session: GLPI refused the credentials, failure;
     *  - anything else (CSRF refusal, maintenance...): not an authentication attempt, ignored.
     */
    public static function recordOutcome(LoginThrottle $throttle, string $account, ?string $ip, bool $log): void
    {
        try {
            if (isset($_SESSION['glpiID']) || isset($_SESSION['mfa_pre_auth'])) {
                $throttle->recordSuccess($account, $ip);
                return;
            }
            if (http_response_code() !== Response::HTTP_BAD_REQUEST) {
                return;
            }
            $locked = $throttle->recordFailure($account, $ip);
            if ($locked !== [] && $log) {
                AuditLog::recordLockout($account, $ip, $locked, $throttle->getLockoutSeconds());
            }
        } catch (Throwable $e) {
            global $PHPLOGGER;
            $PHPLOGGER->error(sprintf('LogByMail: sign-in attempt not counted: %s', $e->getMessage()), ['exception' => $e]);
        }
    }

    /**
     * Answers "429 Too Many Requests" with the GLPI login error page, before GLPI checks any
     * password, and stops the request.
     */
    private static function denyLockedAttempt(Request $request, int $seconds): never
    {
        Plugin::loadLang('logbymail');
        $minutes = max(1, (int) ceil($seconds / 60));
        $message = sprintf(
            _n(
                'Too many failed sign-in attempts. Try again in %d minute.',
                'Too many failed sign-in attempts. Try again in %d minutes.',
                $minutes,
                'logbymail'
            ),
            $minutes
        );

        try {
            $content = TemplateRenderer::getInstance()->render('pages/login_error.html.twig', [
                'errors'    => [$message],
                'login_url' => $request->getBasePath() . '/front/logout.php?noAUTO=1',
            ]);
        } catch (Throwable) {
            $content = htmlescape($message);
        }

        (new Response($content, Response::HTTP_TOO_MANY_REQUESTS, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Retry-After'  => (string) $seconds,
        ]))->send();

        exit();
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
