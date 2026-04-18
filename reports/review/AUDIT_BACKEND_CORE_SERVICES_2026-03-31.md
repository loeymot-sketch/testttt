# Backend Core Services Audit — 2026-03-31

**Auditor**: Claude (Architect & Reviewer)
**Scope**: `FrontendOrderService`, `OrderService`, `CouponService`, `KitchenDisplaySystemOrderService`
**Methodology**: Full file read, cross-referencing with `OrderStatus` enum, `ValidStatusTransition` rule, `OrderRequest` validation, `FrontendOrder` model, and architecture docs.

---

## Executive Summary

The codebase has undergone significant hardening (server-side price recalculation, idempotency keys, cross-item injection guards, IDOR checks, Cache-lock queue allocation, post-commit event dispatch). The overall security posture is **good**. This audit identifies **remaining** issues ranging from critical race conditions to low-severity inconsistencies.

| Severity | Count |
|----------|-------|
| CRITICAL | 2     |
| HIGH     | 7     |
| MEDIUM   | 9     |
| LOW      | 6     |
| **Total**| **24**|

---

## 1. FrontendOrderService.php

### FINDING F-01 — CRITICAL: Idempotency check outside transaction (race window)
- **File**: `app/Services/FrontendOrderService.php` lines 113–123
- **Severity**: CRITICAL
- **Description**: The idempotency lookup (`FrontendOrder::where('idempotency_key', ...)->first()`) runs **before** the `DB::transaction()` block. Two concurrent requests with the same key can both pass this check (both see NULL), both enter the transaction, and one will succeed while the other hits the DB unique constraint. The DB-level catch at line 521–529 handles this gracefully, **but** the pre-transaction check at line 113 reads without `lockForUpdate()`, meaning the first request's order may not yet be committed when the second request reads. The second request could get a stale NULL and proceed into the full transaction body, wasting resources and risking partial side-effects (e.g., loyalty point deduction) before the unique constraint fires.
- **Impact**: Under high concurrency (kiosk double-tap with network retry), loyalty points could be deducted inside the transaction before the unique constraint exception rolls it back — but `Cache::lock` for queue numbers is already acquired and released, potentially consuming a queue number that gets rolled back.
- **Suggested Fix**: Move the idempotency check inside the transaction with `lockForUpdate()`, or use a Redis-based idempotency lock (e.g., `Cache::lock('idem_' . $key, 30)`) before entering the transaction to serialize duplicate requests.

### FINDING F-02 — HIGH: Redundant `DB::rollBack()` absent but `DB::transaction()` pattern is correct
- **File**: `app/Services/FrontendOrderService.php` lines 130–499
- **Severity**: LOW (informational — positive finding)
- **Description**: Unlike `OrderService` (see O-03), `FrontendOrderService::myOrderStore()` correctly does NOT call `DB::rollBack()` in its catch block. The `DB::transaction()` closure handles rollback automatically. This is the correct pattern.

### FINDING F-03 — HIGH: `loyaltyApplied` flag survives transaction rollback
- **File**: `app/Services/FrontendOrderService.php` line 421
- **Severity**: HIGH
- **Description**: `$this->loyaltyApplied = true` is set inside the transaction closure. If the transaction subsequently fails (e.g., at `$this->frontendOrder->save()` on line 463), the transaction rolls back (loyalty points are restored in DB), but `$this->loyaltyApplied` remains `true` on the service instance. The catch block at line 533 does not reset it. If the caller checks `$this->loyaltyApplied` after catching the exception, it will see a false positive.
- **Suggested Fix**: Reset `$this->loyaltyApplied = false` in the catch block, or use a local variable inside the closure and only assign to `$this->loyaltyApplied` after the transaction commits.

