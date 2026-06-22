# Execute Brief — SUPER-AUDIT-M0-P0-DISPATCH-AFTER-COMMIT

Date: 2026-04-28  
Parent report: `reports/audit/CODEX_M0_MACHINE_VALIDATION_2026-04-28.md`  
Priority: P0 invariant repair  
Status: READY

## Problem

`app/Services/Stock/StockService.php` dispatches `ItemAvailabilityChanged` with raw `event($event)` while still inside `DB::transaction`.

Evidence:

- Transaction wrapper: `app/Services/Stock/StockService.php:45-47`
- Raw dispatch before transaction exits: `app/Services/Stock/StockService.php:125-127`
- Event trait exists but is bypassed by raw `event($event)`: `app/Events/ItemAvailabilityChanged.php:21-24`, `app/Events/Concerns/DispatchableAfterCommit.php:29-42`
- Synchronous side-effect listeners exist:
  - `app/Listeners/BumpMenuSnapshotOnItemAvailabilityChanged.php:28-38`
  - `app/Listeners/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php`
  - `app/Listeners/PersistItemAvailabilityChangedToOutbox.php`
  - `app/Listeners/PersistCatalogChangedToOutbox.php`

FoodKing invariant at risk: dispatch/jobs/events after DB commit.

## Goal

Ensure stock-driven availability events and their side effects run only after the stock transaction commits.

## Allowlist

Product files:

- `app/Services/Stock/StockService.php`
- optionally `app/Events/ItemAvailabilityChanged.php` only if needed for a clean dispatch helper.

Tests:

- create `tests/Feature/Stock/StockAvailabilityAfterCommitTest.php`
- optionally update existing stock tests if behavior changes only in timing.

Reports:

- `reports/audit/CODEX_M0_P0_DISPATCH_AFTER_COMMIT_2026-04-28.md`

Do not modify:

- `OrderService.php`
- `FrontendOrderService.php`
- migrations
- pricing services
- KDS/OSS Vue components

## Expected Implementation

Preferred minimal path:

1. Keep stock level and `ItemBranchAvailability` mutations inside the transaction.
2. Do not call raw `event($event)` inside the transaction.
3. Dispatch `ItemAvailabilityChanged` only after commit.
4. Preserve `StockLevelChanged::dispatch(...)` behavior after transaction semantics.
5. Preserve existing idempotency keys and movement behavior.

Possible implementation shape:

- collect availability event payloads during `mutateForOrderInTransaction`;
- return them out of the transaction;
- after `DB::transaction(...)` returns, call `ItemAvailabilityChanged::dispatch(...)` using constructor data or a helper.

If a dispatch must remain inside a transaction, use `DB::afterCommit()` explicitly, never raw `event($event)`.

## Mandatory Tests

New tests:

1. `test_availability_event_side_effects_do_not_run_when_stock_transaction_rolls_back`
   - create order with two lines: first line reaches zero, second line fails stock;
   - call decrement;
   - assert exception;
   - assert no `domain_events` row for `CatalogChanged` / `ItemAvailabilityChanged`;
   - assert menu snapshot/cache is not bumped because the transaction rolled back;
   - assert stock levels remain unchanged.

2. `test_availability_event_side_effects_run_after_successful_stock_commit`
   - create order that legitimately brings stock to zero;
   - call decrement;
   - assert `ItemBranchAvailability` is unavailable;
   - assert outbox/domain event is present after commit;
   - assert snapshot/cache freshness side effect occurs.

Regression:

```bash
php artisan test tests/Feature/Stock/StockAvailabilityAfterCommitTest.php --stop-on-failure
php artisan test tests/Feature/Stock --stop-on-failure
php artisan test tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php --stop-on-failure
```

## PASS Criteria

- No availability/cache/snapshot/outbox side effect is visible after rollback.
- Successful rupture still propagates to menu/KDS/POS pipeline.
- Existing stock tests remain green.
- No `OrderService` / `FrontendOrderService` changes.

## REWORK Criteria

- Raw `event($event)` remains inside the stock DB transaction.
- Rollback test produces any side effect.
- Stock movements/idempotency regress.
- Menu rupture propagation breaks.

