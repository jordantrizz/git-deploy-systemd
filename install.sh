#!/usr/bin/env bash
# ==============================================================================
# git-deploy-systemd bootstrap installer
#
# Downloads the CLI (and its optional WordPress mu-plugin) and hands off to the
# built-in `install` command. Intended for:
#
#   curl -fsSL https://raw.githubusercontent.com/jordantrizz/git-deploy-systemd/main/install.sh | sudo bash
#
# Source overrides (environment):
#   GIT_DEPLOY_REPO      GitHub owner/repo (default: jordantrizz/git-deploy-systemd)
#   GIT_DEPLOY_REF       Branch or tag to install (default: main)
#   GIT_DEPLOY_BASE_URL  Full base URL for the bin/ dir (overrides REPO/REF)
# ==============================================================================
set -euo pipefail

REPO="${GIT_DEPLOY_REPO:-jordantrizz/git-deploy-systemd}"
REF="${GIT_DEPLOY_REF:-main}"
BASE_URL="${GIT_DEPLOY_BASE_URL:-https://raw.githubusercontent.com/${REPO}/${REF}/bin}"

fetch() {
    local url="$1" dest="$2"
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL "${url}" -o "${dest}"
    elif command -v wget >/dev/null 2>&1; then
        wget -qO "${dest}" "${url}"
    else
        echo "error: curl or wget is required" >&2
        exit 1
    fi
}

tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT

echo "Downloading git-deploy-systemd from ${BASE_URL} ..."
fetch "${BASE_URL}/git-deploy-systemd" "${tmp}/git-deploy-systemd"
fetch "${BASE_URL}/git-deploy-systemd.php" "${tmp}/git-deploy-systemd.php" || true
chmod +x "${tmp}/git-deploy-systemd"

# Everything else is handled by the CLI itself.
"${tmp}/git-deploy-systemd" install "$@"
