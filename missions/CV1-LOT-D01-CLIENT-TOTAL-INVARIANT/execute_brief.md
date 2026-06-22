# Execute Brief — CV1-LOT-D01-CLIENT-TOTAL-INVARIANT

Wave: Caisse V1 Wave 2 Option B  
Run order: 1/36  
Lot: D-01 (DATA)  
Status: `READY`

## Objective

Garantir par garde statique et sentinel qu'aucune écriture de totaux finaux depuis JSON client ne contourne le backend SSOT.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `scripts/lint-fk-client-totals.sh`
- `tests/Feature/Sentinels/ClientTotalWriteForbiddenSentinelTest.php`
- `tests/Feature/Sentinels/PosSubtotalForgerySentinelTest.php`
- `tests/Feature/FrontendDiscountIntegrityTest.php`
- `reports/audit/GPT_SELF_AUDIT_CV1-LOT-D01-CLIENT-TOTAL-INVARIANT.md`

## Gates

- No specific gate listed; still verify docs/gates/GATE_LOG.md before frozen edits.

## Tests

- `bash scripts/lint-fk-client-totals.sh`
- `php artisan test --filter='ClientTotalWriteForbiddenSentinelTest|PosSubtotalForgerySentinelTest|FrontendDiscountIntegrityTest'`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-D01-CLIENT-TOTAL-INVARIANT execute "allowlist from input.json" "W2 D-01"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
