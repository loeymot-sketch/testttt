# Execute Brief — CV1-LOT-D02-ORDER-EVENT-OUTBOX-MAP

Wave: Caisse V1 Wave 2 Option B  
Run order: 4/36  
Lot: D-02 (DATA)  
Status: `READY`

## Objective

Cartographier OrderCreated / OrderStatusChanged vers canaux et ajouter un test de non-régression outbox after-commit.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `docs/EVENT_CONTRACT.md`
- `docs/OUTBOX_PATTERN.md`
- `docs/orchestration/ORDER_EVENT_OUTBOX_CHANNEL_MAP_2026-04-26.md`
- `app/Providers/EventServiceProvider.php`
- `app/Listeners/PersistOrderCreatedToOutbox.php`
- `app/Listeners/PersistOrderStatusChangedToOutbox.php`
- `tests/Feature/AfterCommitDispatchTest.php`

## Gates

- No specific gate listed; still verify docs/gates/GATE_LOG.md before frozen edits.

## Tests

- `php artisan test --filter=AfterCommitDispatchTest`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-D02-ORDER-EVENT-OUTBOX-MAP execute "allowlist from input.json" "W2 D-02"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
