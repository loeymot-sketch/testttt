# Execute Brief — CV1-LOT-P12-RT-RESYNC

Wave: Caisse V1 Wave 2 Option B  
Run order: 30/36  
Lot: P-12 (POS)  
Status: `READY`

## Objective

Resync POS au focus tab et dedupe per-tab fiable.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `resources/js/services/realtime/PosFeed.js`
- `resources/js/store/modules/posOrder.js`
- `tests/js/realtime-dedupe.spec.js`
- `tests/Playwright/pos-realtime-multitab.spec.js`

## Gates

- No specific gate listed; still verify docs/gates/GATE_LOG.md before frozen edits.

## Tests

- `npx vitest run tests/js/realtime-dedupe.spec.js`
- `npx playwright test tests/Playwright/pos-realtime-multitab.spec.js`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-P12-RT-RESYNC execute "allowlist from input.json" "W2 P-12"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
