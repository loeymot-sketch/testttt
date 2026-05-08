#!/usr/bin/env bash
# FoodKing — Soketi healthcheck
# Returns 0 if OK, 1 if degraded/down.
#
# Usage:
#   ./scripts/ops/healthcheck-soketi.sh
#   SOKETI_HOST=127.0.0.1 SOKETI_PORT=6001 ./scripts/ops/healthcheck-soketi.sh
#
# OPS context: addresses OPS-3 + SYN-1 (P0) of PRE_PROD_DEEP_RISK_AUDIT_2026-05-08.md
#
# Wire to cron every 5 minutes; on failure restart soketi via systemd:
#   */5 * * * * /home/foodking/foodking/scripts/ops/healthcheck-soketi.sh ||
#               /usr/bin/systemctl --no-block restart foodking-soketi.service

set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration (owner-tunable via env)
# ---------------------------------------------------------------------------
SOKETI_HOST="${SOKETI_HOST:-127.0.0.1}"
SOKETI_PORT="${SOKETI_PORT:-6001}"
TIMEOUT="${SOKETI_TIMEOUT:-3}"
LOG_TAG="foodking-soketi-healthcheck"

# Soketi exposes a `/usage` endpoint returning { memory: ... } (HTTP 200).
# A separate `/` returns HTML "OK". /usage is more meaningful for monitoring.
ENDPOINT="http://${SOKETI_HOST}:${SOKETI_PORT}/usage"

# ---------------------------------------------------------------------------
response_code=$(curl -s -o /dev/null -w "%{http_code}" --max-time "${TIMEOUT}" "${ENDPOINT}" || echo "000")
response_time=$(curl -s -o /dev/null -w "%{time_total}" --max-time "${TIMEOUT}" "${ENDPOINT}" 2>/dev/null || echo "n/a")

if [[ "${response_code}" == "200" ]]; then
    echo "[OK] Soketi reachable on ${SOKETI_HOST}:${SOKETI_PORT} (HTTP ${response_code}, ${response_time}s)"
    exit 0
fi

# Probe the systemd unit so the alert message tells the operator whether the
# process is even running (vs. running-but-not-listening).
unit_state="unknown"
if command -v systemctl >/dev/null 2>&1; then
    unit_state=$(systemctl is-active foodking-soketi.service 2>/dev/null || echo "unknown")
fi

echo "[FAIL] Soketi unreachable on ${SOKETI_HOST}:${SOKETI_PORT} (HTTP ${response_code}, unit=${unit_state})"
logger -t "${LOG_TAG}" "DEGRADED: Soketi http=${response_code} unit=${unit_state} endpoint=${ENDPOINT}"
exit 1
