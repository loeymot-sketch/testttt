#!/usr/bin/env bash
# P0 — Graphiti full ingest with extended drain (align Neo4j ≈ 180 episodes).
#
# Usage (from repo root, machine with MCP env configured):
#   bash bin/graphiti-p0-long-drain.sh
#   nohup bash bin/graphiti-p0-long-drain.sh > /tmp/foodking-p0-ingest.log 2>&1 &
#
# Env (defaults match audit P0):
#   DRAIN_TIMEOUT=7200      # 2h max wait for queue drain
#   DRAIN_STALL_ITERS=120   # 120 × 15s = 30 min stall before giving up
#   SKIP_DRAIN=1          # skip drain (push only)
#
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${REPO_ROOT}"

export DRAIN_TIMEOUT="${DRAIN_TIMEOUT:-7200}"
export DRAIN_STALL_ITERS="${DRAIN_STALL_ITERS:-120}"

echo "[P0] DRAIN_TIMEOUT=${DRAIN_TIMEOUT}s DRAIN_STALL_ITERS=${DRAIN_STALL_ITERS} (poll interval 15s in ingest.py)"
echo "[P0] Starting full ingest via bin/graphiti-ingest.sh …"
exec bash "${REPO_ROOT}/bin/graphiti-ingest.sh" "$@"
