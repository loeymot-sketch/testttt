# FoodKing — Known Critical Paths

> Generated 2026-04-12 from full code inspection.
> Evidence grades: **confirmed** (code read), **partial** (logic verified but edge cases not exhausted), **unverified** (documented risk, needs targeted inspection).

---

## CP-01 — Order creation: kiosk / web (`FrontendOrderService::myOrderStore`)

**Status**: confirmed

**Why dangerous**: This is the primary customer-facing order path. Any defect here creates real financial exposure (wrong totals, phantom orders, loyalty fraud).

**Key files**:
- `app/Services/FrontendOrderService.php` — `myOrderStore()`
- `app/Http/Controllers/Frontend/OrderController.php` — `store()`
- `app/Http/Requests/OrderRequest.php`
- `app/Models/FrontendOrder.php`
- `app/Models/OrderItem.php`

**Flow**:
1. Controller passes `OrderRequest` to service (no client prices forwarded)
2. Service creates `FrontendOrder` with totals forced to 0
3. Loads `Item` prices + `Tax` + `ItemVariation` + `ItemExtra` from DB
4. Per-line: `(itemPrice + variationTotal + extraTotal) * quantity`
5. Tax: `FIXED ? rate : (verifiedTotal * rate) / 100`
6. Coupon: `PERCENTAGE ? min(subtotal * discount / 100, max_discount) : fixed`
7. Loyalty: `lockForUpdate` on user, decrement points, `LoyaltyTransaction::create`
8. Total: `max(0, subtotal + tax + delivery - discount)`
9. `queue_number` via `Cache::lock` + MAX query on `orders` table
10. Inside `DB::transaction` — all persisted atomically
11. **After commit**: kiosk auto-accept (`PENDING→ACCEPT` via extra `save()` + `OrderStatusChanged` event), then `OrderCreated::dispatch`

**Invariants that can break**:
- SSOT pricing: client values are unset, but `OrderItem` is mass-assignable — any direct `fill()` from request elsewhere would bypass
- Coupon `OrderCoupon::create` runs even when `calculatedDiscount == 0` if `coupon_id > 0` (accounting noise)
- `OrderCoupon.discount` stores **total** discount (coupon + loyalty combined), not coupon-only — ledger ambiguity
- Kiosk auto-accept happens **outside** the creation transaction — crash between insert and auto-accept leaves order stuck in PENDING
- `delivery_charge` used without `?? 0` fallback (differs from `OrderService` paths)

**Verification needed**: `local-validation` (unit test coupon + loyalty edge cases), `playwright-critical-flow` (full kiosk order → KDS)

---

## CP-02 — Order creation: POS (`OrderService::posOrderStore`)

**Status**: confirmed

**Why dangerous**: Cashier-facing path with manual discount capability. POS-specific fields (payment method, received amount, cash check) add complexity.

**Key files**:
- `app/Services/OrderService.php` — `posOrderStore()`
- `app/Http/Controllers/Admin/PosController.php` — `store()`
- `app/Http/Requests/PosOrderRequest.php`
- `app/Models/Order.php`

**Flow**:
1. Same server-side price recalculation pattern as CP-01
2. Line discount from payload `(float)($item->discount ?? 0)` — but NOT subtracted from `realSubtotal` in current loop (dead field or intended for display only)
3. Manual discount branch: `min(manualDiscount, realSubtotal)` when no coupon
4. Cash validation: `pos_received_amount >= server-calculated total` (enforced)
5. Branch check: non-admin user's `branch_id` must match request `branch_id`
6. Throws if `coupon_id > 0` but coupon not found (stricter than frontend)
7. Redundant `DB::rollBack()` in catch after `DB::transaction` — potentially harmful with nested transactions
8. **After commit**: `OrderCreated::dispatch`

**Invariants that can break**:
- Line-level `discount` is accepted from payload but not used in subtotal — potential confusion if later code reads `OrderItem.discount` expecting it was applied
- Redundant rollback in catch may break savepoint nesting

**Verification needed**: `local-validation` (manual discount + coupon mutual exclusion, cash check)

---

## CP-03 — Order creation: table / QR (`OrderService::tableOrderStore`)

**Status**: partial

**Why dangerous**: Table ordering route has **no Sanctum auth** — only `apiKey` + throttle. Looser variation/extra resolution than POS.

**Key files**:
- `app/Services/OrderService.php` — `tableOrderStore()`
- `app/Http/Controllers/Table/OrderController.php` — `store()`
- `app/Http/Requests/TableOrderRequest.php`
- `routes/api.php` — `table/*` group

