# D-M13 — Queue Number Uniqueness

Date: 2026-04-26
Updated: 2026-04-27
Status: SIGNED_FOR_LOCAL_TEST_IMPLEMENTATION_AND_BUSINESS_DAY_ALIGNMENT
Human authorization source: chat instruction "Go for the rest use max intelligence and audit before and after" + 2026-04-27 Train A gate answers

## Decision

Use a database unique guard on:

```text
(branch_id, business_date, queue_number)
```

`business_date` is the calendar day used by the queue allocator. `queue_number` stays nullable, so legacy rows or draft rows without a queue number remain allowed. Any non-null customer-visible queue number must be unique within one branch for one business date.

## Consequence

Queue numbers reset per branch per business date. `A0001` can exist for the same branch on a different `business_date`, but two orders in the same branch on the same business date cannot share it. This matches the human-approved D-M13 decision from 2026-04-27 and replaces the earlier full-history uniqueness draft.

## Runtime Strategy

- Keep the application `Cache::lock()` as the primary allocator mutex.
- Scope the lock to branch + business date.
- Remove all `microtime()` fallback queue-number generation.
- On lock timeout, fail explicitly and ask the caller to retry.
- Add bounded retry on database duplicate-key for the queue-number save path.
- Preserve symmetry between `OrderService` and `FrontendOrderService`.

## Rollout Constraint

This decision approves local/test implementation. Production migration execution still requires operational preflight:

- duplicate scan returns zero rows;
- `business_date` backfill from `order_datetime`/`created_at` is accepted;
- backup is available;
- rollback command is known;
- low-traffic migration window is selected.

PROD_MIGRATION_SIGNOFF: APPROVED_BY_HUMAN_FOR_TRAIN_A_EXECUTION_WITH_PREFLIGHT
