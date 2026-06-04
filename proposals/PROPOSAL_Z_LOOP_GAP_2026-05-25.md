# PROPOSAL — Z Loop Gap (10-minute Dead Zone Between Z Close and Z Open)

**ID** : PROPOSAL_Z_LOOP_GAP_2026-05-25
**Finding** : B2-C7 — P0 NF525 risk
**Author** : GAP-PROPOSAL-06 (FoodKing Le Cayenne V1 Gap-Hunt 2026-05-25)
**Date** : 2026-05-25
**Status** : Open — awaiting owner gate
**Severity** : **P0 (NF525 fiscal integrity)**
**Frozen-zone touch** : Path A = NO · Path B = YES (LOCK required) · Path C = YES (LOCK required)
**Recommended path** : **Path A immediately + Path B for V1.0.X** (with LOCK countersign)

---

## 1. Risk Articulation

### 1.1 Dead-zone window definition

The system has **two cron schedules** that create a 10-minute window during which orders can be rung but fall outside any Z report :

```
23:55:00 ─────► [Z(J) closing cron fires]
23:55:11 ─────► Z(J).closed_at recorded
                 │
                 │  ┌─────────────────────────────────────────────┐
                 │  │   10-MINUTE DEAD ZONE — no Z is STATUS_OPEN  │
                 │  │   Orders rung here get fiscal_sequence_no    │
                 │  │   but fall outside both Z(J) and Z(J+1).     │
                 │  └─────────────────────────────────────────────┘
                 │
00:05:00 ─────► [Z(J+1) opening cron fires]
00:05:07 ─────► Z(J+1).opened_at recorded
```

### 1.2 Real-world scenario (cashier serves last customer)

A real, common scenario at Le Cayenne (closing at midnight) :

| Time (Europe/Paris) | Event |
|---|---|
| 23:55:11 | `Z(J).closed_at` — Z(J) report closed (cron `fiscal:close-all-active-branches`) |
| 23:58:00 | Cashier scans last customer's items |
| 23:59:32 | `Order.created_at` — order persisted with allocated `fiscal_sequence_no=N` |
| 23:59:45 | Receipt printed (NF525 ticket with `fiscal_sequence_no=N`) |
| 00:01:00 | `Order.paid_at` — customer card payment confirmed |
| 00:05:07 | `Z(J+1).opened_at` — Z(J+1) report opened |

The order's `created_at` (23:59:32) is :
- **>** `Z(J).closed_at` (23:55:11) → **excluded from Z(J)** aggregation
- **<** `Z(J+1).opened_at` (00:05:07) → **excluded from Z(J+1)** aggregation

**Result** : the order has a valid, monotonic `fiscal_sequence_no`, the receipt is printed, the customer leaves, **but the order is in NO Z report**.

### 1.3 Compounding factor — paid_at vs created_at divergence

`ZReportCashEnrichmentService.php:160` aggregates cash flow using **`paid_at`** window, while `ZReportService.php:343-347` aggregates orders using **`created_at`** window.

For the same cross-midnight order (created 23:59:32, paid 00:01:00) :
- `created_at` (23:59:32) → falls in J's calendar day (but outside Z(J) window post-close)
- `paid_at` (00:01:00) → falls in J+1's calendar day (but outside Z(J+1) window pre-open)

Even if the dead zone were closed, the **two different time columns** between aggregation services would still produce divergent totals for cross-midnight orders.

### 1.4 Owner / accountant impact

- **End-of-month consolidation** : the accountant adds all Z(J) totals for the month → revenue is under-counted by the orphan orders.
- **NF525 inspection** : the cash-register inspector cross-checks `fiscal_sequence_no` monotonic gap-free against the Z aggregation. An auditor running `SELECT fiscal_sequence_no FROM orders WHERE branch_id=X AND business_day=D AND fiscal_sequence_no NOT IN (SELECT seq FROM z_report_orders WHERE z_id IN (Z(J), Z(J+1)))` will flag the orphan.
- **Tax declaration drift** : monthly TVA declared from Z totals diverges from total revenue actually invoiced. NF525 forbids any silent revenue outside a Z.
- **Practical magnitude** : at Le Cayenne single-resto, 10 min/day × 365 days = ~60 hours/year of "dead zone" exposure. Even at low frequency (1 order per dead zone per week), that's ~50 orphan orders/year, ~10K€ unreported revenue/year if AOV = 20€.

