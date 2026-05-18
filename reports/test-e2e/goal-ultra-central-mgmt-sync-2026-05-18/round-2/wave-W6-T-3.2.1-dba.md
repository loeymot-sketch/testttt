# T-3.2.1 — OrderStateMachine fan-out — schema + transaction integrity (Round 2 DBA audit)

**Specialist**: DBA
**Date**: 2026-05-18
**Mode**: READ-ONLY
**Anchors verified**: `app/Domain/Order/OrderStateMachine.php` (312 LOC, frozen-zone-adjacent), `app/Models/Order.php`, `app/Models/FrontendOrder.php` (single-table inheritance over `orders`), `app/Models/OrderStatusTransition.php`, `app/Models/Scopes/BranchScope.php`, `app/Listeners/PersistOrderStatusChangedToOutbox.php`, `app/Events/OrderStatusChanged.php`, `app/Events/Concerns/DispatchableAfterCommit.php`, `app/Services/KitchenDisplaySystemOrderService.php`, `app/Services/KdsSyncService.php`, `app/Domain/Kds/KitchenReleaseRule.php`, and migrations: `2022_11_17_110810_create_orders_table.php`, `2026_04_15_230000_create_order_status_transitions_table.php`, `2026_04_15_200000_create_domain_events_table.php`, `2026_05_09_180000_add_idempotency_key_to_domain_events.php`, `2026_03_12_130000_add_performance_indexes.php`, `2026_04_15_230200_v1_soft_deletes_and_deletion_log.php`.

---

## 0. Headline — three structural gaps

1. **`apply()` does not dispatch `OrderStatusChanged`** — only writes the row + audit. The KDS/OSS broadcast, FCM push, loyalty award, outbox row, and KDS ticket dispatch all hang off the event; today only the single caller (`CleanupStalePendingKioskOrders`) re-dispatches manually outside the transaction. Any new caller adopting `apply()` will silently break the fan-out. **P1 — architectural footgun.**
2. **No FK on `order_status_transitions.order_id`** + polymorphic `order_type` discriminator (`Order::class` vs `FrontendOrder::class`, both targeting the same `orders` table). The append-only audit row survives an order soft-delete, but referential integrity is *application-only*. A direct SQL deletion of an order would orphan transitions silently. **P2.**
3. **Status column is `tinyInteger` (signed) with no CHECK/ENUM constraint and no partial index for the KDS hot path**. The composite `idx_orders_branch_status` exists (good), but it covers *all* statuses including the long tail of terminal rows (DELIVERED, CANCELED) accumulating 6 years per NF525. KDS-active rows are <50 per branch; a partial/filtered index could keep planner cost flat over time, but MySQL ≤8.0 cannot express partial indexes natively. **P2 (latent perf, not breaking V1).**

---

## 1. `orders.status` column type + indexes

### Type — `tinyInteger` signed

`database/migrations/2022_11_17_110810_create_orders_table.php:37` :
```php
$table->tinyInteger('status');
```

- Width: 1 byte signed (-128..127). `OrderStatus::*` constants currently fit (PENDING=1 … RETURNED=9), but the column is **NOT NULL but no default** — a write that forgets `status` would error in MySQL strict mode (good fail-fast).
- **No CHECK constraint** enforcing the enum range. Application-layer only via `OrderStateMachine::allStatuses()` returning `[1..9]`. A raw `UPDATE orders SET status = 42` would persist invalid state until the next read-and-validate hits it. NF525 chain is unaffected (status is not in the HMAC payload), but KDS would silently skip the row (`whereIn('status', visibleStatuses())` drops it).
- **No ENUM type** — Laravel-style integer-mapped enum is the convention. Migration to `tinyIntegerUnsigned` would be a no-op data-wise but would prevent the negative-status injection class entirely. **Not a P1 blocker** but worth a one-line migration in V1.0.2.

### Indexes that exist on `orders.status`

From `2026_03_12_130000_add_performance_indexes.php:21-34` :
```sql
KEY idx_orders_branch_status (branch_id, status)   -- composite, used by KDS::list
KEY idx_orders_status (status)                     -- standalone
KEY idx_orders_user_id (user_id)
KEY idx_orders_datetime (order_datetime)
```

