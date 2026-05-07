#!/usr/bin/env bash
# =============================================================================
# CI Bootstrap — WebSockets Harness for Outbox Pipeline Validation
# Mission : CV1-CI-WEBSOCKETS-HARNESS-001
# RED-R3 / RED-R5 reproducer harness
# =============================================================================
#
# Why this exists
# ---------------
# RED-R3 / RED-R5 found that without a real Pusher-protocol server up on
# port 6001, every "rupture stock sync" test passes only because rows are
# written to `domain_events`. The actual Pusher dispatch path
# (DispatchDomainEventsJob → BroadcastManager::connection()->broadcast)
# is NEVER exercised. A regression on the broadcast pipeline (envelope
# contract, claim/release in phase 3b, attempts counter, etc.) would slip
# silently through CI.
#
# This harness brings up a real broadcaster (soketi by default; falls back
# to `php artisan websockets:serve` if beyondcode/laravel-websockets is
# installed) so the OutboxPipelineHealthSentinelTest can assert end-to-end:
#   1) DomainEvent row created
#   2) DispatchDomainEventsJob processed (queue:work --once)
#   3) dispatched_at IS set, attempts == 1, last_error IS NULL
#
# Boundaries (per advisor review)
# -------------------------------
# - This script DOES start a broadcaster on 127.0.0.1:6001.
# - This script DOES NOT start a `queue:work` daemon. The sentinel itself
#   invokes `Artisan::call('queue:work', ['--once' => true, '--queue' => 'high'])`
#   to avoid races with daemon-vs-test on the same `jobs` table.
# - This script DOES drain pending jobs (queue:flush) for a clean slate.
# - This script fails LOUD if no broadcaster backend is available — the
#   "graceful skip" path lives in the sentinel, not here.
#
# Usage (local)
# -------------
#   ./scripts/ci-bootstrap-websockets-harness.sh
#   export CI_WEBSOCKETS_HARNESS=1
#   export BROADCAST_DRIVER=pusher
#   export QUEUE_CONNECTION=database
#   php artisan test --filter=OutboxPipelineHealthSentinel
#   ./scripts/ci-teardown-websockets-harness.sh
#
# Usage (CI)
# ----------
# See .github/workflows/ci-sync-rupture-harness.yml.
# =============================================================================

set -euo pipefail

# ---------------------------------------------------------------------------
# Locate project root (this script lives in <root>/scripts/)
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${PROJECT_ROOT}"

LOG_DIR="${PROJECT_ROOT}/storage/logs"
PIDS_FILE="${LOG_DIR}/ci-harness.pids"
WS_LOG="${LOG_DIR}/websockets-ci.log"
HEALTH_PORT="${WS_HARNESS_PORT:-6001}"
HEALTH_HOST="${WS_HARNESS_HOST:-127.0.0.1}"
PROBE_TIMEOUT_SECONDS="${WS_HARNESS_TIMEOUT:-10}"

mkdir -p "${LOG_DIR}"

log()  { printf '[ci-bootstrap-ws] %s\n' "$*"; }
fail() { printf '[ci-bootstrap-ws][FAIL] %s\n' "$*" >&2; exit 1; }

# Wipe stale PIDs file from previous run (we'll repopulate).
: > "${PIDS_FILE}"

# ---------------------------------------------------------------------------
# 1. Kill orphan processes from a previous run.
#    Match by command pattern; tolerate "no matches".
# ---------------------------------------------------------------------------
kill_orphans() {
    local pattern="$1"
    local label="$2"
    # pgrep returns 1 when no process matches — that's fine.
    local pids
    pids="$(pgrep -f "${pattern}" 2>/dev/null || true)"
    if [[ -n "${pids}" ]]; then
        log "Killing orphan ${label} PIDs: ${pids}"
        # shellcheck disable=SC2086
        kill ${pids} 2>/dev/null || true
        sleep 1
        # Force-kill any survivor.
        pids="$(pgrep -f "${pattern}" 2>/dev/null || true)"
        if [[ -n "${pids}" ]]; then
            # shellcheck disable=SC2086
            kill -9 ${pids} 2>/dev/null || true
        fi
    fi
}

log "Killing orphan websockets / soketi / queue:work processes from prior runs."
kill_orphans 'soketi'                'soketi'
kill_orphans 'artisan websockets:serve' 'beyondcode/websockets'
kill_orphans 'artisan queue:work'    'queue:work'