### 1.5 Why orphan-warn does NOT catch this

`ZReportService.php:586-616` orphan-warn currently detects orders with **`fiscal_sequence_no IS NULL`** but does NOT detect orders with `fiscal_sequence_no SET but no Z window contains them`. The detector is single-sided — it catches missing allocation but not unallocated-to-Z orders.

---

## 2. Current State Evidence

### 2.1 Cron schedules (non-frozen)

`app/Console/Kernel.php:357` (Z close cron) :
```php
$schedule->command('fiscal:close-all-active-branches')
    ->dailyAt('23:55')
    ->timezone('Europe/Paris')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground()
    ->description('G2-HEAL-06: NF525 Z close all active branches');
```

`app/Console/Kernel.php:399` (Z open cron) :
```php
$schedule->command('fiscal:open-all-active-branches')
    ->dailyAt('00:05')
    ->timezone('Europe/Paris')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground()
    ->description('L2-HEAL-07: NF525 Z open all active branches');
```

**Dead zone window** : 23:55 → 00:05 = **10 minutes**.

### 2.2 ZReportService aggregate window (FROZEN §7)

`app/Services/Fiscal/ZReportService.php:343-347` :
```php
$ordersInWindow = Order::query()
    ->where('branch_id', $branchId)
    ->where('created_at', '>', $z->opened_at)
    ->where('created_at', '<=', $z->closed_at)
    ->...
```

Uses **half-open `(opened_at, closed_at]`** window on `Order.created_at`. Orders created at 23:59:32 (after `closed_at = 23:55:11`) are **excluded** from Z(J).

### 2.3 FiscalSequenceService alloc without Z STATUS check (FROZEN §7)

`app/Services/Fiscal/FiscalSequenceService.php:97-103` :
```php
return DB::transaction(function () use ($branchId, $order) {
    $lock = Cache::lock("fiscal:seq:{$branchId}", 5);
    if (!$lock->get()) { throw new ConcurrencyException(); }
    try {
        $next = $this->nextSequenceForBranch($branchId);
        $order->fiscal_sequence_no = $next;
        $order->save();
        return $next;
    } finally { $lock->release(); }
});
```

Allocates sequence **WITHOUT** checking whether there's a STATUS_OPEN Z for the branch. Sequence is allocated even during the dead zone.

### 2.4 ZReportCashEnrichmentService uses paid_at (NON-frozen)

`app/Services/Fiscal/ZReportCashEnrichmentService.php:160` :
```php
$cashOrders = Order::query()
    ->where('branch_id', $branchId)
    ->whereBetween('paid_at', [$z->opened_at, $z->closed_at])
    ->where('payment_method', 'cash')
    ->...
```

Uses **`paid_at`** for cash aggregation while `ZReportService` uses **`created_at`** for order aggregation → divergent totals for cross-midnight orders.

### 2.5 Existing business_date column (used only for queue_number uniqueness)

`app/Services/Order/OrderService.php:2752-2762` :
```php
// business_date used for queue_number uniqueness
$businessDate = $this->resolveBusinessDate($order);
$order->business_date = $businessDate;
```

`business_date` is **already populated** on every order — currently used only to ensure `queue_number` uniqueness per business day. The column already exists and would be reusable for Z aggregation.

### 2.6 Orphan-warn limitation

`ZReportService.php:586-616` :
```php
// orphan-warn: detect orders with NULL fiscal_sequence_no
$orphans = Order::query()
    ->where('branch_id', $branchId)
    ->whereBetween('created_at', [$z->opened_at, $z->closed_at])
    ->whereNull('fiscal_sequence_no')  // ← single-sided check
    ->count();
if ($orphans > 0) { Log::warning(...); }
```

Catches NULL allocation, but does NOT catch "allocated but no Z covers it" — the exact failure mode of the dead-zone gap.

---

## 3. Three Mitigation Paths

