# Execute Brief — CV1-LOT-K10-CLEANUP-IDEMPOTENCY

Wave: Caisse V1 Wave 2 Option B  
Run order: 27/36  
Lot: K-10 (KIOSK)  
Status: `READY`

## Objective

CleanupStalePendingKioskOrders ignore ordres dont idempotency_key est active; délai >= 2x timeout TPE max.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `app/Jobs/CleanupStalePendingKioskOrders.php`
- `config/kiosk.php`
- `tests/Feature/CleanupStalePendingKioskOrdersTest.php`
- `tests/Feature/CleanupVsConfirmRaceTest.php`

## Gates

- No specific gate listed; still verify docs/gates/GATE_LOG.md before frozen edits.

## Tests

- `php artisan test --filter='CleanupStalePendingKioskOrdersTest|CleanupVsConfirmRaceTest'`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-K10-CLEANUP-IDEMPOTENCY execute "allowlist from input.json" "W2 K-10"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