**Flow**:
1. Price recalculation same core pattern
2. Variation/extra lookup: **silent skip** if DB item not found (vs POS which throws) — allows orders with missing modifiers to succeed silently
3. Coupon: throws if not found (same as POS)
4. Manual discount: same pattern as POS

**Invariants that can break**:
- **No Sanctum auth** on `POST table/dining-order` — anyone with `apiKey` can create table orders
- Silent skip on missing variations means an order can be created with fewer modifiers than the customer selected (and thus a lower total)
- `Table\OrderController::show` returns a `FrontendOrder` via route model binding — **IDOR risk** if no global scope or policy restricts access (BranchScope may help but needs verification)

**Verification needed**: `static-inspection` (variation skip behavior), `playwright-critical-flow` (QR order with variations)

---

## CP-04 — Order status transitions (`ValidStatusTransition`)

**Status**: confirmed

**Why dangerous**: The entire order lifecycle depends on this single validation rule. Any gap allows illegal state jumps.

**Key files**:
- `app/Rules/ValidStatusTransition.php`
- `app/Enums/OrderStatus.php`
- `app/Services/OrderService.php` — `changeStatus()`, `deliveryBoyOrderChangeStatus()`
- `app/Services/FrontendOrderService.php` — `changeStatus()`
- `app/Services/KitchenDisplaySystemOrderService.php` — `changeStatus()`

**Transition matrix** (code-confirmed):

| From | Allowed → | Special |
|------|-----------|---------|
| PENDING (1) | ACCEPT (4), CANCELED (16), REJECTED (19) | — |
| ACCEPT (4) | PREPARING (7), CANCELED (16) | POS permission → DELIVERED (skip KDS) |
| PREPARING (7) | PREPARED (8), CANCELED (16) | POS permission → DELIVERED (skip PREPARED) |
| PREPARED (8) | OUT_FOR_DELIVERY (10), DELIVERED (13) | — |
| OUT_FOR_DELIVERY (10) | DELIVERED (13) | — |
| DELIVERED (13) | RETURNED (22) | — |
| CANCELED (16) | **Any** (Admin only) | Admin bypass from terminal |
| REJECTED (19) | **Any** (Admin only) | Admin bypass from terminal |
| RETURNED (22) | **Any** (Admin only) | Admin bypass from terminal |
| Same status | Always valid (no-op) | — |

**Invariants that can break**:
- **GAP-25-2 shortcuts**: POS can jump `ACCEPT→DELIVERED` or `PREPARING→DELIVERED`, skipping KDS entirely — KDS/OSS displays will never show these orders as PREPARED
- **Admin bypass from terminal states**: Admin can transition `CANCELED→PENDING` or any other combination — no secondary confirmation or audit gate
- **Same-status transitions allowed**: `current === new` returns true — allows redundant status writes + re-triggering of `OrderStatusChanged` event and all its listeners (loyalty, FCM, broadcast)
- `FrontendOrderService::changeStatus` has **additional** guards beyond `ValidStatusTransition`: kiosk can only cancel if `status < PREPARING`; other types only if `< ACCEPT`. These are service-level, not in the rule.

**Verification needed**: `local-validation` (unit test all 9×9 transitions), `static-inspection` (admin bypass audit trail)

---

## CP-05 — KDS / OSS synchronization

**Status**: partial

**Why dangerous**: KDS and OSS rely on realtime events (`ShouldBroadcastNow`). If events fail or fire at wrong time, kitchen and customer displays desync.

**Key files**:
- `app/Events/OrderCreated.php` — `ShouldBroadcastNow`, channel `branch.{branch_id}`
- `app/Events/OrderStatusChanged.php` — `ShouldBroadcastNow`, channel `branch.{branch_id}`
- `app/Services/KitchenDisplaySystemOrderService.php` — `changeStatus()`, `list()`
- `app/Services/OrderStatusScreenOrderService.php` — `list()` (read-only)
- `app/Listeners/SendFcmOnOrderCreated.php`
- `app/Listeners/SendFcmOnOrderStatusChange.php`

**Synchronization flow**:
1. Order created → service commits → `OrderCreated::dispatch` → **synchronous** WebSocket broadcast to `branch.{branch_id}` + FCM job queued
2. Status changed → service commits → `OrderStatusChanged::dispatch` → same broadcast + FCM
3. KDS polls or listens on `branch.{branch_id}` channel; displays ACCEPT/PREPARING/PREPARED orders
4. OSS reads PREPARING/PREPARED orders (filtered by `list()`)

