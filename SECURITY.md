# Security Policy

WP DBase can read and, when allowed, write a site's database, so security reports get priority.

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | ✅ |

## Reporting a vulnerability

Please **do not** open a public issue. Use GitHub's [private vulnerability reporting](https://github.com/worais/wp-dbase/security/advisories/new) instead.

Include the affected version, the steps to reproduce, and the impact you see. You can expect an acknowledgement within 3 working days and a fix or mitigation plan within 14 days for confirmed issues. Credit is given in the release notes unless you prefer otherwise.

## Scope

In scope: authorization bypasses (REST routes, safety modes), SQL injection, XSS in the admin screen, statements that write while classified as reads, information disclosure across databases not listed in `WPDBASE_DATABASES`.

Out of scope: actions performed by an administrator who deliberately enabled Expert mode, and issues that require control of `wp-config.php`.
