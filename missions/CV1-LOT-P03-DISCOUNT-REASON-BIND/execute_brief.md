# Execute Brief — CV1-LOT-P03-DISCOUNT-REASON-BIND

Wave: Caisse V1 Wave 2 Option B  
Run order: 8/36  
Lot: P-03 (POS)  
Status: `READY`

## Objective

Fix binding UI S09 discountReason et sentinel FK-079.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `resources/js/components/admin/pos/PosComponent.vue`
- `tests/js/sentinels/PosDiscountReasonBindingSentinelTest.spec.js`
- `tests/js/quickwins/discountReasonBindingTest.spec.js`

## Gates

- No specific gate listed; still verify docs/gates/GATE_LOG.md before frozen edits.

## Tests

- `npx vitest run tests/js/sentinels/PosDiscountReasonBindingSentinelTest.spec.js tests/js/quickwins/discountReasonBindingTest.spec.js`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-P03-DISCOUNT-REASON-BIND execute "allowlist from input.json" "W2 P-03"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
