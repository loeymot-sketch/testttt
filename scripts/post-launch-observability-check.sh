#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

usage() {
  cat <<'USAGE'
FoodKing Caisse V1 post-launch observability checker.

Read-only. It validates that required post-launch evidence exists and fails
closed when any required report or anomaly/KPI key is absent.

Usage:
  bash scripts/post-launch-observability-check.sh --m14-preflight-report=PATH --m15-canary-report=PATH --lcp-evidence=PATH --anomaly-evidence=PATH --cadence-evidence=PATH
  bash scripts/post-launch-observability-check.sh --help

Options:
  --m14-preflight-report=PATH   Required. Must contain "summary: failures=0".
  --m15-canary-report=PATH      Required. Must contain "summary: failures=0" and payment_success_rate.
  --lcp-evidence=PATH           Required. key=value lines for pos_lcp_p95_ms, kiosk_lcp_p95_ms, kds_lcp_p95_ms.
  --anomaly-evidence=PATH       Required. key=value lines for payment/fiscal/branch/KDS anomaly rules.
  --cadence-evidence=PATH       Required. Must mention J+1, J+7, and J+30.
  --report=PATH                 Optional. Write checker report. Refuses overwrite.
  -h, --help                    Show this help.
USAGE
}

M14_PREFLIGHT_REPORT=""
M15_CANARY_REPORT=""
LCP_EVIDENCE=""
ANOMALY_EVIDENCE=""
CADENCE_EVIDENCE=""
REPORT_PATH=""
FAILURES=0
WARNINGS=0
REPORT=""

append() {
  REPORT+="$*"$'\n'
  printf '%s\n' "$*"
}

pass() {
  append "PASS $1: $2"
}

warn_check() {
  WARNINGS=$((WARNINGS + 1))
  append "WARN $1: $2"
}

fail_check() {
  FAILURES=$((FAILURES + 1))
  append "FAIL $1: $2"
}

need_file() {
  local key="$1"
  local path="$2"

  if [[ -n "$path" && -f "$path" ]]; then
    pass "$key" "$path"
  else
    fail_check "$key" "missing file: ${path:-<not provided>}"
  fi
}

contains_literal() {
  local path="$1"
  local needle="$2"
  grep -Fq "$needle" "$path"
}

metric_value() {
  local path="$1"
  local key="$2"
  awk -F= -v k="$key" '$1 == k {gsub(/^[ \t]+|[ \t]+$/, "", $2); print $2; exit}' "$path"
}

is_number() {
  awk -v n="$1" 'BEGIN { exit !(n ~ /^-?[0-9]+([.][0-9]+)?$/) }'
}

less_than() {
  awk -v a="$1" -v b="$2" 'BEGIN { exit !(a < b) }'
}

greater_than() {
  awk -v a="$1" -v b="$2" 'BEGIN { exit !(a > b) }'
}

check_positive_metric() {
  local path="$1"
  local key="$2"
  local value

  value="$(metric_value "$path" "$key")"
  if [[ -z "$value" ]]; then
    fail_check "$key" "missing"
  elif ! is_number "$value"; then
    fail_check "$key" "not numeric: $value"
  elif less_than "$value" "0"; then
    fail_check "$key" "negative value: $value"
  else
    pass "$key" "$value"
  fi
}

check_zero_anomaly() {
  local path="$1"
  local key="$2"
  local value

  value="$(metric_value "$path" "$key")"
  if [[ -z "$value" ]]; then
    fail_check "$key" "missing"
  elif ! is_number "$value"; then
    fail_check "$key" "not numeric: $value"
  elif less_than "$value" "0"; then
    fail_check "$key" "negative value: $value"
  elif greater_than "$value" "0"; then
    fail_check "$key" "$value > 0"
  else
    pass "$key" "$value"
  fi
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --m14-preflight-report=*) M14_PREFLIGHT_REPORT="${1#--m14-preflight-report=}"; shift ;;
    --m14-preflight-report) M14_PREFLIGHT_REPORT="${2:?--m14-preflight-report requires a value}"; shift 2 ;;
    --m15-canary-report=*) M15_CANARY_REPORT="${1#--m15-canary-report=}"; shift ;;
    --m15-canary-report) M15_CANARY_REPORT="${2:?--m15-canary-report requires a value}"; shift 2 ;;
    --lcp-evidence=*) LCP_EVIDENCE="${1#--lcp-evidence=}"; shift ;;
    --lcp-evidence) LCP_EVIDENCE="${2:?--lcp-evidence requires a value}"; shift 2 ;;
    --anomaly-evidence=*) ANOMALY_EVIDENCE="${1#--anomaly-evidence=}"; shift ;;
    --anomaly-evidence) ANOMALY_EVIDENCE="${2:?--anomaly-evidence requires a value}"; shift 2 ;;
    --cadence-evidence=*) CADENCE_EVIDENCE="${1#--cadence-evidence=}"; shift ;;
    --cadence-evidence) CADENCE_EVIDENCE="${2:?--cadence-evidence requires a value}"; shift 2 ;;
    --report=*) REPORT_PATH="${1#--report=}"; shift ;;
    --report) REPORT_PATH="${2:?--report requires a value}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "post-launch-observability-check: unknown option: $1" >&2; exit 2 ;;
  esac
done

cd "$ROOT_DIR"

append "FoodKing Caisse V1 post-launch observability check"
append "mode=read-only"

