#!/usr/bin/env bash
#
# dev-stack.sh — FoodKing V1 LOCAL Le Cayenne · launchd stack manager (macOS).
#
# Thin wrapper over `launchctl bootstrap/bootout/kickstart` so the owner manages
# the daemon stack with one verb instead of hand-driving 4 plists. Native Mac
# equivalent of `supervisorctl start|stop|restart|status` on the Hetzner box.
#
# DAEMONS MANAGED (auto-start + auto-restart via plist KeepAlive):
#   fr.lecayenne.serve        artisan serve  :8000   (HTTP app)
#   fr.lecayenne.queue        queue:work redis (default lane)
#   fr.lecayenne.queue-high   queue:work redis --queue=high
#   fr.lecayenne.soketi       Soketi WS :6001  (realtime sync)
#
# NOT managed here:
#   redis — already a Homebrew service (~/Library/LaunchAgents/homebrew.mxcl.redis.plist,
#           `brew services list` shows it "started"). It already auto-restarts. Manage it
#           with `brew services start|stop|restart redis`, NOT this script.
#   scheduler — installed as a user crontab line (see README.md §2 / GO_LIVE_RUNBOOK
#           step 3), NOT a launchd job.
#
# ──────────────────────────────────────────────────────────────────────────────
#  SAFETY: This script does NOT run on import. `start` will CONFLICT with any
#  manually-launched daemon already holding :8000 / :6001 and with a live parallel
#  session. Read deploy/local/README.md before first `start`. Stop the manual
#  daemons first.
# ──────────────────────────────────────────────────────────────────────────────

set -euo pipefail

APP_DIR="/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt"
PLIST_SRC_DIR="${APP_DIR}/deploy/local"
LA_DIR="${HOME}/Library/LaunchAgents"
DOMAIN="gui/$(id -u)"

LABELS=(
    "fr.lecayenne.serve"
    "fr.lecayenne.queue"
    "fr.lecayenne.queue-high"
    "fr.lecayenne.soketi"
)

usage() {
    cat <<EOF
FoodKing dev-stack (launchd) — manage the 4 app daemons.

Usage: $0 <command>

  install     Copy the 4 plists into ~/Library/LaunchAgents/ (does NOT load them)
  uninstall   bootout + remove the 4 plists from ~/Library/LaunchAgents/
  start       bootstrap (load + RunAtLoad) the 4 daemons
  stop        bootout (unload) the 4 daemons
  restart     kickstart -k each daemon (hard restart)
  status      launchctl print-state for each label + listening ports
  lint        plutil -lint each plist in deploy/local/

NOTE: redis is a Homebrew service — use 'brew services restart redis', not this.
EOF
}

require_installed() {
    local missing=0
    for label in "${LABELS[@]}"; do
        if [[ ! -f "${LA_DIR}/${label}.plist" ]]; then
            echo "  ! ${label}.plist not in ${LA_DIR} — run '$0 install' first" >&2
            missing=1
        fi
    done
    [[ $missing -eq 0 ]] || exit 1
}

cmd_install() {
    mkdir -p "${LA_DIR}"
    for label in "${LABELS[@]}"; do
        cp "${PLIST_SRC_DIR}/${label}.plist" "${LA_DIR}/${label}.plist"
        echo "  installed ${label}.plist"
    done
    echo "Done. Plists copied (NOT loaded). Run '$0 start' to load them."
}

cmd_uninstall() {
    for label in "${LABELS[@]}"; do
        launchctl bootout "${DOMAIN}/${label}" 2>/dev/null || true
        rm -f "${LA_DIR}/${label}.plist"
        echo "  removed ${label}"
    done
}

cmd_start() {
    require_installed
    for label in "${LABELS[@]}"; do
        launchctl bootstrap "${DOMAIN}" "${LA_DIR}/${label}.plist" \
            && echo "  bootstrapped ${label}" \
            || echo "  ! bootstrap ${label} FAILED (already loaded? port held?)" >&2
    done
}

cmd_stop() {
    for label in "${LABELS[@]}"; do
        launchctl bootout "${DOMAIN}/${label}" 2>/dev/null \
            && echo "  booted out ${label}" \
            || echo "  ${label} not loaded"
    done
}

cmd_restart() {
    require_installed
    for label in "${LABELS[@]}"; do
        launchctl kickstart -k "${DOMAIN}/${label}" \
            && echo "  kickstarted ${label}" \
            || echo "  ! kickstart ${label} FAILED (not loaded? run start)" >&2
    done
}

cmd_status() {
    for label in "${LABELS[@]}"; do
        echo "── ${label}"
        launchctl print "${DOMAIN}/${label}" 2>/dev/null \
            | grep -E "state =|pid =|last exit code =" \
            | sed 's/^/    /' \
            || echo "    (not loaded)"
    done
    echo "── listening ports (expect 8000 serve, 6001 soketi, 6379 redis)"
    lsof -nP -iTCP -sTCP:LISTEN 2>/dev/null \
        | grep -E ":(8000|6001|6379)\b" \
        | awk '{print "    "$1" "$2" "$9}' \
        || echo "    (none of 8000/6001/6379 listening)"
}

cmd_lint() {
    for label in "${LABELS[@]}"; do
        plutil -lint "${PLIST_SRC_DIR}/${label}.plist"
    done
}

case "${1:-}" in
    install)   cmd_install   ;;
    uninstall) cmd_uninstall ;;
    start)     cmd_start     ;;
    stop)      cmd_stop      ;;
    restart)   cmd_restart   ;;
    status)    cmd_status    ;;
    lint)      cmd_lint      ;;
    *)         usage; exit 1 ;;
esac
