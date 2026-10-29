[![Continuous integration](https://github.com/lorentzjohan/logbymail/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/lorentzjohan/logbymail/actions/workflows/continuous-integration.yml)

# LogByMail — GLPI 11 / 12 plugin

Lets users sign in to GLPI with their **email address** as well as with their **login**.

- Version: 1.2.0
- Author: [lorentzjohan (LORENTZ Johan)](https://github.com/lorentzjohan)
- License: MIT
- Compatibility: GLPI 11.x and 12.x (tested on 11.0.12 and 12.0.1), PHP 8.2 to 8.5 (8.3 minimum with GLPI 12)
- Languages: English, French (follows the language of the GLPI user)

## How it works

The plugin only acts when the web login form is submitted (`POST /front/login.php`). If the submitted value is an email address that passes every rule below, the plugin replaces it with the GLPI login of its owner, right before GLPI starts its own authentication process.

Everything else is GLPI's native, unmodified process:

- password check against the right source (local database, LDAP, mail server);
- authorization rules, active or inactive account;
- **two-factor authentication (TOTP)**: prompt, mandatory enrollment, backup codes, rate limiting;
- "Remember me" and the events log, which records the real login.

### Rules applied to the submitted address

| # | Rule | When it is not met |
|---|------|--------------------|
| 1 | Valid address: 254 characters at most, no control characters, ASCII only | Handled as a regular login |
| 2 | Domain is in the allowed domains list (when the list is set) | Refused |
| 3 | No account uses this address as its login (mail server authentication, UPN…) | The native login wins |
| 4 | **The address belongs to a single user** who is not in the trash, whatever the kind of address (default, secondary, synchronized), case-insensitive | Refused: a shared address can never be used |
| 5 | The address is of an allowed kind: default and/or LDAP-synchronized, depending on the settings | Refused |
| 6 | The owner is not in the excluded users list, and is in the included users list when this list is set. **Exclusion takes priority**: a user in both lists is excluded | Refused |
| 7 | The owner's login is itself unique in GLPI (for example, no local + LDAP duplicate) | Refused |

When an address is refused, the user sees the usual GLPI error message. Nothing tells whether the address exists, is shared or is excluded, so account enumeration is not made easier. The reason is written to the plugin log.

## Installation

1. Copy the `logbymail` folder into the GLPI `plugins/` directory. The web server only needs to **read** it: keep it owned by `root` (directories `0755`, files `0644`), like the rest of the GLPI code. The plugin never writes in its folder: settings are stored in the database, the log in the GLPI log directory and the brute-force counters in the GLPI cache.
2. In **Setup > Plugins**, install then enable "LogByMail".

From the command line:

```bash
php bin/console plugin:install logbymail -u glpi
php bin/console plugin:activate logbymail
```

## Configuration

Open **Setup > Plugins > LogByMail > Configure** (URL: `/plugins/logbymail/config`). The page requires the update right on the general setup. On GLPI 12, it also requires a recent re-authentication, like the other setup pages; this mechanism does not exist on GLPI 11.

| Setting | Default | Effect |
|---------|---------|--------|
| Allow sign-in with an email address | Yes | Turns the feature on or off without uninstalling the plugin |
| Accept only the default address of each user | No | Secondary addresses cannot be used to sign in |
| Accept only addresses synchronized from the directory (LDAP) | No | An address added by hand to a user profile cannot be used to sign in |
| Allowed email domains | (empty = all) | One domain per line; exact match (`example.com` does not allow `sub.example.com`) |
| Only these users can sign in with their email | (empty = all users) | Search and select users, as in a ticket. Other users must sign in with their login |
| These users can never sign in with their email | (empty) | Search and select users. They can still sign in with their login. Takes priority over the list above |
| Show "Login or email" on the login page | Yes | Only changes the label of the login field |
| Log email sign-in attempts | Yes | Writes to `files/_log/logbymail.log` |
| Lock sign-in after repeated failures | Yes | Brute-force protection of the login form (see below) |
| Failed attempts allowed per account | 5 | Counted per account **and** per IP address |
| Failed attempts allowed per IP address | 30 | All accounts together; 0 = no limit |
| Counting period / Lockout duration | 15 / 15 min | Failures older than the period are forgotten |

All the rules add up: for example, with an allowed domains list and an included users list, an included user must also use an address from an allowed domain. The page warns about users found in both lists (they are excluded), and a user purged from GLPI is removed from both lists automatically. Both lists accept up to 1,000 users each.

The users lists only affect sign-in **with an email address**. A user whose GLPI login is itself an email address (mail server authentication, UPN) signs in with that login, which these lists do not affect.

The page also lists the **email addresses shared** by several users. These addresses are refused at sign-in: this is the list to clean up.

### Recommended settings with an LDAP directory

- Accept only addresses synchronized from the directory: **Yes**. Nobody can then use an address added by hand to a profile, or block a colleague's address by adding it to their own profile.
- Allowed email domains: your organization's domains only.

## Brute-force protection

GLPI does not limit password attempts on its login form. LogByMail adds this protection to every web sign-in, with a login **or** an email address (an email address counts on the account it belongs to, so alternating both changes nothing).

- **Per account and per IP address:** after 5 failures within 15 minutes, the account is locked for 15 minutes **from this IP address only**. Nobody can lock a user out by failing on purpose from another computer.
- **Per IP address:** after 30 failures within 15 minutes on any accounts, the IP address is locked for 15 minutes. This stops password spraying.
- A locked attempt is refused **before any password is checked**, with the GLPI error page, the message "Too many failed sign-in attempts. Try again in N minutes.", the HTTP status `429` and a `Retry-After` header.
- A successful sign-in (first factor, before two-factor authentication) resets the account counter for this IP.
- Counters are kept in the GLPI cache: clearing the cache (`php bin/console cache:clear`) unlocks everybody.

**Behind a reverse proxy**, every request comes from the proxy address unless GLPI knows the proxy: declare it in `GLPI_TRUSTED_REVERSE_PROXIES` (GLPI 12, in the `local_define.php` file of the GLPI configuration directory), otherwise the per-IP limit applies to all your users together. Set the per-IP limit to `0` if you cannot declare it.

## Log

One line per sign-in attempt made with an email address. The password is never known to the plugin and never written.

```
2026-10-28 20:43:29 [@glpi-server]
result=resolved identifier="jane.doe@example.com" login="jdoe" users_id=7 owners=1 ip=192.0.2.10
2026-10-28 20:43:30 [@glpi-server]
result=ambiguous_email identifier="helpdesk@example.com" login="" users_id=0 owners=2 ip=192.0.2.10
```

Possible `result` values: `resolved`, `unknown_email`, `ambiguous_email`, `not_eligible`, `user_excluded`, `user_not_included`, `ambiguous_login`, `domain_not_allowed`, `login_exists`. For `user_excluded` and `user_not_included`, the line also shows the login and id of the refused user.

The brute-force protection adds two results, for any sign-in (login or email):

```
result=locked scope=account login="jdoe" duration=900 ip=192.0.2.10
result=blocked login="jdoe" retry_after=842 ip=192.0.2.10
```

`locked`: a lockout starts (`scope` is `account`, `ip` or both). `blocked`: an attempt was refused during a lockout.

`resolved` only means that the address was translated into a login. Whether the sign-in then succeeded or failed is recorded in the GLPI events log (Administration > Logs).

The log contains email addresses: set up a rotation, for example with logrotate.

## Security

- The plugin only acts on a web `POST` to `/front/login.php`. It ignores the CLI, the REST API, the high-level API, CalDAV and cross-site requests (same `Sec-Fetch-Site` / `Origin` rules as the GLPI 12 CSRF protection). On GLPI 11, GLPI's token-based CSRF check also applies.
- SQL queries go through the GLPI query builder (bound values) and never modify the database.
- Any unexpected error is written to the GLPI PHP logs. Sign-in then falls back to the native behaviour, and the plugin is not disabled.
- The users lists fail closed: if a stored list cannot be read (for example after a manual database edit), sign-in with an email address is disabled until the configuration page is saved again. Sign-in with a login keeps working.
- The configuration page requires the `config` update right, plus a re-authentication on GLPI 12. CSRF protection is handled by GLPI: form token on GLPI 11, request headers on GLPI 12. Inputs are validated, and an invalid domain blocks the save.

Good to know:

- **fail2ban** can complement the built-in protection by banning an address at the firewall level. GLPI answers `400` to a failed sign-in and LogByMail `429` to a locked one:

  ```ini
  # /etc/fail2ban/filter.d/glpi-login.conf
  [Definition]
  failregex = ^<HOST> .* "POST /front/login\.php HTTP/[0-9.]+" (400|429)
  ```

- As with a login, the response time differs slightly depending on whether the account exists. This is GLPI's native behaviour.
- In GLPI 11.0.12 and 12.0.1, sending `login_name` as an array causes a 500 error in the native code (`Auth::validateLogin()`), whether the plugin is enabled or not. It has no security impact. The plugin ignores these requests.

## Uninstallation

**Setup > Plugins > Uninstall** deletes the plugin settings. Reinstalling without uninstalling first keeps the existing settings.

## Structure

```
setup.php                       Declaration, prerequisites, hooks
hook.php                        Install / uninstall
src/LoginRequestHandler.php     Login form interception
src/EmailLoginResolver.php      Email -> login resolution rules
src/PluginConfig.php            Settings (glpi_configs table)
src/AuditLog.php                Log file files/_log/logbymail.log
src/LoginPageCustomizer.php     "Login or email" label
src/GlpiCompat.php              GLPI 11 / 12 differences (CSRF, re-authentication, IP)
src/SharedEmails.php            Shared addresses report
src/UserLists.php               Included / excluded users lists (validation, cleanup on purge)
src/LoginThrottle.php           Brute-force protection (failure counters in the GLPI cache)
src/Controller/ConfigController.php  Configuration page
templates/config.html.twig      Configuration page view
public/js/login-label.js        Login page label script
locales/                        Translations (en_GB, fr_FR) and logbymail.pot template
tests/Units/                    Unit tests (PHPUnit, GLPI development environment)
```

## Support the project

[![Buy me a beer](https://img.buymeacoffee.com/button-api/?text=Buy%20me%20a%20beer&emoji=%F0%9F%8D%BA&slug=lorentzjohan&button_colour=5F7FFF&font_colour=ffffff&font_family=Bree&outline_colour=000000&coffee_colour=FFDD00)](https://www.buymeacoffee.com/lorentzjohan)
