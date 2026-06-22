# A13 — Branch Isolation (BranchScope) Audit

**Agent**: A13
**HEAD**: a220b9bd8
**Branch**: feature/mobile-app-le-cayenne-2026-05-10
**Date**: 2026-05-11
**Discipline**: READ-ONLY, file:line verified.

---

## 1. Executive verdict

| Severity | Count | Disposition |
|---|---|---|
| P0  | 1 | **PosOrderController::show:108 cross-branch leak — CONFIRMED freshly** |
| P1  | 4 | OrderStatusTransition / PosParkedOrder / OrderQuote / OrderCoupon missing BranchScope (3 partially mitigated, 1 latent) |
| P2  | 2 | `OrderQuoteService::resolveReplay` lookup without branch filter; `OrderCoupon` limit_per_user count user-scoped only |
| P3  | 1 | No regression test pins POS-order `show` against cross-branch access |

**V1 merge**: BLOCK on P0 fix. The leak is a real cross-tenant data-exposure regression of a constraint owner explicitly enshrined in `CLAUDE.md §7 / §9`.

---

## 2. P0 — `Admin\PosOrderController::show` returns ANY order, ignoring `BranchScope`

**File**: `app/Http/Controllers/Admin/PosOrderController.php:108`

```php
public function show(int|string $order)
{
    try {
        $order = Order::withoutGlobalScope(BranchScope::class)->findOrFail($order);
        return new OrderDetailsResource($this->orderService->show($order, false));
```

- The route `GET /api/v1/admin/pos-order/show/{order}` is gated only by `permission:pos-orders|pos` (line 37). Any branch operator with the `pos` permission can pass another branch's order id and receive its full `OrderDetailsResource`.
- `OrderDetailsResource` exposes `composition_snapshot`, `total_price`, customer phone, fiscal data, payment lines — full leak.
- No `branch_id` defensive check downstream: `OrderService::show($order, false)` does not re-check tenancy.
- Past audit `99_CORRIGENDUM.md` claimed the leak was at "PosOrderController.php:108" — **confirmed verbatim at HEAD a220b9bd8**. The earlier "0 match" spot-check by another agent was wrong (likely searched the `Admin/Pos/` subdirectory only; this controller lives in `Admin/`).
- Contrast: `refundWithCounterEntry` on the same controller (line 58-61) DOES include a defense-in-depth cross-branch check. `show` does NOT.

**Fix**: drop `withoutGlobalScope(BranchScope::class)`. If a use case truly needs cross-branch read (super-admin), wrap it in `if (! $authUser->hasRole('Admin')) abort(403)` like `refundWithCounterEntry`. Reorder/destroy/changeStatus all use route-model binding which honors the scope — leaving `show` as the outlier.

**Detection**: a Feature scenario simply hitting `GET /api/v1/admin/pos-order/show/{branchB.order.id}` as a `branch_id=1` POS operator and asserting `404` rather than `200`.

---

## 3. P1 — Four POS-surface models lack `addGlobalScope(BranchScope)`

Verified each model file fresh:

### 3.1 `app/Models/OrderStatusTransition.php` (P1, mitigated)
File has NO `booted()` / `addGlobalScope` (lines 1-26, full file).
- **Single producer**: `OrderStateMachine.php:145` does `OrderStatusTransition::query()->create([...])` with explicit `order_id` derived from a scoped `$order`.
- **No consumer reads** were found across `app/` for `OrderStatusTransition::query()` (zero matches outside the writer).
- **Risk**: latent. Today nobody lists these in admin UI; tomorrow a "transition audit trail" admin endpoint would leak cross-branch unless devs remember to filter. Recommend adding the scope now (defense-in-depth), with a `branch_id` column populated via `Order::$casts` or a `creating` event.
- **Note**: the migration does NOT add a `branch_id` column to `order_status_transitions` — adding BranchScope without the column would silently no-op (column missing → SQL error). **Fix requires migration first**, then scope. Lower priority than P0.

### 3.2 `app/Models/PosParkedOrder.php` (P1, mitigated by service)
File has NO scope (full file, lines 1-29 confirmed).
- **Mitigation**: `PosParkedOrderService` (lines 28, 64, 75, 81) manually filters `where('user_id', $userId)->where('branch_id', $branchId)` on every public method (`park`, `listForOperator`, `recall`, `discard`).
- **Risk**: depends on every future caller using the service. If any controller bypasses the service and writes `PosParkedOrder::find($id)` directly, cross-branch leak.
- **Recommend** adding BranchScope now: parked orders carry a `branch_id` column (confirmed `protected $fillable = [..., 'branch_id', ...]`).

### 3.3 `app/Models/OrderQuote.php` (P1, mitigated)
File has NO scope (lines 1-53 confirmed). Has `branch_id` column.
- **Mitigation**: `OrderQuoteService::resolveReplay` (line 337) does `if ((int) $quote->branch_id !== $branchId) throw 401`, and `findOpenQuote` (line 360) filters `where('branch_id', $branchId)`.
- **Risk-of-drift**: the `if !== $branchId throw 401` is correct, but if anyone else uses `OrderQuote::find(...)` (e.g. analytics, admin debug endpoint), leak. Service-level enforcement is fragile. **Recommend BranchScope.**

