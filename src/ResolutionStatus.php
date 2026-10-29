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

/**
 * Outcome of the resolution of a login form identifier.
 *
 * Only RESOLVED leads to a change of the submitted login. In every other case the
 * identifier is left untouched and GLPI applies its native behaviour, which ends,
 * for an unknown login, with its usual generic error message.
 */
enum ResolutionStatus: string
{
    /** The identifier is not an e-mail address: nothing to do. */
    case NOT_AN_EMAIL = 'not_an_email';

    /** The domain of the address is not in the allowed list. */
    case DOMAIN_NOT_ALLOWED = 'domain_not_allowed';

    /** A GLPI login is literally equal to the address (e-mail server or UPN login): native login wins. */
    case LOGIN_EXISTS = 'login_exists';

    /** No user owns this address. */
    case UNKNOWN_EMAIL = 'unknown_email';

    /** Several users own this address: e-mail sign-in is refused for all of them. */
    case AMBIGUOUS_EMAIL = 'ambiguous_email';

    /** A single user owns the address, but not as an allowed kind (default / synchronized) of address. */
    case NOT_ELIGIBLE = 'not_eligible';

    /** The owner is in the excluded users list (exclusion takes priority over inclusion). */
    case USER_EXCLUDED = 'user_excluded';

    /** An included users list is set and the owner is not in it. */
    case USER_NOT_INCLUDED = 'user_not_included';

    /** The login of the owner is shared by several GLPI accounts (different authentication sources). */
    case AMBIGUOUS_LOGIN = 'ambiguous_login';

    /** The address belongs to exactly one user, whose login is unique. */
    case RESOLVED = 'resolved';

    /**
     * Whether this outcome is worth writing in the plugin log.
     */
    public function isAuditable(): bool
    {
        return $this !== self::NOT_AN_EMAIL;
    }
}
