#!/usr/bin/env bash
# FoodKing – manual safety check
# Run manually before every execution phase.
# Not auto-invoked. No environment variable dependencies.
# Extend FROZEN_ZONES list as the team defines new frozen files.
#
# [P1-24 sync 2026-05-16] Synced with CLAUDE.md §7 frozen zones list.
# Previously only 2 zones tracked → drift accumulated +6782 lines on
# actually-frozen files between cycles. Source of truth = CLAUDE.md §7.

set -euo pipefail

FROZEN_ZONES=(
  # --- Frontend (CLAUDE.md §7) ---
  "resources/js/components/frontend/kiosk/KioskWizardComponent.vue"
  "resources/js/components/frontend/kiosk/KioskAppComponent.vue"
  "resources/js/components/frontend/kiosk/KioskUpsellComponent.vue"
  "public/js/pos-wizard.js"
  "public/css/pos-wizard.css"
  "resources/views/admin-pos-v4.blade.php"

  # --- Backend NF525-critical (CLAUDE.md §7 + §8) ---
  "app/Services/Fiscal/FiscalSequenceService.php"
  "app/Services/Fiscal/ZReportService.php"
  "app/Services/Fiscal/AuditLogService.php"

  # --- Backend multi-tenant + payment-critical (CLAUDE.md §7 + §9) ---
  "app/Models/Scopes/BranchScope.php"
  "app/Http/Middleware/IdempotencyKeyMiddleware.php"
  "app/Services/Pricing/PricingService.php"
  "app/Domain/Order/OrderStateMachine.php"

  # --- Legacy entries (kept for backward compat with previous gate plans) ---
  "app/Services/OrderService.php"
  "app/Services/FrontendOrderService.php"
)

STAGED=$(git diff --cached --name-only 2>/dev/null || echo "")

echo "[safety-check] Checking ${#FROZEN_ZONES[@]} frozen zones..."
HIT=0
for zone in "${FROZEN_ZONES[@]}"; do
  if echo "$STAGED" | grep -q "$zone"; then
    echo "[HALT] Frozen zone staged: $zone — LOCK doc required."
    echo "       → Use /lock-plan skill OR commit LOCK_<id>.md in same PR (CLAUDE.md §7)."
    HIT=1
  fi
done
if [[ $HIT -eq 1 ]]; then
  echo "[safety-check] BLOCKED. See docs/gates/ or run /lock-plan."
  exit 1
fi
echo "[safety-check] Frozen zones: OK"

echo "[safety-check] Checking PHP syntax..."
PHP_STAGED=$(echo "$STAGED" | grep '\.php$' || true)
if [[ -n "$PHP_STAGED" ]]; then
  while IFS= read -r file; do
    [[ -f "$file" ]] && php -l "$file" || { echo "[HALT] PHP syntax error: $file"; exit 1; }
  done <<< "$PHP_STAGED"
  echo "[safety-check] PHP syntax: OK"
else
  echo "[safety-check] No staged PHP files."
fi

echo "[safety-check] Passed. Proceed with execution."
exit 0
