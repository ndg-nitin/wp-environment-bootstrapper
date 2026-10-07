# WordPress Environment Bootstrapper

[![CI](https://github.com/ndg-nitin/wp-environment-bootstrapper/actions/workflows/php.yml/badge.svg)](https://github.com/ndg-nitin/wp-environment-bootstrapper/actions/workflows/php.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Create a reproducible WordPress development environment from one JSON config file and one command.

```bash
wp --require=setup.php setup
```

The command reads `config/setup.json`, validates it, checks the machine (PHP, WP-CLI, install
directory, database), downloads WordPress, creates `wp-config.php` and the database, installs
WordPress, installs/removes plugins, installs and activates a theme, verifies everything, and prints a
summary. `--dry-run` prints the same plan without touching anything.

- **Stack:** plain PHP (7.4+) + WP-CLI. No framework, no Node, no build step, no database abstraction.
- **Dependencies:** just PHP and WP-CLI (see section 3). Composer is optional.
- **License:** MIT.

---

## 1. What it does

```
config/setup.json ──► validate ──► environment checks ──► WordPress core ──► database ──► plugins ──► theme ──► verify
secrets/env.json  ─┘                (PHP, WP-CLI, DB)      download/config    create/install  install     install   └─ summary
```

Every step runs as a real WP-CLI child process with an explicit `--path` — exactly as if you typed it
yourself. The tool never writes its own SQL and never re-implements WordPress logic.

Design goals:

1. **Reproducible** — the same JSON produces the same environment on any machine.
2. **Safe** — nothing runs without a validated config; `--dry-run` shows the plan first; secrets are
   never printed.
3. **Explainable** — every failure says what failed, why, and what to check next.
4. **Honest** — no TODOs, no fake steps: if a step is printed, it really happens.

## 2. Features

- One-command bootstrap: `wp --require=setup.php setup` (or `bin/wp-env`).
- Pinned WordPress version with `wp core verify-checksums` after download.
- `wp-config.php` + database creation with a read-only credential pre-flight, then `wp core install`.
- Plugins from WordPress.org (optional pinned version), a URL, or a local ZIP; activation, removal,
  checksum verification.
- Theme from a slug, a URL, or a local ZIP; activation; default-theme cleanup.
- ACF Pro through the official endpoint with **your own** license key.
- Legacy `setup.json` / `env.json` keep working (auto-mapped, warning).
- `--dry-run`, `--config=`, `--skip-plugins`, `--skip-theme`.
- Secrets redaction in every output, error report and command history.
- Cross-platform paths (PHP filesystem APIs only; Linux, macOS, Windows).
- Dependency-free test suite and CI that run without a WordPress installation.

## 3. Dependencies

Three things must be installed: **PHP, WP-CLI, and MySQL (or MariaDB)**. Everything else is optional.

| Dependency | Version | When | Notes |
|---|---|---|---|
| PHP CLI | >= 7.4 | Always | `mysqli` recommended for the database pre-flight. |
| WP-CLI | 2.x | Always | On `PATH`, or point at one: `WP_CLI=/path/to/wp`. |
| MySQL / MariaDB | MySQL 5.7+ / MariaDB 10.3+ | Always | Your database server. |
| PHP ext-json | bundled | Always | Config decoding. |
| PHP ext-curl (or `allow_url_fopen`) | any | Recommended | Downloads fall back to PHP streams without curl. |
| PHP ext-zip | any | Only for local ZIPs | Local plugin/theme archives. |
| Composer | any | Optional | Only to regenerate the autoloader or run `composer test`. |
| Internet access | — | Always | WordPress.org downloads; ACF Pro endpoint if used. |

Verify before first run:

```bash
php -v                  # PHP >= 7.4
wp --info               # WP-CLI 2.x
mysql --version         # MySQL 5.7+ / MariaDB 10.3+
```

Quick install (Debian/Ubuntu):

```bash
sudo apt install php-cli php-mysql        # PHP + mysqli
sudo apt install mysql-server             # MySQL (or mariadb-server)
curl -O https://raw.githubusercontent.com/wp-cli/builds/phar/wp-cli.phar
chmod +x wp-cli.phar && sudo mv wp-cli.phar /usr/local/bin/wp
```

Full install guides: <https://www.php.net/downloads>, <https://wp-cli.org/#installing>,
<https://dev.mysql.com/downloads/>.

Composer is **not** required to run the tool — `setup.php` ships a minimal PSR-4 autoload fallback for
when `vendor/autoload.php` is absent.

## 4. Install and first run

**Step 1 — clone and enter.**

```bash
git clone https://github.com/ndg-nitin/wp-environment-bootstrapper.git wordpress-env-tool
cd wordpress-env-tool
```

**Step 2 — create your local config files** (both are gitignored — they hold your credentials).

```bash
cp config/setup.example.json config/setup.json
cp secrets/env.example.json   secrets/env.json
```

**Step 3 — edit `config/setup.json`.** Only a few keys matter for a first run:

| Key | Example | What you need to do |
|---|---|---|
| `wordpress.path` | `"project"` | Install folder. **Relative = next to this repository** (§6); absolute paths are used as-is. |
| `database.name` | `"my_wordpress"` | Database to create. |
| `database.user` / `database.password` | `"root"` / `""` | Your MySQL credentials. |
| `site.url` | `"http://localhost/project"` | Where you will open the site. |
| `admin.password` | `"change-this-password"` | Pick a real one (short/placeholder values warn). |

Leave `secrets/env.json` alone (`"acf_pro_key": ""`) unless you install ACF Pro (§13).

**Step 4 — dry run** (optional, always a good idea).

```bash
bin/wp-env --dry-run
```

Expected output:

```text
Environment checks
  ✓ PHP — 7.4.3
  ✓ WP-CLI — 2.8.1
  ✓ Install path — /var/www/html/project (parent writable)
  ✓ Database — 8.0.42 - database "project_db" will be created
...
DRY RUN
No changes were made.
```

Four green checks = you are ready. A red check prints the exact fix below it.

**Step 5 — create the environment.**

```bash
bin/wp-env
```

Steps printed: download WordPress → create `wp-config.php` → create database → install WordPress →
verify checksums → install/activate plugins → remove unwanted plugins → install/activate theme →
verification summary.

**Step 6 — open the site.**

```text
Site:   http://localhost/project
Admin:  http://localhost/project/wp-admin     (user: admin, password: your config value)
```

Where did WordPress go? With `"path": "project"` and a clone in `/var/www/html/wp-environment-bootstrapper`,
it was installed at `/var/www/html/project` — a **sibling** of the repository, never inside it (§6).

The exact same runs work without the wrapper: `wp --require=setup.php setup` (and
`wp --require=setup.php setup --dry-run`).

## 5. Daily usage

```bash
bin/wp-env                       # create or update the environment (safe to re-run, §20)
bin/wp-env --dry-run             # show the plan, change nothing (§10)
bin/wp-env --skip-plugins        # skip every plugin step
bin/wp-env --skip-theme          # skip the theme step
bin/wp-env --config=clients/acme/setup.json    # use another configuration
```

- Windows: use `bin\wp-env.cmd` (or WP-CLI directly).
- `bin/wp-env` is a shortcut; `wp --require=setup.php setup` is identical.

## 6. Configuration reference

Template: `config/setup.example.json`. Every key is listed below; unknown keys produce a warning
(they are usually typos) and are ignored.

```jsonc
{
  "wordpress": {
    "version": "6.8.2",          // required: exact WordPress version to download
    "path": "project"            // required: install directory; relative paths are created NEXT TO the
                                 // bootstrapper repository, absolute paths are used exactly as given
  },

  "database": {
    "name": "my_wordpress",      // required: letters, numbers, underscores only
    "user": "root",              // required: no default is assumed
    "password": "",              // optional: defaults to "" (empty password) with a warning
    "host": "localhost",         // required: "localhost", "127.0.0.1:3307", or a socket path
    "prefix": "wp_"              // optional: default "wp_"
  },

  "site": {
    "url": "http://localhost/project",    // required: http(s) URL, no trailing slash needed
    "title": "My WordPress Site"           // required
  },

  "admin": {
    "username": "admin",         // required: no whitespace
    "password": "change-this-password",    // required; short/placeholder values trigger warnings
    "email": "admin@example.com" // required: must be a valid address
  },

  "plugins": {
    "install": [                 // optional
      { "name": "elementor", "activate": true },
      { "name": "classic-editor", "version": "1.6.7", "activate": true },
      { "name": "my-private-plugin", "source": "./dist/plugin.zip", "activate": true }
    ],
    "remove": ["hello", "akismet"],   // optional: slugs to deactivate and uninstall
    "strict": true               // optional (default true): stop on plugin failure (false = warn and continue)
  },

  "theme": {
    "source": "",                // "" | WordPress.org slug | https URL | local .zip path
    "activate": true,            // default true
    "remove_default_themes": true // default true: delete inactive default themes after activation
  }
}
```

Plugin entry keys: `name` (required slug), `version` (optional pin), `source` (optional URL or local
ZIP — when set, the plugin is fetched from there instead of WordPress.org), `activate` (default `true`).

**Where `wordpress.path` points.** The repository is the *tool* directory, so a relative path is
resolved against the directory that **contains** it — projects are **siblings** of the tool, never
subfolders:

| `wordpress.path` | Repository at `/var/www/html/wp-environment-bootstrapper/` | Resolved install directory |
|---|---|---|
| `project` | | `/var/www/html/project` |
| `clients/project` | | `/var/www/html/clients/project` |
| `./wordpress` | | `/var/www/html/wordpress` |
| `.` | | `/var/www/html` (the sibling directory itself) |
| `/srv/wordpress` (absolute) | | `/srv/wordpress` (used exactly as configured) |

The base is always `dirname(<repository>)`, computed from the tool's own location — nothing is
hard-coded, so it works from any working directory and on Windows. A path that resolves **inside** the
repository is rejected during validation (§24).

Configuration resolution order: `--config=<path>` → `config/setup.json` → `./setup.json` (legacy, warns).

## 7. Secrets and license keys

`secrets/env.json` holds values that must never be committed or printed:

```json
{
  "acf_pro_key": ""
}
```

- Resolution order: `secrets/env.json` → `./env.json` (legacy location, warns). The legacy `acf_key`
  name is mapped to `acf_pro_key` automatically.
- The database password plus every secret string of 6+ characters is registered for redaction: it is
  stripped from command output, error hints and command history.
- Both files are gitignored — keep it that way (§18).
- The example ships an empty key, so a fresh clone never carries a real license.

## 8. The setup command

```bash
wp --require=setup.php setup [options]
```

The command registers with `when => before_wp_load`, so it runs *before* WordPress boots — it can
therefore configure WordPress from scratch on a directory that does not contain it yet.

A full run performs (each step is a real WP-CLI command with an explicit `--path`):

1. Load configuration + secrets; print warnings (legacy schema, unknown keys, weak values).
2. Validate the schema — **all** problems reported at once.
3. Environment checks: PHP version, `wp --info`, install-path writability, database credential
   pre-flight (all read-only).
4. `wp core download` (pinned version; existing files never overwritten).
5. `wp config create` (skipped when `wp-config.php` exists).
6. `wp db create` (idempotent — an existing database is fine).
7. `wp core install` (skipped when already installed).
8. `wp core verify-checksums`.
9. Plugins: install → activate → remove unwanted → verify checksums.
10. Theme: install → activate → remove inactive default themes.
11. Final verification: installed, site URL matches, plugins active, theme ready.
12. Summary: site URL, admin URL, versions, counts.

## 9. Command options

Only these four options are implemented:

| Option | Effect |
|---|---|
| `--dry-run` | Validate config and environment, print the plan, change **nothing**. |
| `--config=<path>` | Use a specific configuration file (relative to the current directory or absolute). |
| `--skip-plugins` | Skip plugin installation, activation, removal and plugin verification. |
| `--skip-theme` | Skip theme installation, activation and default-theme cleanup. |

Options combine freely. Unknown options are rejected by WP-CLI before anything runs.

Note: WP-CLI treats `--skip-plugins` as a global parameter and strips it from the arguments, so the
command reads it back from the WP-CLI runner configuration — where it keeps the documented meaning here.
`--skip-theme` is parsed from the command arguments as usual.

## 10. Dry runs and planning

```bash
bin/wp-env --dry-run
```

A dry run performs only read-only work and prints: the environment checks, the resolved configuration,
the plugin/theme plan, and `DRY RUN — No changes were made.`

Safety is layered:

1. Dry-run mode never calls the execution path.
2. The command runner itself is constructed in dry-run mode — anything not explicitly marked read-only
   is skipped and logged as `Would run: ...`.
3. Only two read-only probes run: `wp --info` and the database pre-flight (a plain `mysqli` connection).

If a check fails during a dry run, the plan is still printed and the exit code is non-zero.

## 11. Plugin catalog

`config/plugins.json` is a **reference catalog only** — never read by `wp setup`, never installs
anything. It exists so you can pick valid slugs:

```json
{
  "_comment": "REFERENCE CATALOG ONLY. ...",
  "plugins": [
    { "name": "classic-editor", "description": "Restores the previous WordPress block editor" },
    { "name": "acf-pro", "description": "Advanced Custom Fields PRO - requires your own license key" }
  ]
}
```

To actually install a plugin, list it under `plugins.install` in `config/setup.json` (§6). The legacy
`pluginList` key maps nowhere and prints a warning: catalog only.

## 12. Themes

`theme.source` accepts:

| Value | Behaviour |
|---|---|
| `""` | No theme installed; the active default stays, other defaults are cleaned up. |
| `twentytwentyfive` | Installed from WordPress.org. |
| `https://example.com/theme.zip` | Downloaded to a temp file, then installed. |
| `./themes/mysite.zip` | Installed from a local archive (path resolved from the project root). |

After installation the theme is activated (unless `"activate": false`) and inactive default themes are
removed when `remove_default_themes` is `true`. The ZIP's root folder slug is detected from the archive
itself, so activation works even when the file name differs from the folder name.

## 13. ACF Pro licensing

ACF Pro is a commercial product. This tool supports it only the legitimate way:

1. Put **your own** license key in `secrets/env.json` as `acf_pro_key`.
2. Add `{ "name": "acf-pro", "activate": true }` to `plugins.install`.
3. The key is sent only to the official endpoint:
   `https://connect.advancedcustomfields.com/index.php?p=pro&a=download&k=<key>`.
4. The key is registered as a secret; it never appears in output, logs, hints or history.

Guarantees: no bundled/generated/shared keys; no bypass, crack, nulled copy or redistributed premium
ZIP is ever fetched — from any source. Without a key, validation fails early and says exactly where to
put it. `config/plugins.json` documents `acf-pro` but, like every entry, installs nothing by itself.

## 14. Legacy configuration support

The original project files keep working without edits:

| Legacy file | Status |
|---|---|
| `./setup.json` | Auto-detected (`version`, `dbname`, `dbuser`, `dbpass`, `dbhost`, `dbprefix`, `site_url`, `title`, `admin_name`, `admin_password`, `admin_email`, `path`, `axioned_theme`, `pluginListInstall`, `pluginListUninstall`, `pluginList`) and mapped to the current schema with a warning. |
| `./env.json` | Still read as the secrets file (warning), including the legacy `acf_key` name. |
| `pluginList` | Catalog only — never installed (warning). |

Mapping examples: `dbname` → `database.name`, `dbpass` → `database.password`, `admin_name` →
`admin.username`, `axioned_theme` → `theme.source`, `pluginListInstall[].status` → `activate`. A legacy
`path` follows the same §6 rule: relative = sibling of the repository, absolute = as configured. If both
files exist, `config/setup.json` wins (warning). Migration is copy-paste: start from
`config/setup.example.json` and compare.

## 15. Execution model

- **Child processes.** Every WP-CLI call runs in its own process via `proc_open` with an argument array
  (no shell — spaces, quotes and `$` need no escaping and cannot inject). A failing subcommand cannot
  corrupt the parent.
- **Explicit `--path`.** Every call carries the install path; nothing depends on the working directory.
- **No in-process WordPress loading.** The command runs before WordPress boots; commands that need
  WordPress run in the child processes.
- **Output capture.** stdout/stderr are captured and redacted; shown only on failure (last 8 lines) or
  when a step reports its own result.

## 16. Project structure

```
.
├── setup.php                  # entry point: autoload fallback + command registration
├── bin/
│   ├── wp-env                 # POSIX wrapper (exec wp --require=setup.php setup)
│   └── wp-env.cmd             # Windows wrapper
├── config/
│   ├── setup.example.json     # configuration template (committed)
│   ├── setup.json             # your configuration (gitignored)
│   └── plugins.json           # reference catalog only - never installed
├── secrets/
│   ├── env.example.json       # secrets template (committed, empty)
│   └── env.json               # your secrets (gitignored)
├── src/
│   ├── Commands/SetupCommand.php            # options + top-level error handling
│   ├── Config/ConfigLoader.php              # file resolution, JSON, legacy mapping
│   ├── Config/ConfigValidator.php           # schema validation (all errors at once)
│   ├── Core/{Environment,Logger,SetupException,Validator}.php
│   ├── Support/{CommandRunner,CommandResult,Downloader,Filesystem,Progress}.php
│   └── WordPress/{Database,Plugins,Themes,WordPressInstaller}.php
├── tests/{run.php,cases/*}
├── .github/workflows/php.yml  # lint + validate + tests + MySQL dry-run smoke
├── composer.json              # PSR-4 autoload only; no runtime dependencies
├── CONTRIBUTING.md  SECURITY.md  CHANGELOG.md  LICENSE  README.md
└── .editorconfig  .gitignore
```

## 17. Error handling and exit codes

| Exit code | Meaning |
|---|---|
| `0` | Setup completed (or a dry run printed the plan and all checks passed). |
| `1` | Invalid config, failed environment check or step, or an unexpected error. |

Failures split into two classes:

- **Critical** (`SetupException`) — stops immediately: invalid configuration, failed environment checks,
  database/core install failure, plugin failure in `strict` mode. Message + `-> hints` say what to check
  (the exact command, the file, the failed value — never a secret).
- **Recoverable** — warnings, setup continues: checksum warnings for private plugins, a plugin that
  could not be deactivated before removal, a stored site URL that differs from config, `strict: false`.

Everything unexpected is caught at the top level, printed with file and line, and exits `1`. There is
no silent-success path.

## 18. Security model

- **Secrets never committed:** `secrets/env.json`, `config/setup.json`, legacy `env.json`/`setup.json`,
  `*.zip`, `*.wpress` and logs are gitignored; only empty templates are committed.
- **Secrets never printed:** the database password and every secret of 6+ characters are redacted.
- **No shell interpolation:** commands are argument arrays through `proc_open` (§15).
- **Licensed software only:** ACF Pro comes exclusively from the official endpoint with your own key.
- **Least destructive:** existing WordPress files, an existing database and an existing installation are
  reused; only *inactive* default themes are deleted.
- **No privilege escalation:** nothing runs as root (unless you invoke it that way) and no credentials
  beyond yours are used.

Report vulnerabilities per `SECURITY.md`.

## 19. Cross-platform notes

- Paths use PHP filesystem APIs (`Filesystem::resolve/normalize/parentOf`) and `isWritableAt` — no
  hard-coded `/`, no working-directory assumptions; Windows drives and UNC paths are recognised. The
  base for a relative `wordpress.path` is the parent of the repository (§6), computed from the tool's
  own location.
- Commands are argument arrays, so spaces in paths (e.g. `WP ENV - Bootstraper`) are safe.
- `bin/wp-env` (POSIX), `bin/wp-env.cmd` (Windows), or WP-CLI directly — all identical.
- Colours only when the terminal supports them; `ERROR` goes to STDERR. Temp files (downloads,
  archives) use the system temp directory and are cleaned up in `finally`.

## 20. Idempotency and re-runs

Running the command twice is safe — a re-run converges on the configured state:

| Situation | Behaviour |
|---|---|
| WordPress files already present | Kept; a version mismatch warns instead of rewriting. |
| `wp-config.php` exists | Kept, never overwritten. |
| Database already exists | Reported; setup continues. |
| WordPress already installed | `wp core install` is skipped. |
| Plugin/theme already installed | Skipped, then activated if configured. |
| Plugin already removed | Logged as "nothing to remove". |

## 21. Testing

```bash
php tests/run.php             # run everything
php tests/run.php Config      # only test files matching "Config"
composer test                 # same as php tests/run.php (needs Composer)
```

The suite is dependency-free (a small hand-written runner, no PHPUnit) and covers: config loading and
validation (incl. legacy mapping, all-error reporting, ACF key rules), secret redaction, dry-run safety,
install-path resolution (relative `project` → sibling of the repository, absolute as configured, in-repo
rejected), option handling including the `--skip-plugins` read-back, a live `wp --info` child-process
round-trip, path/ZIP helpers, and repository invariants (docs match the implemented options, no
committed secrets, catalog is data-only, CI really runs the suite). Every PHP file is also linted with
`php -l` in CI.

## 22. Continuous integration

`.github/workflows/php.yml` runs on pushes and pull requests:

1. **Lint + tests** — matrix over PHP 7.4 through 8.5: `composer validate --strict`, `php -l` on every
   file, `shellcheck bin/wp-env`, `php tests/run.php`.
2. **Dry-run smoke** — boots a MySQL 8 service, writes a config from the example with service
   credentials, and runs `wp --require=setup.php setup --dry-run`. No installation is created; it proves
   the whole load → validate → check → plan path works end to end.

To run the same checks locally: `php -l` on changed files, `shellcheck bin/wp-env` (if installed),
`php tests/run.php`, then `bin/wp-env --dry-run`.

## 23. Extending the tool

- **New config key** — add it to `ConfigValidator` (known keys + validation) and consume it in
  `Environment` or the relevant `WordPress/*` class; document it in §6 and the example config.
- **New setup step** — add a method to the owning class and call it from `Environment::run()` and
  `Environment::dryRun()`'s plan.
- **New command** — register it in `setup.php` with `WP_CLI::add_command()`.
- **New environment check** — append to `Core\Validator::check()` (label/status/detail/hints).

Rules of the road: no framework, no runtime Composer dependencies, PHP 7.4-compatible syntax, every
user-facing error carries hints, and secrets must go through `CommandRunner::addSecret()`.

## 24. Troubleshooting

| Symptom | Likely cause and fix |
|---|---|
| `No configuration file found` | Copy the template: `cp config/setup.example.json config/setup.json`, then edit it (§4). The hints spell out the keys — including where a relative `wordpress.path` will be created. |
| `wordpress.path ... inside the bootstrapper repository` | Use a sibling name such as `project`, or an absolute path outside the repository (§6). |
| `Configuration is invalid (N error(s))` | Each line names a key and the expected form; fix them all at once. |
| `acf-pro requires your ACF Pro license key` | Put your key in `secrets/env.json` → `acf_pro_key` (§13). |
| `Database ... connection failed` | Check `database.host/user/password` and that MySQL is running; the hints classify the error (access denied, unknown host, ...). |
| `WP-CLI ... not available` | Install WP-CLI (§3), or point at one: `WP_CLI=/path/to/wp bin/wp-env`. |
| `Download failed` | No internet, wrong URL, or an authenticated URL this machine cannot reach; for ACF Pro verify the key. |
| `doesn't verify` (checksums) | Local modifications or a private plugin; core failures are fatal, plugin ones are warnings. |
| Plugins install but are not active | Folder name differs from `plugins.install[].name`; align the slug or set `plugins.strict: false`. |
| Stored site URL differs from config | WordPress keeps its stored value; the warning prints the exact `wp option update siteurl ...` to run. |
| Site won't open in the browser | Check that your web server serves the resolved `wordpress.path` (§6) — create the matching vhost/alias. |

Run `wp --require=setup.php help setup` for the generated option list — and always `--dry-run` first
when something surprises you.

## 25. Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). In short: fork, branch, keep the PSR-4 layout, add tests for
behaviour changes, run `php tests/run.php` and `php -l` before pushing, and never commit secrets,
archives or a real `config/setup.json`. Security issues go to [SECURITY.md](SECURITY.md), not the
public tracker.

## 26. License

MIT — see [LICENSE](LICENSE). WordPress and WP-CLI are licensed by their respective projects; plugin
and theme licenses belong to their authors. ACF Pro requires your own valid license (§13).