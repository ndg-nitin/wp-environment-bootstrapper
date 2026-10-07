# WordPress Environment Bootstrapper

Reproducible WordPress development environments from one JSON file and one WP-CLI command.

```bash
wp --require=setup.php setup
```

The command reads `config/setup.json`, validates it, checks the environment, then downloads WordPress,
creates `wp-config.php`, creates the database, installs WordPress, installs/removes plugins, installs and
activates a theme, verifies everything it can, and prints a summary. `--dry-run` prints the same plan
without touching anything.

- **Stack:** plain PHP (7.4+) + WP-CLI. No framework, no Node, no frontend build, no database abstraction layer.
- **Dependencies:** none at runtime beyond PHP and WP-CLI. Composer is optional (an autoloader fallback ships in `setup.php`).
- **License:** MIT.

---

## 1. Overview

This project turns a JSON description of a WordPress environment into a running, verified installation:

```
config/setup.json ──► validate ──► environment checks ──► WordPress core ──► database ──► plugins ──► theme ──► verify
secrets/env.json  ─┘                (PHP, WP-CLI, DB)      download/config    create/install  install     install   └─ summary
```

Every WordPress operation is executed by a real WP-CLI child process with an explicit `--path`, exactly as
if you had typed it yourself. The tool never writes its own SQL and never re-implements WordPress logic.

Design goals, in order:

1. **Reproducible** — the same JSON produces the same environment on any machine.
2. **Safe** — nothing runs without a validated config; `--dry-run` shows the plan first; secrets are never printed.
3. **Explainable** — every failure says what failed, why, and what to check next.
4. **Honest** — no TODOs, no fake steps, no "simulated" work: if a step is printed, it really happens.

## 2. Features

- One-command bootstrap: `wp --require=setup.php setup`.
- WordPress core download with a pinned version and `wp core verify-checksums` afterwards.
- `wp-config.php` creation, database creation with a read-only credential pre-flight, `wp core install`.
- Plugins from WordPress.org (optional pinned version), from a URL, or from a local ZIP.
- Plugin activation, removal (deactivate + uninstall), and `wp plugin verify-checksums`.
- Theme from a WordPress.org slug, a URL, or a local ZIP; activation; default-theme cleanup.
- ACF Pro through the **official** Easy Digital Downloads endpoint with **your own** license key.
- Legacy `setup.json` / `env.json` from the original project keep working (auto-mapped, with a warning).
- `--dry-run`, `--config=`, `--skip-plugins`, `--skip-theme`.
- Secrets redaction in all output, including error reports and command history.
- Cross-platform paths (PHP filesystem APIs only; Windows, macOS, Linux).
- Lightweight test suite and CI that run without a WordPress installation.

## 3. Requirements

| Requirement | Version | Notes |
|---|---|---|
| PHP CLI | >= 7.4 | Checked at run time; `mysqli` recommended for the database pre-flight |
| WP-CLI | 2.x | `wp` on `PATH`, or point at it with `WP_CLI=/path/to/wp` |
| MySQL / MariaDB | 5.7+ / 10.3+ | Reachable with the credentials in your config |
| ext-json | bundled | Required by `composer.json` |
| ext-curl *or* allow_url_fopen | any | Downloads fall back to PHP streams when curl is missing |
| ext-zip | any | Only needed for local ZIP sources and archive inspection |
| Internet access | any | WordPress.org downloads and (optionally) the ACF Pro endpoint |

Composer is **not** required to run the tool: `setup.php` falls back to a minimal PSR-4 loader when
`vendor/autoload.php` is absent. Composer is only needed to regenerate the autoloader or run
`composer test`.

## 4. Installation

```bash
git clone <your-fork-url> wp-env
cd wp-env

# optional: regenerate the autoloader
composer install

# create your configuration and secrets
cp config/setup.example.json config/setup.json
cp secrets/env.example.json   secrets/env.json
```

Edit both files (see sections 6 and 7), then run a dry run (section 10) before the real setup.

