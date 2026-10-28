/**
 * -------------------------------------------------------------------------
 * LogByMail plugin for GLPI
 * @copyright Copyright (C) 2026 LORENTZ Johan
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/lorentzjohan
 * -------------------------------------------------------------------------
 *
 * Replaces the "Login" label of the GLPI login form with the translated
 * "Login or email" label provided by the plugin in a meta tag.
 */
(function () {
    'use strict';

    function applyLabel() {
        var meta = document.querySelector('meta[name="logbymail-login-label"]');
        var input = document.getElementById('login_name');
        if (!meta || !input || !input.form) {
            return;
        }

        // Only the GLPI login form (action ".../front/login.php").
        var action = (input.form.getAttribute('action') || '').split('?')[0];
        if (!/\/front\/login\.php$/.test(action)) {
            return;
        }

        var text = meta.getAttribute('content');
        var label = document.querySelector('label[for="login_name"]');
        if (label && text) {
            label.textContent = text;
        }

        // Typing comfort for e-mail addresses, without changing what is sent.
        input.setAttribute('autocapitalize', 'none');
        input.setAttribute('spellcheck', 'false');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyLabel);
    } else {
        applyLabel();
    }
})();
