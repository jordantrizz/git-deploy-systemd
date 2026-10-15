#!/usr/bin/env bash
# ==============================================================================
# Integration tests for git-deploy-systemd.
#
# Runs unprivileged in a temp sandbox using GIT_DEPLOY_PREFIX +
# GIT_DEPLOY_TESTING so nothing on the host is touched: no root, no systemd,
# no package installs, no /usr/local or /opt writes.
# ==============================================================================
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CLI="${REPO_ROOT}/bin/git-deploy-systemd"
PHP="${REPO_ROOT}/bin/git-deploy-systemd.php"

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

PASS=0
FAIL=0
ok()  { echo "ok   - $1"; PASS=$((PASS + 1)); }
bad() { echo "FAIL - $1"; FAIL=$((FAIL + 1)); }

sandbox() { # sandbox <prefix> <args...>
    local prefix="$1"
    shift
    GIT_DEPLOY_PREFIX="${prefix}" GIT_DEPLOY_TESTING=1 bash "${CLI}" "$@"
}

# -- version -------------------------------------------------------------
echo "# version"
expected="$(grep '^VERSION=' "${CLI}" | cut -d'"' -f2)"
got="$(bash "${CLI}" version)"
if [[ "${got}" == "git-deploy-systemd ${expected}" ]]; then
    ok "version: ${got}"
else
    bad "version mismatch: '${got}' != 'git-deploy-systemd ${expected}'"
fi

# -- help ----------------------------------------------------------------
echo "# help"
if bash "${CLI}" help | grep -q 'install'; then
    ok "help lists commands"
else
    bad "help output missing commands"
fi

# -- config parse (list) -------------------------------------------------
echo "# config parse"
P="${WORK}/list"
sandbox "${P}" install >/dev/null 2>&1
mkdir -p "${P}/opt/git-deploy/config"
cat > "${P}/opt/git-deploy/config/sites.conf" <<'EOF'
[alpha]
path=/var/www/alpha
user=www-data
EOF
out="$(GIT_DEPLOY_PREFIX="${P}" bash "${CLI}" list)"
if echo "${out}" | grep -q 'alpha' && echo "${out}" | grep -q '/var/www/alpha'; then
    ok "list shows the registered site"
else
    bad "list did not show the registered site"
fi

# -- update from git -----------------------------------------------------
echo "# update from git"
SRC="${WORK}/src"
mkdir -p "${SRC}/bin"
cp "${CLI}" "${SRC}/bin/git-deploy-systemd"
cp "${PHP}" "${SRC}/bin/git-deploy-systemd.php"
sed -i 's/^VERSION=".*"/VERSION="9.9.9"/' "${SRC}/bin/git-deploy-systemd"
(
    cd "${SRC}" || exit 1
    git init -q -b main
    git -c user.email=t@t -c user.name=t add -A
    git -c user.email=t@t -c user.name=t commit -qm "bump to 9.9.9"
)
U="${WORK}/upd"
sandbox "${U}" install >/dev/null 2>&1
sandbox "${U}" update --from-git "file://${SRC}" --force >/dev/null 2>&1
if grep -q 'VERSION="9.9.9"' "${U}/usr/local/sbin/git-deploy-systemd"; then
    ok "update replaced the installed binary"
else
    bad "update did not replace the installed binary"
fi
if grep -q '^version=9.9.9' "${U}/opt/git-deploy/config/install.conf"; then
    ok "update recorded the new version"
else
    bad "update did not record the new version"
fi

# -- adopt ---------------------------------------------------------------
echo "# adopt"
A="${WORK}/legacy"
mkdir -p "${A}/opt/git-deploy/config"
cat > "${A}/opt/git-deploy/config/sites.conf" <<'EOF'
[legacy]
path=/var/www/legacy
user=www-data
EOF
cp "${A}/opt/git-deploy/config/sites.conf" "${WORK}/legacy.sites.orig"
sandbox "${A}" adopt --force >/dev/null 2>&1
if cmp -s "${WORK}/legacy.sites.orig" "${A}/opt/git-deploy/config/sites.conf"; then
    ok "adopt preserved sites.conf"
else
    bad "adopt altered sites.conf"
fi
if [[ -x "${A}/usr/local/sbin/git-deploy-systemd" ]]; then
    ok "adopt installed the CLI at /usr/local/sbin"
else
    bad "adopt did not install the CLI"
fi
if grep -q '^adopted=true' "${A}/opt/git-deploy/config/install.conf"; then
    ok "adopt recorded adopted=true"
else
    bad "adopt missing adopted=true"
fi

# -- watcher failure logging ---------------------------------------------
echo "# watcher failure logging"
W="${WORK}/watch"
sandbox "${W}" install >/dev/null 2>&1
mkdir -p "${W}/notgit"
printf '[notgit]\npath=%s\nuser=root\n' "${W}/notgit" > "${W}/opt/git-deploy/config/sites.conf"
sandbox "${W}" install >/dev/null 2>&1
mkdir -p "${W}/fakebin"
cat > "${W}/fakebin/inotifywait" <<EOF
#!/usr/bin/env bash
printf '%s/ CREATE .git-pull-trigger\n' "${W}/notgit"
exit 0
EOF
cat > "${W}/fakebin/sudo" <<'EOF'
#!/usr/bin/env bash
while [[ "${1:-}" == -* ]]; do
    if [[ "$1" == "-u" ]]; then shift 2; else shift; fi
done
exec "$@"
EOF
chmod +x "${W}/fakebin/"*
PATH="${W}/fakebin:${PATH}" bash "${W}/opt/git-deploy/git-deploy-watcher.sh" > "${W}/out.txt" 2>&1
if grep -q "not a git repository" "${W}/out.txt"; then
    ok "watcher surfaces the git error on failure"
else
    bad "watcher did not surface the git error"
fi

# -- run_systemctl dispatch (regression: must call systemctl, not itself) -
# The TESTING short-circuit bypasses this in every sandbox run above, so load
# the definitions directly and exercise the real path with a stub systemctl.
echo "# run_systemctl dispatch"
LIB="${WORK}/lib.sh"
sed '/^main "\$@"$/d' "${CLI}" > "${LIB}"
mkdir -p "${WORK}/sbin"
printf '#!/usr/bin/env bash\nexit 0\n' > "${WORK}/sbin/systemctl"
chmod +x "${WORK}/sbin/systemctl"
if PATH="${WORK}/sbin:${PATH}" GIT_DEPLOY_TESTING=0 bash -c \
    "source '${LIB}'; run_systemctl daemon-reload" >/dev/null 2>&1; then
    ok "run_systemctl dispatches to systemctl (no recursion)"
else
    bad "run_systemctl recursion regression"
fi

# -- summary -------------------------------------------------------------
echo
echo "passed: ${PASS}, failed: ${FAIL}"
[[ "${FAIL}" -eq 0 ]]