**Invariants that can break**:
- **`ShouldBroadcastNow`** is synchronous — if Pusher/WebSocket connection fails during dispatch, the HTTP request may hang or silently fail; no fallback/retry
- KDS `changeStatus` uses `DB::transaction` around save, then broadcasts **after** — safe for phantom prevention
- But `deliveryBoyOrderChangeStatus` in `OrderService` has **no** `DB::transaction` wrapper — status save + event dispatch are not atomic
- OSS has no write endpoints (confirmed safe) but its data freshness depends entirely on broadcast reliability
- `OrderStatusChanged.broadcastWith()` includes `token` field — this is the customer/public order token, potentially sensitive if channel authorization is weak

**Verification needed**: `playwright-critical-flow` (order create → KDS sees it → status change → OSS updates), `static-inspection` (channel auth in `channels.php`)

---

## CP-06 — Pricing authority (SSOT enforcement)

**Status**: confirmed

**Why dangerous**: The fundamental financial invariant. If client prices leak into the database, every order total is suspect.

**Key files**:
- `app/Services/OrderService.php` — all `*Store()` methods
- `app/Services/FrontendOrderService.php` — `myOrderStore()`
- `app/Models/OrderItem.php` — `$fillable` includes `price`, `total_price`, `discount`, tax fields
- `app/Models/Order.php` — `$fillable` includes `subtotal`, `total`, `discount`, `total_tax`
- `app/Models/FrontendOrder.php` — same monetary fillables

**How SSOT is enforced**:
- All `*Store()` methods explicitly unset client `total`/`subtotal`/`discount` from validated data before `Order::create`
- Item prices loaded fresh from `Item::select('id','price','tax_id')`
- Variation/extra prices loaded from `ItemVariation`/`ItemExtra` by ID
- Line totals and taxes computed server-side, stored via `OrderItem::insert` (bulk)
- Order total computed server-side as `max(0, subtotal + tax + delivery - discount)`

**Where it could break**:
- `OrderItem` is fully mass-assignable for monetary fields — any code path that `create()`s or `fill()`s from raw request data without recalculation would bypass SSOT
- `Order.total_tax` is cast `decimal:6` in `Order` but **NOT cast** in `FrontendOrder` — type inconsistency risk
- `PosOrderController::reorderItems` returns stored `price`/`total_price` from DB for cart re-import — safe (read-only), but if frontend sends these back unmodified to a create endpoint, they'll be recalculated anyway

**Verification needed**: `static-inspection` (grep for any `OrderItem::create` or `Order::fill` outside the 4 store methods)

---

## CP-07 — Branch isolation

**Status**: confirmed

**Why dangerous**: Multi-branch restaurant. Branch leaks expose one branch's orders/data to another branch's staff.

**Key files**:
- `app/Models/Scopes/BranchScope.php`
- `app/Models/Order.php` — registers `BranchScope`
- `app/Models/FrontendOrder.php` — registers `BranchScope`
- `app/Services/OrderService.php` — explicit `branch_id` checks in `posOrderStore`, `changeStatus`, `changePaymentStatus`
- `app/Traits/DefaultAccessModelTrait.php` (inferred — provides `branch()`)

**How isolation works**:
- `BranchScope::apply()`: if `User` model → skip (avoid recursion); if console non-test → skip; if auth user `branch_id > 0` → `WHERE {table}.branch_id = {userBranch}`; if `branch_id === 0` → admin, no filter
- Registered on: `Order`, `FrontendOrder`, `User`, `DiningTable`, `PushNotification`
- Service-level: `posOrderStore` aborts 403 if non-admin user `branch_id` doesn't match request; `changeStatus` checks `order.branch_id` matches user

**Where it could break**:
- **NOT registered** on `OrderItem`, `OrderCoupon`, `OrderAddress`, `Transaction`, `Item`, `ItemCategory` — these are accessed through relationships from scoped parents, but any direct query (e.g., `OrderItem::where(...)`) would cross branches
- `withoutGlobalScopes()` used on `User` in `GuestSignupController` and `EnsureAdminLoginCommand` — intentional but widens scope
- `queue_number` is generated per `branch_id` per day — safe as long as `branch_id` on the order is correct before the lock
- Admin (`branch_id = 0`) sees **all** branches in KDS/OSS — potentially undesirable in multi-branch deployment

**Verification needed**: `static-inspection` (grep for `OrderItem::where` / `Transaction::where` without parent scope)