### FINDING F-04 — HIGH: Item-level discount trusts client value
- **File**: `app/Services/FrontendOrderService.php` line 304
- **Severity**: HIGH
- **Description**: `'discount' => (float) ($item->discount ?? 0)` — the per-item discount is taken directly from the client payload. While the order-level `discount` field is properly unset and recalculated server-side (line 173), the **item-level** discount is not validated. A malicious client could send `"discount": 999` on an item, and this value is stored in `order_items.discount`. If any downstream logic (reports, refund calculations, receipt printing) uses `order_items.discount`, it would be manipulated.
- **Suggested Fix**: Either always set item-level discount to `0` for frontend/kiosk orders (since item-level discounts are a POS-only feature), or validate it against a server-side promotion engine.

### FINDING F-05 — MEDIUM: Quantity not validated server-side
- **File**: `app/Services/FrontendOrderService.php` line 291
- **Severity**: MEDIUM
- **Description**: `$item->quantity` is used directly from the decoded JSON. The `OrderRequest` validates `items` as `json` with `ValidJsonOrder`, but there is no explicit check that quantity is a positive integer. A client could send `quantity: 0` (resulting in a zero-price item stored in the DB) or `quantity: -1` (resulting in a negative `$verifiedTotalPrice` that reduces the subtotal). The `max(0, ...)` on line 451 prevents a negative total, but the subtotal and individual item records would be corrupted.
- **Suggested Fix**: Add `if ($item->quantity <= 0) throw new \InvalidArgumentException(...)` before line 291.