need_file "observability-doc" "$ROOT_DIR/docs/observability/CAISSE_V1_POST_LAUNCH_OBSERVABILITY_2026-04-25.md"
need_file "runbook" "$ROOT_DIR/reports/runbooks/RUNBOOK_POST_LAUNCH_OBSERVABILITY_2026-04-25.md"
need_file "m14-preflight-report" "$M14_PREFLIGHT_REPORT"
need_file "m15-canary-report" "$M15_CANARY_REPORT"
need_file "lcp-evidence" "$LCP_EVIDENCE"
need_file "anomaly-evidence" "$ANOMALY_EVIDENCE"
need_file "cadence-evidence" "$CADENCE_EVIDENCE"

if [[ -f "$ROOT_DIR/docs/observability/CAISSE_V1_POST_LAUNCH_OBSERVABILITY_2026-04-25.md" ]]; then
  for token in pos_lcp_p95_ms kiosk_lcp_p95_ms kds_lcp_p95_ms payment_confirm_without_ability branch_crossover noop_double_trigger fiscal_z_mismatch invalid_seal J+1 J+7 J+30; do
    contains_literal "$ROOT_DIR/docs/observability/CAISSE_V1_POST_LAUNCH_OBSERVABILITY_2026-04-25.md" "$token" \
      && pass "doc-token-$token" "declared" \
      || fail_check "doc-token-$token" "missing from observability doc"
  done
fi

if [[ -f "$M14_PREFLIGHT_REPORT" ]]; then
  contains_literal "$M14_PREFLIGHT_REPORT" "summary: failures=0" \
    && pass "m14-preflight-green" "summary: failures=0" \
    || fail_check "m14-preflight-green" "M14 preflight is not green"
fi

if [[ -f "$M15_CANARY_REPORT" ]]; then
  contains_literal "$M15_CANARY_REPORT" "summary: failures=0" \
    && pass "m15-canary-green" "summary: failures=0" \
    || fail_check "m15-canary-green" "M15 canary report is not green"
  contains_literal "$M15_CANARY_REPORT" "payment_success_rate" \
    && pass "m15-payment-success" "payment_success_rate present" \
    || fail_check "m15-payment-success" "payment_success_rate missing from M15 canary report"
fi

if [[ -f "$LCP_EVIDENCE" ]]; then
  for key in pos_lcp_p95_ms kiosk_lcp_p95_ms kds_lcp_p95_ms; do
    check_positive_metric "$LCP_EVIDENCE" "$key"
  done

  for optional_key in pos_tti_p95_ms kiosk_payment_wait_p95_ms kds_release_latency_p95_ms; do
    optional_value="$(metric_value "$LCP_EVIDENCE" "$optional_key")"
    if [[ -n "$optional_value" ]]; then
      if is_number "$optional_value"; then
        pass "$optional_key" "$optional_value"
      else
        fail_check "$optional_key" "not numeric: $optional_value"
      fi
    else
      warn_check "$optional_key" "optional KPI absent from packet"
    fi
  done
fi

if [[ -f "$ANOMALY_EVIDENCE" ]]; then
  for key in payment_confirm_without_ability branch_crossover noop_double_trigger fiscal_z_mismatch invalid_seal; do
    check_zero_anomaly "$ANOMALY_EVIDENCE" "$key"
  done

  kds_error_rate="$(metric_value "$ANOMALY_EVIDENCE" kds_error_rate)"
  if [[ -z "$kds_error_rate" ]]; then
    fail_check "kds_error_rate" "missing"
  elif ! is_number "$kds_error_rate"; then
    fail_check "kds_error_rate" "not numeric: $kds_error_rate"
  elif greater_than "$kds_error_rate" "5"; then
    fail_check "kds_error_rate" "$kds_error_rate > 5"
  else
    pass "kds_error_rate" "$kds_error_rate <= 5"
  fi

  canary_payment_success_rate="$(metric_value "$ANOMALY_EVIDENCE" canary_payment_success_rate)"
  if [[ -z "$canary_payment_success_rate" ]]; then
    fail_check "canary_payment_success_rate" "missing"
  elif ! is_number "$canary_payment_success_rate"; then
    fail_check "canary_payment_success_rate" "not numeric: $canary_payment_success_rate"
  elif less_than "$canary_payment_success_rate" "95"; then
    fail_check "canary_payment_success_rate" "$canary_payment_success_rate < 95"
  else
    pass "canary_payment_success_rate" "$canary_payment_success_rate >= 95"
  fi
fi

if [[ -f "$CADENCE_EVIDENCE" ]]; then
  for marker in J+1 J+7 J+30; do
    contains_literal "$CADENCE_EVIDENCE" "$marker" \
      && pass "cadence-$marker" "present" \
      || fail_check "cadence-$marker" "missing from cadence evidence"
  done
fi

append "summary: failures=$FAILURES warnings=$WARNINGS"

if [[ -n "$REPORT_PATH" ]]; then
  [[ ! -e "$REPORT_PATH" ]] || { echo "post-launch-observability-check: refusing to overwrite report: $REPORT_PATH" >&2; exit 2; }
  mkdir -p "$(dirname "$REPORT_PATH")"
  printf '%s' "$REPORT" > "$REPORT_PATH"
fi

if [[ "$FAILURES" -gt 0 ]]; then
  exit 1
fi

exit 0
