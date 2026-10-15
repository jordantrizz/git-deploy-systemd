# AGENTS.md

## Project Overview

`git-deploy-systemd` is a standalone, systemd-managed, inotify-based `git pull`
deploy watcher. The CLI is a single self-contained bash script
(`bin/git-deploy-systemd`); it installs to `/usr/local/sbin/git-deploy-systemd`
and generates the watcher, service unit, and config under `/opt/git-deploy`
and `/etc/systemd/system`.

## Development Rules

* **Mandatory: follow the `/development-workflow` skill for every change, in
  every session.** This is not optional and does not need to be asked for.
* The skill file is the source of truth for workflow rules — do not duplicate
  them here.

## This repository is PUBLIC

* **`plans/` must never be committed.** It is gitignored and symlinked to the
  private plans hub (`~/git/pgf-plans/git-deploy-systemd`). PGFs and internal
  notes live there only; they are not part of this repository.
* Before every commit, confirm nothing internal is staged:
  * `git check-ignore plans` prints `plans`
  * `git status --porcelain` shows no `plans/` entries
  * never `git add -f` a path under `plans/`
* Do not commit internal infrastructure details — hostnames, site paths,
  credentials, or references to private repositories.

## Versioning

* The version is the `vX.Y.Z` git tag (current: **`v0.0.1`**).
* The version string is embedded in exactly two places, and they MUST match:
  * `bin/git-deploy-systemd` — `VERSION="X.Y.Z"`
  * `bin/git-deploy-systemd.php` — the `Version:` header **and**
    `GIT_DEPLOY_VERSION`
* Releases use the `git-squash-release` skill. It auto-detects and bumps the
  WordPress plugin `Version:` header but does **not** know about the CLI's
  embedded `VERSION=`, so update the CLI (and the `GIT_DEPLOY_VERSION`
  constant) in the same release commit.

## Layout

| Path | Purpose |
|------|---------|
| `bin/git-deploy-systemd` | The CLI (single self-contained bash script) |
| `bin/git-deploy-systemd.php` | Optional WordPress MU-plugin |
| `completion/comp-git-deploy.zsh` | zsh completion |
| `install.sh` | One-line bootstrap installer |
| `tests/test-git-deploy-systemd.sh` | Integration tests |
| `.github/workflows/ci.yml` | CI (shellcheck, php -l, tests) |

## Testing

Always run before finishing a change:

```bash
shellcheck -S warning bin/git-deploy-systemd install.sh
php -l bin/git-deploy-systemd.php
bash tests/test-git-deploy-systemd.sh
```

The tests run unprivileged in a sandbox (`GIT_DEPLOY_PREFIX` +
`GIT_DEPLOY_TESTING`) and never touch the host. Because the sandbox sets
`GIT_DEPLOY_TESTING=1`, code paths gated on it (e.g. `run_systemctl`) are not
exercised by the suite — cover those separately when changing them.

## Conventions

* Bash 4+, `set -euo pipefail`, no runtime dependencies beyond git, coreutils,
  and `curl`/`wget`.
* Keep the CLI a single file — it is installed standalone and self-updates.