### FINDING F-06 — MEDIUM: Queue number fallback can collide
- **File**: `app/Services/FrontendOrderService.php` lines 351–354
- **Severity**: MEDIUM
- **Description**: The lock-timeout fallback uses `(int)(microtime(true) * 10) % 9999 + 1`. Two requests hitting the fallback within the same 100ms window will generate the same queue number. While queue numbers are not unique-constrained in the DB (they're display-only), duplicate queue numbers cause confusion on the KDS/OSS screens.
- **Suggested Fix**: Use `random_int(1, 9999)` or append a random suffix to reduce collision probability.

### FINDING F-07 — MEDIUM: `changeStatus` notifications dispatched before save on cancel
- **File**: `app/Services/FrontendOrderService.php` lines 605–607
- **Severity**: MEDIUM
- **Description**: In the `changeStatus` method, `SendOrderMail/Sms/Push::dispatch()` is called on line 605–607 **after** `$frontendOrder->save()` on line 594, which is correct. However, the `event(new OrderStatusChanged(...))` on line 597 is dispatched **before** the mail/sms/push dispatches but **after** save — this is fine. But note: the entire cancel flow is NOT wrapped in a `DB::transaction()`. If `cashBack()` succeeds (line 586–589) but `$frontendOrder->save()` fails, the cashback is not rolled back.
- **Suggested Fix**: Wrap the cancel flow (cashback + status save) in `DB::transaction()`.

### FINDING F-08 — MEDIUM: `show()` returns empty array instead of 403
- **File**: `app/Services/FrontendOrderService.php` lines 544–555
- **Severity**: MEDIUM
- **Description**: When `$frontendOrder->user_id != Auth::user()->id`, the method returns `[]` instead of throwing a 403. This silently hides the order's existence from unauthorized users (which is good for enumeration), but the caller may not handle an empty array correctly, potentially causing a 200 response with empty data instead of a proper error.
- **Suggested Fix**: Throw a 403 exception or return a structured error, consistent with `OrderService::show()` which uses `abort(403)`.

### FINDING F-09 — LOW: `$loyaltyApplied` set to `($existing->discount > 0)` on idempotency hit
- **File**: `app/Services/FrontendOrderService.php` line 120
- **Severity**: LOW
- **Description**: On idempotency hit, `loyaltyApplied` is inferred from `$existing->discount > 0`. But a discount could come from a coupon, not loyalty. This means the kiosk could show a "loyalty applied" toast when actually a coupon was applied. The order doesn't store a `loyalty_discount_amount` field separately.
- **Suggested Fix**: Store a `loyalty_discount` column on the order, or check `$existing->loyalty_customer_code` presence combined with discount.

---

## 2. OrderService.php

### FINDING O-01 — CRITICAL: `list()` and `userOrder()` have SQL injection via `order_column`
- **File**: `app/Services/OrderService.php` lines 76–77, 149–150, 184–185, 222–223
- **Severity**: CRITICAL
- **Description**: In `list()`, `userOrder()`, `deliveredOrder()`, `deliveryBoyOrder()`, and `salesReportOverview()`, the `order_column` parameter is taken directly from the request and passed to `->orderBy($orderColumn, $orderType)` **without any whitelist validation**. Unlike `FrontendOrderService::myOrder()` (which has a proper whitelist at line 72–74) and `KitchenDisplaySystemOrderService::list()` (which also has a whitelist), `OrderService` methods trust the client value. An attacker could inject `(SELECT password FROM users LIMIT 1)` as the column name, potentially leaking data via timing or error-based SQL injection.
- **Suggested Fix**: Add the same whitelist pattern used in `FrontendOrderService`:
  ```php
  $allowedColumns = ['id', 'order_serial_no', 'total', 'order_datetime', 'status', 'created_at'];
  $orderColumn = in_array($request->get('order_column', 'id'), $allowedColumns, true)
      ? $request->get('order_column', 'id') : 'id';
  ```

### FINDING O-02 — HIGH: `order_by` direction not validated in multiple methods
- **File**: `app/Services/OrderService.php` lines 77, 150, 185, 223
- **Severity**: HIGH
- **Description**: `$orderType = $request->get('order_by') ?? 'desc'` — the sort direction is not validated against `['asc', 'desc']`. While Laravel's query builder does sanitize this in recent versions, it's still a defense-in-depth gap.
- **Suggested Fix**: Add `$orderType = in_array(strtolower($orderType), ['asc', 'desc'], true) ? $orderType : 'desc';`

### FINDING O-03 — HIGH: Redundant `DB::rollBack()` after `DB::transaction()` in catch blocks
- **File**: `app/Services/OrderService.php` lines 485, 851, 1084, 1433
- **Severity**: HIGH
- **Description**: Multiple methods call `DB::rollBack()` in their catch blocks after `DB::transaction()` has already automatically rolled back. This is dangerous because:
  1. If there's a **nested transaction** (e.g., an event listener that opens its own transaction), the extra `rollBack()` will decrement the transaction nesting level incorrectly, potentially rolling back an outer transaction.
  2. If no transaction is active (already rolled back), it throws a `RuntimeException` in some DB drivers.
- **Affected methods**: `myOrderStore()` (line 485), `posOrderStore()` (line 851), `tableOrderStore()` (line 1084), `destroy()` (line 1433)
- **Suggested Fix**: Remove all `DB::rollBack()` calls from catch blocks that follow `DB::transaction()` closures.

### FINDING O-04 — HIGH: `myOrderStore()` coupon validation is weaker than `FrontendOrderService`
- **File**: `app/Services/OrderService.php` lines 406–419
- **Severity**: HIGH
- **Description**: `OrderService::myOrderStore()` does its own inline coupon validation (lines 406–419) instead of using `CouponService::resolveCouponById()` + `calculateDiscountAmount()`. This means:
  - **No date validation**: Expired coupons are accepted.
  - **No limit_per_user check**: Users can reuse coupons beyond their limit.
  - **No minimum_order check**: Coupons can be applied to orders below the minimum.
  - This is inconsistent with `FrontendOrderService::myOrderStore()` (line 363) which correctly delegates to `CouponService`.
- **Suggested Fix**: Replace the inline coupon logic with `app(CouponService::class)->resolveCouponById()` + `calculateDiscountAmount()`, matching `FrontendOrderService`.

### FINDING O-05 — HIGH: `tableOrderStore()` has the same weak coupon validation
- **File**: `app/Services/OrderService.php` lines 1010–1024
- **Severity**: HIGH
- **Description**: Same issue as O-04. `tableOrderStore()` uses inline coupon logic without date/limit/minimum checks.
- **Suggested Fix**: Same as O-04.

### FINDING O-06 — HIGH: `posOrderStore()` has the same weak coupon validation
- **File**: `app/Services/OrderService.php` lines 711–726
- **Severity**: HIGH
- **Description**: Same issue as O-04. `posOrderStore()` uses inline coupon logic without date/limit/minimum checks.
- **Suggested Fix**: Same as O-04. For POS, the cashier is trusted more, but expired coupons should still be rejected server-side.

### FINDING O-07 — MEDIUM: `tableOrderStore()` missing cross-item injection guard on variations/extras
- **File**: `app/Services/OrderService.php` lines 918–935
- **Severity**: MEDIUM
- **Description**: `tableOrderStore()` does NOT check that a variation/extra belongs to the correct item (`$dbVar->item_id !== $item->item_id`). Both `myOrderStore()` and `posOrderStore()` have this guard (see lines 600–605, 320), but `tableOrderStore()` silently accepts cross-item variations/extras. A manipulated QR-code order could attach a cheap item's variation to an expensive item.
- **Suggested Fix**: Add the same `item_id` ownership check as in `posOrderStore()`.

### FINDING O-08 — MEDIUM: `tableOrderStore()` does not strip client financial fields
- **File**: `app/Services/OrderService.php` line 865–872
- **Severity**: MEDIUM
- **Description**: Unlike `myOrderStore()` (line 260) and `posOrderStore()` (line 514), `tableOrderStore()` does NOT `unset($validated['total'], $validated['subtotal'], $validated['discount'])` before `FrontendOrder::create()`. While the total is recalculated and overwritten later (lines 1034–1037), the initial `create()` call persists the client-supplied values momentarily. If the transaction fails between `create()` and `save()`, the DB row retains manipulated financial fields.
- **Suggested Fix**: Add the same `unset()` pattern before `create()`.

### FINDING O-09 — MEDIUM: `changeStatus()` dispatches notifications BEFORE `$order->save()`
- **File**: `app/Services/OrderService.php` lines 1286–1290
- **Severity**: MEDIUM
- **Description**: In the non-auth branch of `changeStatus()`, notifications are dispatched (lines 1286–1288) **before** the status is saved (line 1290). If `save()` fails, notifications with the new status have already been sent. This is the opposite of the post-commit pattern used in `myOrderStore()`.
- **Suggested Fix**: Move notification dispatch after `$order->save()`, or wrap in a transaction with post-commit dispatch.

### FINDING O-10 — MEDIUM: LIKE wildcard injection in filter queries
- **File**: `app/Services/OrderService.php` lines 113, 157, 193, 229, 1474
- **Severity**: MEDIUM
- **Description**: Multiple `list()` methods use `$query->where($key, 'like', '%' . $request . '%')` without escaping `%` and `_` wildcards in the user input. A user could send `order_serial_no=%` to match all orders, or use `_` as a single-character wildcard. This is not SQL injection (parameterized queries prevent that), but it's a **data leakage** vector — a user could craft LIKE patterns to enumerate order serial numbers.
- **Suggested Fix**: Escape wildcards: `str_replace(['%', '_'], ['\\%', '\\_'], $request)`.

### FINDING O-11 — LOW: `$order` local variable in `posOrderStore()` is assigned but `$this->order` is returned
- **File**: `app/Services/OrderService.php` lines 507, 819, 835
- **Severity**: LOW
- **Description**: `$order` is declared at line 507 and assigned at line 819, but the method returns `$this->order` at line 835. The `$order` variable is only used for post-commit notifications (line 823). This works because `$this->order` is the same reference, but the dual-variable pattern is confusing and error-prone.
- **Suggested Fix**: Remove the `$order` local variable and use `$this->order` consistently.

### FINDING O-12 — LOW: `deliveryBoyOrderChangeStatus` dispatches notifications before save
- **File**: `app/Services/OrderService.php` lines 1206–1210
- **Severity**: LOW
- **Description**: Same pattern as O-09 — notifications dispatched before `$order->save()`.

---

## 3. CouponService.php

### FINDING C-01 — HIGH: `list()` has SQL injection via `order_column` and `order_type`
- **File**: `app/Services/CouponService.php` lines 48–49, 74
- **Severity**: HIGH
- **Description**: `$orderColumn` and `$orderType` are taken directly from request without whitelist validation, then passed to `->orderBy($orderColumn, $orderType)`. Same class of vulnerability as O-01.
- **Suggested Fix**: Whitelist both parameters.

### FINDING C-02 — MEDIUM: LIKE wildcard injection in `list()` filter
- **File**: `app/Services/CouponService.php` line 61
- **Severity**: MEDIUM
- **Description**: `$query->where($key, 'like', '%' . $request . '%')` — user input is not escaped for LIKE wildcards. Allows enumeration of coupon codes via pattern matching (e.g., sending `code=SUMMER%` to find all summer coupons).
- **Suggested Fix**: Escape `%` and `_` in user input.

### FINDING C-03 — MEDIUM: `couponDateWise()` loads ALL coupons into memory
- **File**: `app/Services/CouponService.php` lines 186–193
- **Severity**: MEDIUM
- **Description**: `Coupon::all()` loads every coupon into memory, then filters in PHP. With thousands of coupons, this causes memory pressure. The null-date guard (line 188) is good, but the approach is inefficient.
- **Suggested Fix**: Use a database query: `Coupon::whereNotNull('start_date')->whereNotNull('end_date')->where('start_date', '<=', now())->where('end_date', '>=', now())->get()`.

### FINDING C-04 — LOW: `validateCouponForOrder()` does not check coupon `status` or `is_active` field
- **File**: `app/Services/CouponService.php` lines 257–291
- **Severity**: LOW
- **Description**: The validation checks dates, minimum order, and limit per user, but does not check if the coupon has an active/inactive status flag. If the Coupon model has a `status` column (common in this codebase pattern), disabled coupons could still be applied.
- **Suggested Fix**: Add `if ($coupon->status === 0) throw new Exception(...)` if such a field exists.

### FINDING C-05 — LOW: `calculateDiscountAmount()` does not guard against negative `$coupon->discount`
- **File**: `app/Services/CouponService.php` lines 238–249
- **Severity**: LOW
- **Description**: If `$coupon->discount` is negative (data corruption or admin error), the calculation would produce a negative amount. The `max(0, ...)` on line 249 prevents a negative return, so this is properly guarded. However, a negative discount combined with PERCENTAGE type could produce unexpected results before the clamp.
- **Impact**: Minimal due to the `max(0, ...)` guard.

---

## 4. KitchenDisplaySystemOrderService.php

### FINDING K-01 — MEDIUM: LIKE wildcard injection in `list()` filter
- **File**: `app/Services/KitchenDisplaySystemOrderService.php` line 80
- **Severity**: MEDIUM
- **Description**: `$query->where($key, 'like', '%' . $request . '%')` — same pattern as O-10. Allows pattern-based enumeration of order serial numbers on the KDS.
- **Suggested Fix**: Escape `%` and `_` wildcards.

### FINDING K-02 — LOW: `list()` applies status filter via LIKE instead of exact match for non-status fields
- **File**: `app/Services/KitchenDisplaySystemOrderService.php` lines 77–80
- **Severity**: LOW
- **Description**: The `status` field gets an exact `(int)` match (line 78), but `order_serial_no`, `branch_id`, `order_type`, `source`, and `payment_method` all use LIKE. For numeric fields like `branch_id` and `payment_method`, LIKE matching is semantically wrong — `branch_id=1` would match `branch_id=10, 11, 12...`.
- **Suggested Fix**: Use exact match for numeric filter fields.

### FINDING K-03 — LOW: Branch isolation relies on `auth()->user()->branch_id`
- **File**: `app/Services/KitchenDisplaySystemOrderService.php` lines 48, 55–57
- **Severity**: LOW
- **Description**: Branch isolation is correctly implemented — `branch_id > 0` filters to the user's branch, and admin (`branch_id = 0`) sees all. This is a **positive finding**. However, if `auth()->user()` is null (unauthenticated request reaching this service), it would throw a null pointer. The controller should enforce auth, but the service has no defensive check.
- **Suggested Fix**: Add `if (!auth()->check()) throw new Exception('Unauthenticated', 401);` at the top of `list()` and `orderItems()`.

### FINDING K-04 — LOW: `changeStatus()` does not verify branch ownership
- **File**: `app/Services/KitchenDisplaySystemOrderService.php` lines 105–138
- **Severity**: LOW
- **Description**: The `changeStatus()` method validates the status transition but does not check that the authenticated user's branch matches the order's branch. A KDS user from branch A could theoretically change the status of a branch B order if they know the order ID. The `list()` method filters by branch, so the KDS UI wouldn't show cross-branch orders, but a direct API call could bypass this.
- **Suggested Fix**: Add branch ownership check: `if ($userBranchId > 0 && $order->branch_id !== $userBranchId) abort(403);`

---

## Cross-Service Consistency Issues

### FINDING X-01 — HIGH: Coupon validation inconsistency across surfaces
- **Severity**: HIGH
- **Description**: Three different coupon validation patterns exist:
  1. **FrontendOrderService** (kiosk/web): Uses `CouponService::resolveCouponById()` + `calculateDiscountAmount()` — **full validation** (dates, limits, minimum order).
  2. **OrderService::myOrderStore** (web/app): Inline `Coupon::find()` + manual calculation — **no date/limit/minimum check**.
  3. **OrderService::posOrderStore/tableOrderStore**: Same inline pattern — **no date/limit/minimum check**.
- **Impact**: Expired coupons, over-limit usage, and below-minimum-order coupons are accepted on web, POS, and table surfaces.
- **Suggested Fix**: All three surfaces should delegate to `CouponService::resolveCouponById()` + `calculateDiscountAmount()`.

### FINDING X-02 — MEDIUM: `order_column` whitelist inconsistency
- **Severity**: MEDIUM
- **Description**: `FrontendOrderService::myOrder()` and `KitchenDisplaySystemOrderService::list()` have proper column whitelists. `OrderService` methods (list, userOrder, deliveredOrder, deliveryBoyOrder, salesReportOverview) do NOT. `CouponService::list()` does NOT.

---

## Summary of Required Actions

### Immediate (CRITICAL)
1. **O-01**: Add `order_column` whitelist to all `OrderService` list methods.
2. **F-01**: Harden idempotency check with a distributed lock or move inside transaction.

### Short-term (HIGH)
3. **O-03**: Remove all redundant `DB::rollBack()` calls.
4. **O-04/O-05/O-06/X-01**: Replace inline coupon validation with `CouponService` delegation in all `OrderService` methods.
5. **F-03**: Reset `loyaltyApplied` on transaction failure.
6. **F-04**: Force item-level discount to 0 for non-POS orders.
7. **C-01**: Add column whitelist to `CouponService::list()`.
8. **O-02**: Validate sort direction in `OrderService`.

### Medium-term (MEDIUM)
9. **F-05**: Validate item quantity > 0.
10. **O-07**: Add cross-item injection guard to `tableOrderStore()`.
11. **O-08**: Strip client financial fields in `tableOrderStore()`.
12. **O-09**: Move notification dispatch after save in `changeStatus()`.
13. **O-10/C-02/K-01**: Escape LIKE wildcards across all services.
14. **F-06**: Improve queue number fallback randomness.
15. **F-07**: Wrap cancel flow in transaction.
16. **C-03**: Optimize `couponDateWise()` query.

### Low priority (LOW)
17. **F-08**: Return 403 instead of empty array in `show()`.
18. **F-09**: Fix `loyaltyApplied` inference on idempotency hit.
19. **K-04**: Add branch check to KDS `changeStatus()`.
20. **K-02/K-03**: Minor KDS filter and defensive improvements.

---

**Verdict**: `NEEDS_FIX` — Two CRITICAL and seven HIGH findings require attention before the next release. The price recalculation and idempotency infrastructure is solid, but the inconsistent coupon validation and unwhitelisted sort columns are exploitable.
