# FoodKing — Open Risks Registry

> Generated 2026-04-12 from code inspection.
> Severity: **CRITICAL** (data loss / financial / security), **HIGH** (operational failure / UX breakage), **MEDIUM** (inconsistency / tech debt), **LOW** (cosmetic / minor).

---

## RISK-01 — Delivery boy status change dispatches events before DB save

**Severity**: CRITICAL
**Source**: `app/Services/OrderService.php` — `deliveryBoyOrderChangeStatus()`, lines dispatching `SendOrderMail/Sms/Push` before `$order->save()`
**Affected surfaces**: POS, KDS, OSS, customer notifications
**Impact**: If `$order->save()` fails after events are dispatched, phantom notifications reach the customer and phantom broadcasts reach KDS/OSS for a status change that never persisted.
**Recommended inspection**: Read `deliveryBoyOrderChangeStatus()` line by line; write unit test that simulates save failure after event dispatch.
**Inspection order**: **1st** (financial + UX impact)

---

## RISK-02 — `RefreshTokenController` does not revoke old tokens

**Severity**: HIGH
**Source**: `app/Http/Controllers/Auth/RefreshTokenController.php` — `refreshToken()` creates new token without deleting old
**Affected surfaces**: All authenticated surfaces (admin, POS, kiosk, frontend)
**Impact**: Token accumulation — old tokens remain valid indefinitely. No forced session invalidation on refresh. Stolen tokens cannot be revoked by refreshing.
**Recommended inspection**: Verify if token expiration is configured in `config/sanctum.php` (`expiration` key). If not set, tokens live forever.
**Inspection order**: **2nd** (security)

---

## RISK-03 — Table/QR order creation has no Sanctum authentication

**Severity**: HIGH
**Source**: `routes/api.php` — `table/*` group has `installed` + `apiKey` only; `app/Http/Controllers/Table/OrderController.php`
**Affected surfaces**: Table ordering, financial (order creation)
**Impact**: Anyone who obtains the `apiKey` (often hardcoded in frontend JS bundles) can create unlimited table orders without authentication. Throttle (20/min) is the only defense.
**Recommended inspection**: Check if `apiKey` is exposed in frontend JS. Check if `TableOrderRequest` validates `dining_table_id` existence and branch ownership.
**Inspection order**: **3rd** (security + financial)

---

## RISK-04 — Coupon `start_date` not checked in `couponChecking`

**Severity**: MEDIUM
**Source**: `app/Services/CouponService.php` — `couponChecking()` only validates `end_date >= now`, no `start_date` check
**Affected surfaces**: All order surfaces using coupons (kiosk, web, POS)
**Impact**: Customers can redeem coupons before their intended activation date. Promotions may execute early, causing unplanned revenue loss.
**Recommended inspection**: Add `start_date` check to `couponChecking()`. Verify `couponDateWise()` (which does check both dates) is used for display but `couponChecking` is the enforcement point.
**Inspection order**: **4th** (financial)

---

## RISK-05 — `OrderCoupon.discount` stores combined coupon + loyalty discount

**Severity**: MEDIUM
**Source**: `app/Services/FrontendOrderService.php` — `myOrderStore()`, `OrderCoupon::create(['discount' => $calculatedDiscount])` where `$calculatedDiscount` includes loyalty reduction
**Affected surfaces**: Kiosk, web frontend, reporting/analytics
**Impact**: Per-coupon revenue attribution is impossible from `OrderCoupon` alone. Reports that sum coupon discounts will overstate coupon impact and understate loyalty impact.
**Recommended inspection**: Separate coupon and loyalty discount storage, or add `loyalty_discount` column.
**Inspection order**: **5th** (reporting accuracy)

---

## RISK-06 — `ShouldBroadcastNow` has no retry on Pusher failure

**Severity**: HIGH
**Source**: `app/Events/OrderCreated.php`, `app/Events/OrderStatusChanged.php` — implement `ShouldBroadcastNow`
**Affected surfaces**: KDS, OSS, POS realtime updates
**Impact**: `ShouldBroadcastNow` broadcasts synchronously. If Pusher/WebSocket connection times out or fails, the broadcast is lost with no retry. KDS may miss new orders; OSS may show stale queue.
**Recommended inspection**: Check if Pusher SDK has built-in retry; consider fallback to `ShouldBroadcast` (queued) with retry logic, or add try/catch with logging around dispatch.
**Inspection order**: **6th** (operational reliability)

