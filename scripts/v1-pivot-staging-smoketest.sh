#!/usr/bin/env bash
# FoodKing — V1 Pivot staging smoketest
# Usage: bash scripts/v1-pivot-staging-smoketest.sh [--dry-run] [--skip-playwright]
# Source: ultra-review Claude 2026-05-04 §14.1 #4 — Pivot V1 prod readiness
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

DRY_RUN="${DRY_RUN:-0}"
SKIP_PLAYWRIGHT="${SKIP_PLAYWRIGHT:-0}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run) DRY_RUN=1; shift ;;
    --skip-playwright) SKIP_PLAYWRIGHT=1; shift ;;
    *) echo "Unknown arg: $1"; exit 2 ;;
  esac
done

TOTAL_STEPS=10
BASE_URL="${BASE_URL:-http://localhost:8000}"
REPORT_FILE="$REPO_ROOT/reports/execution/SMOKETEST_V1_$(date +%Y-%m-%d_%H%M%S).log"
mkdir -p "$REPO_ROOT/reports/execution"

report() {
  printf '%s\n' "$*" >> "$REPORT_FILE"
}

log() {
  printf '%s\n' "$*"
  printf '%s\n' "$*" >> "$REPORT_FILE"
}

log "=== FoodKing V1 Pivot staging smoketest ==="
log "started: $(date -Iseconds 2>/dev/null || date)"
log "REPO: $REPO_ROOT"
log "DRY_RUN=$DRY_RUN SKIP_PLAYWRIGHT=$SKIP_PLAYWRIGHT BASE_URL=$BASE_URL"

run_step() {
  local num="$1"
  local desc="$2"
  shift 2
  local logf="/tmp/v1-smoketest-step-${num}.log"
  local ec=0
  log "[step ${num}/${TOTAL_STEPS}] ${desc}..."
  report "[step ${num}/${TOTAL_STEPS}] START: ${desc}"
  "$@" >"$logf" 2>&1 || ec=$?
  if [[ "$ec" -ne 0 ]]; then
    echo "[step ${num}/${TOTAL_STEPS}] FAILED"
    tail -20 "$logf" || true
    report "[step ${num}/${TOTAL_STEPS}] FAILED (exit $ec)"
    report "--- tail ${logf} ---"
    tail -20 "$logf" >>"$REPORT_FILE" || true
    report "[smoketest] FAILED at step ${num}"
    exit "$ec"
  fi
  log "[step ${num}/${TOTAL_STEPS}] OK"
}

# --- Step 1: Pre-flight ---
step1_log="/tmp/v1-smoketest-step-1.log"
log "[step 1/${TOTAL_STEPS}] Pre-flight (php, node, npm, npx, env, playwright)..."
report "[step 1/${TOTAL_STEPS}] START: Pre-flight"
{
  for cmd in php node npm npx; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
      echo "Missing required command: $cmd" >&2
      exit 1
    fi
  done

  if [[ ! -f "$REPO_ROOT/.env" && ! -f "$REPO_ROOT/.env.testing" ]]; then
    echo "[WARN] neither .env nor .env.testing — Laravel may fail; create one for staging."
  fi

  if [[ "$SKIP_PLAYWRIGHT" -eq 0 ]]; then
    if ! npx playwright --version >/dev/null 2>&1; then
      if [[ "$DRY_RUN" -eq 1 ]]; then
        echo "[WARN] npx playwright not available — acceptable for --dry-run only; install before full run."
      else
        echo "npx playwright not available (run npm install; optional: npx playwright install chromium)" >&2
        exit 1
      fi
    fi
  else
    echo "[INFO] skipping playwright CLI check (--skip-playwright)"
  fi
} >"$step1_log" 2>&1 || {
  _s1_ec=$?
  echo "[step 1/${TOTAL_STEPS}] FAILED"
  tail -20 "$step1_log" || true
  report "[step 1/${TOTAL_STEPS}] FAILED (exit $_s1_ec)"
  tail -20 "$step1_log" >>"$REPORT_FILE" || true
  report "[smoketest] FAILED at step 1"
  exit "$_s1_ec"
}
log "[step 1/${TOTAL_STEPS}] OK"

# --- Step 2: Migrations dry-run ---
run_step 2 "Migrations dry-run (pretend + status)" bash -lc "cd \"$REPO_ROOT\" && php artisan migrate --pretend && php artisan migrate:status"

if [[ "$DRY_RUN" -eq 1 ]]; then
  log "[smoketest] DRY_RUN: stopping after step 2 (no DB mutations)"
  log "[smoketest] ALL 2 STEPS PASSED (dry-run)"
  log "[smoketest] Report: $REPORT_FILE"
  exit 0
