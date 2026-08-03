# D-M13-QUEUE-NUMBER-DB-UNIQUE-2026-04-28

Mode: BLOCKED until A.1/A.2/A.3 CLOSED and human migration signoff
Purpose: make `(branch_id, queue_number)` database-unique and remove unsafe queue-number fallbacks.

## Current Status

This mission is intentionally not executable yet.

Human decision:

- DB unique strategy is approved in principle.
- Migration execution is deferred until preflight and explicit `HG-DM13-MIGRATION-SIGNOFF`.

## Preconditions

- `GOV-PERSIST-SENTINELS-2026-04-27` CLOSED.
- `GOV-PERSIST-QUOTE-SUBSYSTEM-2026-04-27` CLOSED.
- `GOV-CYCLE-AND-MEMORY-CLEANUP-2026-04-27` CLOSED.
- `HG-PHASE-A-CLOSE-SIGNOFF` final approval.
- `HG-DM13-MIGRATION-SIGNOFF` approval before migration execution.

## Technical Plan

1. Preflight duplicate scan for non-null `(branch_id, queue_number)`.
2. If duplicates exist, stop or run signed backfill plan.
3. Add DB unique guard for `(branch_id, queue_number)`.
4. Remove microtime fallback queue-number generation at:
   - `app/Services/FrontendOrderService.php:421`
   - `app/Services/OrderService.php:498`
   - `app/Services/OrderService.php:873`
   - `app/Services/OrderService.php:1295`
5. Replace fallback behavior with bounded retry on duplicate key and explicit failure after retry exhaustion.
6. Preserve `OrderService` / `FrontendOrderService` symmetry.
7. Validate POS, Kiosk, KDS, OSS/outbox behavior after migration.

## Allowlist

See `allowlist.txt`.

## Hard Prohibitions

- Do not execute this mission before preconditions.
- No production migration without `HG-DM13-MIGRATION-SIGNOFF`.
- No weakening of `QueueNumberUniquenessSentinelTest`.
- No frontend price or order-status logic changes.

## Validation

```bash
php artisan test tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php
php artisan test --filter='QueueNumber|Kiosk|POS|Order'
php artisan test
```

Expected result:

- `QueueNumberUniquenessSentinelTest` passes.
- full PHP suite has 0 failures.

## Output Contract

- Write `docs/decisions/D-M13-QUEUE-NUMBER-UNIQUE.md`.
- Write `docs/runbooks/D-M13-QUEUE-NUMBER-ROLLOUT.md`.
- Write `missions/D-M13-QUEUE-NUMBER-DB-UNIQUE-2026-04-28/report.md`.
- Write self-audit under `reports/audit/`.
- Verdict values: `A4_STATUS: CLOSED|BLOCKED_HUMAN_GATE|REWORK`.
