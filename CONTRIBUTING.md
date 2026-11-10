# Contributing to WP DBase

Thanks for helping! This project doubles as a reference for how a WordPress plugin can be built with modern tooling, so the bar is intentionally high — and automated.

## Setup

```bash
git clone https://github.com/worais/wp-dbase.git
cd wp-dbase
composer install
composer qa
```

To try the plugin, symlink or mount the folder into `wp-content/plugins/` of any local WordPress (6.4+, PHP 8.1+).

## Quality gates

Every pull request must pass, locally and in CI:

| Command | What it checks |
|---|---|
| `composer lint` | PHP syntax |
| `composer cs` | WordPress Coding Standards + PHPCompatibilityWP (`composer cs:fix` fixes most) |
| `composer stan` | PHPStan level 8 with WordPress stubs |
| `composer test` | PHPUnit (Brain Monkey for WordPress functions) |
| `composer mutate` | Infection on the pure core — MSI ≥ 95% |
| Plugin Check | the official WordPress.org checks, run in CI on the built zip |

## Architecture rules

- Business rules go in the **pure core** (no WordPress functions, no `$wpdb`), with unit tests written to kill mutants. Read `infection.log` after `composer mutate`.
- WordPress lives in **adapters** (`src/Rest`, `src/Admin`, repositories). `Plugin::boot()` is the only place that registers hooks.
- SQL identifiers are checked against `information_schema` and quoted with `Identifier`; values always go through `Query::prepare()`.
- The browser never decides what is allowed: every rule is enforced on the server.
- Every user-facing string is translatable (`wp-dbase` text domain), in PHP and JS.

## Commits

[Conventional Commits](https://www.conventionalcommits.org/), checked by commitlint:

```
feat(console): run the statement under the cursor with Ctrl+Enter

Users kept selecting a statement by hand before running it. …
```

Types: `feat`, `fix`, `docs`, `test`, `refactor`, `perf`, `build`, `ci`, `chore`. Subject in the imperative, ≤ 72 characters, no trailing period. One logical change per commit.

## Pull requests

- Branch from `main` (`feat/...`, `fix/...`).
- Update `CHANGELOG.md` (Unreleased) and the README when public behaviour changes.
- Fill in the PR template, including how you tested.

## Reporting security issues

Please don't open a public issue — see [SECURITY.md](SECURITY.md).