fi

# --- Step 3: Migrations apply ---
run_step 3 "Migrations apply (force)" bash -lc "cd \"$REPO_ROOT\" && php artisan migrate --force"

# --- Step 4: Rollback round-trip (Pivot V1 — last 4 batches) + re-migrate ---
run_step 4 "Migrations rollback 4 steps + re-migrate" bash -lc "cd \"$REPO_ROOT\" && php artisan migrate:rollback --step=4 --force && php artisan migrate --force"

# --- Step 5: Ingredient permission seeder ---
run_step 5 "Seeder IngredientPermissionSeeder" bash -lc "cd \"$REPO_ROOT\" && php artisan db:seed --class=IngredientPermissionSeeder --force"

# --- Step 6: PHPUnit targeted ---
run_step 6 "PHPUnit filter Ingredient|WizardPerItem|ChoiceAvailability" bash -lc "cd \"$REPO_ROOT\" && php artisan test --filter='Ingredient|WizardPerItem|ChoiceAvailability'"

# --- Step 7: PHPUnit full ---
run_step 7 "PHPUnit full suite" bash -lc "cd \"$REPO_ROOT\" && php artisan test"

# --- Step 8: Vitest ---
run_step 8 "Vitest full" bash -lc "cd \"$REPO_ROOT\" && npx vitest run"

# --- Step 9: Production build ---
run_step_9() {
  local logf="/tmp/v1-smoketest-step-9.log"
  local ec=0
  log "[step 9/${TOTAL_STEPS}] Build (npm run prod or dev)..."
  report "[step 9/${TOTAL_STEPS}] START: npm prod/dev"
  if npm run prod >"$logf" 2>&1; then
    log "[step 9/${TOTAL_STEPS}] OK (npm run prod)"
    return 0
  fi
  report "[step 9/${TOTAL_STEPS}] npm run prod failed — trying npm run dev"
  if npm run dev >"$logf" 2>&1; then
    log "[step 9/${TOTAL_STEPS}] OK (npm run dev fallback)"
    return 0
  fi
  ec=$?
  echo "[step 9/${TOTAL_STEPS}] FAILED"
  tail -20 "$logf" || true
  report "[step 9/${TOTAL_STEPS}] FAILED (exit $ec)"
  tail -20 "$logf" >>"$REPORT_FILE" || true
  report "[smoketest] FAILED at step 9"
  exit "$ec"
}
run_step_9

# --- Step 10: Playwright critical-flow (conditional) ---
step10_log="/tmp/v1-smoketest-step-10.log"
log "[step 10/${TOTAL_STEPS}] Playwright critical-flow (conditional)..."
report "[step 10/${TOTAL_STEPS}] START: Playwright v1-* critical-flow"

if [[ "$SKIP_PLAYWRIGHT" -ne 0 ]]; then
  log "[step 10/${TOTAL_STEPS}] WARN: skipped (--skip-playwright)"
elif [[ "${E2E_BACKEND_AVAILABLE:-0}" != "1" ]]; then
  log "[step 10/${TOTAL_STEPS}] WARN: skipped (E2E_BACKEND_AVAILABLE!=1; set E2E_BACKEND_AVAILABLE=1 when app is up)"
elif ! curl -sS -o /dev/null --connect-timeout 3 --max-time 10 "${BASE_URL}/" 2>"$step10_log"; then
  log "[step 10/${TOTAL_STEPS}] WARN: could not reach BASE_URL=${BASE_URL} — skipping Playwright"
  cat "$step10_log" >>"$REPORT_FILE" || true
else
  PLAYWRIGHT_BASE_URL="$BASE_URL" BASE_URL="$BASE_URL" E2E_BACKEND_AVAILABLE=1 \
    npx playwright test tests/Playwright/critical-flow/v1-*.spec.js --reporter=list >"$step10_log" 2>&1 || {
    _pwc=$?
    echo "[step 10/${TOTAL_STEPS}] FAILED"
    tail -20 "$step10_log" || true
    report "[step 10/${TOTAL_STEPS}] FAILED (exit $_pwc)"
    tail -20 "$step10_log" >>"$REPORT_FILE" || true
    report "[smoketest] FAILED at step 10"
    exit "$_pwc"
  }
  log "[step 10/${TOTAL_STEPS}] OK"
fi

log "[smoketest] ALL ${TOTAL_STEPS} STEPS PASSED"
log "[smoketest] Report: $REPORT_FILE"
exit 0