### Path A — Compress Dead Zone to 10 Seconds (cron schedule edit)

**Concept** : Move close cron from 23:55 → 23:59:55 and open cron from 00:05 → 00:00:05. Dead zone shrinks 10 min → 10 sec.

**Edit** : `app/Console/Kernel.php` (NON-frozen file).

```diff
-$schedule->command('fiscal:close-all-active-branches')->dailyAt('23:55')->timezone('Europe/Paris')
+$schedule->command('fiscal:close-all-active-branches')->dailyAt('23:59:55')->timezone('Europe/Paris')

-$schedule->command('fiscal:open-all-active-branches')->dailyAt('00:05')->timezone('Europe/Paris')
+$schedule->command('fiscal:open-all-active-branches')->dailyAt('00:00:05')->timezone('Europe/Paris')
```

Note : Laravel's `dailyAt()` only accepts `HH:MM`. For second-level precision use `->cron('59 23 * * *')` and a wrapper command that sleeps the offset, or use `->everyMinute()->when()` predicate. Simplest portable form :
```php
$schedule->command('fiscal:close-all-active-branches')
    ->cron('59 23 * * *')
    ->timezone('Europe/Paris')
    ...
```
combined with an internal `sleep(55)` at the start of the close handler (acceptable since `runInBackground()` + `withoutOverlapping()` is already applied).

**Pro** :
- ✅ 10 min → 10 sec : ~99.97% dead-zone reduction
- ✅ Zero frozen-zone touch
- ✅ Zero migration
- ✅ Ships today (2 LOC change + sentinel)
- ✅ Compatible with both Path B and Path C if added later
- ✅ NF525 chain untouched

**Con** :
- ⚠️ Dead zone still exists (10 sec is not 0 sec)
- ⚠️ paid_at vs created_at divergence still present for cross-midnight orders
- ⚠️ Mitigation only — not elimination

**Effort** : XS (~30 min including sentinel + test + commit)

**Frozen-zone touch** : **NO**

---

### Path B — `business_date` SSOT Discipline (eliminates dead zone)

**Concept** : Use the existing `Order.business_date` column as the authoritative aggregation key. ZReport aggregates `WHERE business_date = Z.business_date`, not by `created_at` window. Dead zone disappears by construction.

**Edits required** :

1. `app/Services/Fiscal/ZReportService.php` (FROZEN §7 — **LOCK required**) — change aggregation predicate from `created_at` window to `business_date` equality.
2. `app/Services/Fiscal/ZReportCashEnrichmentService.php` (NON-frozen) — same change : aggregate by `business_date = Z.business_date`, not `paid_at` window. **Fixes the paid_at/created_at divergence too.**
3. `app/Services/Order/OrderService.php` — `business_date` already populated, audit for one-off branch resolution (use already-computed value).
4. Migration `2026_05_25_business_date_index` — add index `(branch_id, business_date)` on orders if not present.
5. Backfill script for historical orders : `UPDATE orders SET business_date = DATE(created_at - INTERVAL 4 HOUR) WHERE business_date IS NULL` (4-hour offset to attribute night-time orders to the previous business day, configurable per branch).
6. `Z(J).business_date` column populated at Z creation : `business_date = DATE(opened_at - INTERVAL 4 HOUR)`.

**Business day attribution rule** (clear & documented) :
```
Business day D = "service evening of date D"
Order belongs to business day D if its business_date column = D
business_date computed at creation = closest "opened" Z(J) for the branch
   - if Z(J) is STATUS_OPEN and opened_at < now → business_date = Z(J).business_date
   - if no Z open (dead-zone fallback) → business_date = DATE(now - INTERVAL 4 HOUR) (i.e. "the night still belongs to the day that just closed")
```

**Pro** :
- ✅ Zero dead zone by construction — every order has a `business_date`, every Z has a `business_date`, aggregation is `business_date = X` equality
- ✅ Fixes paid_at/created_at divergence (single source of truth)
- ✅ Reuses already-existing `business_date` column
- ✅ Idempotent (re-running aggregation gives identical result)
- ✅ Inspector-friendly : `SELECT * FROM orders WHERE business_date = 'YYYY-MM-DD'` matches Z exactly
- ✅ NF525 chain preserved (audit_logs/z_reports HMAC unchanged ; only aggregation predicate changes)

