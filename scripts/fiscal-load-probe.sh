#!/usr/bin/env bash
#
# Real multi-process concurrency probe for the NF525 fiscal sequence.
#
# Spawns K parallel `php artisan fiscal:load-probe` workers against a fresh
# branch, each opening its own DB connection so they genuinely contend on
# FiscalSequenceService::next() (cache lock + lockForUpdate). Then verifies
# the persisted chain is contiguous and duplicate-free.
#
# This replaces the old single-process sequential `kiosk:simulate-orders`
# loop, which never exercised the lock under contention and therefore
# proved nothing about concurrency safety.
#
# Usage:   bash scripts/fiscal-load-probe.sh [WORKERS] [COUNT_PER_WORKER]
# Default: 20 workers x 50 allocations = 1000 concurrent fiscal numbers.
#
# Self-contained: uses a dedicated, migrated SQLite file so it is fully
# reproducible in any clone (CLAUDE.md §9/§11 — evidence lives in the repo).

set -euo pipefail

WORKERS="${1:-20}"
COUNT="${2:-50}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PROBE_DB="$ROOT/storage/app/fiscal-load-probe.sqlite"

# Dedicated, isolated DB so we never touch dev/prod data and always start
# from an empty chain (expected result becomes a clean 1..WORKERS*COUNT).
export DB_CONNECTION=sqlite
export DB_DATABASE="$PROBE_DB"
export CACHE_DRIVER=file        # file lock store: shared across the parallel PHP processes
export BROADCAST_DRIVER=log
export QUEUE_CONNECTION=sync

rm -f "$PROBE_DB"
mkdir -p "$(dirname "$PROBE_DB")"
touch "$PROBE_DB"

echo "==> Migrating dedicated probe DB ($PROBE_DB)"
php artisan migrate --force --no-interaction >/dev/null

echo "==> Creating fresh probe branch"
BRANCH_ID="$(php artisan fiscal:load-probe --setup | sed -n 's/^BRANCH_ID=//p')"
if [[ -z "${BRANCH_ID:-}" ]]; then
  echo "FATAL: could not create probe branch" >&2
  exit 1
fi
echo "    branch_id=$BRANCH_ID"

EXPECTED=$(( WORKERS * COUNT ))
echo "==> Launching $WORKERS parallel workers x $COUNT allocations = $EXPECTED total"
pids=()
for ((w = 0; w < WORKERS; w++)); do
  php artisan fiscal:load-probe "$BRANCH_ID" "$COUNT" &
  pids+=("$!")
done

fail=0
for pid in "${pids[@]}"; do
  wait "$pid" || fail=1
done
if [[ "$fail" -ne 0 ]]; then
  echo "FATAL: at least one worker process exited non-zero" >&2
  exit 1
fi

echo "==> Verifying gap-free / no-dup invariant"
php artisan fiscal:load-probe --verify="$BRANCH_ID"
