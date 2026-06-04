# KDS Cluster — 10 Pre-Existing Test Failures (Root-Cause Investigation)

- **Branch**: `heal/cms-pr1-quickwins-2026-05-18`
- **HEAD**: `b0bc75987` (at investigation start; baseline `ec0d49241` per brief)
- **Investigator**: Claude (read-only audit subagent, autonomous)
- **Date**: 2026-05-18 22:25 CEST
- **Verdict**: **Single root cause** — all 10 failures collapse to one regression in
  `KitchenDisplaySystemOrderService` introduced by `c2613cab0` (Wave 3b TZ-aware
  boundaries). The fix is correct for production (MySQL+TIMESTAMP) but broke the
  test environment (SQLite+naive datetime string) where stored `order_datetime`
  values are written in Paris-local time and never converted to UTC.
- **Recommendation**: **scope-mini, single 1-3 line heal** (see §5). Same heal
  closes every member of the cluster.

---

## 0. Executive summary

| Cluster member | Endpoint | Failure manifest | Root cause |
|---|---|---|---|
| `KDSDeliveryEnrichmentTest` (3 cases) | GET `/api/admin/kds-order` (→ `service->list()`) | `data` is `[]`, the seeded delivery row is filtered out | TZ window excludes `now()`-stamped orders during evening Paris hours |
| `KdsAllergenAggregationSplitTest` (5 cases) | direct `service->orderItems()` | `$items` is empty (`size 0`), `assertCount(1\|2, ...)` fails | same TZ window in `orderItems()` (sister 4-site mirror) |
| `KioskFullFlowE2ETest` (1 case) | POST `/api/frontend/order` → GET `/api/admin/kds-order` | `data` lacks `$orderAId`, `assertContains` fails | same TZ window in `list()` |
| `KDSAllergenVisibilityTest` (1 case) | GET `/api/admin/kds-order` | `data.0` does not exist → `assertJsonPath('data.0.order_items.0.allergens_snapshot.0')` is `null` | same TZ window in `list()` |

**One commit broke all 10. One heal fixes all 10.**

The `c2613cab0` heal was P0 production-correct (MySQL stores TIMESTAMP in UTC and
needed Paris-to-UTC bound conversion to stop dropping nightly orders 00:00-02:00
Paris). What the heal missed is that the existing test suite stores naive Paris-
local strings in SQLite, and the heal's own sentinel
(`SisterServicesTzAwareTest`) only asserts on **bound bindings**, not on
**row-vs-bound inclusion** — so the sentinel passed while every Order-creating
KDS sentinel quietly broke. The failure window is the evening: in summer (CEST
= +2h) any test that creates orders between **22:00-23:59:59 Paris** and queries
the KDS endpoint will fail; in winter (CET = +1h), **23:00-23:59:59 Paris**.

Confirmation: current local time at investigation start was 22:20 CEST — inside
the failure window, which is why all 10 fail at HEAD. Earlier same-day morning
runs would have shown different counts.

None of the 10 are healed by the recent `d3dc4c2c6` `BroadcastableOrder` widen
(that fix closed F1/F2/F3 only — different code path, `OrderDetailsResource` not
`KDSOrderDetailsResource`).

---

## 1. Per-cluster actual-vs-expected

### 1A. KDSDeliveryEnrichmentTest — 3 cases

- `tests/Feature/KDS/KDSDeliveryEnrichmentTest.php:148`
  `assertNotEmpty($payload, 'KDS list should expose at least the seeded delivery order')` — payload is `[]`.
- `:207` `assertNotNull($row)` — `$row` is `null` because `firstWhere('id', $order->id)` returned nothing on an empty array.
- `:268` `assertGreaterThanOrEqual(3, $rows->count())` — actual 0.

Common precondition: each test `forceCreate`s an `Order` with `'order_datetime' => now()` (line 125, 193, 247) and an admin user with `branch_id = $this->branch->id` (line 75). Status is `ACCEPT` (line 127) or `PREPARING` (line 195), payment `PAID` (line 126), advance flag `Ask::NO` (line 124). All filter requirements are met **except** the TZ window.

### 1B. KdsAllergenAggregationSplitTest — 5 cases

- `:119` (`different allergens NOT merged`) — `assertCount(2, $matching)` got `0`.
- `:136` (`same allergens ARE merged`) — `assertCount(1, $matching)` got `0`.
- `:151` (`null + [] merge`) — `assertCount(1)` got `0`.
- `:166` (`unsorted arrays merge`) — `assertCount(1)` got `0`.
- `:215` (`different orders NOT merged across`) — `assertCount(2)` got `0`.

