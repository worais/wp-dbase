# WP DBase

**A database manager that speaks WordPress.** Object View, a SQL console with safety modes, and schema and health insight — inside wp-admin.

[![CI](https://github.com/worais/wp-dbase/actions/workflows/ci.yml/badge.svg)](https://github.com/worais/wp-dbase/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-8.1%E2%80%938.4-777bb4)](composer.json)
[![PHPStan level 8](https://img.shields.io/badge/PHPStan-level%208-2a5ea7)](phpstan.neon.dist)
[![Mutation score](https://img.shields.io/badge/MSI%20(core)-%E2%89%A595%25-success)](infection.json5)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

phpMyAdmin shows you tables. WP DBase shows you **objects**: a post with its meta, terms and related rows in one panel; a user with roles and sessions decoded from serialized meta; a custom `wp_contatos` row with everything in `wp_contatosmeta` — without any configuration.

| | |
|---|---|
| **Object View** | Row + meta + terms + related rows. Serialized/JSON values as trees. IDs become links with a back trail. "View the SQL behind this panel". |
| **Explorer** | Tables grouped into core / plugins / custom, estimates from `information_schema` (never `COUNT(*)` on open), sortable grid, inline edits with SQL review. |
| **SQL console** | Statement-by-statement execution, pre-flight classification, read-only transactions, timeout and row cap. |
| **Safety modes** | Read-only (default) → Assisted editing → Expert. Enforced by the server; capped per site with a constant. |
| **Structure** | Columns, `SHOW CREATE TABLE`, duplicate and redundant index detection. |
| **Audit trail** | Every write: who, when, IP, database, SQL, affected rows, error. |

## Requirements

- WordPress 6.4+ (tested up to 7.1)
- PHP 8.1+
- MySQL 5.7+ or MariaDB 10.3+

## Installation

Install from the Plugins screen (once published), or download the zip from the [latest release](https://github.com/worais/wp-dbase/releases) and upload it. Then open **Tools → Database**.

## Configuration

Everything is optional. Infrastructure decisions are `wp-config.php` constants — they belong to whoever runs the server, not to the dashboard.

```php
// wp-config.php

// Most permissive mode anyone can switch to: 'read', 'assisted' or 'expert' (default).
define( 'WPDBASE_MAX_SAFETY_MODE', 'assisted' );

// Extra databases on the same server, opened with the wp-config.php credentials.
// A list of names, or '*' for every database the MySQL user can see. Default: none.
define( 'WPDBASE_DATABASES', array( 'staging', 'legacy_crm' ) );

define( 'WPDBASE_CAPABILITY', 'manage_options' ); // Who can open it.
define( 'WPDBASE_QUERY_TIMEOUT', 20 );            // Seconds per console statement.
define( 'WPDBASE_MAX_ROWS', 1000 );               // Rows returned per console statement.
```

### Filters

```php
// Only user #1 may use the plugin (runs after the capability check).
add_filter( 'wpdbase_user_can_access', fn( bool $allowed, int $user_id ) => $allowed && 1 === $user_id, 10, 2 );

// Decide the database list in code (same values as WPDBASE_DATABASES).
add_filter( 'wpdbase_databases', fn( $databases ) => is_main_site() ? '*' : array() );

// Recognise your own plugin's tables in the sidebar.
add_filter( 'wpdbase_known_plugin_tables', fn( array $plugins ) => $plugins + array( 'acme_' => 'Acme CRM' ) );
```

Also available: `wpdbase_capability`, `wpdbase_max_safety_mode`.

### Loading as a must-use plugin

Must-use plugins in sub-folders are not loaded automatically. A one-line loader is enough — activation hooks never run there, and the plugin does not need them (its audit table is created on first use):

```php
// wp-content/mu-plugins/wp-dbase-loader.php
require WPMU_PLUGIN_DIR . '/wp-dbase/wp-dbase.php';
```

## Security model

- Only users with `WPDBASE_CAPABILITY` (default `manage_options`) who pass `wpdbase_user_can_access` can open the screen or call the REST API (`wp-dbase/v1`, cookie + nonce).
- **Safety modes are enforced server-side.** Each console statement is classified (read / write / DDL / other) *before anything runs*; one forbidden statement rejects the whole script.
- Read-only scripts run inside `START TRANSACTION READ ONLY`, so even a misclassified statement cannot write. Executable `/*! … */` comments, `SELECT … INTO OUTFILE`, locking reads and `EXPLAIN ANALYZE` of writes are never treated as reads.
- Writes require the database name (or table name, for row edits) to be typed; the server checks it.
- Identifiers are validated against `information_schema` and quoted; every value goes through `$wpdb->prepare()`.
- Serialized values are decoded with `allowed_classes => false` — no object is ever instantiated.
- No external requests, no remote assets, no telemetry.

Found a vulnerability? See [SECURITY.md](SECURITY.md).

## Development

```bash
composer install
composer qa       # lint + PHPCS (WPCS) + PHPStan + PHPUnit
composer mutate   # Infection on the pure core (MSI ≥ 95%)
bin/build.sh      # build/wp-dbase.zip, exactly what ships
```

The code is split into a **pure core** (SQL lexer and classifier, safety modes, meta-table detection, value typing, index advice — no WordPress calls, unit and mutation tested) and **adapters** (REST controllers, `$wpdb` repositories, admin page). `Plugin::boot()` is the only place that wires hooks. The front end is plain ES2020 with no build step, using `wp.apiFetch` and `wp.i18n`.

CI runs PHPCS, PHPStan, PHPUnit on PHP 8.1–8.4, Infection, the official [Plugin Check](https://wordpress.org/plugins/plugin-check/) and commitlint. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Roadmap

- Snapshots before every write, with one-click undo from the audit trail
- Dry-run routines (search & replace with serialized data, orphan cleanup, autoload tuning, user password reset)
- Global search across tables
- Editable serialized trees and a diff before saving meta
- Structure diff between tables/databases with generated `ALTER`
- Multisite site switcher
- Integration test suite against MySQL 8 and MariaDB in CI

## License

[GPL-2.0-or-later](LICENSE).
