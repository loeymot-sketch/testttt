# WH-4 / bug_005 — SimpleDeliveryBoyOrderResource N+1 via lazy nested orderItem

**Wave**: H · 2026-05-19  
**Branch**: `heal/cms-pr1-quickwins-2026-05-18`  
**Status**: GREEN — sentinel locked, heal landed.

---

## 1. Bug summary

| | |
|---|---|
| Severity | P1 (perf, not correctness) |
| Surface | `/api/frontend/delivery-boy-order` (livreur index endpoint) |
| Root cause | Lazy second-hop belongsTo not eager-loaded |
| Caller | `App\Services\OrderService::deliveryBoyOrder()` |
| Resource | `App\Http\Resources\SimpleDeliveryBoyOrderResource::resolveItemsForDriver()` |

The resource's docstring (line 47-50) claims N+1 protection via
`$this->resource->relationLoaded('orderItems')`. The guard correctly catches
the FIRST hop — but the inner `map` closure dereferences
`$line->orderItem?->name`, where `OrderItem::orderItem` is a SECOND-level
`belongsTo(App\Models\Item)` (`app/Models/OrderItem.php:89-92`). The caller
loads only `orderItems`, not `orderItems.orderItem`, so every render fires
one `SELECT * FROM items WHERE id = ?` per order_item line.

For a 10-orders × 5-items page, this is ~50 extra round-trips on top of the
~5 framework / settings / scope lookups — a measured 67 queries in the
broken state versus a ~7-query budget in the fixed state.

## 2. Heal applied

One-line eager-load change at `app/Services/OrderService.php:283`:

```diff
-            return Order::with('transaction', 'orderItems', 'branch', 'user')
+            return Order::with(['transaction', 'orderItems.orderItem', 'branch', 'user'])
                     ->where('order_type', "!=", OrderType::POS)
```

The dotted form instructs Eloquent to batch the inner belongsTo with
`SELECT * FROM items WHERE id IN (?, ?, …)` exactly once per page.

The heal comment is attached at the call site so a future refactor that
flattens the `with(...)` array reads the regression history in-line. The
comment cross-references the sentinel name.

The heal happens to share a git commit hash with WH-2 (`5e906658d`,
`fix(orderservice-bug002): NF525 cash audit row written on canonical
2-step driver flow…`) because both heals edited `OrderService.php` in
overlapping windows of the parallel master-agent wave and the working
copy was committed together. The diff is bit-identical to what this WH-4
task specified.

## 3. Sentinel added

`tests/Feature/Sentinels/DeliveryBoyOrderIndexNoN1SentinelTest.php`
(new — 297 LOC, 2 test methods).

Captures the live livreur-index pipeline end-to-end:

```php
DB::flushQueryLog();
DB::enableQueryLog();
$orders = app(OrderService::class)->deliveryBoyOrder($paginateRequest);
$payload = SimpleDeliveryBoyOrderResource::collection($orders)
    ->toArray(Request::create('/'));
$count = count(DB::getQueryLog());
```

The materialization through the Resource is non-negotiable — the lazy
`belongsTo(Item)` only awakens on iteration, so a sentinel that counts
queries on the service call alone would see ~5 queries even on the broken
code and pass silently.

### Two complementary invariants

1. **Absolute bound** — `10 orders × 5 items ⇒ ≤ 25 queries`. The broken
   state hits 67, the fixed state ~6–7, so the threshold of 25 has
   comfortable slack both ways.
2. **Scaling bound** — running the same pipeline at two cardinalities
   (5×3 and 10×5) and asserting `Δ ≤ 10`. Empirically the broken state
   shows a delta of 40 (small=27, big=67); the fixed state shows a delta
   under 5. This guard catches a hypothetical regression that adds a
   different per-line lazy load (e.g. `orderItem.category`) and that
   would silently sit under the 25 threshold for tiny payloads.

### Sentinel pre-conditions guarded inline

- `assertCount($orderCount, $payload)` — empty list would trivially count
  zero lazy queries and silently pass on a future listing-query regression.
- `assertCount($itemsPerOrder, $entry['items'])` for every entry — the
  inner belongsTo is only touched when items exist on the line, so an
  empty `items` array would defeat the assertion.

### Test-class isolation discipline

The setUp inserts EXACTLY ONE role row (`['id' => 3, 'name' => 'Delivery Boy']`)
via `DB::table('roles')->insert(...)` and calls
`PermissionRegistrar::forgetCachedPermissions()`. We deliberately do NOT
call the shared `$this->seedSpatieRoles()` helper because that helper
seeds 6 anonymous-id roles (Admin, Chef, Branch Manager, POS Operator,
Customer, Stuff via `Role::firstOrCreate(['name' => …])`), letting sqlite
AUTO_INCREMENT pick their ids (1..6). The follow-up
`Role::firstOrCreate(['id' => 3], ['name' => 'Delivery Boy', …])` then
becomes a no-op (id=3 is already taken by Branch Manager) and the
PermissionRegistrar in-process cache holds a stale mapping for
'Delivery Boy' that survives RefreshDatabase rollbacks and corrupts the
adjacent `SelectDeliveryBoyRoleByNameSentinelTest` (WH-1).

