## What and why

<!-- What does this change, and what problem does it solve? Link the issue. -->

## How it was tested

- [ ] `composer qa` passes
- [ ] `composer mutate` passes (new pure-core code has tests that kill its mutants)
- [ ] Tried in WordPress (version: , PHP: , MySQL/MariaDB: )

## Checklist

- [ ] Every rule is enforced on the server, not only in the UI
- [ ] SQL identifiers are allow-listed and quoted; values are prepared
- [ ] New strings are translatable
- [ ] `CHANGELOG.md` (Unreleased) and README updated if public behaviour changed
