#!/bin/bash
# Sync Monitor loop — captures 11 snapshots over 10 minutes (T+0..T+600).
# Output: one snapshot per file + appended JSONL stream.

set -u
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
OUT=reports/test-e2e/real-live-flow-2026-05-28/agents
STREAM=$OUT/snapshots.jsonl
LOG=$OUT/monitor.log

: > "$STREAM"
: > "$LOG"

START_EPOCH=$(date +%s)
echo "[monitor] start $(date -u +%FT%TZ) epoch=$START_EPOCH" >> "$LOG"

for i in $(seq 0 10); do
  TARGET=$((START_EPOCH + i*60))
  NOW=$(date +%s)
  SLEEP=$((TARGET - NOW))
  if [ "$SLEEP" -gt 0 ]; then
    sleep "$SLEEP"
  fi

  TS_TAG=$(printf "T+%03ds" $((i*60)))
  echo "[monitor] snapshot $TS_TAG at $(date -u +%FT%TZ)" >> "$LOG"

  # Run snapshot via artisan tinker — grab final JSON line only
  RAW=$(php artisan tinker --execute="require 'reports/test-e2e/real-live-flow-2026-05-28/agents/snapshot.php';" 2>>"$LOG")
  # Extract last line starting with {
  JSON=$(echo "$RAW" | grep -E '^\{' | tail -1)
  if [ -z "$JSON" ]; then
    echo "[monitor] WARN empty snapshot at $TS_TAG, retrying once" >> "$LOG"
    sleep 2
    RAW=$(php artisan tinker --execute="require 'reports/test-e2e/real-live-flow-2026-05-28/agents/snapshot.php';" 2>>"$LOG")
    JSON=$(echo "$RAW" | grep -E '^\{' | tail -1)
  fi

  # Annotate snapshot with sequence tag
  if [ -n "$JSON" ]; then
    ANNOTATED=$(echo "$JSON" | php -r '$j=json_decode(stream_get_contents(STDIN),true); $j["seq"]=$argv[1]; $j["seq_idx"]=intval($argv[2]); echo json_encode($j,JSON_UNESCAPED_SLASHES);' "$TS_TAG" "$i")
    echo "$ANNOTATED" >> "$STREAM"
  else
    echo "[monitor] FAIL snapshot $TS_TAG empty after retry" >> "$LOG"
  fi
done

echo "[monitor] end $(date -u +%FT%TZ)" >> "$LOG"
