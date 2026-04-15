#!/usr/bin/env bash
# FoodKing – post-execute hook
# Run after execution phase completes, before Composer validate phase.
# Writes to timestamped log and stable latest pointer. Never mutates plan files or ACTIVE_CYCLE.md.

set -uo pipefail

TASK_ID="${FOODKING_TASK_ID:-unknown}"
DATE=$(date +"%Y%m%d_%H%M%S")
REPORT_DIR="reports"
LOG_FILE="${REPORT_DIR}/test_${TASK_ID}_${DATE}.log"
LATEST_FILE="${REPORT_DIR}/post_execute_latest.log"

mkdir -p "$REPORT_DIR"

# Write to timestamped log, mirror to latest
log() {
  echo "$1" | tee -a "$LOG_FILE" >> "$LATEST_FILE"
  echo "$1"
}

# Reset latest log for this run
> "$LATEST_FILE"

log "[post-execute] task: $TASK_ID"
log "[post-execute] timestamp: $DATE"
log "[post-execute] timestamped log: $LOG_FILE"

# 1. PHP / Laravel test suite
if command -v php &>/dev/null && [[ -f "artisan" ]]; then
  log "[post-execute] running: php artisan test --stop-on-failure"
  if php artisan test --stop-on-failure >> "$LOG_FILE" 2>&1; then
    cat "$LOG_FILE" >> "$LATEST_FILE" 2>/dev/null || true
    log "[post-execute] tests: PASSED"
  else
    cat "$LOG_FILE" >> "$LATEST_FILE" 2>/dev/null || true
    log "[post-execute] tests: FAILED — see $LOG_FILE"
  fi
else
  log "[post-execute] tests: SKIPPED — php or artisan not available"
fi

# 2. Frontend lint
if [[ -f "package.json" ]] && command -v npm &>/dev/null; then
  if grep -q '"lint"' package.json; then
    log "[post-execute] running: npm run lint"
    if npm run lint >> "$LOG_FILE" 2>&1; then
      log "[post-execute] lint: PASSED"
    else
      log "[post-execute] lint: FAILED — see $LOG_FILE"
    fi
  else
    log "[post-execute] lint: SKIPPED — no lint script in package.json"
  fi
else
  log "[post-execute] lint: SKIPPED — npm or package.json not available"
fi

# 3. Playwright E2E (Phase 3 — conditionnel sur la stratégie de test du plan)
# Déclenché uniquement si le plan déclare playwright-mcp, playwright-critical-flow,
# ou playwright-full-e2e. Sinon : skipped silencieusement.
PLAYWRIGHT_STRATEGY=""
ACTIVE_CYCLE=".cursor/ACTIVE_CYCLE.md"

if [[ -f "$ACTIVE_CYCLE" ]]; then
  PLAN_FILE=$(grep "^PLAN_FILE:" "$ACTIVE_CYCLE" | awk '{print $2}' | xargs 2>/dev/null || true)
  if [[ -n "${PLAN_FILE:-}" && -f "$PLAN_FILE" ]]; then
    PLAYWRIGHT_STRATEGY=$(grep -ioE "playwright-mcp|playwright-critical-flow|playwright-full-e2e" \
      "$PLAN_FILE" 2>/dev/null | head -1 || true)
  fi
fi

if [[ -n "$PLAYWRIGHT_STRATEGY" ]]; then
  log "[post-execute] playwright: strategy=$PLAYWRIGHT_STRATEGY"
  log "PLAYWRIGHT_REQUIRED: $PLAYWRIGHT_STRATEGY"
  log "[post-execute] playwright: BASE_URL=http://localhost:8000"

  # Flows critiques FoodKing à couvrir (référence pour le validateur MCP)
  log "[post-execute] playwright: flows à valider via MCP tools :"
  log "[post-execute]   1. POS Cash  — /admin/pos        → login caissier → item → cash → KDS"
  log "[post-execute]   2. POS Card  — /admin/pos        → login caissier → item → card → KDS"
  log "[post-execute]   3. Kiosk     — /kiosk            → idle → type → menu → paiement → OSS"
  log "[post-execute]   4. KDS       — /kds              → login chef → status PREPARING → PREPARED → OSS"
  log "[post-execute]   5. Auth F5   — /admin/pos        → F5 → authcheck → surface correcte (pas /home)"

  # Playwright CLI si des fichiers de test existent
  if command -v npx &>/dev/null; then
    if [[ -f "playwright.config.js" || -f "playwright.config.ts" || -d "tests/e2e" ]]; then
      log "[post-execute] playwright-cli: running npx playwright test"
      mkdir -p reports/antigravity
      if npx playwright test \
          --reporter=json \
          --output="reports/antigravity/playwright-${TASK_ID}-${DATE}.json" \
          >> "$LOG_FILE" 2>&1; then
        log "[post-execute] playwright-cli: PASSED — see reports/antigravity/"
      else
        log "[post-execute] playwright-cli: FAILED or partial — MCP validation still required"
      fi
    else
      log "[post-execute] playwright-cli: SKIPPED — pas de config playwright ni tests/e2e/"
      log "[post-execute] playwright: utiliser Playwright MCP tools dans la phase VALIDATE"
    fi
  else
    log "[post-execute] playwright-cli: SKIPPED — npx non disponible"
  fi
else
  log "[post-execute] playwright: SKIPPED — aucune stratégie playwright déclarée dans le plan"
fi

log "[post-execute] done — invoke Composer validate phase next"
exit 0
