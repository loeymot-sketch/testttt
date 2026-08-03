#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

usage() {
  cat <<'USAGE'
FoodKing Caisse V1 rollout canary drill.

Read-only. It never flips flags; it validates rollout evidence and predicates.

Usage:
  bash scripts/rollout-canary-drill.sh --preflight-report=PATH --branch-id=ID --metrics=PATH --phase=pilot_branch
  bash scripts/rollout-canary-drill.sh --help

Options:
  --preflight-report=PATH   Required. Must contain "summary: failures=0" from M14 preflight.
  --branch-id=ID            Required for pilot branch evidence.
  --metrics=PATH            Required. key=value lines for payment_success_rate, fiscal_anomaly, kds_error_rate.
  --phase=NAME              pilot_branch, ten_percent, fifty_percent, or full. Default: pilot_branch.
  --report=PATH             Write drill report. Refuses overwrite.
  -h, --help                Show this help.
USAGE
}

PREFLIGHT_REPORT=""
BRANCH_ID=""
METRICS_PATH=""
PHASE="pilot_branch"
REPORT_PATH=""
FAILURES=0
REPORT=""

append() {
  REPORT+="$*"$'\n'
  printf '%s\n' "$*"
}

pass() {
  append "PASS $1: $2"
}

fail_check() {
  FAILURES=$((FAILURES + 1))
  append "FAIL $1: $2"
}

metric_value() {
  local key="$1"
  awk -F= -v k="$key" '$1 == k {gsub(/^[ \t]+|[ \t]+$/, "", $2); print $2; exit}' "$METRICS_PATH"
}

less_than() {
  awk -v a="$1" -v b="$2" 'BEGIN { exit !(a < b) }'
}

greater_than() {
  awk -v a="$1" -v b="$2" 'BEGIN { exit !(a > b) }'
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --preflight-report=*) PREFLIGHT_REPORT="${1#--preflight-report=}"; shift ;;
    --preflight-report) PREFLIGHT_REPORT="${2:?--preflight-report requires a value}"; shift 2 ;;
    --branch-id=*) BRANCH_ID="${1#--branch-id=}"; shift ;;
    --branch-id) BRANCH_ID="${2:?--branch-id requires a value}"; shift 2 ;;
    --metrics=*) METRICS_PATH="${1#--metrics=}"; shift ;;
    --metrics) METRICS_PATH="${2:?--metrics requires a value}"; shift 2 ;;
    --phase=*) PHASE="${1#--phase=}"; shift ;;
    --phase) PHASE="${2:?--phase requires a value}"; shift 2 ;;
    --report=*) REPORT_PATH="${1#--report=}"; shift ;;
    --report) REPORT_PATH="${2:?--report requires a value}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "rollout-canary-drill: unknown option: $1" >&2; exit 2 ;;
  esac
done

cd "$ROOT_DIR"

append "FoodKing Caisse V1 rollout canary drill"
append "mode=read-only"
append "phase=$PHASE"

case "$PHASE" in
  pilot_branch|ten_percent|fifty_percent|full) pass "phase" "$PHASE" ;;
  *) fail_check "phase" "unknown phase: $PHASE" ;;
esac

if [[ -f "$ROOT_DIR/config/caisse_v1_rollout.php" ]]; then
  for flag in payment_ledger_v1 pos_revenue_guards kds_strict_release quote_v1 fiscal_z_v1 kiosk_offline_strict; do
    grep -q "'$flag'" "$ROOT_DIR/config/caisse_v1_rollout.php" \
      && pass "flag-$flag" "declared" \
      || fail_check "flag-$flag" "missing from config/caisse_v1_rollout.php"
  done
else
  fail_check "rollout-config" "missing config/caisse_v1_rollout.php"
fi

if [[ -z "$PREFLIGHT_REPORT" || ! -f "$PREFLIGHT_REPORT" ]]; then
  fail_check "preflight-report" "missing --preflight-report file from M14"
elif grep -q "summary: failures=0" "$PREFLIGHT_REPORT"; then
  pass "preflight-report" "M14 preflight has failures=0"
else
  fail_check "preflight-report" "M14 preflight report does not show summary: failures=0"
fi

if [[ "$BRANCH_ID" =~ ^[1-9][0-9]*$ ]]; then
  pass "branch-id" "exact branch_id=$BRANCH_ID"
else
  fail_check "branch-id" "pilot rollout requires numeric exact branch_id"
fi

if [[ -z "$METRICS_PATH" || ! -f "$METRICS_PATH" ]]; then
  fail_check "metrics" "missing metrics snapshot"
else
  payment_success_rate="$(metric_value payment_success_rate || true)"
  fiscal_anomaly="$(metric_value fiscal_anomaly || true)"
  kds_error_rate="$(metric_value kds_error_rate || true)"

  [[ -n "$payment_success_rate" ]] || fail_check "payment_success_rate" "missing"
  [[ -n "$fiscal_anomaly" ]] || fail_check "fiscal_anomaly" "missing"
  [[ -n "$kds_error_rate" ]] || fail_check "kds_error_rate" "missing"

  if [[ -n "${payment_success_rate:-}" ]]; then
    if less_than "$payment_success_rate" "95"; then
      fail_check "payment_success_rate" "$payment_success_rate < 95 over 5m"
    else
      pass "payment_success_rate" "$payment_success_rate >= 95 over 5m"
    fi
  fi

  if [[ -n "${fiscal_anomaly:-}" ]]; then
    if greater_than "$fiscal_anomaly" "0"; then
      fail_check "fiscal_anomaly" "$fiscal_anomaly > 0"
    else
      pass "fiscal_anomaly" "$fiscal_anomaly == 0"
    fi
  fi

  if [[ -n "${kds_error_rate:-}" ]]; then
    if greater_than "$kds_error_rate" "5"; then
      fail_check "kds_error_rate" "$kds_error_rate > 5 over 5m"
    else
      pass "kds_error_rate" "$kds_error_rate <= 5 over 5m"
    fi
  fi
fi

append "summary: failures=$FAILURES"

if [[ -n "$REPORT_PATH" ]]; then
  [[ ! -e "$REPORT_PATH" ]] || { echo "rollout-canary-drill: refusing to overwrite report: $REPORT_PATH" >&2; exit 2; }
  mkdir -p "$(dirname "$REPORT_PATH")"
  printf '%s' "$REPORT" > "$REPORT_PATH"
fi

if [[ "$FAILURES" -gt 0 ]]; then
  exit 1
fi

exit 0
