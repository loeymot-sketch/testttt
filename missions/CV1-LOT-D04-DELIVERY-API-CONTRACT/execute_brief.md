# Execute Brief — CV1-LOT-D04-DELIVERY-API-CONTRACT

Wave: Caisse V1 Wave 2 Option B  
Run order: 10/36  
Lot: D-04 (DATA)  
Status: `READY_NO_MIGRATION`

## Objective

Ajouter un flux API delivery création -> statut aligné docs sans migration DB.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `app/Http/Controllers/Admin/DeliveryBoyOrderController.php`
- `app/Http/Controllers/Frontend/DeliveryBoyOrderController.php`
- `app/Services/OrderService.php`
- `tests/Feature/DeliveryOrderContractTest.php`
- `docs/ORDER_FLOW.md`

## Gates

- STOP if DB schema change is needed; require M-13/schema human gate before any migration

## Tests

- `php artisan test --filter='DeliveryOrderContractTest|DeliveryBoyOrderStatusOrderingTest'`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-D04-DELIVERY-API-CONTRACT execute "allowlist from input.json" "W2 D-04"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