`config/setup.json` and `secrets/env.json` are gitignored on purpose - they carry your credentials.

## 5. Quick start

```bash
# 1. Inspect the plan. Nothing is changed.
bin/wp-env --dry-run

# 2. Create the environment.
bin/wp-env

# 3. Open the site.
#    Site:  http://localhost/wordpress
#    Admin: http://localhost/wordpress/wp-admin
```

`bin/wp-env` is a three-line wrapper around `wp --require=setup.php setup`; on Windows use
`bin\wp-env.cmd`, or call WP-CLI directly. Both accept the same options.

Prefer the explicit form? This is exactly equivalent:

```bash
wp --require=setup.php setup
```

## 6. Configuration reference

Template: `config/setup.example.json`. Every section and key is listed below; unknown keys produce a
warning (they are usually typos) and are ignored.

```jsonc
{
  "wordpress": {
    "version": "6.8.2",          // required: exact WordPress version to download
    "path": "./wordpress"        // required: install directory, relative to the project root ("." = project root)
  },

  "database": {
    "name": "my_wordpress",      // required: letters, numbers, underscores only
    "user": "root",              // required: no default is assumed
    "password": "",              // optional: defaults to "" (empty password) with a warning
    "host": "localhost",         // required: "localhost", "127.0.0.1:3307", or a socket path
    "prefix": "wp_"              // optional: default "wp_"
  },

  "site": {
    "url": "http://localhost/wordpress", // required: http(s) URL, no trailing slash needed
    "title": "My WordPress Site"         // required
  },

  "admin": {
    "username": "admin",         // required: no whitespace
    "password": "change-this-password", // required; short/placeholder values trigger warnings
    "email": "admin@example.com" // required: must be a valid address
  },

  "plugins": {
    "install": [                 // optional
      { "name": "elementor", "activate": true },
      { "name": "classic-editor", "version": "1.6.7", "activate": true },
      { "name": "my-private-plugin", "source": "./dist/plugin.zip", "activate": true }
    ],
    "remove": ["hello", "akismet"], // optional: slugs to deactivate and uninstall
    "strict": true               // optional (default true): stop on plugin failure (false = warn and continue)
  },

  "theme": {
    "source": "",                // "" | WordPress.org slug | https URL | local .zip path
    "activate": true,            // default true
    "remove_default_themes": true // default true: delete inactive default themes after activation
  }
}
```

Plugin entry keys: `name` (required slug), `version` (optional pin), `source` (optional URL or local ZIP -
when set, the plugin is fetched from there instead of WordPress.org), `activate` (default `true`).

Resolution order: `--config=<path>` → `config/setup.json` → `./setup.json` (legacy, warns).

## 7. Secrets and license keys

`secrets/env.json` holds values that must never be committed or printed:

```json
{
  "acf_pro_key": ""
}
```

- Resolution order: `secrets/env.json` → `./env.json` (legacy location, warns).
- The legacy `acf_key` name is mapped to `acf_pro_key` automatically.
- At run time the database password plus every secret string of 6+ characters are registered for
  redaction: they are stripped from command output, error hints and the command history.
- Both files are listed in `.gitignore`. Keep it that way - see section 18.

The example file ships with an empty key so a fresh clone never carries a real license.

## 8. The setup command

```bash
wp --require=setup.php setup [options]
```

The command is registered by `setup.php` with `when => before_wp_load`, so it runs *before* WordPress is
booted - it can therefore download and configure WordPress from scratch, including on a directory that
does not contain WordPress yet.

Steps performed by a full run (each one is a real WP-CLI command with an explicit `--path`):

1. Load configuration and secrets; print warnings (legacy schema, unknown keys, weak values).
2. Validate the schema - **all** problems are reported at once, not just the first.
3. Environment checks: PHP version, `wp --info`, database credential pre-flight (read-only).
4. `wp core download` (pinned version; existing files are never overwritten).
5. `wp config create` (skipped when `wp-config.php` already exists).
6. `wp db create` (idempotent - an existing database is fine).
7. `wp core install` (skipped when WordPress is already installed).
8. `wp core verify-checksums`.
9. Plugins: install → activate → remove unwanted → `wp plugin verify-checksums --all`.
10. Theme: install → activate → remove inactive default themes.
11. Final verification: WordPress installed, site URL matches, configured plugins active, theme ready.
12. Summary with site URL, admin URL, versions and counts.

