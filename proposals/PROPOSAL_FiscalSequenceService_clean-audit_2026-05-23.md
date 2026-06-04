# PROPOSAL — FiscalSequenceService Clean Audit (Phase B.5 Auditeur-fiscal)

**File audited**: `app/Services/Fiscal/FiscalSequenceService.php` (115 LOC)
**Branch**: `heal/cms-pr1-quickwins-2026-05-18`
**HEAD**: `f28688675` (per B3.6 verdict)
**Audited at**: 2026-05-23
**Mode**: READ-ONLY, ZERO file edits
**Persona**: Auditeur-fiscal PRIMARY (legal/prison-time risk under NF525 / Loi Finance 2018)
**Existing LOCK exception**: `plans/LOCK_FISCAL_WGS_Z6_P1_2026-05-19.md` (line 88 `->withTrashed()`, owner countersigned 2026-05-20, heal commit `8e6dceb5c`)

---

## VERDICT

**GREEN — NO NEW NF525-CRITICAL FINDING. NO NEW LOCK REQUIRED.**

Cross-validates B3.6 verdict (`reports/test-e2e/goal-2026-05-23/round-1/B3.6-fiscal-findings.json`):
- chain bit-identical pre/post audit (count=64 last_hash=8daed68a65b…)
- 10 production boot guards intact (5 mandated + 5 bonus)
- triple-defense allocation pattern intact at lines 66-104
- `fiscal_alloc_error_at` flag pattern + retry cron present
- 0 forbidden code patterns (no DELETE/TRUNCATE on audit_logs/z_reports)
- frozen-zone diff = 0 outside the documented LOCK

The triple-defense pattern (`Cache::lock` + `DB::transaction` + `Order::lockForUpdate()` + `Order::withTrashed()` + `Order::withoutGlobalScope(BranchScope::class)`) is the **canonical NF525 allocation primitive** for this codebase. No structural gap, no race that produces sequence duplication or silent skip.

---

## INVESTIGATION SUMMARY

### Targets hunted

1. Race conditions in allocation (cache lock TTL vs acquire TTL, lock expiry mid-tx)
2. Paths where `fiscal_sequence_no` could **gap** (silently skip) or **duplicate** (UNIQUE collision)
3. Failure modes not handled (alloc fail → flag retry pattern coverage)
4. Performance under high concurrency (Wave Polish Final stress 50 orders / 3 parallel verified PASS)

### Methodology

- Full read of `FiscalSequenceService.php` (115 LOC, single-method API: `next(int $branchId): int`)
- Read of all 3 production call sites + their parent-transaction wrappers:
  - `app/Services/OrderService.php:1006` (POS create order)
  - `app/Services/PaymentService.php:242` (POS counter-payment confirm)
  - `app/Services/FrontendOrderService.php:1157` (kiosk paid TPE finalize)
- Read of retry cron `app/Console/Commands/RetryFiscalAllocCommand.php` + schedule registration `app/Console/Kernel.php:155-165`
- Read of migration `database/migrations/2026_04_22_000001_add_fiscal_sequence_no_to_orders.php` (UNIQUE `(branch_id, fiscal_sequence_no)`)
- Read of 4 test files:
  - `tests/Feature/Fiscal/FiscalSequenceTest.php` (5 invariant tests)
  - `tests/Feature/Fiscal/FiscalHardeningMinorTest.php` (H.2.10 monotonic serial test)
  - `tests/Feature/Fiscal/FiscalAllocOrphanRetryTest.php` (4 orphan-retry path tests)
  - `tests/load/RushMidiSimulationTest.php` (real Cache::lock contention exercise)
- Read of LOCK doc `plans/LOCK_FISCAL_WGS_Z6_P1_2026-05-19.md`
- Read of B3.6 verdict (Phase B verdict GREEN)
- Verified Laravel `lockForUpdate()` + `->max()` produces `SELECT MAX(col) FROM t WHERE ... FOR UPDATE` (vendor `Database/Query/Builder.php:2553` + `:3152`) — under InnoDB REPEATABLE READ this acquires gap locks and serialises concurrent MAX queries

