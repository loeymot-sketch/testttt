# Execute Brief — CV1-LOT-P06-PARK-TTL

Wave: Caisse V1 Wave 2 Option B  
Run order: 17/36  
Lot: P-06 (POS)  
Status: `BLOCKED_SCHEMA_GATE_IF_MIGRATION`

## Objective

Ajouter expires_at sur pos_parked_orders, purge job, et branch scope reprise.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `database/migrations/*_add_expires_at_to_pos_parked_orders.php`
- `app/Models/PosParkedOrder.php`
- `app/Services/PosParkedOrderService.php`
- `app/Console/Commands/PosPurgeParkedOrders.php`
- `app/Http/Controllers/Admin/Pos/ParkedOrderController.php`
- `tests/Feature/Pos/ParkedOrderExpirationTest.php`
- `tests/Feature/Pos/ParkedOrderBranchScopeTest.php`
- `tests/Feature/Pos/PosPurgeParkedScheduleTest.php`

## Gates

- Any DB migration requires schema/M-13 human gate and rehearsal evidence before execute

## Tests

- `php artisan test --filter='ParkedOrderExpirationTest|ParkedOrderBranchScopeTest|PosPurgeParkedScheduleTest|PosParkedOrderTest'`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-P06-PARK-TTL execute "allowlist from input.json" "W2 P-06"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