### 3.4 `app/Models/OrderCoupon.php` (P1, real gap)
File has NO scope (lines 1-22 confirmed). **Does NOT have `branch_id` column** (`protected $fillable = ['order_id', 'coupon_id', 'user_id', 'discount']`).
- **Risk**: `CouponService.php:439` runs `OrderCoupon::where(['user_id' => $userId, 'coupon_id' => $coupon->id])->count()` to enforce `limit_per_user`. A coupon redeemed in branch A counts against the same `user_id` in branch B. This is *probably intentional* for global per-user redemption caps but is undocumented — surfaces as P2 (see §4).
- Adding BranchScope here requires schema work. Not urgent. Document the behavior.

---

## 4. P2 findings

### 4.1 `OrderQuoteService::resolveReplay` (line 332-339)
```php
$quote = OrderQuote::query()
    ->where('quote_token', $token)
    ->lockForUpdate()
    ->first();

if (! $quote || (int) $quote->branch_id !== $branchId) {
    throw new HttpException(401, 'Invalid order quote.');
}
```
- Lookup by `quote_token` alone, then branch validated after. Acceptable IF tokens are HMAC-bound to branch (they are, line 350 `hash_equals($quote->intent_hash, ...)`).
- Still: **add `->where('branch_id', $branchId)` BEFORE `first()`** so the row never enters memory. Defense-in-depth + index efficiency.

### 4.2 `OrderCoupon::limit_per_user` is global, not branch-scoped (line 439)
- Undocumented behavior: a coupon's `limit_per_user` counts across ALL branches.
- If FoodKing's V1 doctrine says coupons belong to a branch (or the marketplace), this is wrong. If global is intentional, add a code comment + doc entry.

---

## 5. P3 — Test coverage gaps

- `tests/Feature/Branch/OrderBranchIsolationTest.php` covers the `Order::query()` scope but does NOT hit the `posOrder.show` route. There is no regression test pinning P0 §2 closed once fixed.
- No tests for `PosParkedOrder` cross-branch isolation.
- No tests for `OrderQuote` cross-branch token replay (branch A operator replays branch B's quote token).
- `withoutGlobalScope` audit: my grep across `app/Http/Controllers/Admin/Pos*.php + Admin/Pos/*` returned exactly **one occurrence — line 108 of PosOrderController**, which is the P0. All other `withoutGlobalScope` usages live in fiscal services, kiosk login (pre-auth), and cleanup jobs (legitimate, documented).

---

## 6. Suggested Feature scenarios

1. **`test_pos_order_show_denies_cross_branch_access`** — branch A POS operator hits `/api/v1/admin/pos-order/show/{branchB.order.id}` → 404 (currently 200).
2. **`test_parked_order_recall_denies_cross_branch`** — branch A operator tries `/api/v1/admin/pos/parked-orders/{branchB.parked.id}` → 404 (currently OK via service).
3. **`test_quote_token_replay_denied_when_branch_mismatch`** — capture branch B quote token, replay in branch A context → 401.
4. **`test_coupon_limit_per_user_is_cross_branch_documented`** — assert (and document) that one user's redemption in branch A counts against the limit in branch B.
5. **`test_pos_order_reorder_items_respects_branch_scope`** — `posOrder.reorderItems` uses route-model binding so it's already scoped, but pin it.

---

## 7. Verdict & blocker list for V1 merge

| ID | File:line | Severity | Action |
|---|---|---|---|
| BI-1 | `PosOrderController.php:108` | **P0** | Remove `withoutGlobalScope` OR add admin-only guard. **Blocker.** |
| BI-2 | `OrderStatusTransition.php` | P1 | Migration + scope. Track. |
| BI-3 | `PosParkedOrder.php` | P1 | Add scope. Track. |
| BI-4 | `OrderQuote.php` | P1 | Add scope. Track. |
| BI-5 | `OrderCoupon.php` | P1 | Decide policy (branch-scoped vs global) + document. |
| BI-6 | `OrderQuoteService.php:332` | P2 | Defense-in-depth `where('branch_id',…)`. |
| BI-7 | tests | P3 | Add 5 Feature scenarios above. |

**V1 merge gate**: **BLOCK** on BI-1 only. Past audit (P0-06) was correct, recent re-spot-check missed the real path.

---

## 8. Method log

- Grepped `withoutGlobalScope` across `app/Http/Controllers/Admin/**` — 1 hit (P0).
- Read each of the 17 in-scope models for `addGlobalScope(new BranchScope())`. 13 have it, 4 don't.
- Read `OrderQuoteService`, `PosParkedOrderService`, `CouponService` to assess mitigation.
- Read `routes` to confirm route-model binding for sister methods.
- Skimmed existing `BranchIsolationTest` files to identify coverage gaps.

No code modified.