All five call `actingAsChefAndCallOrderItems()` (line 116/133/148/163/213) which calls `app(KitchenDisplaySystemOrderService::class)->orderItems()` (line 244). The service `orderItems()` runs the same Paris-day TZ window introduced by `c2613cab0` (lines 282-295 of the service today). Orders are forceCreated with `now()` (line ~230 of the test helper). Same TZ exclusion.

### 1C. KioskFullFlowE2ETest — 1 case

- `:193` `assertContains($orderAId, $orderIds)` — `$orderIds` is `[]`.

POSTs `/api/frontend/order` via `postKioskOrder()` (line 167). Service `FrontendOrderService::myOrderStore` sets `order_datetime = now()` internally (it doesn't override the test's clock). Subsequent GET `/api/admin/kds-order` runs the broken `list()` window and finds nothing.

### 1D. KDSAllergenVisibilityTest — 1 case

- `:150` `assertJsonPath('data.0.order_items.0.allergens_snapshot.0', 'arachides')` — actual is `null`.

`data.0` does not exist (the array is empty). Test forceCreates Order with
`'order_datetime' => now()` (line 101). Same TZ exclusion in `list()`.

---

## 2. Code path traced (single root cause)

The full call graph that ALL 10 tests converge on:

```
test::forceCreate(Order, ['order_datetime' => now()])
   ↓  (SQLite, naive string, Paris-local at write time)
$order->order_datetime  =  '2026-05-18 22:20:33'    ← Paris-local, no TZ tag

HTTP GET /api/admin/kds-order
   ↓
KitchenDisplaySystemController::index               (l. 28)
   ↓
KitchenDisplaySystemOrderService::list($request)    (l. 52)
   ↓ build query with whereBetween('order_datetime', [start, end])
$parisTodayStartUtc = Carbon::today('Europe/Paris')->setTimezone('UTC')
                    = '2026-05-17 22:00:00'         ← UTC
$parisTodayEndUtc   = Carbon::today('Europe/Paris')->endOfDay()->setTimezone('UTC')
                    = '2026-05-18 21:59:59'         ← UTC

SQL: WHERE order_datetime BETWEEN '2026-05-17 22:00:00' AND '2026-05-18 21:59:59'
                                 ── compared as STRINGS in SQLite ──
'2026-05-18 22:20:33' > '2026-05-18 21:59:59'  →  excluded.

Returns []. Test fails.
```

For `KdsAllergenAggregationSplitTest` the same exclusion happens at
`KitchenDisplaySystemOrderService::orderItems()` (lines 282-295), which is an
exact textual mirror of the `list()` window.

I verified the mechanism by booting Laravel in CLI:

```
app.timezone:         Europe/Paris
now():                2026-05-18 22:20:33 (Europe/Paris-tagged Carbon)
str-cast to DB:       2026-05-18 22:20:33
parisTodayStartUtc:   2026-05-17 22:00:00
parisTodayEndUtc:     2026-05-18 21:59:59
parisTomorrowStartUtc: 2026-05-18 22:00:00
'2026-05-18 22:20:33' BETWEEN '...22:00:00' AND '...21:59:59' → NO (excluded)
```

The same test, run at 14:00 CEST, would store `'2026-05-18 14:00:33'`, which
**is** between the bounds (14:00 < 21:59:59). That is why the cluster appears
"flaky" — it's deterministically time-of-day-dependent, not random.

---

## 3. Git blame

| Commit | When | What |
|---|---|---|
| **`c2613cab016b6ad346d02fefb94330942953cfe4`** | 2026-05-18 10:27 +0200 | `fix(kds+oss): TZ-aware boundaries in KitchenDisplay + OSS services (Wave 3b P0)` — **the breaking commit** |

`git show c2613cab0 -- app/Services/KitchenDisplaySystemOrderService.php`
shows 4 sites changed (2 in `list()`, 2 in `orderItems()`): `Carbon::today()` →
`Carbon::today($appTz)->setTimezone('UTC')`, `Carbon::tomorrow()` →
`Carbon::tomorrow($appTz)->setTimezone('UTC')`. The body is otherwise identical.

The pre-commit version (`git show c2613cab0^:.../KitchenDisplaySystemOrderService.php`)
used naked `Carbon::today()` — which resolves in `app.timezone='Europe/Paris'`
and thus matched the Paris-local strings the tests store in SQLite. That code
worked for the test environment but was wrong for MySQL production (where the
column is UTC-stored TIMESTAMP, so the Paris bound was 1-2h off and silently
hid nightly orders in [00:00-02:00 Paris]).

The breaking commit's own sentinel (`tests/Feature/Services/SisterServicesTzAwareTest.php`,
4 cases, all green at HEAD) tests **only that the bound bindings are UTC** —
it captures `DB::listen()` SQL bindings and `assertSame('2026-01-14 23:00:00',
$bound)`. It does **not** create an Order with `now()` and assert that the
order appears in the result set. The sentinel passes; the real-world contract
breaks.

