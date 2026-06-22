# A06 — Fiscal Sequence Allocation (NF525)

**Agent**: A06 — Fiscal Sequence Allocation
**HEAD**: `a220b9bd8` on `feature/mobile-app-le-cayenne-2026-05-10`
**Scope**: `FiscalSequenceService`, `RetryFiscalAllocCommand`, schema migrations, allocator call sites (POS counter, kiosk auto-alloc, refund mirror), Console schedule.
**Method**: READ-ONLY. file:line verified.

---

## 0. P1-09 verification (past audit — full-table scan)

Past audit claimed: *"RetryFiscalAllocCommand polls full-table scan every minute."*

Verified at `app/Console/Commands/RetryFiscalAllocCommand.php:61-71` and migration `2026_05_09_200000_add_fiscal_alloc_error_at_to_orders.php:36-41`. The migration explicitly **refuses to add an index** ("the predicate is sparse, premature optimisation, partial-index gap on SQLite/MySQL"). The query is:

```php
FrontendOrder::withoutGlobalScopes()
    ->where('payment_status', PaymentStatus::PAID)
    ->whereNull('fiscal_sequence_no')
    ->whereNotNull('fiscal_alloc_error_at')
    ->orderBy('fiscal_alloc_error_at', 'asc')
```

No partial / composite index supports this predicate. Schedule (`Kernel.php:83`) runs `everyMinute()` + `withoutOverlapping(5)`. **P1-09 still open** — but downgraded to P2 in V1 (sparse predicate, see F-02 below).

---

## 1. FINDINGS

### F-01 (P1) — POS cash counter path has NO orphan recovery for fiscal alloc failure

**File**: `app/Services/PaymentService.php:178-180`
**File**: `app/Services/OrderService.php:922-923`

```php
// PaymentService.php (counter cash close)
if ($locked->fiscal_sequence_no === null) {
    $locked->fiscal_sequence_no = app(FiscalSequenceService::class)->next((int) $locked->branch_id);
}
```

The kiosk path (`FrontendOrderService::finalizePaidKioskOrder`, lines 1108-1167) wraps the allocator in a try/catch and sets `fiscal_alloc_error_at` so the retry cron can recover. **The POS cash counter path does not.** If `FiscalSequenceService::next()` throws (cache backend down → 3s lock-acquire timeout, or `RuntimeException` "could not acquire lock"), the entire `DB::transaction` at `PaymentService.php:156` rolls back. Order remains `payment_status=PENDING_PAYMENT, fiscal_sequence_no=NULL`. The cashier sees an HTTP 500. The cash was physically handed over (drawer opened, cash given). The `fiscal_alloc_error_at` marker is **never set** because the column is on the `orders` table but no code path on the POS cash close writes it.

Same gap at `OrderService.php:922-923` — POS new order creation path. If the lock timeout fires (5 concurrent cashiers same branch under cache outage), the order creation rolls back, cash drawer state diverges from order state, no marker, no retry. Cashier must manually re-enter the order.

**Asymmetry confirmed**: only the kiosk auto-alloc branch in `FrontendOrderService.php:1108-1167` has the error-flag-and-continue pattern. POS cash close + POS new order both re-throw bare. CLAUDE.md §8 says *"If alloc fail → flag `fiscal_alloc_error_at` + retry cron, no crash, no silent gap"* — POS paths violate this.

### F-02 (P2) — Retry command full-table scan (P1-09 confirmed)

**File**: `app/Console/Commands/RetryFiscalAllocCommand.php:61-71`

`everyMinute() * withoutOverlapping(5)` on a predicate with **no index**. On a happy single-branch single-tenant V1 the orders table is small, the WHERE is highly selective (`fiscal_alloc_error_at IS NOT NULL` matches ~0 rows in steady state), and InnoDB will read 1 page. Acceptable for V1.

Risk at scale: when the `orders` table reaches 100K+ rows and multi-tenant aggregates, the unindexed scan becomes a per-minute O(n) sequential. The migration author's argument (`fiscal_alloc_error_at_to_orders.php:36-41`) — "partial index conflicts with SQLite/MySQL" — is partially wrong: a plain B-tree on `fiscal_alloc_error_at` would work on both engines, the column is sparse but the index can be plain (most rows NULL → null-skip in MySQL 8 InnoDB doesn't apply, but the index is small because only NOT NULL rows are indexed effectively via cardinality). **No regression risk** to add the index now.