## 9. Command options

Only these four options are implemented and documented:

| Option | Effect |
|---|---|
| `--dry-run` | Validate config and environment, print the complete plan, change **nothing**. |
| `--config=<path>` | Use a specific configuration file (relative to the current directory or absolute). |
| `--skip-plugins` | Skip plugin installation, activation, removal and plugin verification. |
| `--skip-theme` | Skip theme installation, activation and default-theme cleanup. |

```bash
wp --require=setup.php setup --dry-run
wp --require=setup.php setup --config=clients/acme/setup.json
wp --require=setup.php setup --skip-plugins --skip-theme
```

Options combine freely. Unknown options are rejected by WP-CLI's synopsis parsing before anything runs.

## 10. Dry runs and planning

```bash
bin/wp-env --dry-run
```

A dry run performs only read-only work and prints:

- the environment checks (PHP, WP-CLI, database credentials),
- the resolved configuration (version, database, site, path),
- the plugins to install, the plugins to remove, and the theme step,
- `DRY RUN - No changes were made.`

Safety is layered:

1. In dry-run mode the command never calls the execution path - it prints the plan instead.
2. The `CommandRunner` itself is constructed in dry-run mode, so any command that is *not* explicitly
   marked read-only is skipped and logged as `Would run: ...`.
3. Only three read-only probes are allowed through: `wp --info`, the database pre-flight (a plain
   `mysqli` connection), and nothing else.

If an environment check fails during a dry run, the plan is still printed and the command then exits
non-zero with the failed checks as hints.

## 11. Plugin catalog (`config/plugins.json`)

`config/plugins.json` is a **reference catalog only**. It is never read by `wp setup` and never installs
anything. It exists so you can pick valid slugs:

```json
{
  "_comment": "REFERENCE CATALOG ONLY. ...",
  "plugins": [
    { "name": "classic-editor", "description": "Restores the previous WordPress block editor (TinyMCE)" },
    { "name": "acf-pro", "description": "Advanced Custom Fields PRO - requires your own license key ..." }
  ]
}
```

To actually install a plugin, list it under `plugins.install` in `config/setup.json` (section 6). The
same applies to the legacy `pluginList` key: it is mapped nowhere and a warning tells you it is
catalog-only.

## 12. Themes

`theme.source` accepts three forms:

| Value | Behaviour |
|---|---|
| `""` | No theme installed; the active default stays, other defaults are cleaned up. |
| `twentytwentyfive` | Installed from WordPress.org. |
| `https://example.com/theme.zip` | Downloaded to a temp file, then installed. |
| `./themes/mysite.zip` | Installed from a local archive (path resolved from the project root). |

After installation the theme is activated (unless `"activate": false`), and every *inactive* default
theme is removed when `remove_default_themes` is `true`. The final verification confirms the expected
theme is present and active. The root folder slug of a ZIP is detected from the archive itself, so
"already installed" and activation work even when the file name differs from the folder name.

## 13. ACF Pro licensing

Advanced Custom Fields PRO is a commercial product. This tool supports it the only legitimate way:

1. Put **your own** license key in `secrets/env.json` as `acf_pro_key`.
2. `plugins.install` may then contain `{ "name": "acf-pro", "activate": true }`.
3. The key is sent only to the official endpoint:
   `https://connect.advancedcustomfields.com/index.php?p=pro&a=download&k=<key>`.
4. The key is registered as a secret, so it never appears in output, logs, error hints or history.

Guarantees:

- No key is bundled, no key is generated, no key is shared.
- No bypass, crack, nulled copy or redistributed premium ZIP is ever fetched - from any source.
- Without a key, validation fails early with an error that says exactly where to put the key.
- `config/plugins.json` documents `acf-pro` but, like every other entry, installs nothing by itself.

