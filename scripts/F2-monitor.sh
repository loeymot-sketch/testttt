#!/usr/bin/env bash
# F.2 soak monitor - tick every 20s, capture key metrics
# Usage: ./F2-monitor.sh <SERVER_PID> <DURATION_SEC>
# Writes one CSV-ish line per tick to F2-soak-monitor.txt

SERVER_PID="${1:-94396}"
DURATION="${2:-600}"
OUT="reports/test-e2e/goal-2026-05-23/phase-f/F2-soak-monitor.txt"

cd "$(dirname "$0")/.."

START=$(date +%s)
END=$((START + DURATION))

while [ "$(date +%s)" -lt "$END" ]; do
  TS=$(date +"%Y-%m-%d_%H:%M:%S")
  RSS_KB=$(ps -o rss= -p "$SERVER_PID" 2>/dev/null | tr -d ' ')
  if [ -z "$RSS_KB" ]; then
    RSS_KB="DEAD"
  fi

  # Query DB+Redis via tinker (single eval, parsed to single line)
  METRICS=$(php artisan tinker --execute='
    echo \App\Models\AuditLog::count() . "|";
    echo \App\Models\Order::count() . "|";
    echo \App\Models\DomainEvent::whereNull("dispatched_at")->count() . "|";
    echo \App\Models\DomainEvent::whereNull("dispatched_at")->where("created_at","<",now()->subSeconds(30))->count() . "|";
    echo (\App\Models\Order::where("branch_id",1)->max("fiscal_sequence_no") ?? 0) . "|";
    echo (\Illuminate\Support\Facades\Redis::llen("queues:default") ?? 0);
  ' 2>/dev/null | tr -d "\n")

  echo "$TS | $METRICS | rss_kb=$RSS_KB" >> "$OUT"
  sleep 20
done
echo "$(date +"%Y-%m-%d_%H:%M:%S") | MONITOR_END" >> "$OUT"