---

## CP-08 — Auth / authz boundaries

**Status**: confirmed

**Why dangerous**: Multiple auth mechanisms (Sanctum tokens, API key, kiosk abilities) with different privilege levels. A hole here is a direct security breach.

**Key files**:
- `routes/api.php` — middleware stacks per group
- `app/Http/Middleware/ApiKeyMiddleware.php`
- `app/Http/Middleware/Installed.php`
- `app/Http/Controllers/Auth/KioskMachineLoginController.php`
- `app/Http/Controllers/Auth/LoginController.php`
- `app/Http/Controllers/Auth/RefreshTokenController.php`
- `app/Models/KioskMachine.php`

**Boundary map**:

| Surface | Auth | Abilities | Evidence |
|---------|------|-----------|----------|
| Admin / POS / KDS / OSS | Sanctum + apiKey + Spatie permission | Full token (no ability restriction) | confirmed |
| Kiosk | Sanctum + apiKey | `kiosk:order` only | confirmed |
| Frontend (customer) | Sanctum + apiKey (on sensitive routes) | Full token | confirmed |
| Table / QR | apiKey only (NO Sanctum) | None | confirmed |
| OSS | apiKey + Sanctum + `order-status-screen` permission | Read-only | confirmed |

**Where it could break**:
- **Table order creation has NO Sanctum** — only `apiKey` + throttle protects it. If `apiKey` leaks, anonymous order creation is possible
- **`RefreshTokenController`** mints new token **without revoking old one** — token accumulation, no forced re-auth
- **`LoginController`** creates token with **no ability restrictions** — full Sanctum token for admin/manager/cashier; permission enforcement is in controllers only (Spatie middleware)
- **Kiosk token** correctly restricts to `kiosk:order`, but the enforcement point for this ability is **not visible** in any route middleware — it must be checked in controllers or policies (unverified)
- **`KioskMachine.password`** is in `$fillable` — mass-assignment path could store plaintext if hashing is missed
- **`TableOrderController::tokenCreate`** lacks Spatie permission in its constructor `only()` list — inherits only Sanctum from route, no role check
- **`OnlineOrderController`** has no `destroy` method but route references it — 500 on DELETE

**Verification needed**: `static-inspection` (grep for `tokenCan('kiosk:order')` enforcement), `local-validation` (test kiosk token cannot access admin endpoints)

---

## CP-09 — Queue number generation

**Status**: confirmed

**Why dangerous**: Queue numbers are customer-facing (displayed on receipts, KDS, OSS). Duplicates or gaps cause operational confusion.

**Key files**:
- `app/Services/OrderService.php` — all 3 `*Store()` methods
- `app/Services/FrontendOrderService.php` — `myOrderStore()`

**How it works**:
1. `Cache::lock("queue_lock_{branch_id}_{Y-m-d}", 10)` with `block(5)` timeout
2. Query: `MAX(CAST(SUBSTRING(queue_number, 2) AS UNSIGNED))` from `orders` where `branch_id` + today + non-null + matches `^A[0-9]+$`
3. Next: `'A' . str_pad($max + 1, 4, '0', STR_PAD_LEFT)`
4. Fallback on lock timeout: `'A' . str_pad((int)(microtime(true)*10) % 9999 + 1, ...)` + warning log
5. Assigned before `save()` inside same `DB::transaction`

**Invariants that can break**:
- **Lock timeout fallback** uses `microtime` modulo — can collide with existing queue numbers or produce duplicates under high concurrency
- Query scans **both** `orders` table rows (POS `Order` + kiosk `FrontendOrder` share the same `orders` table) — this is actually correct for uniqueness, but only if both paths use the same lock key format (confirmed: they do)
- If `Cache` driver doesn't support atomic locks (e.g., `file` driver), the lock is advisory only
- Queue numbers reset daily (by `whereDate`) — correct for restaurant operations

**Verification needed**: `static-inspection` (verify cache driver supports atomic locks), `local-validation` (concurrent order creation test)

---

## CP-10 — Coupon discipline

**Status**: partial

**Why dangerous**: Coupons directly reduce order totals. Validation gaps mean revenue loss.

**Key files**:
- `app/Services/CouponService.php` — `couponChecking()`, `couponDateWise()`
- `app/Services/OrderService.php` — coupon blocks in `*Store()` methods
- `app/Services/FrontendOrderService.php` — coupon block in `myOrderStore()`
- `app/Models/OrderCoupon.php`