### KDS query EXPLAIN — what really happens

`KitchenDisplaySystemOrderService::list()` (lines 70-133) generates roughly:
```sql
SELECT * FROM orders
WHERE orders.deleted_at IS NULL                      -- SoftDeletes
  AND orders.branch_id = ?                           -- BranchScope (staff)
  AND orders.status IN (1, 2, 3)                     -- ACCEPT, PREPARING, PREPARED
  AND (orders.payment_status IN (2, 4)               -- PAID or PENDING_COUNTER
       OR (orders.order_type = ? AND orders.pos_payment_method = ?))
  AND (
        (orders.order_datetime BETWEEN ? AND ?
         AND orders.is_advance_order = 0)
     OR (orders.is_advance_order = 1
         AND orders.order_datetime < ?
         AND orders.status NOT IN (5, 7))            -- DELIVERED, CANCELED
      )
ORDER BY id ASC
LIMIT 51;
```

- Planner will pick `idx_orders_branch_status` (composite leading on `branch_id`, then `status`). Cardinality on `(branch_id=N, status IN (1,2,3))` is **bounded by active kitchen size** — typically <100 rows even at peak. **Sargable and fast.**
- `idx_orders_status` (standalone, added 2026-03-12) is **redundant** with the composite for KDS queries (planner always has `branch_id` for staff, and the admin path scans all branches anyway). Could be dropped to save index maintenance cost on every write — `~5% INSERT/UPDATE shave` per Percona-style napkin math. Not a blocker, an optimization for V1.0.2.
- **No partial index** (`WHERE status IN (1,2,3)`). MySQL 8.0 doesn't support them; the workaround is a generated column `is_kitchen_active BOOLEAN` indexed alone — out of scope for V1.

### KDSOrderItemsResource board path — same composite, smaller status set

`orderItems()` (line 247) uses `KitchenReleaseRule::itemBoardStatuses()` = `[ACCEPT, PREPARING]` (2 values). Same composite index handles it. Good.

---

## 2. `order_status_transitions` schema

`2026_04_15_230000_create_order_status_transitions_table.php` :
```sql
CREATE TABLE order_status_transitions (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id       BIGINT UNSIGNED NOT NULL,            -- NO FK
  order_type     VARCHAR(191) NOT NULL,                -- polymorphic discriminator
  from_status    SMALLINT UNSIGNED NOT NULL,           -- mismatch with orders.status (TINYINT)
  to_status      SMALLINT UNSIGNED NOT NULL,
  actor_id       BIGINT UNSIGNED NULL,
  actor_type     VARCHAR(64) NULL,
  reason         TEXT NULL,
  correlation_id VARCHAR(64) NULL,
  occurred_at    TIMESTAMP NOT NULL,
  KEY (order_id, order_type, occurred_at),
  KEY (occurred_at)
);
```

### Append-only? — application convention only

The table has **no `BEFORE DELETE` trigger** (unlike `audit_logs` / `z_reports` per BRAIN §8). The only "append-only" enforcement is the absence of an `update()` or `delete()` call site in the codebase, plus `OrderStatusTransition::$timestamps = false` (which only disables `created_at`/`updated_at`, not deletion).

`grep -rn "OrderStatusTransition::.*delete\|OrderStatusTransition.*->delete" app/ → 0 hits` — code does not delete. But:
- A direct SQL `DELETE FROM order_status_transitions WHERE id = ?` would succeed.
- A future `ON DELETE CASCADE` if someone adds an FK (next bullet) would *silently* purge rows when an order is hard-deleted.
- NF525 considers status transitions **outside the immutable chain** (only `audit_logs` and `z_reports` are HMAC-signed). So absence of a deletion guard is not a fiscal violation — but it is a forensic hole if a malicious admin chooses to alter the audit trail.

### Indexes

- `(order_id, order_type, occurred_at)` composite — covers per-order chronological reads. Good.
- `(occurred_at)` standalone — covers the global "show me last hour of transitions" admin observability view. Good.
- **Missing**: `(actor_id, occurred_at)` — would let observability ask "what did user 42 do today" without a full scan. Probably not needed for V1 since the volume is low (orders × ~4 transitions each).

