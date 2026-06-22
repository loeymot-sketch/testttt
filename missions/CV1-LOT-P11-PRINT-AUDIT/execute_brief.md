# Execute Brief — CV1-LOT-P11-PRINT-AUDIT

Wave: Caisse V1 Wave 2 Option B  
Run order: 28/36  
Lot: P-11 (POS)  
Status: `READY`

## Objective

Audit alert sur échec impression et duplicata explicite.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `app/Http/Controllers/Admin/Pos/PosReceiptPrintController.php`
- `app/Services/Receipt/ReceiptAuditService.php`
- `app/Services/Receipt/ReceiptDataService.php`
- `tests/Feature/ReceiptAuditFailureAlertTest.php`
- `tests/Feature/ReceiptDuplicataIncrementTest.php`
- `tests/Feature/Admin/POS/ReceiptPrintControllerTest.php`

## Gates

- No specific gate listed; still verify docs/gates/GATE_LOG.md before frozen edits.

## Tests

- `php artisan test --filter='ReceiptAuditFailureAlertTest|ReceiptDuplicataIncrementTest|ReceiptPrintControllerTest'`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-P11-PRINT-AUDIT execute "allowlist from input.json" "W2 P-11"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
