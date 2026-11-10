=== WP DBase ===
Contributors: worais
Tags: database, sql, mysql, developer, admin
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A database manager that speaks WordPress: Object View, a SQL console with safety modes, and schema and health insight inside wp-admin.

== Description ==

WP DBase is a database tool for developers and administrators who are tired of switching to phpMyAdmin or SSH to find out why a post doesn't show, where a value is stored, or which plugin left a table behind.

Instead of raw tables, it shows **objects**: a row together with its meta, terms and related rows, in one panel.

= Object View =

* A post with all its `postmeta`, terms, comments and child posts.
* A user with `usermeta`, roles (deserialized from `wp_capabilities`) and active sessions (from `session_tokens`).
* **Custom tables too**: any `<table>` + `<table>meta` pair is detected by convention — `wp_contatos` + `wp_contatosmeta` with `_id` / `key` / `value` columns works without configuration.
* Serialized PHP and JSON values are shown as trees (decoded safely: objects are never instantiated).
* IDs stored in meta (`_thumbnail_id`, `contato_id`, …) become links that open the referenced object, with a back trail.
* "View the SQL behind this panel" — every query the panel ran, ready to copy.

= Explorer =

* Tables grouped into WordPress core, plugins and custom tables, with row and size estimates read from `information_schema` (no `COUNT(*)` on open).
* Paginated, sortable grid; long values are truncated and complete in the Object View.
* Inline editing with a pending-changes bar and a review of the exact `UPDATE` before saving.
* Structure, `SHOW CREATE TABLE`, and an index list that flags duplicate and redundant indexes.

= SQL console =

* Runs scripts statement by statement and reports rows, affected rows and time for each.
* Pre-flight: before anything runs, every statement is classified (read, write, DDL, other) and checked against the safety mode.
* Read-only scripts run inside a `READ ONLY` transaction, with a per-statement timeout and a row cap.

= Safety modes =

* **Read-only** (default): nothing can change, not even from the console.
* **Assisted editing**: inline row edits with SQL review; free SQL writes and DDL stay blocked.
* **Expert**: free SQL and DDL, always after typing the database name to confirm.

The server enforces the mode — the interface only reflects it. Site owners can cap the most permissive mode with a constant.

= Audit trail =

Every write made through the plugin is recorded: who, when, from which IP, on which database, the SQL, the affected rows and any error.

= Privacy =

WP DBase makes no external requests, loads no remote assets and collects no data. It only reads and writes the database(s) configured in `wp-config.php`.

== Installation ==

1. Upload the `wp-dbase` folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate it.
3. Open **Tools → Database**. You start in read-only mode.

= Configuration (wp-config.php) =

All settings are optional constants:

`define( 'WPDBASE_MAX_SAFETY_MODE', 'assisted' );` — the most permissive mode anyone can switch to (`read`, `assisted` or `expert`; default `expert`).

`define( 'WPDBASE_DATABASES', array( 'staging' ) );` — extra databases on the same server that can be opened with the site's credentials. Use `'*'` for every database the MySQL user can see. Default: only the site's own database.

`define( 'WPDBASE_CAPABILITY', 'manage_options' );` — capability required to open the plugin.

`define( 'WPDBASE_QUERY_TIMEOUT', 20 );` — seconds before a console statement is aborted.

`define( 'WPDBASE_MAX_ROWS', 1000 );` — rows returned per console statement.

Filters for developers: `wpdbase_user_can_access`, `wpdbase_databases`, `wpdbase_capability`, `wpdbase_max_safety_mode`, `wpdbase_known_plugin_tables`. See the README on GitHub for examples.

== Frequently Asked Questions ==

= Is it safe to install on a production site? =

It starts in read-only mode, only administrators can open it, and writes require switching mode and confirming. Still, it is a tool that *can* change your database when you allow it: keep backups, and consider `define( 'WPDBASE_MAX_SAFETY_MODE', 'read' );` on sites where nobody should write.

= Can I connect to another server? =

No. WP DBase never accepts a host, user or password from the browser. It can open other databases on the same server with the credentials from `wp-config.php`, and only those you list in `WPDBASE_DATABASES`.

= Does it run COUNT(*) on my big tables? =

Not when listing tables or opening the overview: sizes and row counts are estimates from `information_schema`. A few health checks (expired transients, revisions, orphan post meta) run indexed counts and are aborted after 5 seconds on MySQL.

= Is there an undo? =

Not yet. Snapshots with one-click undo are on the roadmap. Every write is in the audit trail with its exact SQL.

= Does it work on multisite? =

It recognises multisite tables (`wp_2_posts`, network tables) and keeps the audit trail network-wide. A per-site switcher is on the roadmap.

== Screenshots ==

1. Object View: a post with its meta, terms and related rows in one panel.
2. Explorer with grouped tables and inline editing.
3. SQL console with the pre-flight check.
4. Database health overview.
5. Safety modes.

== Changelog ==

= 0.1.0 =
* First release: Overview, Explorer with inline editing, Object View with custom meta table detection, SQL console with safety modes and pre-flight, structure and index assessment, audit trail, command palette and dark theme.

== Upgrade Notice ==

= 0.1.0 =
First release.