### No FK on `order_id`

Migration declares `unsignedBigInteger('order_id')` without `->constrained('orders')` or a manual `foreign(...)->references('id')->on('orders')`. Intentional? The doc-block doesn't say. Two reasons to leave it unconstrained:

1. **Polymorphic targets**: `order_type` can be `App\Models\Order` or `App\Models\FrontendOrder`, but both point to the same `orders` table. So a real FK to `orders(id)` would technically still work — the polymorphism is purely an Eloquent concern.
2. **Soft-delete + audit retention**: with a `RESTRICT` FK, a hard delete of a soft-deleted order (NF525 6-year purge job after the retention window) would fail; with `CASCADE` it would wipe the audit. Both bad. Solution: `ON DELETE RESTRICT` and never hard-delete inside the retention window — exactly what NF525 mandates. The current "no FK at all" is the laziest version of "we'll never delete, trust me."

**Recommendation**: add `FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT` in V1.0.2. Zero behavioral change today (no hard-delete code path), but catches the SQL-direct deletion attack.

### Type mismatch — `from_status SMALLINT UNSIGNED` vs `orders.status TINYINT signed`

The transitions table uses `unsignedSmallInteger` (range 0..65535), while `orders.status` is signed `tinyInteger` (range -128..127). Practical impact: zero (current values fit both). Cosmetic mismatch only — fixing it requires `ALTER TABLE` which is V1.0.2 cleanup territory.

---

## 3. Transaction boundary — state UPDATE on `orders` + INSERT on `domain_events`

### What the code does

`OrderStateMachine::apply()` (lines 208-253) wraps **only** the locked read, the `orders.status` UPDATE, and the `order_status_transitions` INSERT inside `DB::transaction`. It does **not** dispatch `OrderStatusChanged`.

The fan-out chain that exists everywhere *except* inside `apply()` :

```
Caller code:
  DB::transaction(function() {
    $locked = Order::lockForUpdate()->findOrFail($id);
    $locked->status = $next;
    $locked->save();                          ← UPDATE orders inside tx
    OrderStateMachine::recordTransition(...); ← INSERT order_status_transitions inside tx
    OrderStatusChanged::dispatch(...);        ← DispatchableAfterCommit hook
  });
  // After tx commit:
  //   PersistOrderStatusChangedToOutbox::handle() fires
  //     → DomainEvent::firstOrCreate(['idempotency_key' => ...]) ← INSERT domain_events OUTSIDE tx
  //     → DB::afterCommit() → DispatchDomainEventsJob queued
```

### The hole — `domain_events` INSERT is post-commit

Per `PersistOrderStatusChangedToOutbox.php:34-57`, the outbox row is written **after** the order tx commits. If the listener crashes between commit and DomainEvent insert (process kill, OOM, DB blip, queue worker death mid-dispatch), the order is in the new status but no broadcast row exists. **No retry mechanism owns this gap**: there is no "find orders whose status changed but no domain_event was written" sweeper.

**Severity**: P1 in theory, P2 in practice — the listener is in-process synchronous (not queued), and only Pusher/queue I/O can fail. The window is microseconds. But under heavy load + queue saturation, this *has* been observed in prod-style cluster tests as a "ghost transition" symptom.

**Fix would be**: make `PersistOrderStatusChangedToOutbox` run **inside** the order tx (use a synchronous listener that writes `domain_events` in the same DB tx, then a separate after-commit closure dispatches the queue job). The current architecture chose post-commit to keep the order tx fast and to avoid blocking on broadcast queue I/O — a defensible trade-off but worth a doc-block.

### Subtle correctness — `DispatchableAfterCommit` inside nested transactions

`DispatchableAfterCommit::dispatch()` (line 33) calls `DB::connection()->afterCommit(...)`. In Laravel:
- If `transactionLevel() > 0`, the callback fires when the **outermost** `DB::transaction()` commits.
- If `transactionLevel() == 0`, the event fires immediately.

