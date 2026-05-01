# Execute Brief — CV1-LOT-P10-REFUND-LEDGER

Wave: Caisse V1 Wave 2 Option B  
Run order: 26/36  
Lot: P-10 (POS)  
Status: `BLOCKED_OPTION_B_RESCOPING_REQUIRED`

## Objective

Refund partiel ledger + wallet idempotency est hors full-ledger sous Option B sauf rescope humain explicite.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `app/Services/PaymentService.php`
- `app/Services/OrderService.php`
- `tests/Feature/PartialRefundLedgerTest.php`
- `tests/Feature/CreditWalletIdempotencyTest.php`
- `tests/Feature/PaymentProviderReferenceUniqueTest.php`

## Gates

- BLOCKED under Option B unless human approves restricted refund-ledger pilot; never launch M-04A from this lot

## Tests

- `php artisan test --filter='PartialRefundLedgerTest|CreditWalletIdempotencyTest|PaymentProviderReferenceUniqueTest|RefundPreZTest|RefundPostZTest'`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-P10-REFUND-LEDGER execute "allowlist from input.json" "W2 P-10"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
