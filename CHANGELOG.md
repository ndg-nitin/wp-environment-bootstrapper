# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-07

First stable release of the WordPress Environment Bootstrapper.

### Added

- `wp --require=setup.php setup` command with `--dry-run`, `--config=<path>`, `--skip-plugins`
  and `--skip-theme`.
- JSON configuration in `config/setup.json` (template: `config/setup.example.json`) covering
  WordPress version/path, database, site, admin, plugins and theme.
- Secrets file `secrets/env.json` (template: `secrets/env.example.json`) holding `acf_pro_key`;
  legacy `./env.json` and the legacy `acf_key` name are still supported.
- Full schema validation that reports **every** problem at once, plus warnings for unknown keys,
  weak passwords and legacy fields.
- Environment checks: PHP version, `wp --info`, install-path writability, read-only database
  credential pre-flight with classified connection errors.
- WordPress core download (pinned version), `wp-config.php` creation, database creation,
  `wp core install` and `wp core verify-checksums`.
- Plugins: WordPress.org install with optional pinned version, URL and local ZIP sources,
  activation, removal (deactivate + uninstall), `wp plugin verify-checksums`, and a
  `plugins.strict` switch (default `true`).
- ACF Pro installation through the official Easy Digital Downloads endpoint using the user's own
  license key; the key is redacted from all output.
- Theme install from slug, URL or local ZIP, activation, inactive default-theme cleanup and
  final verification.
- `config/plugins.json` reference catalog (documented as catalog-only; never installed).
- Legacy `setup.json` schema auto-mapping (`dbname`, `dbuser`, `dbpass`, `dbhost`, `dbprefix`,
  `site_url`, `title`, `admin_name`, `admin_password`, `admin_email`, `path`, `axioned_theme`,
  `pluginListInstall`, `pluginListUninstall`, `pluginList`).
- Child-process execution model (`proc_open` with argument arrays, explicit `--path`, dry-run
  guard, secret redaction, command history).
- Final verification step (WordPress installed, site URL comparison, configured plugins active,
  theme ready) and a closing summary.
- `bin/wp-env` (POSIX) and `bin\wp-env.cmd` (Windows) wrappers.
- Dependency-free test suite (`php tests/run.php`) and GitHub Actions CI
  (`.github/workflows/php.yml`: PHP 7.4-8.5 lint + tests, ShellCheck on `bin/wp-env`, MySQL
  dry-run smoke test).
- Documentation: `README.md` (26 sections), `CONTRIBUTING.md`, `SECURITY.md`, `LICENSE` (MIT),
  `.editorconfig`, `.gitignore`, `.gitattributes` (LF in the repository, CRLF for `*.cmd`).

### Changed

- Relative `wordpress.path` values are resolved against the directory that **contains** the
  bootstrapper repository, so projects are created as sibling directories and never inside the tool
  (`/var/www/html/wp-environment-bootstrapper` + `project` -> `/var/www/html/project`). Absolute
  paths are used exactly as configured, and a path that resolves inside the repository is rejected
  during validation. `config/setup.example.json` now ships `"path": "project"` accordingly.
- The `No configuration file found` error now spells out the first-run steps: the copy command, the
  keys to edit, where a relative `wordpress.path` is created (next to the repository, with the real
  resolved path), and the `bin/wp-env` short form.

### Security

- Secrets (database password, `acf_pro_key`, any secret value of 6+ characters) are redacted from
  stdout, stderr, error hints and displayed command lines.
- No shell is used to start WP-CLI, so configuration values cannot inject shell syntax.
- `.gitignore` excludes `config/setup.json`, `secrets/env.json`, legacy `setup.json`/`env.json`,
  `*.zip`, `*.wpress` and logs.

[1.0.0]: https://github.com/ndg-nitin/wp-environment-bootstrapper/releases/tag/v1.0.0
