# Execute Brief — CV1-LOT-K04-PAYMENT-UX-OFFLINE

Wave: Caisse V1 Wave 2 Option B  
Run order: 12/36  
Lot: K-04 (KIOSK)  
Status: `READY_GATE_APPROVED`

## Objective

Clarifier UX cash/card/TR et désactiver CB/TR offline selon GATE_OFFLINE_SCOPE Option A.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`
- `resources/js/store/modules/kioskCart.js`
- `resources/js/helpers/kioskOfflineQueue.js`
- `tests/js/kioskCartOfflinePaymentScope.spec.js`
- `tests/Feature/KioskOfflinePaymentScopeTest.php`
- `tests/Playwright/sentinels/kioskCbTrOfflineRefused.spec.js`

## Gates

- GATE_OFFLINE_SCOPE_V1_2026-04-25: Approved Option A read-only menu, payment disabled

## Tests

- `php artisan test --filter=KioskOfflinePaymentScopeTest`
- `npx vitest run tests/js/kioskCartOfflinePaymentScope.spec.js`
- `npx playwright test tests/Playwright/sentinels/kioskCbTrOfflineRefused.spec.js`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-K04-PAYMENT-UX-OFFLINE execute "allowlist from input.json" "W2 K-04"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
