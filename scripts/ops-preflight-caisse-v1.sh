#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

usage() {
  cat <<'USAGE'
FoodKing Caisse V1 ops preflight.

Read-only by default. It verifies that the deployment has explicit evidence
for queue/scheduler/workers/broadcast/cache/outbox/fiscal archive and refuses
production GO when staging migration rehearsal evidence is missing.

Usage:
  bash scripts/ops-preflight-caisse-v1.sh --staging-rehearsal-transcript=PATH --branch-leak-evidence=PATH
  bash scripts/ops-preflight-caisse-v1.sh --help

Options:
  --staging-rehearsal-transcript=PATH   Required for GO. Must include dry-run, migrate, rollback, migrate.
  --branch-leak-evidence=PATH           Required for GO. Must document exact branch_id checks.
  --strict                              Treat warnings as failures.
  --report=PATH                         Write the text report to PATH. Refuses overwrite.
  -h, --help                            Show this help.
USAGE
}

STRICT=0
REPORT_PATH=""
STAGING_REHEARSAL_TRANSCRIPT=""
BRANCH_LEAK_EVIDENCE=""
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

warn() {
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
  if [[ -f "$path" ]]; then
    pass "$key" "$path"
  else
    fail_check "$key" "missing file: $path"
  fi
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --staging-rehearsal-transcript=*) STAGING_REHEARSAL_TRANSCRIPT="${1#--staging-rehearsal-transcript=}"; shift ;;
    --staging-rehearsal-transcript) STAGING_REHEARSAL_TRANSCRIPT="${2:?--staging-rehearsal-transcript requires a value}"; shift 2 ;;
    --branch-leak-evidence=*) BRANCH_LEAK_EVIDENCE="${1#--branch-leak-evidence=}"; shift ;;
    --branch-leak-evidence) BRANCH_LEAK_EVIDENCE="${2:?--branch-leak-evidence requires a value}"; shift 2 ;;
    --report=*) REPORT_PATH="${1#--report=}"; shift ;;
    --report) REPORT_PATH="${2:?--report requires a value}"; shift 2 ;;
    --strict) STRICT=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "ops-preflight: unknown option: $1" >&2; exit 2 ;;
  esac
done

cd "$ROOT_DIR"

append "FoodKing Caisse V1 ops preflight"
append "mode=read-only"

need_file "artisan" "$ROOT_DIR/artisan"
need_file "queue-config" "$ROOT_DIR/config/queue.php"
need_file "broadcast-config" "$ROOT_DIR/config/broadcasting.php"
need_file "cache-config" "$ROOT_DIR/config/cache.php"
need_file "preflight-command" "$ROOT_DIR/app/Console/Commands/PreflightProductionCommand.php"
need_file "outbox-rescue-command" "$ROOT_DIR/app/Console/Commands/OutboxRescueCommand.php"
need_file "outbox-retry-command" "$ROOT_DIR/app/Console/Commands/OutboxRetryFailedCommand.php"
need_file "fiscal-archive-command" "$ROOT_DIR/app/Console/Commands/FiscalArchiveCommand.php"
need_file "scheduler-kernel" "$ROOT_DIR/app/Console/Kernel.php"
need_file "horizon-config" "$ROOT_DIR/config/horizon.php"

if [[ -f "$ROOT_DIR/app/Console/Kernel.php" ]]; then
  grep -q "foodking:outbox:rescue" "$ROOT_DIR/app/Console/Kernel.php" \
    && pass "scheduler-outbox-rescue" "foodking:outbox:rescue scheduled" \
    || fail_check "scheduler-outbox-rescue" "foodking:outbox:rescue not found in Kernel schedule"
  grep -q "foodking:fiscal:archive" "$ROOT_DIR/app/Console/Kernel.php" \
    && pass "scheduler-fiscal-archive" "foodking:fiscal:archive scheduled" \
    || fail_check "scheduler-fiscal-archive" "foodking:fiscal:archive not found in Kernel schedule"
  grep -q "onOneServer" "$ROOT_DIR/app/Console/Kernel.php" \
    && pass "scheduler-singleton" "onOneServer present" \
    || fail_check "scheduler-singleton" "onOneServer missing"