The 10 cluster tests pre-date `c2613cab0` (created on commits `5f48856f9`,
`7fc62c066`, `1f145bdbe`, `79591eb39` — all before 2026-05-17). None were
updated after `c2613cab0`. They were written assuming `Carbon::today()` Paris-
local semantics, so when the implementation switched to UTC-bound semantics
they silently regressed.

No subsequent commit between `c2613cab0` and HEAD touches
`KitchenDisplaySystemOrderService.php`. `d3dc4c2c6` (the `BroadcastableOrder`
widen) closes F1/F2/F3 but is on `ReceiptDataService` (not KDS path) and does
not affect this cluster.

---

## 4. Why the cluster shares a single root cause

I considered three alternative hypotheses while reading:

1. **`KDSOrderDetailsResource` serialization defect** — rejected: resource code
   correctly exposes `order_address` (via `whenLoaded('address')`),
   `customer.{name,phone}` (with Z9-P0-03 GDPR phone-suppression for non-DELIVERY),
   and `allergens_snapshot` per-item (verified by reading lines 48-72 of
   `KDSOrderDetailsResource.php`). The resource path is never reached because
   the query returns 0 rows.

2. **Allergen aggregation merge-key regression** — rejected: the aggregation
   path is at `KitchenDisplaySystemOrderService::orderItems()` lines 297+
   (`groupBy` on `item_id|variations|extras|addons|instruction|allergens`). The
   tests are designed to validate exactly this merge logic, but they assert
   `assertCount(... , $matching)` got `0` — meaning the collection is empty
   BEFORE merge can split or coalesce. The merge logic is not exercised.

3. **`BroadcastableOrder` typehint blast radius** — rejected: that bug is in
   `OrderDetailsResource::toArray()` → `ReceiptDataService::buildForOrderModel`
   on the `/api/frontend/order` (FrontendOrder) path. The KDS path uses
   `KDSOrderDetailsResource` which does **not** call `ReceiptDataService`
   (verified by `grep ReceiptDataService app/Http/Resources/KDSOrderDetailsResource.php`
   = no match). `d3dc4c2c6` already healed that and is unrelated.

The TZ window is the only filter that could exclude a properly-statused,
properly-paid, properly-branched, just-created order. I confirmed by computing
the binding values manually and noting the string-comparison exclusion.

---

## 5. Recommendation per cluster member

**One heal closes all 10. Recommended approach: scope-mini, ≤5 LOC.**

The production code is correct (do not roll back `c2613cab0` — that would
re-introduce the nightly 00:00-02:00 Paris drop on MySQL prod, a P0). The test
environment needs to write `order_datetime` values **in the same timezone the
query binds against**. Three viable approaches, in order of safety:

### Option A (recommended, smallest, test-side) — pin Carbon to a safe Paris hour

Add to `Tests\TestCase::setUp()` (or to a `KdsTestHelpers` trait), once:

```php
Carbon::setTestNow(Carbon::create(2026, 5, 18, 12, 0, 0, 'UTC'));
```

This makes `now()` return 12:00 UTC = 14:00 Paris (CEST), which is well inside
the `[22:00 UTC yesterday, 21:59:59 UTC today]` window regardless of DST. Zero
production-code change. The sentinel `SisterServicesTzAwareTest` already uses
exactly this pattern (`pinParisWinterNow()` lines 83-107) — proof the pattern
is approved on this branch. Apply it to the 4 affected test classes (or
globally via `TestCase`).

**Risk**: low — `Carbon::setTestNow()` is reset in PHPUnit `tearDown()`, no
cross-test leak; pattern already used in production tests.

### Option B (test-side, explicit per fixture) — store UTC explicitly

Change `'order_datetime' => now()` to `'order_datetime' => now()->utc()` in the
4 test files (≤10 sites total). This stores the UTC-equivalent in the naive
SQLite column, matching the query bounds. Minimal but spreads the fix across 4
files.

**Risk**: low; but documentation burden — the next test author may not know to
use `now()->utc()` and the regression returns.

### Option C (production-side, broader) — bind native MySQL DATE comparison

Replace the UTC-bound `whereBetween` with `DB::raw('CONVERT_TZ(...)')` or move
the conversion to the column side. This is **NEEDS-OWNER-DECISION** territory:
it is exactly what `c2613cab0` rejected as "not surgical at the query level"
(see commit message). The current `c2613cab0` approach is the cleaner
production design; only the test fixture pattern needs alignment.