## 14. Legacy configuration support

The original project files keep working without edits:

| Legacy file | Status |
|---|---|
| `./setup.json` | Auto-detected schema (`version`, `dbname`, `dbuser`, `dbpass`, `dbhost`, `dbprefix`, `site_url`, `title`, `admin_name`, `admin_password`, `admin_email`, `path`, `axioned_theme`, `pluginListInstall`, `pluginListUninstall`, `pluginList`) and mapped to the current schema with a warning. |
| `./env.json` | Still read as the secrets file (warning), including the legacy `acf_key` name. |
| `pluginList` | Catalog only - never installed (warning explains this). |

Mapping examples: `dbname` → `database.name`, `dbpass` → `database.password`, `admin_name` →
`admin.username`, `axioned_theme` → `theme.source`, `pluginListInstall[].status` → `activate`.

If both `config/setup.json` and `./setup.json` exist, the new location wins and a warning tells you.
Migration is a copy-paste: start from `config/setup.example.json` and compare.

## 15. Execution model

- **Child processes.** Every WP-CLI subcommand runs in its own process (`proc_open` with an **argument
  array**, so no shell is involved - spaces, quotes and `$` in values need no escaping and cannot cause
  injection). Each invocation gets a clean bootstrap; a failing subcommand cannot corrupt the parent.
- **Explicit `--path`.** Every call carries the configured install path, so WP-CLI never depends on the
  current working directory.
- **No in-process WordPress loading.** The `setup` command runs before WordPress loads (section 8);
  commands that need WordPress run in the child processes instead.
- **Read-only probes vs. mutations.** Environment checks are marked read-only and may run during a dry
  run; everything else is skipped in dry-run mode.
- **Output capture.** stdout/stderr are captured, redacted, and only shown when a command fails (last
  8 lines) or when the step reports its own result.

## 16. Project structure

```
.
├── setup.php                  # WP-CLI entry point: autoload fallback + command registration
├── bin/
│   ├── wp-env                 # POSIX wrapper (exec wp --require=setup.php setup)
│   └── wp-env.cmd             # Windows wrapper
├── config/
│   ├── setup.example.json     # Configuration template (committed)
│   ├── setup.json             # Your configuration (gitignored)
│   └── plugins.json           # Reference catalog only - never installed
├── secrets/
│   ├── env.example.json       # Secrets template (committed, empty values)
│   └── env.json               # Your secrets (gitignored)
├── src/
│   ├── Commands/SetupCommand.php      # Option parsing and top-level error handling
│   ├── Config/ConfigLoader.php        # File resolution, JSON decoding, legacy mapping
│   ├── Config/ConfigValidator.php     # Schema validation (collects every problem)
│   ├── Core/Environment.php           # Workflow orchestration, dry run, verification, summary
│   ├── Core/Logger.php                # Leveled console output (ERROR -> STDERR)
│   ├── Core/SetupException.php        # Message + actionable hints
│   ├── Core/Validator.php             # PHP / WP-CLI / database checks
│   ├── Support/CommandRunner.php      # Child-process execution, dry-run guard, redaction
│   ├── Support/CommandResult.php      # Exit code + captured output
│   ├── Support/Downloader.php         # curl/streams downloads to temp files
│   ├── Support/Filesystem.php         # Cross-platform paths, ZIP root slug, temp cleanup
│   ├── Support/Progress.php           # Sections, checkmarks, counts, key/value lines
│   ├── WordPress/Database.php         # Preflight, host parsing, wp db create
│   ├── WordPress/Plugins.php          # Install/activate/remove/verify, ACF Pro
│   ├── WordPress/Themes.php           # Install/activate/cleanup/verify
│   └── WordPress/WordPressInstaller.php # Download, config, install, checksums, queries
├── tests/
│   ├── run.php                # Dependency-free runner (exit code 0/1)
│   └── cases/*Test.php
├── .github/workflows/php.yml  # Lint + validate + tests (+ dry-run against MySQL)
├── composer.json               # PSR-4 autoload only; no runtime dependencies
├── CONTRIBUTING.md  SECURITY.md  CHANGELOG.md  LICENSE  README.md
└── .editorconfig  .gitignore
```