fi

if command -v php >/dev/null 2>&1; then
  pass "php" "$(command -v php)"
else
  fail_check "php" "php binary not found"
fi

if [[ -f "$ROOT_DIR/artisan" ]]; then
  if php artisan list --raw >/tmp/foodking-ops-preflight-artisan-list.$$ 2>/tmp/foodking-ops-preflight-artisan-list.err.$$; then
    for command_name in \
      "app:preflight-production" \
      "foodking:outbox:rescue" \
      "foodking:outbox:retry-failed" \
      "foodking:fiscal:archive" \
      "queue:work" \
      "schedule:list"; do
      grep -Eq "^${command_name}[[:space:]]" /tmp/foodking-ops-preflight-artisan-list.$$ \
        && pass "artisan-$command_name" "registered" \
        || fail_check "artisan-$command_name" "not registered"
    done
  else
    fail_check "artisan-list" "$(cat /tmp/foodking-ops-preflight-artisan-list.err.$$ 2>/dev/null || true)"
  fi
  rm -f /tmp/foodking-ops-preflight-artisan-list.$$ /tmp/foodking-ops-preflight-artisan-list.err.$$
fi

if [[ -z "$STAGING_REHEARSAL_TRANSCRIPT" ]]; then
  fail_check "staging-rehearsal-transcript" "missing --staging-rehearsal-transcript; production GO blocked"
elif [[ ! -f "$STAGING_REHEARSAL_TRANSCRIPT" ]]; then
  fail_check "staging-rehearsal-transcript" "file not found: $STAGING_REHEARSAL_TRANSCRIPT"
else
  grep -q "dry-run.sh" "$STAGING_REHEARSAL_TRANSCRIPT" \
    && grep -q "migrate:rollback" "$STAGING_REHEARSAL_TRANSCRIPT" \
    && grep -q "migrate --force" "$STAGING_REHEARSAL_TRANSCRIPT" \
    && pass "staging-rehearsal-transcript" "contains dry-run + Up/Down/Up evidence" \
    || fail_check "staging-rehearsal-transcript" "must include dry-run.sh, migrate --force, and migrate:rollback"
fi

if [[ -z "$BRANCH_LEAK_EVIDENCE" ]]; then
  fail_check "branch-leak-evidence" "missing --branch-leak-evidence; exact branch_id proof required"
elif [[ ! -f "$BRANCH_LEAK_EVIDENCE" ]]; then
  fail_check "branch-leak-evidence" "file not found: $BRANCH_LEAK_EVIDENCE"
else
  if grep -qi "branch_id" "$BRANCH_LEAK_EVIDENCE" && ! grep -Eqi "branch_id[[:space:]]+LIKE|LIKE[[:space:]]+.*branch_id" "$BRANCH_LEAK_EVIDENCE"; then
    pass "branch-leak-evidence" "exact branch_id evidence present without LIKE/prefix proof"
  else
    fail_check "branch-leak-evidence" "must document exact branch_id checks and must not use LIKE/prefix proof"
  fi
fi

if [[ "$STRICT" -eq 1 && "$WARNINGS" -gt 0 ]]; then
  fail_check "strict" "$WARNINGS warning(s) treated as failure"
fi

append "summary: failures=$FAILURES warnings=$WARNINGS"

if [[ -n "$REPORT_PATH" ]]; then
  [[ ! -e "$REPORT_PATH" ]] || { echo "ops-preflight: refusing to overwrite report: $REPORT_PATH" >&2; exit 2; }
  mkdir -p "$(dirname "$REPORT_PATH")"
  printf '%s' "$REPORT" > "$REPORT_PATH"
fi

if [[ "$FAILURES" -gt 0 ]]; then
  exit 1
fi

exit 0
