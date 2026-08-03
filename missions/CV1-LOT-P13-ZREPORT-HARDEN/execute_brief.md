# Execute Brief — CV1-LOT-P13-ZREPORT-HARDEN

Wave: Caisse V1 Wave 2 Option B  
Run order: 32/36  
Lot: P-13 (POS)  
Status: `BLOCKED_SCHEMA_AND_FISCAL_GATE_IF_MIGRATION`

## Objective

Z idempotent par branch_id/business_date et alert sur gap ticket.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `app/Services/Fiscal/ZReportService.php`
- `database/migrations/*add_unique_z_per_branch_day*.php`
- `tests/Feature/Fiscal/ZIdempotencyPerDayTest.php`
- `tests/Feature/Fiscal/FiscalSealingHmacTest.php`
- `tests/Feature/Fiscal/ZAggregationKioskRoutingTest.php`

## Gates

- GATE_FISCAL_KIOSK_SCOPE_V1_2026-04-25 and schema/M-13 human gate required before fiscal service/migration edits

## Tests

- `php artisan test --filter='ZIdempotencyPerDayTest|FiscalSealingHmacTest|ZAggregationKioskRoutingTest|ZReportCloseTest'`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-P13-ZREPORT-HARDEN execute "allowlist from input.json" "W2 P-13"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