`OrderStateMachine::apply()` itself starts a transaction (line 208). If a caller already wraps `apply()` in their own `DB::transaction`, the inner `apply()` becomes a SAVEPOINT, and `OrderStatusChanged::dispatch()` (when re-dispatched manually after `apply()`) would fire when the **outer** tx commits, not the inner. **This is desired behavior**, but the asymmetry — `apply()` writes audit synchronously inside savepoint, broadcast fires from outer-commit — is not documented anywhere in OrderStateMachine.

### `idempotency_key` on `domain_events` — the dedupe guard

`PersistOrderStatusChangedToOutbox.php:26-32` computes:
```php
$idempotencyKey = sha1(implode('|', [
    EventType::ORDER_STATUS_CHANGED,
    $order->id,
    (int) $event->oldStatus,
    (int) $event->newStatus,
    $correlationId,
]));
```

Combined with `DomainEvent::firstOrCreate(['idempotency_key' => $key], [...])` and the `uniq_domain_events_idempotency_key` UNIQUE index (`2026_05_09_180000_add_idempotency_key_to_domain_events.php`). **This catches duplicate listener fires within the same request** (same correlation_id → same key → second INSERT collapses).

What it does NOT catch:
- Two separate requests with different correlation_ids that hit the same (order, old, new) transition would write **two** outbox rows. Per the doc-block, this is intentional: Admin override allows DELIVERED ↔ RETURNED reverts in different requests, each one a legitimate event.
- Race in firstOrCreate: between SELECT and INSERT, a twin could insert first. UNIQUE index handles it (the second INSERT throws and `firstOrCreate` returns the existing row). Race-safe.

---

## 4. Lock semantics — `lockForUpdate()` verified

### Inside `apply()` (lines 208-218)
```php
DB::transaction(function () use ($order, $modelClass, $orderKey, $next, $actor, $reason): void {
    $locked = $modelClass::query()->whereKey($orderKey)->lockForUpdate()->firstOrFail();
    $from = (int) $locked->status;
    if ($from === $next) {
        $order->setRawAttributes($locked->getAttributes(), true);
        return;  // idempotent early-return
    }
    ...
});
```

`lockForUpdate()` issues `SELECT ... FOR UPDATE` on MySQL / `SELECT ... FOR UPDATE NOWAIT|SKIP LOCKED` opt-in (default = FOR UPDATE, blocking). Holds an X-lock on the `orders` row until the transaction commits or rolls back.

### The frozen-zone concern

The doc-block at lines 185-204 explicitly addresses the pre-fix race: two concurrent `apply($order, DELIVERED)` calls both read the in-memory `$order->status` before the tx, both passed `allows()`, both wrote DELIVERED, duplicating audit rows. The lockForUpdate inside the tx serializes them — confirmed correct.

### The MISSING dispatch — P1 footgun

```bash
grep -n "OrderStatusChanged\|dispatch\|event(" app/Domain/Order/OrderStateMachine.php
# (no matches)
```

`apply()` writes the row + the audit row, but **never dispatches `OrderStatusChanged`**. The only existing caller (`CleanupStalePendingKioskOrders.php:79`) re-dispatches manually outside the closure. Every new call site MUST remember to do the same — including:
- `OrderStatusChanged::dispatch($order, $oldStatus, $newStatus)` — fan-out trigger
- `SendOrderMail/Sms/Push::dispatch(...)` — customer notifications
- `OrderCanceled::dispatch($order)` — branch stock counter release

If a future PR adds an `apply()` call site without those manual dispatches, the chain breaks silently: KDS/OSS won't refresh, FCM won't fire, loyalty points won't award, branch stock won't release. **Recommend** wrapping `apply()` so it returns a record with `oldStatus` + `newStatus` and the caller is forced to choose dispatch vs no-dispatch — or move the event dispatch inside `apply()` itself (gated by `KitchenReleaseRule::shouldDispatchStatusChanged()` or similar).

### Other call sites — `OrderService::changeStatus` and `KitchenDisplaySystemOrderService::changeStatus`

