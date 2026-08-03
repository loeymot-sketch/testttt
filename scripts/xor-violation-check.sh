#!/usr/bin/env bash
# FoodKing — Monitoring XOR violations item_wizard_profiles
# Usage: bash scripts/xor-violation-check.sh [--quiet] [--alert-webhook URL]
# Source: gate brief prod-cutover §11 + V1.5 D3 (MySQL < 8.0 silent ignore CHECK)
#
# DB connection uses Laravel bootstrap (.env: DB_*); no credentials in this script.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

QUIET=0
ALERT_WEBHOOK=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --quiet) QUIET=1; shift ;;
    --alert-webhook) ALERT_WEBHOOK="$2"; shift 2 ;;
    *) echo "Unknown arg: $1" >&2; exit 2 ;;
  esac
done

ts_iso() {
  date -u +"%Y-%m-%dT%H:%M:%SZ" 2>/dev/null || date -u +"%Y-%m-%dT%H:%M:%SZ"
}

TS="$(ts_iso)"
LOG_DAY="$(date -u +%Y-%m-%d)"
MONITOR_DIR="$REPO_ROOT/reports/monitoring"
VIOLATION_LOG="$MONITOR_DIR/xor-violations-${LOG_DAY}.log"

mkdir -p "$MONITOR_DIR"

TINKER_COUNT=$(php artisan tinker --execute='echo (int) App\Models\ItemWizardProfile::query()->where(function($q){ $q->whereNull("item_id")->whereNull("item_category_id"); })->orWhere(function($q){ $q->whereNotNull("item_id")->whereNotNull("item_category_id"); })->count();' 2>&1) || {
  echo "[XOR-CHECK] ERROR: tinker failed: $TINKER_COUNT" >&2
  exit 3
}

COUNT="$(printf '%s' "$TINKER_COUNT" | tail -n 1 | tr -d '[:space:]')"

if ! [[ "$COUNT" =~ ^[0-9]+$ ]]; then
  echo "[XOR-CHECK] ERROR: invalid count from tinker: $TINKER_COUNT" >&2
  exit 3
fi

emit() {
  if [[ "$QUIET" -eq 0 ]]; then
    printf '%s\n' "$*" >&2
  fi
}

if [[ "$COUNT" -gt 0 ]]; then
  MSG="[XOR-CHECK] CRITICAL: ${COUNT} violations detected at ${TS}"
  printf '%s\n' "$MSG" >&2
  {
    printf '%s\n' "$MSG"
    printf '%s\n' "--- violating rows (${TS}) ---"
  } >>"$VIOLATION_LOG"

  ROWS=$(php artisan tinker --execute='echo json_encode(Illuminate\Support\Facades\DB::table("item_wizard_profiles")->select("id", "item_id", "item_category_id", "template", "version", "created_at")->where(function($q){ $q->whereNull("item_id")->whereNull("item_category_id"); })->orWhere(function($q){ $q->whereNotNull("item_id")->whereNotNull("item_category_id"); })->get()->all());' 2>&1) || ROWS="(failed to list rows: ${ROWS})"
  printf '%s\n' "$ROWS" >>"$VIOLATION_LOG"
  printf '%s\n' "$ROWS" >&2

  if [[ -n "$ALERT_WEBHOOK" ]]; then
    HOSTNAME="$(hostname 2>/dev/null || echo unknown)"
    JSON="$(printf '{"service":"foodking-monitoring","check":"xor_violations_item_wizard_profiles","severity":"critical","count":%s,"timestamp":"%s","host":"%s"}' "$COUNT" "$TS" "$HOSTNAME")"
    curl -sS -X POST -H 'Content-Type: application/json' -d "$JSON" "$ALERT_WEBHOOK" --max-time 10 >/dev/null 2>&1 || true
  fi

  exit 1
fi

emit "[XOR-CHECK] OK: 0 violations at ${TS}"
exit 0
