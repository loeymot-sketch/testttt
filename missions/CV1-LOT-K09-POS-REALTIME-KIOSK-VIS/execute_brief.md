# Execute Brief — CV1-LOT-K09-POS-REALTIME-KIOSK-VIS

Wave: Caisse V1 Wave 2 Option B  
Run order: 25/36  
Lot: K-09 (KIOSK)  
Status: `READY_WITH_FROZEN_GATE_CHECK`

## Objective

Garantir visibilité POS temps réel des transitions kiosk avec origin, payment_method et queue_number.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `app/Events/OrderCreated.php`
- `app/Events/OrderStatusChanged.php`
- `app/Http/Resources/OrderResource.php`
- `resources/js/store/modules/posOrder.js`
- `tests/Feature/KioskRealtimeBroadcastTest.php`
- `tests/Playwright/pos-receives-kiosk-realtime.spec.js`

## Gates

- Frozen event/service paths require GATE_LOG check before edit

## Tests

- `php artisan test --filter='KioskRealtimeBroadcastTest|AfterCommitDispatchTest'`
- `npx playwright test tests/Playwright/pos-receives-kiosk-realtime.spec.js`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-K09-POS-REALTIME-KIOSK-VIS execute "allowlist from input.json" "W2 K-09"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