**Con** :
- ⚠️ Touches FROZEN file `ZReportService.php` — requires **LOCK_FISCAL_BUSINESS_DATE_2026-05-25.md** owner countersign
- ⚠️ Migration + backfill script
- ⚠️ Backfill must be idempotent (safe to re-run if interrupted)
- ⚠️ Edge case : Z reports created BEFORE this migration won't have `business_date` populated — backfill Z(J) too

**Effort** : **M (~4h)** — migration + backfill + ZReportService edit + LOCK doc + 8+ sentinels + cross-midnight E2E

**Frozen-zone touch** : `ZReportService.php` — **LOCK required**

---

### Path C — Refuse Allocation When No Z is STATUS_OPEN (rejected)

**Concept** : `FiscalSequenceService::allocate()` checks `ZReport::where('branch_id', $branchId)->where('status', 'open')->exists()`. If no Z is open → throw `FiscalAllocException`. Cashier sees "Wait 10 sec, day not opened yet" message.

**Edits required** :
1. `app/Services/Fiscal/FiscalSequenceService.php` (FROZEN §7 — **LOCK required**) — add Z STATUS_OPEN precondition before alloc.
2. Frontend POS handles `FiscalAllocException` with retry-after toast.

**Pro** :
- ✅ Provably no orphan orders (by construction)
- ✅ Tight invariant : "every order with fiscal_sequence_no has a STATUS_OPEN Z at allocation time"

**Con** :
- ❌ **User-hostile** : last-second cashier rejected, customer waiting
- ❌ Bad UX : "system says wait" is not acceptable at a POS counter
- ❌ Cross-midnight payment scenario : order created at 23:54 in Z(J), payment confirmed at 00:01 — refund/reprint flows during the gap would also fail
- ❌ Touches FROZEN file — LOCK required anyway
- ❌ Cron failure compounds : if open cron is delayed/missed, POS goes down until manual intervention
- ❌ Conflicts with NF525 spirit : the law requires every transaction to be in a Z, not that POS refuse transactions during cron transitions

**Effort** : S (~2h) — but UX cost is unacceptable

**Frozen-zone touch** : `FiscalSequenceService.php` — LOCK required

**Verdict** : **REJECTED**. The cost (user-hostile rejection at the counter, cron-failure compounding) outweighs the benefit (which Path B achieves cleanly without UX cost).

---

## 4. Recommendation

### 4.1 Immediate (ships today) — **Path A**
- 2 LOC change in `Kernel.php` (non-frozen)
- Compresses dead zone 10 min → 10 sec (~99.97% reduction)
- Zero risk to NF525 chain
- Buys time for proper Path B engineering

### 4.2 V1.0.X (deferred) — **Path B**
- Requires `LOCK_FISCAL_BUSINESS_DATE_2026-05-25.md` countersign
- Eliminates dead zone by construction
- Fixes paid_at vs created_at divergence as bonus
- 8+ sentinels covering cross-midnight + dead-zone + historical backfill

### 4.3 Rejected — **Path C**
- User-hostile, not aligned with NF525 spirit, no benefit Path B doesn't provide

### 4.4 Combined timeline
```
2026-05-25 (today)        : Path A ships → dead zone 10 sec
2026-06-xx (V1.0.X)       : LOCK_FISCAL_BUSINESS_DATE_2026-05-25 countersign
2026-06-xx (V1.0.X+1d)    : Path B ships → dead zone eliminated, paid_at/created_at unified
2026-06-xx (V1.0.X+1w)    : Backfill historical Z reports for completeness
```

---

## 5. Acceptance Criteria — Path A (Immediate)

### 5.1 Code
- [ ] `app/Console/Kernel.php` close cron schedule string = `'59 23 * * *'` (or `dailyAt('23:59:55')` if Laravel version supports HH:MM:SS) Europe/Paris
- [ ] `app/Console/Kernel.php` open cron schedule string = `'0 0 * * *'` + 5-second internal sleep (or `dailyAt('00:00:05')`) Europe/Paris
- [ ] Verify : close at 23:59:55 completes BEFORE open at 00:00:05 (`withoutOverlapping()` already ensures no overlap within same command, but cross-command timing : close handler completes within 5 sec for single-branch Le Cayenne)
- [ ] No frozen-zone files touched (sentinel asserts via diff against frozen list)