---

## RISK-07 — Admin can bypass terminal status restrictions

**Severity**: MEDIUM
**Source**: `app/Rules/ValidStatusTransition.php` — lines 79-81: Admin role can transition from CANCELED/REJECTED/RETURNED to any status
**Affected surfaces**: All order surfaces, financial records, loyalty
**Impact**: Admin can resurrect canceled/rejected orders without secondary confirmation. No audit gate or reason requirement for terminal→active transitions. Loyalty points may be re-awarded on re-delivery.
**Recommended inspection**: Add reason/confirmation requirement for admin terminal bypasses. Verify `AwardLoyaltyPointsOnDelivery` idempotency sentinel handles re-delivery correctly.
**Inspection order**: **7th** (business rules integrity)

---

## RISK-08 — Same-status transition re-triggers all event listeners

**Severity**: MEDIUM
**Source**: `app/Rules/ValidStatusTransition.php` — line 37: `$current === $newStatus` returns true; all `changeStatus()` methods dispatch `OrderStatusChanged` on any valid transition
**Affected surfaces**: KDS, OSS, customer notifications, loyalty
**Impact**: Setting an order to its current status triggers `OrderStatusChanged` → `AwardLoyaltyPointsOnDelivery` (idempotency sentinel protects), `SendFcmOnOrderStatusChange` (duplicate FCM push), broadcast (redundant WebSocket message).
**Recommended inspection**: Add early return in services when old status equals new status, or guard in `ValidStatusTransition`.
**Inspection order**: **8th** (UX + notification noise)

---

## RISK-09 — Kiosk auto-accept outside creation transaction

**Severity**: HIGH
**Source**: `app/Services/FrontendOrderService.php` — `myOrderStore()`, post-commit auto-accept block
**Affected surfaces**: Kiosk, KDS
**Impact**: Kiosk orders for kiosk machines go through auto-accept (`PENDING→ACCEPT`) in a separate `save()` call after the creation transaction commits. If the process crashes between commit and auto-accept, the order stays PENDING and never appears on KDS (since KDS filters for ACCEPT+).
**Recommended inspection**: Consider wrapping auto-accept in the same transaction, or adding a recovery job that promotes stale PENDING kiosk orders.
**Inspection order**: **9th** (operational reliability)

---

## RISK-10 — `FrontendOrder.total_tax` not in `$casts`

**Severity**: LOW
**Source**: `app/Models/FrontendOrder.php` — `total_tax` is in `$fillable` but NOT in `$casts` (unlike `Order` which casts it as `decimal:6`)
**Affected surfaces**: Kiosk/web order tax display, reports
**Impact**: `total_tax` may be returned as string or with inconsistent precision compared to `Order` model. Could cause display mismatches or comparison failures in code that handles both models.
**Recommended inspection**: Add `'total_tax' => 'decimal:6'` to `FrontendOrder::$casts`.
**Inspection order**: **10th** (consistency)

---

## RISK-11 — `TableOrderController::tokenCreate` lacks Spatie permission

**Severity**: MEDIUM
**Source**: `app/Http/Controllers/Admin/TableOrderController.php` — `tokenCreate` not listed in constructor `middleware()->only([...])`
**Affected surfaces**: Table ordering admin
**Impact**: Any authenticated user (with Sanctum token) can call `tokenCreate` regardless of role — no Spatie permission check. This is a privilege escalation if `tokenCreate` performs a sensitive operation.
**Recommended inspection**: Add `'tokenCreate'` to the permission middleware `only()` array or add explicit `abort_unless` in the method.
**Inspection order**: **11th** (authorization)

---

## RISK-12 — `OnlineOrderController::destroy` referenced in routes but not implemented

