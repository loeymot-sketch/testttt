# Execute Brief — CV1-LOT-K08-GLOBAL-ERRORS

Wave: Caisse V1 Wave 2 Option B  
Run order: 23/36  
Lot: K-08 (KIOSK)  
Status: `READY`

## Objective

Centraliser erreurs kiosk via goToKioskError(code,payload) et harmoniser CTAs.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `resources/js/components/frontend/kiosk/KioskErrorLayoutComponent.vue`
- `resources/js/components/frontend/kiosk/KioskErrorMenuUnavailableComponent.vue`
- `resources/js/components/frontend/kiosk/KioskErrorNetworkComponent.vue`
- `resources/js/components/frontend/kiosk/KioskErrorPaymentRefusedComponent.vue`
- `resources/js/components/frontend/kiosk/KioskErrorProductRemovedComponent.vue`
- `resources/js/helpers/kioskAnalytics.js`
- `resources/js/store/modules/kioskCart.js`
- `tests/js/kioskGlobalErrors.spec.js`
- `tests/Playwright/kiosk-errors.spec.js`

## Gates

- No specific gate listed; still verify docs/gates/GATE_LOG.md before frozen edits.

## Tests

- `npx vitest run tests/js/kioskGlobalErrors.spec.js`
- `npx playwright test tests/Playwright/kiosk-errors.spec.js`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-K08-GLOBAL-ERRORS execute "allowlist from input.json" "W2 K-08"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