### 5.2 Sentinel
- [ ] `tests/Feature/Fiscal/ZCronScheduleSentinelTest.php` — new test asserting :
  - close schedule cron expression == `'59 23 * * *'`
  - open schedule cron expression == `'0 0 * * *'`
  - both have `timezone('Europe/Paris')`
  - both have `withoutOverlapping()` and `onOneServer()`

### 5.3 Documentation
- [ ] `docs/NF525_Z_REPORT_LIFECYCLE.md` — add §3.2 "Dead-zone risk window" documenting the 10-sec residual gap and Path B as planned mitigation
- [ ] Add entry to `PROJECT_BRAIN.md` §6 DECISIONS LOG : "2026-05-25 — Z dead zone compressed via Path A pending Path B LOCK countersign"

### 5.4 Verification (operator runbook)
- [ ] Manual `php artisan schedule:list` → close at 23:59:55, open at 00:00:05
- [ ] Run `php artisan fiscal:close-all-active-branches` manually + measure handler wall-time : must be < 4 sec for single-branch Le Cayenne (gives 1 sec margin before open fires)
- [ ] Stage : trigger close + open within 10 sec → verify no overlap error, no missed orders

### 5.5 NF525 invariant
- [ ] audit_logs HMAC chain bit-identical before/after deploy (count + last_hash unchanged for historical rows)
- [ ] fiscal_sequence_no monotonic gap-free across the deploy window

---

## 6. Acceptance Criteria — Path B (Deferred V1.0.X)

### 6.1 LOCK document
- [ ] `plans/LOCK_FISCAL_BUSINESS_DATE_2026-05-25.md` written + countersigned by owner
- [ ] Scope : `ZReportService.php:343-347` aggregation predicate change ONLY
- [ ] Frozen-zone diff = only those 5 lines of change
- [ ] Safety-check.sh override config documented

### 6.2 Schema
- [ ] Migration `2026_xx_xx_add_business_date_index_orders.php` — index on `(branch_id, business_date)` if not present
- [ ] Migration `2026_xx_xx_add_business_date_z_reports.php` — `business_date DATE NOT NULL` on z_reports (or computed from opened_at)
- [ ] All FK constraints + cascades reviewed

### 6.3 Service edits
- [ ] `ZReportService.php` aggregation : `WHERE business_date = $z->business_date` (replaces `WHERE created_at IN window`)
- [ ] `ZReportCashEnrichmentService.php` cash aggregation : same predicate (eliminates paid_at/created_at divergence)
- [ ] `OrderService.php` `business_date` resolution audited : "closest STATUS_OPEN Z(J) or fallback to 4-hour-shifted calendar date"
- [ ] `FiscalSequenceService.php` UNCHANGED (allocation logic stays as-is — Path B does not require Path C)

### 6.4 Backfill
- [ ] Backfill script `database/scripts/backfill_business_date_orders.php`
  - Idempotent (re-running gives same result)
  - Batched (1000 rows/batch)
  - Logs progress
  - Verifies post-fill : `count(WHERE business_date IS NULL) == 0`
- [ ] Backfill script for Z reports : `database/scripts/backfill_business_date_z_reports.php`
- [ ] Cross-validation script : `database/scripts/verify_business_date_consistency.php`
  - For every Z(J), `count(orders WHERE branch_id=X AND business_date=Z(J).business_date)` matches Z(J).orders_count
  - Fail-loud on mismatch

