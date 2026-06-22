# Execute Brief — CV1-LOT-K05-PAYMENT-CONFIRM-WS

Wave: Caisse V1 Wave 2 Option B  
Run order: 15/36  
Lot: K-05 (KIOSK)  
Status: `BLOCKED_FROZEN_F21_GATE_UNTIL_VERIFIED`

## Objective

Rendre payment-confirm robuste, event WS après finalizePaidKioskOrder, collisions payload divergent loggées.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `app/Http/Controllers/Frontend/OrderController.php`
- `app/Services/FrontendOrderService.php`
- `app/Events/KioskPaymentConfirmed.php`
- `tests/Feature/PaymentConfirmIdempotencyTest.php`
- `tests/Feature/PaymentConfirmMachineResolverTest.php`
- `tests/Feature/PaymentConfirmCrossBranchTest.php`

## Gates

- GATE_FROZEN_F21_FINALIZE_PAID_KIOSK_2026-04-23 must be read and confirmed before touching finalizePaidKioskOrder

## Tests

- `php artisan test --filter='PaymentConfirmIdempotencyTest|PaymentConfirmMachineResolverTest|PaymentConfirmCrossBranchTest|CleanupVsConfirmRaceTest'`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-K05-PAYMENT-CONFIRM-WS execute "allowlist from input.json" "W2 K-05"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
