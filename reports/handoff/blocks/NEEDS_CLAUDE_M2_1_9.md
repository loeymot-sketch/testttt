# NEEDS_CLAUDE_M2_1_9

TASK_ID: CV1-LIFECYCLE-UX-001-task-1.9
DATE: 2026-05-02
STATUS: BLOCKED_BEFORE_PRODUCT_EDIT

## Blocker

`AvailabilityService::decrementForOrder()` is not currently called from inside the `OrderService::create` / `FrontendOrderService::create` database transaction boundary. The only runtime call path found is:

- `OrderCreated::dispatch(...)` from order creation flows.
- `OrderCreated` uses `DispatchableAfterCommit`, which defers the event until the surrounding transaction commits.
- `EventServiceProvider` maps `OrderCreated` to `DecrementItemAvailabilityOnOrder`.
- `DecrementItemAvailabilityOnOrder::handle()` loads `orderItems` and calls `AvailabilityService::decrementForOrder($order)` after commit.

Because the call occurs after commit, adding `lockForUpdate()` to the existing read inside `AvailabilityService::decrementForOrder()` would not satisfy the plan requirement "inside the existing transaction context" for the order-create transaction. Wrapping the method in a new transaction here would change lifecycle semantics and was explicitly forbidden by the task brief.

## Evidence

- `app/Events/OrderCreated.php` uses `DispatchableAfterCommit`.
- `app/Events/Concerns/DispatchableAfterCommit.php` registers `DB::afterCommit(...)` when `transactionLevel() > 0`.
- `app/Providers/EventServiceProvider.php` registers `DecrementItemAvailabilityOnOrder` on `OrderCreated`.
- `app/Listeners/DecrementItemAvailabilityOnOrder.php` calls `AvailabilityService::decrementForOrder($order)`.
- `app/Services/OrderService.php` and `app/Services/FrontendOrderService.php` call `StockService::decrementForOrder(...)` inside their queue-number transaction callbacks, but do not call `AvailabilityService::decrementForOrder(...)` there.

## Required Orchestration Decision

Claude/orchestrator should choose one of these before re-running task 1.9:

1. Move availability daily-counter decrement into the same order-create transaction boundary, alongside `StockService::decrementForOrder(...)`, and remove/adjust the after-commit listener path to avoid double-decrement.
2. Explicitly authorize a local transaction inside `AvailabilityService::decrementForOrder()` plus a deterministic test proving the new lifecycle semantics and event timing are still acceptable.

No product files were edited for task 1.9 in this pass.
