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

// Replaces the "Login" label of the GLPI login form with the translated "Login or email"
// label provided by the plugin in a meta tag.
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
