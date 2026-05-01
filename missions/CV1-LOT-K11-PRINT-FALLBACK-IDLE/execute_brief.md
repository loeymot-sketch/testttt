# Execute Brief — CV1-LOT-K11-PRINT-FALLBACK-IDLE

Wave: Caisse V1 Wave 2 Option B  
Run order: 29/36  
Lot: K-11 (KIOSK)  
Status: `READY`

## Objective

Retry imprimante 1x si off et ne jamais bloquer retour idle.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue`
- `resources/js/helpers/kioskPrinter.js`
- `resources/js/helpers/kioskReceiptPersistence.js`
- `tests/js/kioskPrinter.spec.js`
- `tests/js/kioskConfirmationFallback.spec.js`
- `tests/Playwright/kiosk-printer-off.spec.js`

## Gates

- No specific gate listed; still verify docs/gates/GATE_LOG.md before frozen edits.

## Tests

- `npx vitest run tests/js/kioskPrinter.spec.js tests/js/kioskConfirmationFallback.spec.js`
- `npx playwright test tests/Playwright/kiosk-printer-off.spec.js`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-K11-PRINT-FALLBACK-IDLE execute "allowlist from input.json" "W2 K-11"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