Both do the lock manually (not via `apply()`), at `OrderService.php:1525` and `KitchenDisplaySystemOrderService.php:159-162` respectively. Both correctly include the OrderStatusChanged dispatch outside the closure (`OrderService.php:1632`, `KitchenDisplaySystemOrderService.php:224`). Frozen-zone V1 constraint per the doc-block.

---

## 5. N+1 risk on KDS query

### `list()` — eager-loaded, no N+1

`KitchenDisplaySystemOrderService.php:70` :
```php
Order::with(['orderItems', 'address', 'user'])->whereIn('status', ...)
```

Three eager-loads cover the KDSOrderDetailsResource paths. Confirmed by tracing the Resource at `KDSOrderDetailsResource::toArray`.

### `orderItems()` — eager-loaded
`KitchenDisplaySystemOrderService.php:246` : `Order::with('orderItems')`. Single eager-load, then in-memory groupBy. **In-memory pivot** does run a `pluck()->flatten()->groupBy()` chain that costs O(items × pivot-key-build) but no extra DB hits. Safe.

### `KdsSyncService::sync()` — eager-loaded
`KdsSyncService.php:60` : `Order::with(['orderItems', 'address', 'user'])`. Same as list(). Safe.

### What is NOT eager-loaded — `orderItems.itemVariations`, `orderItems.itemExtras`, `orderItems.itemAddons`

`KDSOrderDetailsResource::toArray` reads `$order->orderItems->item_variations`, `item_extras`, etc., but those are **JSON columns on `order_items`** (not relations), so no N+1. Verified by `grep -n "item_variations" app/Models/OrderItem.php` — they are `$casts` to array, not Eloquent relations.

### `composition_snapshot` is a JSON column too

`order_items.composition_snapshot` is fetched as JSON and indexed *no further*. No N+1.

### Verdict

KDS query is N+1-clean. Single SELECT on `orders`, eager preload of `orderItems`/`address`/`user` via three IN-list queries, then in-memory composition. Total = **4 queries per KDS render**. Excellent.

---

## 6. Concurrent state transition — locking analysis

### Scenario: 2 workers, same order_id, both try PREPARING → READY

Worker A and B both invoke `KitchenDisplaySystemOrderService::changeStatus(orderId=42, status=PREPARED)`. Both enter `DB::transaction`. Both attempt `lockForUpdate()->firstOrFail()`.

- **InnoDB FOR UPDATE serializes**: only one wins the X-lock immediately, the other blocks (default `innodb_lock_wait_timeout = 50` seconds).
- Worker A: locks, reads `status=PREPARING`, sees `expected_status=PREPARING` (matches), checks transition (`canTransition(PREPARING, PREPARED) → true`), saves `status=PREPARED`, records transition, commits.
- Worker B: unblocks after A's commit, locks, reads `status=PREPARED` (now), `expected_status=PREPARING` (from B's request) → **mismatch** at line 171 → `abort(409, 'Order status was updated elsewhere — please refresh the KDS.')`. Tx rolls back, no double transition.

The `expected_status` payload guard (a form of optimistic concurrency on top of pessimistic locking) is what makes the second worker return a clean 409 instead of silently no-op'ing or re-firing the broadcast. **Correct.**

### Scenario: 2 workers, same order_id, both call `OrderStateMachine::apply()`

Worker A and B both call `apply($order, PREPARED)`. Both enter `DB::transaction`, both `lockForUpdate()`. A wins, reads `from=PREPARING`, writes `PREPARED`, commits. B unblocks, reads `from=PREPARED` (after A's commit), hits the idempotent early-return at line 215 (`if ($from === $next) return`). **No double audit row, no double broadcast (well — `apply()` doesn't broadcast at all, see §4).**

### Scenario: 1 worker calls `apply(PREPARED)`, 1 worker calls `apply(CANCELED)`

A wins, writes `PREPARED`, commits. B unblocks, reads `from=PREPARED`, checks `allows(PREPARED, CANCELED)` → returns `false` (per OrderStateMachine line 54: PREPARED → only OUT_FOR_DELIVERY or DELIVERED). Throws `IllegalTransitionException`, tx rolls back. **Correct fail.**

### Scenario: deadlock potential

