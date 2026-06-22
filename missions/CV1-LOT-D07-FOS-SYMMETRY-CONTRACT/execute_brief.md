# Execute Brief — CV1-LOT-D07-FOS-SYMMETRY-CONTRACT

Wave: Caisse V1 Wave 2 Option B  
Run order: 19/36  
Lot: D-07 (DATA)  
Status: `READY`

## Objective

Documenter et tester la cartographie symétrie OS/FOS, notamment absence FOS changePaymentStatus.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `docs/orchestration/OS_FOS_SYMMETRY_MATRIX_2026-04-25.md`
- `tests/Feature/Symmetry/OrderServicesContractTest.php`
- `reports/audit/GPT_SELF_AUDIT_CV1-LOT-D07-FOS-SYMMETRY-CONTRACT.md`

## Gates

- No specific gate listed; still verify docs/gates/GATE_LOG.md before frozen edits.

## Tests

- `php artisan test --filter=OrderServicesContractTest`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-D07-FOS-SYMMETRY-CONTRACT execute "allowlist from input.json" "W2 D-07"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
