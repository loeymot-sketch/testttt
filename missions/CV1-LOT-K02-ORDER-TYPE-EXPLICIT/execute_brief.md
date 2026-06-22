# Execute Brief — CV1-LOT-K02-ORDER-TYPE-EXPLICIT

Wave: Caisse V1 Wave 2 Option B  
Run order: 6/36  
Lot: K-02 (KIOSK)  
Status: `READY`

## Objective

Exiger un choix order_type explicite avant catégories / wizard et bloquer submitOrder si absent.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue`
- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue`
- `resources/js/store/modules/kioskCart.js`
- `tests/js/kioskOrderTypeExplicit.spec.js`
- `tests/Playwright/kiosk-order-type-required.spec.js`

## Gates

- No specific gate listed; still verify docs/gates/GATE_LOG.md before frozen edits.

## Tests

- `npx vitest run tests/js/kioskOrderTypeExplicit.spec.js`
- `npx playwright test tests/Playwright/kiosk-order-type-required.spec.js`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-K02-ORDER-TYPE-EXPLICIT execute "allowlist from input.json" "W2 K-02"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