Downgraded **P1→P2** for V1 (single-branch).

### F-03 (P2) — Retry command has no max-attempt cap → potential infinite retry loop

**File**: `app/Console/Commands/RetryFiscalAllocCommand.php:81-121`

The retry command picks orders by `ORDER BY fiscal_alloc_error_at ASC` then re-invokes `finalizePaidKioskOrder`. On failure, the kiosk finalize closure (`FrontendOrderService.php:1152`) **resets** `fiscal_alloc_error_at = now()` — so the row stays in the retry set with a fresh timestamp. There is **no `attempt_count`** column, no exponential backoff, no dead-letter. A permanently failing order (e.g. branch hard-deleted but order kept for audit, or `FiscalSequenceService` throwing `InvalidArgumentException` because `branch_id <= 0` due to a backfill bug) will be retried every minute forever, polluting fiscal logs (`Log::channel('fiscal')->error('kiosk.fiscal_sequence_alloc_failed')`).

The catch at line 108-120 increments only the local counter `$retriedFailed` and continues — no per-order pause / circuit breaker. The `$limit = 50` per tick is the **only** throttle.

### F-04 (P2) — `RefundWithCounterEntryService` allocates with no try/catch → 422 on partial mirror

**File**: `app/Services/Order/RefundWithCounterEntryService.php:87-89`

```php
return $this->connection->transaction(function () use (...) {
    $mirrorSeq = $this->sequence->next($branchId);    // <-- bare
    ...
});
```

If `FiscalSequenceService::next()` throws (lock timeout under contention OR Cache::lock RuntimeException), the entire refund transaction rolls back. The parent order is **already sealed (post-Z)**. The cashier sees a 500. There is no flag (refund table has no `fiscal_alloc_error_at` field). The refund is just lost. Operator must retry manually. P2 because (a) post-Z refunds are low-frequency and (b) the transaction rollback at least leaves the system consistent. But CLAUDE.md says *"never silently dangerous"* — a silent 500 on a fiscal refund is risky.

### F-05 (P3) — Cache::lock TTL=5s vs acquire=3s → 2s tail wasted

**File**: `app/Services/Fiscal/FiscalSequenceService.php:42-43,66-74`

```php
private const LOCK_TTL_SECONDS      = 5;
private const LOCK_ACQUIRE_SECONDS  = 3;
```

The lock has a **5s TTL** but callers only `block(3)`. If a holder crashes between line 88 and line 95 (`finally { $lock->release() }`), the next caller waits 3s, gives up with `RuntimeException`, even though after 5s the lock auto-expires. A retry from the cashier 2s later would succeed. The narrative comment line 32-33 says lock is *"correctness optimisation"* — true, but the asymmetry forces transient 500s under cache outage / split brain. Either bump acquire to 5s, or drop TTL to 4s. Minor.

### F-06 (P2) — Migration drops UNIQUE on rollback → temporary monotonicity hole

**File**: `database/migrations/2026_04_22_000001_add_fiscal_sequence_no_to_orders.php:47-64`

`down()` drops the UNIQUE constraint `orders_branch_fiscal_seq_unique` AND the column. If a deploy runs `migrate:rollback` against prod (which should never happen but DBA error → real risk), the NF525 invariant disappears for the window between rollback and forward-fix. There is no documentation comment marking this as a destructive operation. Compare with `2026_05_10_010000_secure_fiscal_audit_trail_immutability.php` which has DELETE triggers — equivalent protection here would be a deploy-doc note + `down()` that refuses to run in production env.

### F-07 (P3) — Migration uses MySQL/SQLite-agnostic `Schema::unique` but no MySQL-only constraint

Past audit checklist asked: *"Migration MySQL-only constraint vs SQLite test environment"*. Verified: `2026_04_22_000001` adds a portable UNIQUE INDEX (works on both MySQL and SQLite). No MySQL-specific feature here — **clean**. Note for record: SQLite tests in `FiscalSequenceTest::test_atomic_per_branch_no_gaps` DO exercise the unique constraint via `Order::factory()` writes (line 47-50 `OrderFiscalSequenceSchemaTest` expects `QueryException`). Solid.