### 6.5 Sentinels (8+ minimum)
- [ ] `BusinessDateNotNullSentinelTest` — every order has business_date populated
- [ ] `BusinessDateMatchesZSentinelTest` — every order's business_date matches some Z(J).business_date
- [ ] `CrossMidnightOrderSentinelTest` — order created 23:59:32, paid 00:01:00 → business_date = D, appears in Z(J).business_date = D, NOT in Z(J+1)
- [ ] `DeadZoneOrderSentinelTest` — order created in cron gap window → business_date = D (the day that just closed), appears in Z(J)
- [ ] `ZReportServiceAggregationSentinelTest` — `ZReportService` aggregation uses `business_date` predicate (asserts SQL via query log inspection)
- [ ] `ZReportCashEnrichmentSentinelTest` — cash aggregation uses `business_date` predicate (no `paid_at` window)
- [ ] `HistoricalBackfillSentinelTest` — post-backfill, no NULL business_dates remain
- [ ] `ZReportTotalsConsistencySentinelTest` — sum(Z reports for month) == sum(orders for month grouped by business_date)

### 6.6 NF525 invariants
- [ ] audit_logs HMAC chain bit-identical before/after migration (count + last_hash unchanged for all pre-migration rows)
- [ ] fiscal_sequence_no monotonic gap-free preserved
- [ ] z_reports HMAC chain bit-identical (chain spans `prev_hash → current_hash` on the Z payload, business_date addition just extends payload — but only NEW Z reports get business_date in chain; old Z chain untouched)

### 6.7 E2E
- [ ] Playwright spec : ring order at 23:59:30 (mocked clock), trigger close cron, payment at 00:01 (mocked clock), open cron, verify order appears in Z(J).orders_list with business_date = D
- [ ] Playwright spec : dead zone scenario reproduced + verify business_date attribution

---

## 7. NF525 Invariants (both paths)