**Validation chain**:
1. `CouponService::couponChecking()` — lookup by code, check `end_date >= now`, check per-user limit
2. Order service re-loads coupon by ID, applies: PERCENTAGE → `min(subtotal * rate / 100, max_discount)`, FIXED → `(float) discount`
3. `OrderCoupon::create` stores applied discount

**Where it could break**:
- **`couponChecking` does NOT check `start_date`** — coupons can be redeemed before their intended start date
- **`couponChecking` uses client-supplied `$request->total`** for minimum order check — wrong total could bypass minimum
- **`FrontendOrderService`**: coupon not found → discount stays 0 (silent); **but** `OrderCoupon::create` still runs if `coupon_id > 0`, creating a row with discount=0
- **`FrontendOrderService`**: `OrderCoupon.discount` stores **combined** coupon + loyalty discount, not coupon-only — breaks per-coupon revenue attribution
- **`OrderService` (POS/table)**: throws if coupon not found → stricter than frontend
- **No cross-branch coupon scoping** visible — a coupon created for branch A may be usable at branch B

**Verification needed**: `local-validation` (start_date bypass test, minimum order with wrong total), `static-inspection` (cross-branch coupon usage)

---

## CP-11 — Transaction / post-commit dispatch rules

**Status**: confirmed

**Why dangerous**: Events dispatched inside a DB transaction can cause phantom notifications (if transaction rolls back) or lost notifications (if dispatched after a crash before commit).

**Key files**:
- `app/Services/OrderService.php` — `myOrderStore`, `posOrderStore`, `tableOrderStore`, `changeStatus`
- `app/Services/FrontendOrderService.php` — `myOrderStore`, `changeStatus`
- `app/Services/KitchenDisplaySystemOrderService.php` — `changeStatus`
- `app/Events/OrderCreated.php` — `ShouldBroadcastNow`
- `app/Events/OrderStatusChanged.php` — `ShouldBroadcastNow`
- `app/Listeners/SendFcmOnOrderCreated.php` — sync listener, queues FCM jobs
- `app/Listeners/AwardLoyaltyPointsOnDelivery.php` — sync listener with own transaction

**Dispatch safety matrix** (code-confirmed):

| Method | DB::transaction? | Events dispatched | Position | Safe? |
|--------|------------------|-------------------|----------|-------|
| `OrderService::myOrderStore` | Yes | `OrderCreated` | After commit | **Yes** |
| `OrderService::posOrderStore` | Yes | `OrderCreated` | After commit | **Yes** |
| `OrderService::tableOrderStore` | Yes | `OrderCreated` | After commit | **Yes** |
| `OrderService::changeStatus` (staff) | Yes | `OrderStatusChanged` | After commit | **Yes** |
| `OrderService::changeStatus` (customer) | No transaction | `Send*` events only (no `OrderStatusChanged`) | After save | **Partial** — no atomicity |
| `OrderService::deliveryBoyOrderChangeStatus` | No transaction | `Send*` before save, `OrderStatusChanged` after save | Mixed | **Risky** — events fire before DB persistence |
| `FrontendOrderService::myOrderStore` | Yes | `OrderCreated` after commit; kiosk auto-accept `OrderStatusChanged` also after commit but outside creation transaction | After commit | **Mostly safe** — gap between creation commit and auto-accept |
| `FrontendOrderService::changeStatus` | No explicit transaction | `OrderStatusChanged` after save | After save | **Partial** — single save without transaction wrapper |
| `KitchenDisplaySystemOrderService::changeStatus` | Yes | `OrderStatusChanged` | After commit | **Yes** |

**Invariants that can break**:
- `deliveryBoyOrderChangeStatus` sends notification events **before** `$order->save()` — if save fails, notifications were already dispatched (phantom)
- `ShouldBroadcastNow` is synchronous — broadcast happens inline, not queued. If Pusher fails, event is lost with no retry
- FCM listeners queue `SendFcmNotificationJob` (3 retries, 10/30s backoff) — but the job is queued by the sync listener, so if the listener throws before dispatch, the job is lost
- `AwardLoyaltyPointsOnDelivery` uses idempotency sentinel (`loyalty_points_awarded = -1`) outside its own transaction — race condition window between sentinel set and transaction start
- Redundant `DB::rollBack()` in `OrderService` catch blocks after `DB::transaction` closures — can break Laravel's savepoint counter

**Verification needed**: `local-validation` (delivery boy status change with save failure), `static-inspection` (redundant rollback audit)
