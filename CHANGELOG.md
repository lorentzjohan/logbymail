# Changelog

## [1.2.0] - 2026-10-29

### Added
- Brute-force protection of the login form, for sign-ins with a login or an email address: lockout after repeated failures per account and IP address, and per IP address. Locked attempts are refused before any password check, with HTTP status 429 and a `Retry-After` header. Configurable, enabled by default.
- Brute-force protection: cache keys are built with SHA-256 instead of SHA-1. SHA-1 was not used for security (only to build cache keys), but it is reported as a weak algorithm by code analysis tools. Existing counters are reset by the update.
- New log results: `locked` and `blocked`.

### Changed
- The plugin settings are only read for sign-ins and for the login page: other anonymous requests (REST API, inventory, CalDAV...) no longer run a database query.
- The client IP follows the GLPI 12 trusted reverse proxies (`GLPI_TRUSTED_REVERSE_PROXIES`).
- Installation: the plugin folder can stay owned by `root`, the web server only reads it.
- Line endings are normalized to LF in the repository (`.gitattributes`).
- Continuous integration on GLPI 11 and 12 (`.github/workflows/continuous-integration.yml`). The code passes PHP-CS-Fixer, PHPStan (max level), Psalm (with taint analysis), Rector, TwigCS and the licence headers check.
- Integration tests running inside GLPI, on its test database: e-mail resolution rules, sign-in with the resolved login through GLPI's authentication, login form handling, sign-in log, brute-force protection with the GLPI cache, hooks, settings update and uninstall.

## [1.1.0] - 2026-10-29

### Added
- Included users list: when set, only these users can sign in with their email address.
- Excluded users list: these users can never sign in with their email address. Exclusion takes priority over inclusion.
- Users are searched and selected as in a ticket. Both lists combine with the existing rules (allowed domains, default or synchronized addresses only).
- Warning on the configuration page for users found in both lists.
- Purged users are removed from both lists automatically.
- New log results: `user_excluded` and `user_not_included`.

### Security
- An unreadable users list disables sign-in with an email address (fail closed) instead of being read as empty.

## [1.0.1]

### Added
- French translation (fr_FR): the plugin follows the language of the GLPI user.

## [1.0.0] - 2026-10-28

First release.

### Added
- Sign-in with an email address as well as with the login, on the GLPI web login form.
- Addresses shared by several users and duplicated logins are refused.
- Two-factor authentication support (TOTP, mandatory enrollment, backup codes).
- Settings: default address only, LDAP-synchronized addresses only, allowed domains, login page label, log.
- Configuration page with the list of shared addresses.
- Log file `files/_log/logbymail.log`.
- GLPI 11.x and 12.x compatibility (tested on 11.0.12 and 12.0.1), PHP 8.2 minimum.
- English strings and a translation template.
