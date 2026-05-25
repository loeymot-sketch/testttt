#!/usr/bin/env bash
# =============================================================================
# [V1.0.2 Wave C2 UR4-007 2026-05-25]
#
# FoodKing pre-deploy ship-readiness gate.
#
# Run from project root BEFORE rsync/deploy. Exits non-zero on any
# failed check — deploy.sh / CI / manual operator must NOT proceed.
#
# Checks performed:
#   1. PHP version >= 8.2
#   2. composer.lock matches composer.json (no drift)
#   3. package-lock.json (or yarn.lock) present
#   4. .env exists with APP_KEY
#   5. NF525 chain clean (fiscal:assert-chain-clean if present,
#      else fall back to fiscal:verify-chain --all)
#   6. Production boot guards intact (app:preflight-production)
#   7. Frozen-zone diff vs main = empty in protected paths
#   8. Vitest sentinels all pass (bundle staleness + branch-scope)
#   9. PHPUnit smoke pass (Sentinel filter)
#  10. Vendor dir clean (no .git directories)
#  11. Migrations up-to-date (no pending)
#  12. Queue config = redis/database (not sync/null for prod)
#
# Usage:
#   bash scripts/deploy/pre-flight.sh
#   bash scripts/deploy/pre-flight.sh --dry-run   # report only, don't fail
#
# Exit codes:
#   0 = all checks pass — safe to deploy
#   1 = at least one check failed — refuse to deploy
#   2 = invalid invocation
# =============================================================================

set -uo pipefail
# NOTE: NOT using `set -e` — we want to run ALL checks then summarize.

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$PROJECT_ROOT"

DRY_RUN=0
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    *) echo "Unknown arg: $arg"; exit 2 ;;
  esac
done

echo "================================================"
echo "FoodKing Pre-Deploy Ship-Readiness Gate"
echo "Project root : $PROJECT_ROOT"
echo "Dry-run mode : $DRY_RUN"
echo "================================================"

FAILED=0
# Parallel arrays (bash 3.2 compatible — no assoc arrays on macOS default bash)
CHECK_NAMES=()
CHECK_RESULTS=()

check() {
  local name="$1"
  local cmd="$2"
  echo ""
  echo "----------------------------------------------"
  echo "-> $name"
  echo "----------------------------------------------"
  if eval "$cmd"; then
    CHECK_NAMES+=("$name")
    CHECK_RESULTS+=("PASS")
  else
    CHECK_NAMES+=("$name")
    CHECK_RESULTS+=("FAIL")
    FAILED=$((FAILED + 1))
  fi
}

# 1. PHP version
check "PHP version >= 8.2" '
  php -r "echo PHP_VERSION . PHP_EOL; exit(version_compare(PHP_VERSION, \"8.2.0\", \">=\") ? 0 : 1);"
'

# 2. composer.lock fresh
check "composer.lock matches composer.json" '
  composer validate --no-check-publish --strict 2>&1 | tail -3
  [ "${PIPESTATUS[0]}" -eq 0 ]
'

# 3. package-lock.json — skip if no package-lock (Laravel Mix sometimes uses yarn.lock)
check "package-lock or yarn.lock present" '
  if [ -f package-lock.json ]; then
    echo "package-lock.json found"
    true
  elif [ -f yarn.lock ]; then
    echo "yarn.lock found"
    true
  else
    echo "Neither package-lock.json nor yarn.lock found"
    false
  fi
'

# 4. .env file basic check (production env should have APP_KEY)
check ".env exists with APP_KEY" '
  if [ ! -f .env ]; then
    echo ".env not found at project root"
    false
  elif ! grep -q "^APP_KEY=base64:" .env; then
    echo ".env present but APP_KEY missing or not base64-encoded"
    false
  else
    echo ".env present + APP_KEY OK"
    true
  fi
'

# 5. NF525 chain assert (Wave C1 command preferred; fallback to fiscal:verify-chain --all)
check "NF525 chain clean" '
  if php artisan list 2>/dev/null | grep -q "fiscal:assert-chain-clean"; then
    php artisan fiscal:assert-chain-clean 2>&1 | tail -5
    [ "${PIPESTATUS[0]}" -eq 0 ]
  else
    echo "fiscal:assert-chain-clean not registered — falling back to fiscal:verify-chain --all"
    php artisan fiscal:verify-chain --all 2>&1 | tail -10
    [ "${PIPESTATUS[0]}" -eq 0 ]
  fi