# ---------------------------------------------------------------------------
# 2. Drain pending jobs (clean slate). Best effort — the database may not
#    be migrated yet on a totally fresh checkout. We only emit a warning.
# ---------------------------------------------------------------------------
log "Draining queued jobs (php artisan queue:flush) — best effort."
if ! php artisan queue:flush >>"${WS_LOG}" 2>&1; then
    log "WARN queue:flush failed (jobs table may not exist yet); continuing."
fi
if ! php artisan queue:clear --queue=high >>"${WS_LOG}" 2>&1; then
    log "WARN queue:clear --queue=high failed; continuing."
fi

# ---------------------------------------------------------------------------
# 3. Detect and start a broadcaster backend.
#    Priority order:
#      a) `php artisan websockets:serve` (beyondcode/laravel-websockets)
#      b) `soketi` (npm i -g @soketi/soketi) — uses ./soketi.json
#    Fail loud if neither is available.
# ---------------------------------------------------------------------------
WS_PID=""
WS_BACKEND="none"

artisan_has_websockets() {
    php artisan list 2>/dev/null | grep -qE '^\s+websockets:serve' || return 1
}

if artisan_has_websockets; then
    WS_BACKEND="beyondcode-websockets"
    log "Starting beyondcode/laravel-websockets on ${HEALTH_HOST}:${HEALTH_PORT}."
    nohup php artisan websockets:serve --host="${HEALTH_HOST}" --port="${HEALTH_PORT}" \
        >>"${WS_LOG}" 2>&1 &
    WS_PID=$!
elif command -v soketi >/dev/null 2>&1; then
    WS_BACKEND="soketi"
    SOKETI_CONFIG="${PROJECT_ROOT}/soketi.json"
    if [[ ! -f "${SOKETI_CONFIG}" ]]; then
        fail "soketi.json not found at ${SOKETI_CONFIG} — cannot start soketi without app config."
    fi
    log "Starting soketi with config ${SOKETI_CONFIG}."
    nohup soketi start --config="${SOKETI_CONFIG}" >>"${WS_LOG}" 2>&1 &
    WS_PID=$!
else
    fail "No broadcaster backend available. Install one of:
   - composer require beyondcode/laravel-websockets   (then this script will use 'php artisan websockets:serve')
   - npm i -g @soketi/soketi                          (then this script will use 'soketi start --config=soketi.json')
RED-R3 reproduction requires a real Pusher-protocol endpoint on ${HEALTH_HOST}:${HEALTH_PORT}.
The sentinel test will SKIP loudly if CI_WEBSOCKETS_HARNESS=1 is not set, but the bootstrap
itself MUST succeed in CI — failing here protects against silent harness regressions."
fi

if [[ -z "${WS_PID}" ]] || ! kill -0 "${WS_PID}" 2>/dev/null; then
    fail "Failed to spawn ${WS_BACKEND}. Check ${WS_LOG}."
fi

echo "${WS_BACKEND}:${WS_PID}" >>"${PIDS_FILE}"
log "Started ${WS_BACKEND} (PID=${WS_PID}). Logs: ${WS_LOG}"

# ---------------------------------------------------------------------------
# 4. TCP probe — wait until the broadcaster accepts connections on the
#    configured port. Bash's /dev/tcp is sufficient; do NOT add curl/nc deps.
# ---------------------------------------------------------------------------
probe_tcp() {
    local host="$1"
    local port="$2"
    # Open + immediately close a TCP socket via bash builtin.
    if (echo > "/dev/tcp/${host}/${port}") 2>/dev/null; then
        return 0
    fi
    return 1
}

log "Probing TCP ${HEALTH_HOST}:${HEALTH_PORT} (timeout=${PROBE_TIMEOUT_SECONDS}s)."
deadline=$(( $(date +%s) + PROBE_TIMEOUT_SECONDS ))
while (( $(date +%s) < deadline )); do
    if probe_tcp "${HEALTH_HOST}" "${HEALTH_PORT}"; then
        log "OK — ${WS_BACKEND} accepting TCP on ${HEALTH_HOST}:${HEALTH_PORT}."
        log "PIDs file: ${PIDS_FILE}"
        log "Bootstrap complete. Export CI_WEBSOCKETS_HARNESS=1 before running sentinel."
        exit 0
    fi
    sleep 1
done

# Probe timed out — capture last 50 lines of broadcaster log for forensics.
log "Probe timed out after ${PROBE_TIMEOUT_SECONDS}s. Tail of ${WS_LOG}:"
tail -n 50 "${WS_LOG}" || true
fail "${WS_BACKEND} did not accept connections on ${HEALTH_HOST}:${HEALTH_PORT} within ${PROBE_TIMEOUT_SECONDS}s."