### F-08 (P3) — No metric / alert on fiscal_alloc_error_at row count

No Prometheus / log-based alert tracks the steady-state count of `fiscal_alloc_error_at IS NOT NULL`. The retry command emits `info` on success and `error` on crash — but no aggregate gauge. If the cache backend dies for 6 hours, 200 orphan orders accumulate silently. NF525 audit (post-mortem) cannot reconstruct the window. Recommend a `fiscal.orphans.count` gauge published in the cron tick.

---

## 2. RECOMMENDED TEST SCENARIOS

### T-01 (PHPUnit Feature) — `test_pos_counter_cash_close_flags_on_alloc_failure`
- Bind a stub `FiscalSequenceService` throwing `RuntimeException("lock acquire failed")`.
- Call `PaymentService::confirmCounterPayment(...)` for an order with `payment_status=PENDING_PAYMENT, fiscal_sequence_no=NULL`.
- **Expect**: order kept in PENDING_PAYMENT, `fiscal_alloc_error_at` SET (currently FAILS — that path does not flag, F-01).
- Equivalent test on POS new-order path `OrderService.php:922-923`.

### T-02 (PHPUnit Concurrency) — `test_two_concurrent_allocs_serialize_strictly`
- Spawn two PHP threads (use `pcntl_fork` or two `artisan` invocations) hitting `FiscalSequenceService::next($branchId)` simultaneously.
- **Expect**: both return distinct values (1 and 2). No `QueryException` on unique violation. No null return.
- Run 20 iterations to surface intermittent races. The current `FiscalSequenceTest::test_atomic_per_branch_no_gaps` is **sequential** — does not test concurrency. SQLite test env ignores `FOR UPDATE` (line 86-87 of service), so this test should be MySQL-only via `@requires` extension MySQL.

### T-03 (PHPUnit Feature) — `test_retry_command_caps_attempts_for_perma_failing_order`
- Seed an order with branch_id=0 (invalid → `FiscalSequenceService` throws `InvalidArgumentException`).
- Run `foodking:fiscal:retry-alloc` 5 times.
- **Expect**: order eventually parked with `fiscal_alloc_error_at` capped and an `attempt_count` field reaching max (currently FAILS — no cap exists, F-03).

### T-04 (PHPUnit Schema) — `test_fiscal_sequence_no_query_uses_index`
- After running migrations, execute `EXPLAIN SELECT * FROM orders WHERE payment_status=2 AND fiscal_sequence_no IS NULL AND fiscal_alloc_error_at IS NOT NULL` (MySQL only).
- **Expect**: `Using index` or `Using where; Using index`. Currently FAILS — `type=ALL` full scan (F-02).

### T-05 (Playwright E2E) — Cache-down POS cash close survives
- Deploy a fake Redis that 503s on `Cache::lock`.
- Cashier on `/admin/pos` finishes a wizard, clicks "Encaisser cash".
- **Expect**: order created in DB with `fiscal_alloc_error_at` SET, status PENDING_PAYMENT, cashier sees "Réessayez dans 1 min" not HTTP 500. Retry cron picks up within 60s, allocates, order reaches PAID. Currently FAILS (F-01).

---

## 3. VERDICT

| Class | Count |
|---|---|
| P0 | 0 |
| P1 | 1 (F-01 — POS cash close has no orphan recovery, kiosk-vs-POS asymmetry) |
| P2 | 4 (F-02 index, F-03 retry cap, F-04 refund, F-06 destructive down) |
| P3 | 3 (F-05 lock TTL, F-07 schema-clean note, F-08 observability) |

**Verdict for V1 (single-branch FoodKing fast-food)**:
- Kiosk path: solid. Orphan retry path correctly designed and tested.
- POS path: **asymmetric** — same invariant ("never silently dangerous") not enforced on counter cash close. F-01 should be HEAL before V1 production.
- Retry cron: works but lacks cap + index → degrades at scale, fine for V1 traffic.

**Recommendation**: **heal F-01** before V1 cut. F-02..F-08 defer to post-V1 hardening sprint.