'

# 6. Production boot guards
check "Production boot guards (app:preflight-production)" '
  php artisan app:preflight-production 2>&1 | tail -8
  [ "${PIPESTATUS[0]}" -eq 0 ]
'

# 7. Frozen-zone unchanged vs main
check "Frozen-zone untouched vs main" '
  CHANGED=$(git diff main...HEAD --name-only 2>/dev/null | grep -E "PaymentComponent\.vue|PosV5TrancheRow\.vue|KioskWizardComponent\.vue|KioskAppComponent\.vue|KioskUpsellComponent\.vue|pos-wizard\.(js|css)|FiscalSequenceService\.php|ZReportService\.php|AuditLogService\.php|BranchScope\.php|IdempotencyKeyMiddleware\.php|PricingService\.php|OrderStateMachine\.php" || true)
  if [ -n "$CHANGED" ]; then
    echo "FROZEN-ZONE CHANGES DETECTED vs main:"
    echo "$CHANGED"
    false
  else
    echo "No frozen-zone path modified vs main"
    true
  fi
'

# 8. Vitest sentinels
check "Vitest sentinels pass (bundle freshness etc.)" '
  if [ ! -d tests/js/sentinels ]; then
    echo "tests/js/sentinels directory not found"
    false
  else
    npx vitest run tests/js/sentinels/ --reporter=basic 2>&1 | tail -15
    [ "${PIPESTATUS[0]}" -eq 0 ]
  fi
'

# 9. PHPUnit smoke
check "PHPUnit smoke (Sentinel filter)" '
  php artisan test --filter="Sentinel" 2>&1 | tail -12
  [ "${PIPESTATUS[0]}" -eq 0 ]
'

# 10. Vendor clean (no .git in deps)
check "Vendor dir clean (no .git in deps)" '
  if [ ! -d vendor ]; then
    echo "vendor/ not present — run composer install first"
    false
  else
    FOUND=$(find vendor -type d -name ".git" 2>/dev/null | head -3)
    if [ -n "$FOUND" ]; then
      echo "Found stray .git directories inside vendor/:"
      echo "$FOUND"
      false
    else
      echo "vendor/ clean (no .git directories)"
      true
    fi
  fi
'

# 11. Migrations up-to-date
check "Migrations up-to-date (no pending)" '
  STATUS=$(php artisan migrate:status 2>&1)
  if echo "$STATUS" | grep -qi "Pending"; then
    echo "$STATUS" | grep -i "Pending" | head -10
    false
  else
    PENDING_COUNT=$(echo "$STATUS" | grep -cE "^\s*Pending" || true)
    echo "No pending migrations detected (pending count: $PENDING_COUNT)"
    true
  fi
'

# 12. Queue config not sync/null
check "Queue config production-ready (not sync/null)" '
  QC=$(grep -E "^QUEUE_CONNECTION=" .env 2>/dev/null | head -1 | cut -d= -f2 | tr -d "\"" | tr -d "[:space:]")
  echo "QUEUE_CONNECTION=$QC"
  if [ -z "$QC" ]; then
    echo "QUEUE_CONNECTION missing from .env"
    false
  elif [ "$QC" = "sync" ] || [ "$QC" = "null" ]; then
    echo "QUEUE_CONNECTION must NOT be sync/null in production"
    false
  else
    true
  fi
'

echo ""
echo "================================================"
echo "SUMMARY"
echo "================================================"
for i in "${!CHECK_NAMES[@]}"; do
  result="${CHECK_RESULTS[$i]}"
  if [ "$result" = "PASS" ]; then
    marker="[PASS]"
  else
    marker="[FAIL]"
  fi
  printf "%-55s %s\n" "${CHECK_NAMES[$i]}" "$marker"
done
echo "------------------------------------------------"
echo "Total failures : $FAILED"

if [ "$FAILED" -gt 0 ]; then
  if [ "$DRY_RUN" -eq 1 ]; then
    echo "DRY-RUN : would exit 1, but --dry-run forces exit 0"
    exit 0
  fi
  echo "PRE-FLIGHT FAILED — REFUSING TO DEPLOY"
  exit 1
fi

echo "PRE-FLIGHT PASSED — safe to deploy"
exit 0
