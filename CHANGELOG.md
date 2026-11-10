# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-10

### Added
- Database health overview from `information_schema` plus indexed WordPress checks (autoload size, expired transients, revisions, trash, orphan post meta).
- Explorer with tables grouped into core, plugin, custom and foreign; paginated and sortable grid.
- Object View: row, meta (with automatic `<table>` + `<table>meta` detection), terms, roles, sessions, related rows, decoded serialized/JSON trees, reference links and the SQL behind the panel.
- Inline row editing (Assisted mode) with SQL review and typed confirmation; row deletion.
- SQL console with statement splitting, classification, pre-flight, read-only transactions, timeout and row cap.
- Safety modes (read-only, assisted, expert) enforced by the server and capped by `WPDBASE_MAX_SAFETY_MODE`.
- Structure view with `SHOW CREATE TABLE` and duplicate/redundant index detection.
- Audit trail of every write.
- Extra databases on the same server through `WPDBASE_DATABASES`.
- Command palette (Ctrl/Cmd+K), dark theme, onboarding.

[Unreleased]: https://github.com/worais/wp-dbase/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/worais/wp-dbase/releases/tag/v0.1.0
