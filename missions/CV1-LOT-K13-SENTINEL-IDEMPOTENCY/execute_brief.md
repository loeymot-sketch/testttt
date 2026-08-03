# Execute Brief — CV1-LOT-K13-SENTINEL-IDEMPOTENCY

Wave: Caisse V1 Wave 2 Option B  
Run order: 33/36  
Lot: K-13 (KIOSK)  
Status: `READY_WITH_FROZEN_GATE_CHECK`

## Objective

ActionLog idempotency_collision_divergent_payload pour même clé avec hash items différent.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `app/Services/FrontendOrderService.php`
- `app/Models/ActionLog.php`
- `tests/Feature/IdempotencyCollisionDivergentPayloadTest.php`
- `tests/Feature/Orders/IdempotencyBranchScopedTest.php`

## Gates

- GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25: Approved Option C required before touching FrontendOrderService.php

## Tests

- `php artisan test --filter='IdempotencyCollisionDivergentPayloadTest|IdempotencyBranchScopedTest'`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-K13-SENTINEL-IDEMPOTENCY execute "allowlist from input.json" "W2 K-13"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
