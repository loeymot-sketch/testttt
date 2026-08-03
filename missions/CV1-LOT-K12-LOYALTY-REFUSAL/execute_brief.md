# Execute Brief — CV1-LOT-K12-LOYALTY-REFUSAL

Wave: Caisse V1 Wave 2 Option B  
Run order: 31/36  
Lot: K-12 (KIOSK)  
Status: `READY_WITH_FROZEN_READONLY_CHECK`

## Objective

Exposer loyalty_refusal_reason dans OrderDetailsResource et UI toast clair.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `app/Http/Resources/OrderDetailsResource.php`
- `resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue`
- `resources/js/components/frontend/kiosk/KioskCartComponent.vue`
- `tests/Feature/LoyaltyRefusalReasonTest.php`
- `tests/Playwright/loyalty-refused.spec.js`

## Gates

- No specific gate listed; still verify docs/gates/GATE_LOG.md before frozen edits.

## Tests

- `php artisan test --filter='LoyaltyRefusalReasonTest|LoyaltyApiTest'`
- `npx playwright test tests/Playwright/loyalty-refused.spec.js`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-K12-LOYALTY-REFUSAL execute "allowlist from input.json" "W2 K-12"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
