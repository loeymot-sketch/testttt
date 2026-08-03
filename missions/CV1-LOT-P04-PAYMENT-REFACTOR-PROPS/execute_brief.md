# Execute Brief — CV1-LOT-P04-PAYMENT-REFACTOR-PROPS

Wave: Caisse V1 Wave 2 Option B  
Run order: 11/36  
Lot: P-04 (POS)  
Status: `READY_GATE_APPROVED`

## Objective

Refactor PaymentComponent pour éliminer mutations directes de props et conserver one-shot 401 retry.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `resources/js/components/admin/pos/PaymentComponent.vue`
- `resources/js/components/admin/pos/PosComponent.vue`
- `tests/js/sentinels/PaymentComponentPropMutationSentinelTest.spec.js`
- `tests/js/sentinels/paymentComponentPropMutation.spec.js`
- `tests/js/paymentComponentPropMutation.spec.js`
- `tests/js/paymentComponent401Retry.spec.js`
- `tests/js/posPaymentComponentContract.spec.js`

## Gates

- GATE_PAYMENT_PROP_MUTATION_2026-04-26: Approved Option A

## Tests

- `npx vitest run tests/js/paymentComponentPropMutation.spec.js tests/js/paymentComponent401Retry.spec.js tests/js/posPaymentComponentContract.spec.js tests/js/sentinels/paymentComponentPropMutation.spec.js`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-P04-PAYMENT-REFACTOR-PROPS execute "allowlist from input.json" "W2 P-04"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