This isolation was the difference between
`670 passed, 0 failed → 669 passed, 3 failed` and
`670 passed, 0 failed → 672 passed, 0 failed`. Documented inline in
setUp() so future authors do not regress the discipline.

## 4. Verification evidence

### Step 1 — Sentinel against broken code: RED

```
✗ test_livreur_index_render_is_bounded_no_n1_on_order_item_dot_item
  Livreur index render (10 orders × 5 items) executed 67 DB queries.
  Expected ≤ 25 (bounded eager-load budget).
✗ test_livreur_index_render_query_count_does_not_scale_with_payload
  Livreur index render scales unbounded:
  small=27, big=67, delta=40. Expected delta ≤ 10.
```

### Step 2 — Sentinel against fixed code: GREEN

```
✓ livreur index render is bounded no n1 on order item dot item
✓ livreur index render query count does not scale with payload
Tests: 2 passed · Time: 0.60s
```

### Step 3 — Regression suite `--filter="Delivery|Order"`

Baseline (HEAD without WH-4 sentinel): **670 passed, 1 incomplete, 1 skipped**.  
With WH-4 sentinel landed: **672 passed, 1 incomplete, 1 skipped**.  

Net = +2 (WH-4) · -0 regressions · 1 incomplete pre-existing (RushMidi
`s72 n kiosk card orders` — owner-finalize note already documented in
the test file).

### Step 4 — Adjacent classes (regression smoke)

```
DeliveryBoyHardeningSentinelTest    11/11 PASS
DeliveryBoyOrderIndexNoN1SentinelTest 2/2 PASS
SelectDeliveryBoyRoleByNameSentinelTest 4/4 PASS
Tests: 17 passed · Time: 2.72s
```

## 5. Why a NEW sentinel rather than extending the existing test

`DeliveryBoyHardeningSentinelTest::test_p1_liv_01_simple_delivery_boy_order_resource_includes_items`
asserts the RESOURCE SHAPE includes items, but it manually calls
`$order->load('orderItems')` (not `.orderItem`) and does not count
queries. It would have happily continued to pass even with this N+1
regression in place. Adding a query-count assertion to that test would
have required re-shaping its setUp to seed real `Item` rows for the
`belongsTo(Item)` to resolve, conflating two distinct invariants
(shape vs. performance) in one method.

A dedicated sentinel with its own seeding helper isolates the perf
invariant cleanly and makes the failure message specific
(`"…confirm OrderService::deliveryBoyOrder still eager-loads
orderItems.orderItem (dotted)…"`).

## 6. Frozen-zone diff

```
git diff HEAD -- public/js/pos-wizard.js public/css/pos-wizard.css \
                 resources/views/admin-pos-v4.blade.php \
                 app/Services/Fiscal/ app/Models/Scopes/BranchScope.php \
                 app/Http/Middleware/IdempotencyKeyMiddleware.php \
                 app/Services/Pricing/PricingService.php \
                 app/Domain/Order/OrderStateMachine.php
→ EMPTY (0 lines)
```

Migrations: 0. POS/Kiosk frontend: 0. NF525 fiscal services: 0.

## 7. Touched files

| File | Status | LOC |
|---|---|---|
| `app/Services/OrderService.php` | already in HEAD (commit `5e906658d`) | +8 / -1 |
| `tests/Feature/Sentinels/DeliveryBoyOrderIndexNoN1SentinelTest.php` | new (this commit) | +297 |
| `reports/audit/wave-h-2026-05-19/WH-4-bug005-N1-DELIVERY-RESOURCE/STATUS.md` | new (this commit) | +160 |

## 8. Follow-ups (out of scope for this WH-4 commit)

- `OrderService::myOrder()`, `OrderService::posOrder()`, and the kiosk
  variants do NOT use `SimpleDeliveryBoyOrderResource`, but they DO
  return `OrderResource` / `KioskOrderResource` collections which may
  have similar latent N+1 patterns. Out of scope for bug_005 — would
  warrant a generic "Resource never lazy-loads beyond eager-loaded
  relations" sentinel in a future hardening wave.
- The query budget threshold of 25 was empirically tuned against the
  current Spatie / Sanctum / settings stack. A future framework bump
  could shift the baseline by ±2 queries; the docstring notes the
  intent so a future author can re-tune deliberately rather than weaken
  the threshold blindly.
