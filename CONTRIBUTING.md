# Contributing

Thanks for helping improve the WordPress Environment Bootstrapper.

## Ground rules

- **No framework, no runtime dependencies.** Plain PHP 7.4+ and WP-CLI. Composer is used only for the
  PSR-4 autoloader and scripts.
- **No TODOs, stubs or simulated steps.** If a step is printed, it must really run. If you cannot finish
  a feature, leave it out of the output entirely.
- **Never commit secrets.** No license keys, passwords, real `config/setup.json`, real `secrets/env.json`,
  ZIPs, `.wpress` archives or dumps. Only the empty templates (`config/setup.example.json`,
  `secrets/env.example.json`) belong in the repository.
- **Licensed software only.** ACF Pro and other commercial products are supported exclusively through
  official endpoints with the user's own key. Cracked, nulled or redistributed premium code is rejected.
- **Errors must teach.** Every failure message states what failed, why, and what to check next
  (`SetupException` hints).

## Getting started

```bash
git clone <your-fork-url> wp-env
cd wp-env
composer install          # optional: regenerates vendor/autoload.php
php tests/run.php         # must be green before you start
```

## Making a change

1. Create a branch: `git checkout -b fix/short-description`.
2. Keep the existing layout and style:
   - PSR-4 namespaces (`WpEnvironment\` → `src/`), one class per file;
   - `declare(strict_types=1);` in every PHP file;
   - PHP 7.4-compatible syntax (typed properties are fine, constructor property promotion and
     enums are not);
   - docblocks on every public method, including `@throws` and array shapes where useful;
   - no hardcoded `/` separators and no hardcoded credentials - use `Support\Filesystem`.
3. Put WordPress operations in the owning class and route process execution through
   `Support\CommandRunner` (never `exec()`, `shell_exec()` or string interpolation).
4. Register anything user-visible in `setup.php` (synopsis) and document it in `README.md`
   (options are documented in section 9 only when they actually exist).
5. Add or extend tests under `tests/cases/`.

## Tests

```bash
php tests/run.php           # everything
php tests/run.php Config    # a single group
composer test               # same as php tests/run.php
php -l src/Commands/SetupCommand.php   # lint individual files (CI lints all)
```

Tests must pass without a WordPress installation, without a database, and without network access -
except for the WP-CLI round-trip test, which skips itself when `wp` is not on `PATH`.

## Pull requests

- Describe **what** changed and **why**; mention how you verified it
  (`php tests/run.php`, `bin/wp-env --dry-run`, a real run against a disposable path).
- Keep the diff focused; unrelated reformatting makes review harder.
- Update `README.md` and `CHANGELOG.md` when behaviour changes.
- Ensure CI is green (PHP 7.4 - 8.3 lint, tests, dry-run smoke test).

## Reporting bugs

Open an issue with:

- the exact command you ran,
- your configuration **with secrets removed** (`acf_pro_key`, passwords, user names),
- the full error output (it is already redacted, but double-check before pasting),
- `wp --info` and `php -v`.

Security issues: follow [SECURITY.md](SECURITY.md) instead of filing a public issue.