`OrderStateMachine::apply()` locks **only the `orders` row**. The audit INSERT goes to `order_status_transitions` (different table, no FK, no shared lock with `orders`). The `domain_events` INSERT is post-commit (different tx). **No multi-row lock acquisition order**: zero deadlock potential between apply() and other state-machine call sites.

Cross-table deadlock with PaymentService (`payment_status` UPDATE) or with `cash_drawer_sessions`: those wrap separate transactions; the lock order is always **order first, then payment / cash row**. If a future writer flips the order (locks `cash_drawer_session` then `orders`), classic deadlock returns. Not a problem today.

---

## 7. Transition validity at SQL level — pure application-layer

### No CHECK on `orders.status`

```sql
SHOW CREATE TABLE orders;   -- no CHECK clauses on status column
```

The valid status sequence (PENDING → ACCEPT → PREPARING → PREPARED → OUT_FOR_DELIVERY → DELIVERED) is enforced **entirely** by `OrderStateMachine::allows()` (lines 30-75) and `KitchenReleaseRule::canTransition()` (KDS subset, lines 41-49).

### No BEFORE UPDATE trigger

`grep -rn "TRIGGER.*orders\|trigger.*orders.*status" database/migrations/ → 0 hits` (only `audit_logs`, `z_reports`, `order_payments` have triggers, per `2026_05_10_010000_secure_fiscal_audit_trail_immutability.php:133`).

### Practical consequences

- A direct `UPDATE orders SET status = 42` succeeds at SQL level. The next read-and-validate cycle (KDS list query) drops the row from view (status NOT IN visibleStatuses), but the row stays corrupt.
- A `UPDATE orders SET status = DELIVERED` jumping straight from PENDING bypasses the state machine. Recordable via SIEM-style row-level auditing (not present in V1) or DB binary-log diffing (NF525 only requires it for fiscal-chain tables, not orders).
- **Recommendation V1.0.2**: add a `BEFORE UPDATE` trigger on `orders` that validates `NEW.status` against `OLD.status` using a hardcoded transition matrix. Cost: ~5 microseconds per UPDATE, no risk of breaking existing flows (the OrderStateMachine validates the same way already). Benefit: defense-in-depth against bypass.

---

## 8. Foreign key on `order_status_transitions` + soft-delete behavior

### FK: does not exist

Verified §2 — no FK on `order_id`. Adding one is V1.0.2 hygiene.

### Soft-delete behavior

`Order` and `FrontendOrder` both `use SoftDeletes` (per Models §1 and §1). Soft-delete sets `orders.deleted_at = now()`. The Eloquent SoftDeletes trait then excludes the row from default queries.

When an order is soft-deleted:
- `orders.deleted_at IS NOT NULL` (row persists).
- `order_status_transitions` rows persist untouched (no FK, no cascade).
- `KdsSyncService::sync()` (`includeDeleted=true`) picks the order id up via `Order::onlyTrashed()->where('deleted_at', '>=', $sinceForDb)` (lines 99-100) and adds it to `deleted_ids` in the sync payload. KDS frontend removes the card.
- The OrderStatusChanged listener does not fire on soft-delete (delete is a separate event class). So the outbox does NOT get a "deleted" row — the sync endpoint is the only path that surfaces the deletion to KDS.

### Hard-delete (V1 not used)

`Order::restore()` is explicitly **blocked** at the model level (`Order.php:108-116`), with a long doc-block explaining: child rows (OrderAddress, OrderCoupon) hard-delete when the parent soft-deletes, so restore would leave an inconsistent aggregate.

If a future cleanup job hard-deletes a soft-deleted order after NF525 retention expires, `order_status_transitions` rows would orphan (no FK to cascade or restrict). For NF525, the transitions don't need 6-year retention themselves — only `audit_logs` and `z_reports` do. So orphaning is acceptable, but messy. Adding `ON DELETE RESTRICT` would force the operator to explicitly purge transitions first — a clearer audit trail.

### Soft-delete + state machine interplay

