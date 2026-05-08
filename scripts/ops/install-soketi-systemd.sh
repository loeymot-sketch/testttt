#!/usr/bin/env bash
# Install Soketi + queue worker + scheduler as systemd services.
# Resolves the Soketi binary location at install time so the unit file does not
# depend on a hardcoded /usr/bin/soketi path (npm global typically lands in
# /usr/local/bin or ~/.npm-global/bin).
#
# Usage:
#   sudo ./scripts/ops/install-soketi-systemd.sh [/path/to/repo]
#
# Default repo path: /home/foodking/foodking
#
# Exit codes:
#   0  installed and started
#   1  not run as root
#   2  invalid repo path
#   3  required binary missing (soketi or php)

set -euo pipefail

# ---------------------------------------------------------------------------
APP_USER="${APP_USER:-foodking}"
REPO_DIR="${1:-/home/foodking/foodking}"
SYSTEMD_DIR="/etc/systemd/system"
TEMPLATE_DIR="${REPO_DIR}/deploy/systemd"

# ---------------------------------------------------------------------------
[[ "${EUID}" -eq 0 ]] || { echo "[FAIL] Run as root (sudo)."; exit 1; }
[[ -d "${TEMPLATE_DIR}" ]] || { echo "[FAIL] Templates dir missing: ${TEMPLATE_DIR}"; exit 2; }

# Resolve binary paths.
SOKETI_BIN=$(command -v soketi || true)
PHP_BIN=$(command -v php || true)

if [[ -z "${SOKETI_BIN}" ]]; then
    echo "[FAIL] 'soketi' not found in PATH. Install with: npm install -g @soketi/soketi"
    exit 3
fi
if [[ -z "${PHP_BIN}" ]]; then
    echo "[FAIL] 'php' not found in PATH."
    exit 3
fi

echo "[INFO] soketi binary : ${SOKETI_BIN}"
echo "[INFO] php binary    : ${PHP_BIN}"
echo "[INFO] repo dir      : ${REPO_DIR}"
echo "[INFO] runtime user  : ${APP_USER}"

# Confirm the runtime user exists.
id "${APP_USER}" >/dev/null 2>&1 || { echo "[FAIL] Runtime user '${APP_USER}' does not exist."; exit 2; }

render_unit() {
    local src="$1"
    local dst="$2"
    sed \
        -e "s|__SOKETI_BIN__|${SOKETI_BIN}|g" \
        -e "s|__PHP_BIN__|${PHP_BIN}|g" \
        -e "s|__APP_USER__|${APP_USER}|g" \
        -e "s|__REPO_DIR__|${REPO_DIR}|g" \
        "${src}" > "${dst}"
}

echo "[INSTALL] Rendering systemd units..."
render_unit "${TEMPLATE_DIR}/foodking-soketi.service"        "${SYSTEMD_DIR}/foodking-soketi.service"
render_unit "${TEMPLATE_DIR}/foodking-queue-worker.service"  "${SYSTEMD_DIR}/foodking-queue-worker.service"
render_unit "${TEMPLATE_DIR}/foodking-scheduler.service"     "${SYSTEMD_DIR}/foodking-scheduler.service"
cp -f       "${TEMPLATE_DIR}/foodking-scheduler.timer"       "${SYSTEMD_DIR}/foodking-scheduler.timer"

systemctl daemon-reload

echo "[INSTALL] Enabling units..."
systemctl enable foodking-soketi.service
systemctl enable foodking-queue-worker.service
systemctl enable foodking-scheduler.timer

echo "[INSTALL] Starting units..."
systemctl restart foodking-soketi.service
systemctl restart foodking-queue-worker.service
systemctl start   foodking-scheduler.timer

echo
echo "[OK] Services installed."
echo
for svc in foodking-soketi.service foodking-queue-worker.service foodking-scheduler.timer; do
    echo "----- ${svc} -----"
    systemctl status "${svc}" --no-pager -l | head -5 || true
    echo
done

echo "[NEXT] Verify with:"
echo "  sudo journalctl -u foodking-soketi.service -n 50"
echo "  curl -fsS http://127.0.0.1:6001/usage"
echo "  ${REPO_DIR}/scripts/ops/healthcheck-soketi.sh"
