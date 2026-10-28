# Changelog

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