**Severity**: LOW
**Source**: `routes/api.php` — `DELETE admin/online-order/{order}` → `OnlineOrderController@destroy`; method does not exist in controller
**Affected surfaces**: Admin panel
**Impact**: DELETE requests return 500 (BadMethodCallException). No data loss risk but pollutes error logs and exposes internal stack traces if debug mode is on.
**Recommended inspection**: Either implement the method or remove the route.
**Inspection order**: **12th** (code quality)

---

## RISK-13 — Redundant `DB::rollBack()` after `DB::transaction` closures

**Severity**: LOW
**Source**: `app/Services/OrderService.php` — catch blocks in `myOrderStore`, `posOrderStore`, `tableOrderStore`, `destroy`
**Affected surfaces**: All order creation paths
**Impact**: Laravel's `DB::transaction()` closure automatically rolls back on exception. The extra `DB::rollBack()` in the catch can decrement the transaction nesting counter below zero, potentially causing issues with nested transactions or savepoints.
**Recommended inspection**: Remove redundant rollback calls; verify no nested transaction patterns exist.
**Inspection order**: **13th** (tech debt)

---

## RISK-14 — `ItemAvailabilityChanged` broadcasts to ALL active branches

**Severity**: LOW
**Source**: `app/Events/ItemAvailabilityChanged.php` — `broadcastOn()` queries `Branch::where('status', ACTIVE)->pluck('id')` and creates one `PrivateChannel` per branch
**Affected surfaces**: All branch kiosk/POS displays
**Impact**: Item availability changes are broadcast to every branch even if the item is branch-specific. Unnecessary WebSocket traffic; no data leak if items are properly filtered on frontend, but increases load on Pusher.
**Recommended inspection**: Verify if items are branch-scoped; if so, broadcast only to relevant branch(es).
**Inspection order**: **14th** (performance)

---

## RISK-15 — Loyalty idempotency sentinel race condition

**Severity**: MEDIUM
**Source**: `app/Listeners/AwardLoyaltyPointsOnDelivery.php` — sentinel update (`loyalty_points_awarded = -1`) runs outside the listener's `DB::transaction`
**Affected surfaces**: Loyalty program, financial
**Impact**: Between the sentinel `UPDATE` and the inner `DB::transaction` start, a concurrent request could read the sentinel as `-1` (in-progress) and either skip or double-award. The window is small but exists under high concurrency.
**Recommended inspection**: Wrap sentinel + points increment in a single transaction with `lockForUpdate` on the order row.
**Inspection order**: **15th** (financial edge case)

---

## RISK-16 — `KioskMachine.password` mass-assignable

**Severity**: MEDIUM
**Source**: `app/Models/KioskMachine.php` — `password` in `$fillable`
**Affected surfaces**: Kiosk authentication
**Impact**: Any code path that calls `KioskMachine::create()` or `->fill()` with raw input could store an unhashed password. Login would then fail (since `Hash::check` is used), but plaintext passwords in DB are a security violation.
**Recommended inspection**: Verify all `KioskMachine::create` / `update` call sites hash the password. Consider adding a mutator.
**Inspection order**: **16th** (security hardening)

---

## Recommended inspection order (summary)

| Priority | Risk ID | Title |
|----------|---------|-------|
| 1 | RISK-01 | Delivery boy dispatch before save |
| 2 | RISK-02 | Token refresh without revocation |
| 3 | RISK-03 | Table/QR unauthenticated order creation |
| 4 | RISK-04 | Coupon start_date not checked |
| 5 | RISK-05 | Combined discount in OrderCoupon |
| 6 | RISK-06 | ShouldBroadcastNow no retry |
| 7 | RISK-07 | Admin terminal status bypass |
| 8 | RISK-08 | Same-status re-triggers listeners |
| 9 | RISK-09 | Kiosk auto-accept outside transaction |
| 10 | RISK-10 | FrontendOrder.total_tax not cast |
| 11 | RISK-11 | tokenCreate missing permission |
| 12 | RISK-12 | OnlineOrder destroy not implemented |
| 13 | RISK-13 | Redundant DB::rollBack |
| 14 | RISK-14 | Item availability broadcast to all branches |
| 15 | RISK-15 | Loyalty sentinel race condition |
| 16 | RISK-16 | KioskMachine password mass-assignable |
