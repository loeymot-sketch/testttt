# Execute Brief — CV1-LOT-P09-AFTER-COMMIT-DISPATCH

Wave: Caisse V1 Wave 2 Option B  
Run order: 24/36  
Lot: P-09 (POS)  
Status: `READY_WITH_FROZEN_GATE_CHECK`

## Objective

Garantir tous events POS après commit et idempotence outbox.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `app/Services/OrderService.php`
- `app/Jobs/DispatchDomainEventsJob.php`
- `tests/Feature/AfterCommitDispatchTest.php`
- `tests/Feature/DispatchAfterCommitTest.php`
- `tests/Feature/OutboxRescueTest.php`

## Gates

- GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25: Approved Option C required before touching OrderService.php

## Tests

- `php artisan test --filter='AfterCommitDispatchTest|DispatchAfterCommitTest|OutboxRescueTest'`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-P09-AFTER-COMMIT-DISPATCH execute "allowlist from input.json" "W2 P-09"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
