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

namespace GlpiPlugin\Logbymail\Controller;

use Config;
use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Logbymail\AuditLog;
use GlpiPlugin\Logbymail\GlpiCompat;
use GlpiPlugin\Logbymail\PluginConfig;
use GlpiPlugin\Logbymail\SharedEmails;
use GlpiPlugin\Logbymail\UserLists;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use UnexpectedValueException;
use User;

/**
 * Configuration page: /plugins/logbymail/config
 *
 * Same protections as the GLPI setup pages:
 *  - "Setup > General" update right (firewall + explicit check);
 *  - recent re-authentication, as required by GLPI 12 for the `Config` itemtype (GLPI 12 only);
 *  - CSRF checked by GLPI: form token on GLPI 11, Sec-Fetch-Site / Origin headers on GLPI 12.
 */
final class ConfigController extends AbstractController
{
    private const SHARED_EMAILS_LIMIT = 100;

    #[Route('/config', name: 'config', methods: ['GET'])]
    #[SecurityStrategy(Firewall::STRATEGY_ADMIN_ACCESS)]
    public function show(Request $request): Response
    {
        Session::checkRight(Config::$rightname, UPDATE);
        GlpiCompat::checkConfigReAuthentication();

        global $DB;

        $config = PluginConfig::load();

        // An unreadable stored list (manual database edit) is shown empty, flagged, and
        // replaced on the next save. Meanwhile, e-mail sign-in is disabled (fail closed).
        $users_lists_unreadable = false;
        try {
            $included_users = UserLists::filterExisting($DB, $config->getIncludedUserIds());
            $excluded_users = UserLists::filterExisting($DB, $config->getExcludedUserIds());
        } catch (UnexpectedValueException) {
            $included_users = [];
            $excluded_users = [];
            $users_lists_unreadable = true;
        }

        $users_in_both = [];
        foreach (array_intersect($included_users, $excluded_users) as $users_id) {
            $users_in_both[] = [
                'name' => getUserName($users_id),
                'url'  => User::getFormURLWithID($users_id),
            ];
        }

        return $this->render('@logbymail/config.html.twig', [
            'form_url'               => self::getUrl($request),
            'config'                 => $config->toArray(),
            'domains'                => implode("\n", $config->getAllowedDomains()),
            'shared_emails'          => (new SharedEmails($DB))->find(self::SHARED_EMAILS_LIMIT),
            'shared_limit'           => self::SHARED_EMAILS_LIMIT,
            'log_file'               => 'files/_log/' . AuditLog::LOG_NAME . '.log',
            'csrf_token'             => GlpiCompat::getCsrfToken(),
            'included_users'         => $included_users,
            'excluded_users'         => $excluded_users,
            'users_in_both'          => $users_in_both,
            'users_lists_unreadable' => $users_lists_unreadable,
        ]);
    }

    #[Route('/config', name: 'config_update', methods: ['POST'])]
    #[SecurityStrategy(Firewall::STRATEGY_ADMIN_ACCESS)]
    public function update(Request $request): Response
    {
        Session::checkRight(Config::$rightname, UPDATE);
        GlpiCompat::checkConfigReAuthentication();

        global $DB;

        $input = $request->request;

        $invalid = [];
        $domains = PluginConfig::parseDomains(mb_substr($input->getString(PluginConfig::ALLOWED_DOMAINS), 0, 5000), $invalid);

        if ($invalid !== []) {
            Session::addMessageAfterRedirect(
                htmlescape(sprintf(
                    _n('Invalid domain: %s', 'Invalid domains: %s', count($invalid), 'logbymail'),
                    implode(', ', array_slice($invalid, 0, 10))
                )) . ' ' . __s('Nothing was saved.', 'logbymail'),
                false,
                ERROR
            );
            return new RedirectResponse(self::getUrl($request));
        }

        if (count($domains) > PluginConfig::MAX_DOMAINS) {
            Session::addMessageAfterRedirect(
                htmlescape(sprintf(
                    __('Too many domains: %d at most.', 'logbymail'),
                    PluginConfig::MAX_DOMAINS
                )) . ' ' . __s('Nothing was saved.', 'logbymail'),
                false,
                ERROR
            );
            return new RedirectResponse(self::getUrl($request));
        }

        // An emptied multiple dropdown is submitted as an empty string, a filled one as an
        // array: read the raw value instead of InputBag::all($key), which rejects strings.
        $submitted   = $input->all();
        $users_lists = [];
        foreach ([PluginConfig::INCLUDED_USERS, PluginConfig::EXCLUDED_USERS] as $setting) {
            $ids = PluginConfig::normalizeUserIds($submitted[$setting] ?? []);
            if (count($ids) > PluginConfig::MAX_LISTED_USERS) {
                Session::addMessageAfterRedirect(
                    htmlescape(sprintf(
                        __('Too many users in a list: %d at most.', 'logbymail'),
                        PluginConfig::MAX_LISTED_USERS
                    )) . ' ' . __s('Nothing was saved.', 'logbymail'),
                    false,
                    ERROR
                );
                return new RedirectResponse(self::getUrl($request));
            }

            $users_lists[$setting] = PluginConfig::encodeUserIds(UserLists::filterExisting($DB, $ids));
        }

        PluginConfig::save([
            PluginConfig::ENABLED             => $input->getBoolean(PluginConfig::ENABLED) ? '1' : '0',
            PluginConfig::ONLY_DEFAULT_EMAIL  => $input->getBoolean(PluginConfig::ONLY_DEFAULT_EMAIL) ? '1' : '0',
            PluginConfig::ONLY_DYNAMIC_EMAIL  => $input->getBoolean(PluginConfig::ONLY_DYNAMIC_EMAIL) ? '1' : '0',
            PluginConfig::ALLOWED_DOMAINS     => implode("\n", $domains),
            PluginConfig::REPLACE_LOGIN_LABEL => $input->getBoolean(PluginConfig::REPLACE_LOGIN_LABEL) ? '1' : '0',
            PluginConfig::LOG_ENABLED         => $input->getBoolean(PluginConfig::LOG_ENABLED) ? '1' : '0',
        ] + $users_lists);

        Session::addMessageAfterRedirect(__s('Settings saved', 'logbymail'));

        return new RedirectResponse(self::getUrl($request));
    }

    private static function getUrl(Request $request): string
    {
        return $request->getBasePath() . '/plugins/logbymail/config';
    }
}