## 17. Error handling and exit codes

| Exit code | Meaning |
|---|---|
| `0` | Setup completed (or `--dry-run` printed the plan and all checks passed). |
| `1` | Configuration invalid, environment check failed, a step failed, or an unexpected error occurred. |

Failures are split into two classes:

- **Critical** (`SetupException`) - setup stops immediately: invalid configuration, failed environment
  checks, database creation failure, core install failure, plugin failure in `strict` mode.
  The message is followed by `-> hints` that say what to check (the exact command, the relevant file,
  the value that failed - never a secret).
- **Recoverable** - logged as a warning and setup continues: checksum warnings for private plugins,
  a plugin that could not be deactivated before removal, a stored site URL that differs from the
  configuration (never silently rewritten), `plugins.strict: false` failures.

Everything unexpected is caught at the top level, printed with file and line, and exits `1` - there is no
silent-success path and no blanket "ignore errors" behaviour.

## 18. Security model

- **Secrets never committed.** `secrets/env.json`, `config/setup.json`, the legacy `env.json` /
  `setup.json`, `*.zip`, `*.wpress` and logs are gitignored; only empty templates are committed.
- **Secrets never printed.** The database password and every secret of 6+ characters are redacted from
  stdout, stderr, error hints and the displayed command history.
- **No shell interpolation.** Commands are argument arrays passed to `proc_open` (section 15).
- **Licensed software only.** ACF Pro comes exclusively from the official endpoint with your own key;
  no cracked, nulled or redistributed premium code is supported or possible.
- **Reference catalog installs nothing.** `config/plugins.json` is data, never code.
- **Least destructive behaviour.** Existing WordPress files are not overwritten, an existing database
  and an existing installation are reused, and only *inactive* default themes are deleted.
- **No privilege escalation.** Nothing runs as root, nothing changes system configuration, and no
  credentials other than those you supplied are used.

Report vulnerabilities per `SECURITY.md`.

## 19. Cross-platform notes

- All paths are built with PHP filesystem APIs (`Filesystem::join/resolve/normalize`) - no hardcoded
  `/`, no assumptions about the working directory; Windows drive letters and UNC paths are recognised.
- Commands are passed as argument arrays, so spaces in paths (e.g. `WP ENV - Bootstraper`) are safe.
- `bin/wp-env` is a POSIX script; `bin/wp-env.cmd` covers Windows, and `wp --require=setup.php setup`
  works everywhere unchanged.
- Colours are only emitted when the terminal supports them; `ERROR` output goes to STDERR.
- Temp files (downloads, archives) use the system temp directory and are cleaned up in `finally`.

## 20. Idempotency and re-runs

Running the command twice is safe:

| Situation | Behaviour |
|---|---|
| WordPress files already present | Kept; a version mismatch produces a warning instead of a rewrite. |
| `wp-config.php` exists | Kept (never overwritten). |
| Database already exists | `wp db create` reports it; setup continues. |
| WordPress already installed | `wp core install` is skipped. |
| Plugin already installed | Skipped, then activated if configured. |
| Theme already installed | Skipped, then activated if configured. |
| Plugin already removed | Logged as "nothing to remove". |

A re-run therefore converges on the configured state, which makes it usable for re-syncing a teammate's
environment or repairing a partially failed run.

## 21. Testing

```bash
php tests/run.php             # run everything
php tests/run.php Config      # only test files matching "Config"
composer test                 # same as php tests/run.php
```

The suite is dependency-free (a ~100-line runner, no PHPUnit) and covers:

