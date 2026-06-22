# Execute Brief — CV1-LOT-K06-OFFLINE-WAITING-UX

Wave: Caisse V1 Wave 2 Option B  
Run order: 18/36  
Lot: K-06 (KIOSK)  
Status: `READY_GATE_APPROVED`

## Objective

Guard waiting offline_ IDs and show sync-pending UX without polling backend.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`
- `resources/js/router/modules/kioskRoutes.js`
- `resources/js/helpers/kioskOfflineQueue.js`
- `tests/js/sentinels/kioskOfflineIdPrefix.spec.js`
- `tests/Playwright/kiosk-offline-waiting.spec.js`

## Gates

- GATE_OFFLINE_SCOPE_V1_2026-04-25: Approved Option A

## Tests

- `npx vitest run tests/js/sentinels/kioskOfflineIdPrefix.spec.js`
- `npx playwright test tests/Playwright/kiosk-offline-waiting.spec.js`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-K06-OFFLINE-WAITING-UX execute "allowlist from input.json" "W2 K-06"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