---

## DETAILED OBSERVATIONS (all INFO / non-blocking)

### O-1 — Cache lock TTL (5s) > acquire timeout (3s) — IS the intended pattern, not a bug

**File**: `FiscalSequenceService.php:42-43`
```php
private const LOCK_TTL_SECONDS      = 5;
private const LOCK_ACQUIRE_SECONDS  = 3;
```

**Analysis**:
- Constants `LOCK_TTL_SECONDS=5` (lock auto-expiry) vs `LOCK_ACQUIRE_SECONDS=3` (max wait to acquire). On the surface this looks suspicious: a worker holding the lock for slightly more than 3s would let another waiter give up, then if the holder takes > 5s the lock expires while it still believes it owns the lock.
- However: the `connection->transaction(...)` payload does ONLY `SELECT MAX(...) FOR UPDATE` + arithmetic + return. This is a single ms-scale DB round-trip. There is no realistic path where the inner work exceeds 5s, let alone exceeds it AND a competing caller enters before the row lock is released.
- **And even if the cache lock expires mid-work, the DB-side `lockForUpdate()` row+gap locks remain held by InnoDB until the OUTER transaction commits** (the inner `$this->connection->transaction(...)` is a SAVEPOINT when nested under the caller's `DB::transaction(...)`). InnoDB row locks taken inside a SAVEPOINT propagate to the outer transaction and are released only on outer commit/rollback. So a Caller B that enters because Caller A's cache lock expired would block at `lockForUpdate()` on Caller A's row locks. This is exactly the "defense in depth" the comment at lines 80-87 documents.

**Verdict**: design intentional, defense-in-depth correct. **NOT A FINDING.**

### O-2 — `$max + 1` race after `next()` returns but before caller persists Order — closed by parent-transaction discipline

**Call site analysis** (3 production paths):

| Caller | Path | Calls `next()` from… | Persists `fiscal_sequence_no` in… |
|---|---|---|---|
| `OrderService:1006` | POS create | Inside `saveOrderWithQueueNumber` closure, which itself runs inside parent `DB::transaction` | Same parent tx (Eloquent `$this->order->save()` later in the same tx) |
| `PaymentService:242` | POS counter-payment confirm | Inside parent `DB::transaction` at `:219` | Same parent tx (`$locked->save()` at line 282) |
| `FrontendOrderService:1157` | Kiosk paid TPE finalize | Inside parent `DB::transaction` at `:1093` (approx) | Same parent tx (`$locked->save()` at line 1213) |

**Key invariant**: every caller does `next()` inside their own `DB::transaction(...)` and persists `fiscal_sequence_no` in the SAME transaction. Because of MySQL/InnoDB nested-transaction semantics:
- `lockForUpdate()` row+gap locks taken in the inner SAVEPOINT survive to the outer transaction.
- They are released only on outer commit (when the INSERT/UPDATE persists the new sequence number) or rollback (in which case the sequence number is effectively unreserved → next caller correctly re-uses the same MAX, no gap).

**Verdict**: parent-transaction discipline closes the race window. **NOT A FINDING.**

### O-3 — No explicit retry-on-23000 UNIQUE collision inside `next()` itself — by design, not a gap

**Observation**: `next()` doesn't catch `QueryException(code=23000)` for `orders_branch_fiscal_seq_unique` violations. If for some pathological reason two callers DID end up trying to persist the same `(branch_id, fiscal_sequence_no)`, one would hit the UNIQUE constraint and throw at INSERT time, not inside `next()`.

**Why it's OK**:
- The triple-defense pattern makes that path effectively unreachable in production:
  - Cache lock serializes happy-path callers (no DB round-trip cost).
  - DB row+gap lock serializes the unhappy path (cache outage / split-brain).
  - The UNIQUE constraint is the ultimate gate — if a duplicate ever reaches INSERT, the transaction rolls back cleanly, no half-state on the chain.
- The CLAUDE.md §8 mandate is "monotonic + gap-free + no silent gap". A failed INSERT is **not a silent gap** — it's an exception observable to the caller, and the caller's parent transaction rolls back so the would-be-N row never persists. The next caller correctly sees the same MAX and re-allocates N. **Correctness preserved.**

**Verdict**: design intentional. **NOT A FINDING.**

### O-4 — Alloc-fail → `fiscal_alloc_error_at` flag pattern only present on **kiosk** path, not POS — by design

**Observation**: of the 3 production call sites, only `FrontendOrderService::finalizePaidKioskOrder()` wraps the `next()` call in try/catch that flags `fiscal_alloc_error_at` (line 1153-1209, Wave M Z5 P1-C heal at `:1286-1320` writes the flag OUTSIDE the parent tx via `DB::table()->update()` to survive parent rollback). POS paths (`OrderService:1006`, `PaymentService:242`) let the `RuntimeException` bubble up.

**Why both behaviours are correct**:
- **POS create path**: the order does not exist before `next()`. If alloc fails, the parent tx rolls back. No order is created. No orphan, no gap. Caller (cashier UI) sees an error and retries — no silent fiscal violation.
- **POS counter-payment path**: the order exists at `payment_status=UNPAID` before `confirmCounterPayment`. If alloc fails inside the tx, the tx rolls back, the order STAYS at UNPAID with `fiscal_sequence_no=NULL`. The cashier retries the "Encaisser" button — clean state. No half-state.
- **Kiosk paid TPE path**: this is the **only** path where the order is ALREADY at `payment_status=PAID` BEFORE finalize runs (Senangpay webhook → payment marked PAID → finalize promotes to ACCEPT + allocates sequence). If alloc fails here and we simply roll back, the order stays PAID with `fiscal_sequence_no=NULL` and no marker — invisible to KDS, NOT picked up by retry cron, NOT visible to the customer who has paid. That is the unique "orphan" case the flag pattern exists for.

**The asymmetry IS the correct invariant.** The flag pattern is needed only where the row pre-exists payment confirmation.

**Verdict**: NOT A FINDING. The asymmetric pattern correctly maps to the payment-vs-allocation ordering of each surface.

### O-5 — `withTrashed()` LOCK exception (line 88-101) — already documented and countersigned

**State**: documented in `plans/LOCK_FISCAL_WGS_Z6_P1_2026-05-19.md`, owner countersigned 2026-05-20 (tacit via "continue"), heal commit `8e6dceb5c`.

**Auditeur-fiscal cross-check**:
- Without `withTrashed()`, a soft-deleted order that had allocated `fiscal_sequence_no=N` would be invisible to `Order::max(...)`. The next caller would see `MAX = N-1` and allocate N again → UNIQUE collision OR (worse, if soft-deleting also cleared the row) silent re-issue of N → chain violation.
- With `withTrashed()`, the soft-deleted row's allocated sequence is honoured. Plus `Order::restoring` already throws (audit one-way), so soft-delete on a fiscalised order is permanent.
- This is the correct NF525 invariant. The LOCK exception is correctly scoped: 1 logic line + 10-line explanatory comment, byte-equivalent SQL guarantee (since `Order` has `SoftDeletes` trait, the legacy `withoutGlobalScopes()` already included trashed rows transitively, but the new form is intent-explicit and immune to future scope-toggle drift).

**Verdict**: LOCK exception still applies, no additional action.

### O-6 — `connection->transaction(...)` nested under outer DB::transaction — SAVEPOINT semantics

**Observation**: when a caller invokes `app(FiscalSequenceService::class)->next($branchId)` from inside its own `DB::transaction(...)`, the inner `$this->connection->transaction(...)` does NOT start a real transaction — it creates a SAVEPOINT. The lockForUpdate row locks taken at that SAVEPOINT survive to the outer transaction's commit/rollback.

**Risk angle**: if the inner `transaction(...)` were to throw, Laravel would roll back the SAVEPOINT only — not the outer transaction. In this code there is no throw inside the closure other than what the DB itself produces (no app-side logic that could fail), so the SAVEPOINT rollback path is dormant. If the DB does throw at the `lockForUpdate()` query (e.g. deadlock detected), the SAVEPOINT is rolled back; the outer transaction stays open; the caller sees the exception bubble. Whether the outer caller catches it or not determines whether the outer tx commits — but no orphan sequence can result because nothing was written to `orders.fiscal_sequence_no` yet (the service only RESERVES, it does not persist).

**Verdict**: NOT A FINDING.

### O-7 — Cache backend dependency — already enforced by production boot guard

**Observation**: the cache lock primitive depends on a coherent cross-process cache. `AppServiceProvider.php:215` (verified in B3.6 F7) forbids `CACHE_DRIVER ∈ ['array', 'null']` at boot in production. `file` and `database` PASS the guard (already known via UNI-03 backlog item). For V1 LOCAL Le Cayenne single-box, `file` driver is safe (single-process FS); for multi-instance cloud, the cache guard must widen to `redis|memcached`.

**Verdict**: already tracked as **V1.0.X UNI-03 cloud-prep backlog**, per CLAUDE.md §8 verified note 2026-05-21 and B3.6-F7. NO NEW ACTION here.

### O-8 — Performance under stress (50 orders / 3 parallel) — already verified PASS

**Observation**: Wave Polish Final 2026-05-21 ran the stress profile (50 orders / 3 parallel concurrency / 7s window) and recorded PASS. The cache lock TTL=5s + acquire=3s is sized for that profile. With a P99 inner-tx latency well under 100ms, the contention window is dominated by DB row locks, which scale linearly with parallel callers on the same branch.

**No new performance finding.**

---

## INVARIANT CHECKLIST (cross-validates B3.6)

| Invariant | Source | Status |
|---|---|---|
| `next()` returns MAX+1 starting at 1 | Service contract + `FiscalSequenceTest::test_first_number_for_a_branch_is_one` | PASS |
| Strict monotonic gap-free per branch | `FiscalSequenceTest::test_atomic_per_branch_no_gaps` + `FiscalHardeningMinorTest::test_fiscal_sequence_is_monotonic_under_serial_calls` | PASS |
| Per-branch isolation | `FiscalSequenceTest::test_sequences_are_independent_per_branch` | PASS |
| Non-positive `branch_id` rejected | `FiscalSequenceTest::test_non_positive_branch_id_is_rejected` | PASS |
| Continues from existing MAX | `FiscalSequenceTest::test_continues_from_existing_max` | PASS |
| Cache lock acquired with 3s timeout + 5s TTL | constants `LOCK_TTL_SECONDS`/`LOCK_ACQUIRE_SECONDS` lines 42-43 | PASS |
| Row-level DB lock as defense in depth | line 100 `->lockForUpdate()` (no-op on SQLite acknowledged in comment) | PASS |
| `withoutGlobalScope(BranchScope)` (singular) — admin/branch=0 + cross-branch safe | line 97 | PASS |
| `withTrashed()` to honor soft-deleted allocations | line 98 (LOCK exception applied) | PASS |
| Alloc-fail flag pattern (kiosk pre-existing PAID order only) | `FrontendOrderService:1153-1320` + `RetryFiscalAllocCommand` + `FiscalAllocOrphanRetryTest` | PASS |
| Retry cron registered everyMinute + onOneServer | `Console/Kernel.php:161-165` | PASS |
| UNIQUE `(branch_id, fiscal_sequence_no)` at DB layer | migration `2026_04_22_000001_add_fiscal_sequence_no_to_orders.php:37-40` | PASS |
| Lock released in `finally` (idempotent, swallows re-release) | lines 105-113 | PASS |
| Cache backend production guard | `AppServiceProvider.php:215` (forbid array+null) | PASS — V1 file driver safe |

---

## CROSS-VALIDATION WITH B3.6

| B3.6 finding | Cross-check on FiscalSequenceService.php |
|---|---|
| `chain_bit_identical_or_extended_legit: true` (count=64 pre/post) | Confirmed — service never writes to audit_logs/z_reports, only reserves sequence numbers |
| `fiscal_sequence_gap_count: 2` (dev TRUNCATE residue, fsn 29-32 + 35 missing on branch=1) | Cross-checked — gaps live in `orders` row IDs from FreshOrderSeed dev seeds (`FreshOrderSeed.php:21-24` env-guarded `if APP_ENV=production return FAILURE`). Service runtime invariant is intact: the next call would (correctly) return current `MAX+1`, skipping the dead range — these are NOT runtime allocation bugs. **B3.6-F3 verdict re-confirmed.** |
| `fiscal_alloc_error_at_count: 0` | Confirmed in dev DB — no orphan rows |
| `runtime_allocation_pattern: triple defense intact` | Confirmed by direct file read |
| `lock_exception: LOCK_FISCAL_WGS_Z6_P1_2026-05-19` | Confirmed — single application of the LOCK at line 88, owner-countersigned, byte-equivalent SQL |

**No divergence between B3.6 verdict and direct file audit.** Verdict GREEN holds.

---

## RECOMMENDATIONS (V1.0.X backlog, NOT BLOCKING)

These mirror B3.6 backlog items, no new items introduced:
- **UNI-03 cache driver widening**: `CACHE_DRIVER ∈ ['array', 'null']` guard widens to `redis|memcached` at cloud cutover. Currently file driver is V1-LOCAL-safe.
- **F6 partitioning + cold-storage policy**: `audit_logs` PARTITION BY RANGE(created_at) recommended at V1.0.X — does not affect `FiscalSequenceService` directly but does affect long-term `Order::max()` perf if `orders` table grows past several million rows. NOT urgent for Le Cayenne single-restaurant load (~150 orders/day per memory).

---

## FILES READ (full or focused)

- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Fiscal/FiscalSequenceService.php` (full, 115 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/plans/LOCK_FISCAL_WGS_Z6_P1_2026-05-19.md` (full)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/test-e2e/goal-2026-05-23/round-1/B3.6-fiscal-findings.json` (full)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/OrderService.php` (focused 980-1110)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/FrontendOrderService.php` (focused 1115-1330)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/PaymentService.php` (focused 215-355)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Console/Commands/RetryFiscalAllocCommand.php` (full)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Console/Kernel.php` (focused 140-190)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/Fiscal/FiscalSequenceTest.php` (full)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/Fiscal/FiscalHardeningMinorTest.php` (full)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/Fiscal/FiscalAllocOrphanRetryTest.php` (full)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/load/RushMidiSimulationTest.php` (focused 140-220)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2026_04_22_000001_add_fiscal_sequence_no_to_orders.php` (full)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php` (focused `lockForUpdate` + `aggregate`)

---

## ZERO FILE EDITS PERFORMED

This is a READ-ONLY audit. No file in `/app`, `/database`, `/tests` was modified. No new LOCK doc proposed.

---

## SIGN-OFF

Auditeur-fiscal: **GREEN — fiscal chain integrity preserved, allocation primitive bit-equivalent to B3.6 verdict, no NF525 active violation, no new LOCK required.**

The existing `LOCK_FISCAL_WGS_Z6_P1_2026-05-19.md` covers the only frozen-zone edit on this file. Triple-defense allocation pattern + alloc-fail flag pattern + retry cron + DB UNIQUE constraint + production cache guard = full NF525 monotonic gap-free invariant arsenal intact.

Legal risk: ZERO active NF525 violation for V1 LOCAL Le Cayenne single-box deployment.
