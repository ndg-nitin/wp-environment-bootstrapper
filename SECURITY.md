# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 1.x (current main branch) | :white_check_mark: |
| Legacy `setup.php` / `setup.json` workflow | :white_check_mark: (security fixes only) |

## Reporting a vulnerability

Please **do not** open a public issue for security vulnerabilities.

1. Report privately to the maintainers of this repository (use the repository's
   *Security → Report a vulnerability* advisory, or contact the maintainer directly by e-mail).
2. Include: a description of the issue, steps to reproduce, the affected file/line, and the impact.
3. Allow a reasonable time for a fix before public disclosure.

You should receive an acknowledgement within a few working days, and an assessment with a planned fix
date shortly after. Credit is given in the advisory unless you prefer to remain anonymous.

## Threat model and guarantees

This tool runs locally, as your user, with credentials **you** provide. Its security properties:

| Area | Guarantee |
|---|---|
| Secret storage | `secrets/env.json` and `config/setup.json` are gitignored; only empty templates are committed. Legacy `./env.json` and `./setup.json` are gitignored too. |
| Secret output | The database password and every secret string of 6+ characters are registered with `CommandRunner::addSecret()` and redacted from stdout, stderr, error hints and the command history. |
| Command execution | Subcommands are executed via `proc_open()` with an **argument array** - no shell, so configuration values cannot inject shell metacharacters. |
| Licensed software | ACF Pro is downloaded only from `https://connect.advancedcustomfields.com/index.php?p=pro&a=download&k=<key>` using the user's own key. Keys are never bundled, generated, logged or transmitted anywhere else. Cracked/nulled software is not supported. |
| Third-party code | `config/plugins.json` is a reference catalog and is never executed or installed. Plugins/themes are installed only from values explicitly listed in `config/setup.json`. |
| Destructive actions | Existing WordPress files, `wp-config.php`, an existing database and an existing installation are never overwritten; only *inactive* default themes are deleted. |
| Privileges | Nothing is executed as root, no system configuration is modified, and no credentials other than yours are used. |

## Assumptions and non-goals

- You trust the machine you run this on and the WP-CLI/PHP binaries installed there.
- You are responsible for the plugins and themes you list in your configuration: they are third-party
  code and are installed from WordPress.org, from URLs you specify, or from archives you provide.
- This tool does not harden WordPress itself (security plugins, WAF, rate limiting) - it only builds
  and verifies the environment.
- The tool does not protect secrets at rest: `secrets/env.json` is plain JSON with file permissions as
  your only protection. Use OS-level permissions (`chmod 600`) and full-disk encryption where it matters.

## Handling of reported issues

- Valid reports are fixed on a branch, released, and credited in `CHANGELOG.md`.
- Reports about bundled secrets (keys, credentials in committed files) are treated as critical: the
  artifact is removed from history where possible and rotation of the exposed credential is requested.