- example configuration loads and passes validation,
- legacy `setup.json`/`env.json` mapping (including `acf_key` → `acf_pro_key`),
- missing/broken configuration errors are actionable,
- validation collects **every** error, and ACF Pro without a key is rejected with instructions,
- secret redaction (secrets never appear in output or history),
- dry-run really executes nothing, while read-only probes still run,
- a live `wp --info` child process round-trip (skipped automatically when WP-CLI is absent),
- path normalization/resolution and ZIP root-slug detection.

Every PHP file is also linted (`php -l`) in CI.

## 22. Continuous integration

`.github/workflows/php.yml` runs on pushes and pull requests:

1. **Lint and tests** - matrix over PHP 7.4, 8.0, 8.1, 8.2, 8.3: `composer validate --strict`,
   `php -l` on every file, `php tests/run.php` (WP-CLI installed so the child-process test runs).
2. **Dry-run smoke test** - boots a MySQL 8 service, writes `config/setup.json` from the example with
   service credentials, and runs `wp --require=setup.php setup --dry-run`. No WordPress installation is
   created; it proves the whole load → validate → check → plan path works end to end.

To run the same checks locally: `php -l` on changed files, `php tests/run.php`, then `bin/wp-env --dry-run`.

## 23. Extending the tool

The codebase is intentionally small and layered; typical changes:

- **New configuration key** - add it to `ConfigValidator` (known keys + validation) and consume it in
  `Environment` or the relevant `WordPress/*` class; document it in section 6 and in
  `config/setup.example.json`.
- **New setup step** - add a method to the owning class (`WordPressInstaller`, `Plugins`, `Themes`,
  `Database`) and call it from `Environment::run()` plus `Environment::dryRun()`'s plan output.
- **New command** - register it in `setup.php` with `WP_CLI::add_command()`; keep `when =>
  before_wp_load` if it must run before WordPress boots.
- **Extra checks** - `Core\Validator::check()` returns a list of `{label, status, detail, hints}`
  entries; append yours.

Rules of the road: no framework, no runtime Composer dependencies, PHP 7.4-compatible syntax, all user
facing errors must carry hints, and secrets must go through `CommandRunner::addSecret()`.

## 24. Troubleshooting

| Symptom | Likely cause and fix |
|---|---|
| `No configuration file found` | Run `cp config/setup.example.json config/setup.json`, or pass `--config=`. |
| `Configuration is invalid (N error(s))` | Every line under the message names a key and the expected form; fix them all at once. |
| `acf-pro requires your ACF Pro license key` | Put your key in `secrets/env.json` → `acf_pro_key` (section 13). |
| `Database ... connection failed` | Check `database.host/user/password` and that MySQL is running; the hints include the classified error (access denied, unknown host, ...). |
| `WP-CLI ... not available` | Install WP-CLI, or run through the same `wp` binary: `WP_CLI=/path/to/wp bin/wp-env`. |
| `Download failed` | No internet, wrong URL, or an authenticated URL this machine cannot reach; for ACF Pro verify the key is valid. |
| `doesn't verify` (checksums) | Local modifications or a private plugin; core checksum failures are fatal, plugin ones are warnings. |
| WordPress installs but plugins are not active | A folder name differs from `plugins.install[].name`; align the slug, or set `plugins.strict: false` to warn instead of failing. |
| Stored site URL differs from config | WordPress keeps its stored value; the warning prints the exact `wp option update siteurl ...` to run. |
| `Plugin X could not be deactivated before removal` | Warning only - deactivate it manually or fix filesystem permissions under `wp-content/plugins/`. |

Run `wp --require=setup.php help setup` for the generated option list, and `--dry-run` first whenever
something surprises you.

## 25. Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). In short: fork, branch, keep the PSR-4 layout, add tests for
behaviour changes, run `php tests/run.php` and `php -l` before pushing, and never commit secrets,
archives or a real `config/setup.json`. Security issues go to [SECURITY.md](SECURITY.md), not to the
public tracker.

## 26. License

MIT - see [LICENSE](LICENSE). WordPress and WP-CLI are licensed by their respective projects; plugin and
theme licenses belong to their authors. ACF Pro requires your own valid license (section 13).