**Recommendation to session-A**: **Option A**, applied either globally
(`tests/TestCase.php`) or per the 4 affected files. Re-run the 10 cluster
members to confirm + the sentinel `SisterServicesTzAwareTest` to confirm
no regression on the UTC-bound assertion (it uses its own
`Carbon::setTestNow()` so a global setTestNow in `TestCase::setUp()` would
need a clean override — `pinParisWinterNow()` calls `Carbon::setTestNow()`
again, which is idempotent, so safe).

### Per-member fix mapping

| Member | Fix scope |
|---|---|
| `KDSDeliveryEnrichmentTest` (3) | Option A — 1 line in test's `setUp()` |
| `KdsAllergenAggregationSplitTest` (5) | Option A — 1 line in test's `setUp()` |
| `KioskFullFlowE2ETest` (1) | Option A — 1 line in test's `setUp()` |
| `KDSAllergenVisibilityTest` (1) | Option A — 1 line in test's `setUp()` |

Total: 4 LOC (or 1 LOC in `Tests\TestCase` if applied globally — but verify
no other test relies on `now()` resolving to wall-clock).

### Stronger sentinel (V1.0.X follow-up, NOT now)

The `SisterServicesTzAwareTest` should be extended with a 5th case:
"forceCreate an Order with `order_datetime = $now` and assert the service
returns it." This would have caught the regression. Filing this as backlog,
not blocking.

---

## 6. NF525 / production impact

- **NF525 fiscal chain**: untouched. No fiscal_sequence_no allocation, no
  audit_logs writes, no z_reports rows reached.
- **Multi-tenant**: untouched. `BranchScope` and `branch_id` filtering on the
  admin user path is correct; the test sets `branch_id = $branch->id` and the
  query honours it.
- **Production MySQL**: `c2613cab0` is **correct for production** per the
  commit author's stated mechanism (TIMESTAMP column, UTC MySQL session TZ).
  I did **not** independently verify the prod schema or DB session TZ during
  this read-only audit — pending owner confirmation of those two facts,
  `c2613cab0` should **not** be reverted. The 10 failures are a test-fixture
  asymmetry, not a production defect. If the author's claims hold,
  `c2613cab0` closes a real P0 (nightly orders dropped from KDS UI
  00:00-02:00 Paris on MySQL).
- **Risk if left as-is**: CI flake (deterministic by clock-hour but flake-like
  to downstream readers), false-positive failures masking real future
  regressions in this cluster.

---

## 7. V1.0.X backlog recommendation for session-A

**Single backlog item, P1, scope-mini, ≤30 min:**

> **V1.0.X-KDS-TZ-TEST-FIXTURE-ALIGN** — Pin `Carbon::setTestNow()` in `Tests\TestCase::setUp()`
> (or per-test in the 4 KDS-cluster files) to a safe Paris afternoon hour, so
> `order_datetime = now()` writes land inside the UTC-converted day window
> applied by `KitchenDisplaySystemOrderService::list()` / `::orderItems()` after
> commit `c2613cab0`. **Do not revert `c2613cab0`** — it closes a production
> P0 (nightly orders dropped 00:00-02:00 Paris on MySQL prod). Sentinel
> `SisterServicesTzAwareTest` already passes and asserts the UTC-bound binding
> contract; this item only realigns 10 dependent fixtures + adds an optional
> 5th sentinel case ("row-vs-bound inclusion") to prevent recurrence.

**Cross-cluster relationship**: F1/F2/F3 (closed by `d3dc4c2c6`) and this
KDS cluster of 10 are **independent regressions on the same branch**. The
`d3dc4c2c6` heal closed the FrontendOrder→ReceiptDataService TypeError; this
cluster is the orthogonal TZ-fixture issue. Healing one does not heal the
other.

**Parallel sister cluster (OSS)**: `c2613cab0` applied the identical
4-site Paris-to-UTC pattern to `app/Services/OrderStatusScreenOrderService.php`.
Any OSS test that creates orders with `now()` and queries the customer
status screen / OSS list will exhibit the same time-of-day-dependent
regression. Out of this brief's scope, but session-A should grep
`tests/Feature/OSS/` and `tests/Feature/OrderStatusScreen/` for the same
symptom and apply Option A globally if confirmed.

**Verified preconditions for Option A applicability** (read-only checks at
audit time): no global `Carbon::setTestNow()` exists in `tests/TestCase.php`,
`tests/CreatesApplication.php`, `phpunit.xml`, or any boot listener — so
adding one would not collide. The fixture asymmetry premise stands.

---

## 8. Read-only constraint compliance

- No code edits (read-only audit).
- No fixtures changed.
- No tests changed.
- Only artifact: this report at `reports/audit/foundation-2026-05-18/failures/KDS_CLUSTER_10_FAILURES.md`.
- No FROZEN files inspected for modification.
- No DIRTY files touched.

End of report.
