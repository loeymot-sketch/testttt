#!/usr/bin/env bash
# =============================================================================
# CI Teardown — WebSockets Harness
# Mission : CV1-CI-WEBSOCKETS-HARNESS-001
# =============================================================================
#
# Reads PIDs written by ci-bootstrap-websockets-harness.sh and terminates them
# cleanly. Idempotent: safe to call multiple times; safe to call when nothing
# is running. Always exits 0 unless an explicit kill failure happens, so it
# can be used in `if: always()` CI cleanup steps without masking real failures.
#
# Flags
# -----
#   --truncate-logs   Empty storage/logs/websockets-ci.log after teardown
#                     (CI artefacts upload step should run BEFORE this).
# =============================================================================

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${PROJECT_ROOT}"

LOG_DIR="${PROJECT_ROOT}/storage/logs"
PIDS_FILE="${LOG_DIR}/ci-harness.pids"
WS_LOG="${LOG_DIR}/websockets-ci.log"

TRUNCATE_LOGS=0
for arg in "$@"; do
    case "${arg}" in
        --truncate-logs) TRUNCATE_LOGS=1 ;;
        *) printf '[ci-teardown-ws][WARN] Unknown arg: %s\n' "${arg}" >&2 ;;
    esac
done

log()  { printf '[ci-teardown-ws] %s\n' "$*"; }

if [[ ! -f "${PIDS_FILE}" ]]; then
    log "No PIDs file at ${PIDS_FILE} — nothing to teardown. (Bootstrap may not have run, or already cleaned up.)"
    exit 0
fi

# ---------------------------------------------------------------------------
# Read PIDs file. Format per line: <backend>:<pid>
# ---------------------------------------------------------------------------
exit_status=0
while IFS= read -r line || [[ -n "${line}" ]]; do
    [[ -z "${line}" ]] && continue
    backend="${line%%:*}"
    pid="${line##*:}"
    if [[ -z "${pid}" || ! "${pid}" =~ ^[0-9]+$ ]]; then
        log "WARN malformed PIDs line: '${line}' — skipping."
        continue
    fi
    if kill -0 "${pid}" 2>/dev/null; then
        log "Stopping ${backend} PID=${pid} (SIGTERM)."
        if ! kill -TERM "${pid}" 2>/dev/null; then
            log "WARN kill -TERM ${pid} failed."
        fi
        # Give it 3 seconds to exit gracefully.
        for _ in 1 2 3; do
            kill -0 "${pid}" 2>/dev/null || break
            sleep 1
        done
        if kill -0 "${pid}" 2>/dev/null; then
            log "Force-killing ${backend} PID=${pid} (SIGKILL)."
            kill -9 "${pid}" 2>/dev/null || exit_status=1
        fi
    else
        log "${backend} PID=${pid} no longer running — already exited."
    fi
done < "${PIDS_FILE}"

# ---------------------------------------------------------------------------
# Belt-and-suspenders: also kill any orphan that escaped the PIDs file
# (e.g. soketi spawned a child or PIDs file got truncated).
# ---------------------------------------------------------------------------
sweep_orphans() {
    local pattern="$1"
    local label="$2"
    local pids
    pids="$(pgrep -f "${pattern}" 2>/dev/null || true)"
    if [[ -n "${pids}" ]]; then
        log "Sweeping orphan ${label}: ${pids}"
        # shellcheck disable=SC2086
        kill ${pids} 2>/dev/null || true
        sleep 1
        pids="$(pgrep -f "${pattern}" 2>/dev/null || true)"
        if [[ -n "${pids}" ]]; then
            # shellcheck disable=SC2086
            kill -9 ${pids} 2>/dev/null || true
        fi
    fi
}
sweep_orphans 'soketi'                'soketi'
sweep_orphans 'artisan websockets:serve' 'beyondcode/websockets'

# Remove the PIDs file so a re-run starts from a clean state.
rm -f "${PIDS_FILE}"
log "Removed ${PIDS_FILE}."

if [[ "${TRUNCATE_LOGS}" == "1" && -f "${WS_LOG}" ]]; then
    : > "${WS_LOG}"
    log "Truncated ${WS_LOG}."
fi

if [[ "${exit_status}" -ne 0 ]]; then
    log "Teardown completed WITH ERRORS."
else
    log "Teardown complete."
fi
exit "${exit_status}"