### 7.1 Invariants preserved by Path A
- ✅ `fiscal_sequence_no` monotonic gap-free per branch (unchanged — same alloc logic)
- ✅ audit_logs HMAC chain bit-identical (cron schedule edit doesn't touch audit_logs writes)
- ✅ z_reports HMAC chain bit-identical (no aggregation logic change)
- ✅ DB triggers `BEFORE DELETE` on audit_logs / z_reports unchanged
- ✅ Branch isolation BranchScope unchanged
- ✅ Cache::lock 5s + DB FOR UPDATE triple-defense unchanged

### 7.2 Invariants preserved by Path B
- ✅ `fiscal_sequence_no` monotonic gap-free per branch (FiscalSequenceService UNCHANGED)
- ✅ audit_logs HMAC chain bit-identical (no migration on audit_logs rows ; only orders + z_reports columns extended)
- ✅ z_reports HMAC chain — historical rows untouched ; new Z reports include `business_date` in HMAC payload (acceptable since chain is `prev_hash + payload → current_hash` ; payload extension only affects NEW rows going forward)
- ✅ DB triggers unchanged
- ✅ Branch isolation BranchScope unchanged
- ✅ Aggregation idempotent (re-running gives identical result — `business_date = X` is set-based)

### 7.3 Risk register (Path B)
| Risk | Mitigation |
|---|---|
| Backfill assigns wrong business_date to old orders | Cross-validation script + manual sample review before commit |
| Migration locks orders table | Run in maintenance window OR `pt-online-schema-change` |
| Z report total mismatch post-backfill | Sentinel `ZReportTotalsConsistencySentinelTest` fails CI before deploy |
| LOCK not countersigned in time | Path A stays in place — 10-sec dead zone is acceptable interim |
| paid_at/created_at divergence found in production data | Path B fixes by construction (single source = business_date) |

---

## 8. Cost-Benefit Summary

| Dimension | Path A | Path B | Path C |
|---|---|---|---|
| Dead-zone reduction | 99.97% (10min→10s) | 100% (eliminated) | 100% (eliminated) |
| paid_at/created_at divergence fixed | No | **Yes** | No |
| Frozen-zone touch | No | Yes (LOCK) | Yes (LOCK) |
| Effort | XS (~30 min) | M (~4h) | S (~2h) |
| UX impact | None | None | **Hostile (rejects orders)** |
| Ships today | **Yes** | No | No |
| Long-term solution | No (interim) | **Yes** | Yes |
| NF525 chain risk | Zero | Zero (chain payload extension only) | Zero |
| Verdict | **SHIP NOW** | **SHIP V1.0.X** | **REJECT** |

---

## 9. V1 Ship-Readiness Verdict

### 9.1 Without Path A
**NO-GO for V1** : 10-minute dead zone × 365 days/year = ~60 hours of NF525 exposure per year. Even at low frequency (~50 orphan orders/year), this is auditable risk and revenue under-reporting. NF525 inspection failure probability material.

### 9.2 With Path A only (Path B deferred)
**GO-CONDITIONAL for V1 Le Cayenne LOCAL** :
- Residual dead zone = 10 sec (~99.97% reduction)
- Expected orphan frequency : ~1-2 orders/year at Le Cayenne single-resto (vs ~50/year with 10-min zone)
- Acceptable interim risk for V1 LOCAL single-resto launch
- Path B MUST be on the V1.0.X backlog with a hard date (within 60 days of V1 launch)
- paid_at/created_at divergence still latent but rare (only triggers if order spans the 10-sec window AND uses cash)

### 9.3 With Path A + Path B
**GO for V1.0.X** :
- Zero dead zone by construction
- paid_at/created_at divergence eliminated
- Inspector-clean : `business_date` SSOT matches Z aggregation 1:1
- NF525 chain preserved
- Ready for V2 SaaS multi-branch (business_date discipline scales — each branch has its own business_date attribution rule via config)

### 9.4 Final recommendation
1. **Ship Path A today** (2 LOC + sentinel + commit) → unblocks V1 GO-CONDITIONAL
2. **Schedule Path B for V1.0.X within 60 days** (LOCK countersign + migration + 8 sentinels + E2E)
3. **Reject Path C permanently** (UX cost > benefit, Path B achieves same end-state)
4. **Track**: add entry to `PROJECT_BRAIN.md` §4 NEXT TO DO and Graphiti `foodking` group episode

---

## 10. Artifacts (to produce on owner GO)

- `plans/LOCK_FISCAL_BUSINESS_DATE_2026-05-25.md` (for Path B)
- `database/migrations/2026_xx_xx_add_business_date_index_orders.php`
- `database/migrations/2026_xx_xx_add_business_date_z_reports.php`
- `database/scripts/backfill_business_date_orders.php`
- `database/scripts/backfill_business_date_z_reports.php`
- `database/scripts/verify_business_date_consistency.php`
- `tests/Feature/Fiscal/ZCronScheduleSentinelTest.php` (Path A)
- `tests/Feature/Fiscal/BusinessDateNotNullSentinelTest.php` (Path B)
- `tests/Feature/Fiscal/CrossMidnightOrderSentinelTest.php` (Path B)
- `tests/Feature/Fiscal/DeadZoneOrderSentinelTest.php` (Path B)
- (+ 4 additional sentinels per §6.5)
- `docs/NF525_Z_REPORT_LIFECYCLE.md` update §3.2

---

## 11. Open Questions (owner gate)

1. **Path A timing precision** — accept `dailyAt('23:55') → dailyAt('23:59')` (1 min dead zone, simpler Laravel API) vs full 10-sec precision via cron expression + sleep? Recommend full 10-sec precision (negligible complexity, much smaller residual zone).
2. **Path B target window** — V1.0.X within 30 days, 60 days, or 90 days? Recommend 60 days hard cap.
3. **business_date attribution rule** — 4-hour shift hardcoded vs per-branch config? Recommend per-branch config with default 4-hour shift (Le Cayenne closes at midnight → 4-hour cutoff means orders 00:00-03:59 attributed to "previous evening").
4. **Backfill timing** — during V1.0.X deploy window or pre-staged separate maintenance window? Recommend separate maintenance window 24h before deploy.

---

## 12. Sign-off

- [ ] Owner : decision Path A immediate + Path B deferred V1.0.X confirmed
- [ ] Owner : LOCK_FISCAL_BUSINESS_DATE_2026-05-25.md countersigned (for Path B)
- [ ] Tech lead : Path A acceptance criteria §5 verified pre-merge
- [ ] QA : sentinel `ZCronScheduleSentinelTest` GREEN
- [ ] NF525 attestation : audit_logs HMAC chain bit-identical post-Path-A deploy

---

**END PROPOSAL** — GAP-PROPOSAL-06 · 2026-05-25