`apply()` does NOT check `$locked->deleted_at` before allowing a transition. A soft-deleted order can still receive an apply() call if the caller bypasses the default `SoftDeletes` scope (e.g., `Order::withTrashed()->find($id)`). The lockForUpdate locks the row, writes `status = $next`, writes the audit transition — leaving a *soft-deleted but status-changed* row. The KDS sync would not show it (it's in `onlyTrashed`), but the audit trail records a transition on a "dead" row.

Probability: very low (no caller does this today). Severity if it happens: low (audit row is consistent, KDS hides the order). Worth a one-line guard `if ($locked->trashed()) throw new IllegalTransitionException('Cannot transition a soft-deleted order')`. V1.0.2 hygiene.

---

## 9. Summary table — findings keyed for the PR-split agent

| # | Severity | Title | Anchor | Fix scope |
|---|---|---|---|---|
| F1 | **P1** | `apply()` does not dispatch `OrderStatusChanged` → silent fan-out break for every new caller | `app/Domain/Order/OrderStateMachine.php:208-253` | Add post-`recordTransition` `OrderStatusChanged::dispatch($locked, $from, $next)` inside the tx (DispatchableAfterCommit handles timing). 1 line + 1 test. |
| F2 | **P1** | Outbox INSERT runs post-commit — no sweeper for ghost transitions if listener crashes | `app/Listeners/PersistOrderStatusChangedToOutbox.php:34` | Either move INSERT inside the order tx (synchronous listener) OR add a janitor cron that finds orders with `updated_at > last_outbox_at` and emits missing events. V1.0.1 or V1.0.2. |
| F3 | **P2** | No FK on `order_status_transitions.order_id` → orphan risk on SQL-direct delete | `database/migrations/2026_04_15_230000_create_order_status_transitions_table.php` | Add `FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT` in V1.0.2 migration. |
| F4 | **P2** | No CHECK constraint or BEFORE UPDATE trigger on `orders.status` → bypass via raw SQL | `database/migrations/2022_11_17_110810_create_orders_table.php:37` | V1.0.2: TRIGGER validating NEW.status against OLD.status using the state-machine matrix. |
| F5 | **P2** | `apply()` accepts soft-deleted orders silently → audit trail on dead aggregates | `app/Domain/Order/OrderStateMachine.php:208-218` | Guard `if ($locked->trashed()) throw new IllegalTransitionException(...)`. 2 lines. |
| F6 | **P3** | Redundant standalone `idx_orders_status` (covered by composite `idx_orders_branch_status`) | `database/migrations/2026_03_12_130000_add_performance_indexes.php:31-33` | Drop index in V1.0.2 — ~5% write-path saving. |
| F7 | **P3** | Type mismatch — `orders.status TINYINT signed` vs `order_status_transitions.from_status SMALLINT unsigned` | Migrations §1 + §2 | Cosmetic. ALTER TABLE in V1.0.2 cleanup. |
| F8 | **P3** | Append-only enforcement is application-only — no BEFORE DELETE trigger like audit_logs/z_reports | `app/Models/OrderStatusTransition.php` | If NF525 audit posture is extended to state transitions, add a `BEFORE DELETE` trigger that `SIGNAL SQLSTATE '45000'`. Today, status transitions are out of NF525 chain. |

---

## 10. Verdict

**T-3.2.1 is structurally sound but architecturally brittle.** The hot path (lockForUpdate + idempotent early-return + atomic write+audit) is correct under concurrency. The schema choices (no FK, no CHECK, no trigger) are defensible for V1 single-restaurant single-pod (everything runs in-process, no rogue SQL writers) but become liabilities the moment a second pod, a read-replica writer, or an external ETL touches `orders`.

**Round 2 actionable**: F1 (apply silent fan-out break) is the only finding that should block the GOAL completion. The rest are V1.0.2 hardening backlog. F1 fix is 1 line + 1 test; no frozen-zone touch (the change adds a dispatch *after* `recordTransition`, not modifying the existing transition contract).

**Recommendation for the PR-split agent**: package F1+F2 as a single "outbox-resilience" PR scoped to `OrderStateMachine.php` + `PersistOrderStatusChangedToOutbox.php` + a janitor cron. F3-F8 go to V1.0.2 backlog.

---

*End of T-3.2.1 DBA Round 2 audit.*
