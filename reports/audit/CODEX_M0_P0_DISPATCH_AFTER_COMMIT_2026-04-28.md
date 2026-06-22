# Codex M0-P0 Dispatch After Commit — Stock Availability — 2026-04-28

Date: 2026-04-28  
Executor: Codex  
Mission: `SUPER-AUDIT-M0-P0-DISPATCH-AFTER-COMMIT`  
Source plan: `plans/PLAN_SUPER_AUDIT_REWORK_ORCHESTRATION_2026-04-28.md`  
Mission brief: `missions/SUPER-AUDIT-M0-P0-DISPATCH-AFTER-COMMIT/execute_brief.md`

## Verdict

`M0_P0_DISPATCH_AFTER_COMMIT_VERDICT: PASS`

`RELEASE_DECISION: M0_P0_FIXED_CONTINUE_REWORK_PLAN`

The stock availability event path now respects the FoodKing invariant:

> Dispatch / jobs / domain events must fire only after the surrounding DB transaction commits, and must be discarded on rollback.

## Root Cause

`app/Services/Stock/StockService.php` collected `ItemAvailabilityChanged` events during stock mutation, then emitted them with raw `event($event)` while still inside `DB::transaction()`.

That bypassed `App\Events\Concerns\DispatchableAfterCommit`, so listeners with non-transactional side effects could run before the full write boundary was committed:

- `BumpMenuSnapshotOnItemAvailabilityChanged`
- `InvalidateKioskMenuCacheOnItemAvailabilityChanged`
- `PersistCatalogChangedToOutbox`
- `PersistItemAvailabilityChangedToOutbox`

The practical risk was a rollback leaving visible side effects behind: menu snapshot bumped, kiosk menu cache invalidated, and surfaces prompted to refresh state that did not commit.

## Fix Applied

Files changed:

- `app/Services/Stock/StockService.php`
- `tests/Feature/Stock/StockAvailabilityAfterCommitTest.php`

Implementation:

- Replaced raw `event($event)` emission with `ItemAvailabilityChanged::dispatch(...)`.
- Kept the dispatch scheduling inside the stock transaction so callback ordering remains consistent.
- Let `DispatchableAfterCommit` defer the event until the outermost transaction commits.
- Added a regression test proving side effects are dropped when an outer transaction rolls back.

No changes were made to:

- `app/Services/OrderService.php`
- `app/Services/FrontendOrderService.php`
- pricing services
- migrations
- KDS / OSS / kiosk Vue components

## Validation Evidence

Red test before fix:

- `php artisan test tests/Feature/Stock/StockAvailabilityAfterCommitTest.php`
- Result before patch: failed because kiosk cache was invalidated after an outer rollback.

Green tests after fix:

- `php artisan test tests/Feature/Stock/StockAvailabilityAfterCommitTest.php` → 2 passed
- `for i in 1 2 3 4 5; do php artisan test tests/Feature/Stock/StockAvailabilityAfterCommitTest.php || exit 1; done` → 5/5 passed
- `php artisan test tests/Feature/Stock` → 19 passed
- `php artisan test tests/Feature/Menu/AvailabilityServiceTest.php` → 7 passed
- `php artisan test tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php` → 1 passed
- `php -l app/Services/Stock/StockService.php && php -l tests/Feature/Stock/StockAvailabilityAfterCommitTest.php` → no syntax errors

Static scan:

- `rg "event\\(\\$event\\)|event\\(new ItemAvailabilityChanged|ItemAvailabilityChanged::dispatchNow|ItemAvailabilityChanged::dispatch\\(" app tests -n`
- Result: only guarded `ItemAvailabilityChanged::dispatch(...)` remains in `StockService`.

## Invariants Checked

- Backend pricing SSOT: not touched.
- `OrderStatus` enum: not touched.
- `branch_id` isolation: stock mutation still filters stock levels by `branch_id`.
- Dispatch after DB commit: fixed and regression-tested.
- Frozen `OrderService` / `FrontendOrderService`: not touched.
- POS / kiosk stock parity: `StockSymmetryDiffTest` remained green through `tests/Feature/Stock`.

## Residual Scope

This mission closes only M0-P0 dispatch-after-commit.

The larger super-audit plan still has pending missions:

- M1/C3 runtime multi-surface real synchronization.
- M2/C4 stock concurrency stress.
- M3/C5 queue number concurrency stress.
- M4/C6 fiscal/outbox/persistence hardening.
- M5 KDS/OSS throttle audit.
- M6 MySQL validation for skipped menu tests.
- M7 dashboard management journey.
- M8 full submit C1/C2 replacement.
- M9 final consolidation.

## Workspace Note

`git status --short app/Services/Stock/StockService.php tests/Feature/Stock/StockAvailabilityAfterCommitTest.php` reports these paths as untracked in this local workspace. I did not modify Git tracking or revert any existing workspace state.
