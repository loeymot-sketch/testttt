# Execute Brief — CV1-LOT-P07-KIOSK-CASH-DECOUPLE

Wave: Caisse V1 Wave 2 Option B  
Run order: 20/36  
Lot: P-07 (POS)  
Status: `READY_WITH_FROZEN_GATE_CHECK`

## Objective

Découpler encaissement cash kiosk de la transition cuisine; éviter double release.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `app/Services/OrderService.php`
- `app/Services/PaymentService.php`
- `routes/api.php`
- `tests/Feature/PosCashEndpointSentinelTest.php`
- `tests/Feature/PosCollectKioskCashRouteTest.php`

## Gates

- GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25: Approved Option C required before touching services/routes

## Tests

- `php artisan test --filter='PosCashEndpointSentinelTest|PosCollectKioskCashRouteTest|PaymentNoopIdempotencyTest'`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-P07-KIOSK-CASH-DECOUPLE execute "allowlist from input.json" "W2 P-07"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
