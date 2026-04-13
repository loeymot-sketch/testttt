# FoodKing — Orchestrator Stable Memory

> This file contains long-lived project truths that do not change between cycles.
> It is not a replacement for CLAUDE.md, MEMORY.md, or the risk brief.
> It is a judgment accelerator — read once per session, trust across cycles.
> Last verified: 2026-04-12 (full code audit).

---

## 1. The System You Are Operating On

FoodKing is a Laravel 9 monolith + Vue 3 SPA serving a restaurant chain ("Le Cayenne").
Single MySQL database. Sanctum auth. Spatie Permission. Pusher/WebSockets. FCM push.
Five user-facing surfaces: **POS**, **kiosk**, **KDS**, **OSS**, **web frontend**.
One shared table (`orders`) accessed through two Eloquent models (`Order`, `FrontendOrder`).
~111 controllers, ~86 services, ~49 models, ~80 migrations, ~41 test files.

---

## 2. Absolute Truths (Never Negotiate)

| # | Truth | Enforcement point |
|---|-------|-------------------|
| T1 | Backend is the single source of truth for price, total, tax, discount | `OrderService::*Store()`, `FrontendOrderService::myOrderStore()` — client values unset before `Order::create` |
| T2 | Branch isolation is enforced by `BranchScope` global scope | Registered on `Order`, `FrontendOrder`, `User`, `DiningTable`, `PushNotification` — NOT on child models |
| T3 | Order status transitions are guarded by `ValidStatusTransition` | Applied in service `changeStatus()` methods, not in `OrderStatusRequest` |
| T4 | OSS is strictly read-only | No write endpoints. `OrderStatusScreenOrderService` has only `list()` and `mostPopularItems()` |
| T5 | Kiosk tokens carry `kiosk:order` ability only | Created in `KioskMachineLoginController` via `createToken('kiosk-token', ['kiosk:order'])` |
| T6 | Events dispatch after DB commit in safe paths | `OrderCreated` and `OrderStatusChanged` are dispatched after `DB::transaction` closure in main store/status methods |
| T7 | `ShouldBroadcastNow` = synchronous, no retry | All three broadcast events (`OrderCreated`, `OrderStatusChanged`, `ItemAvailabilityChanged`) use synchronous broadcast |
| T8 | Queue defaults to `sync` | `config/queue.php` default: `env('QUEUE_CONNECTION', 'sync')` — FCM jobs run inline unless explicitly configured |
| T9 | Broadcast defaults to `null` | `config/broadcasting.php` default: `env('BROADCAST_DRIVER', 'null')` — all broadcasts silently discarded unless explicitly configured |

---

## 3. The Real Status Enum (Source of Truth)

**File**: `app/Enums/OrderStatus.php`

| Name | Value | Doc aliases |
|------|-------|-------------|
| PENDING | 1 | — |
| ACCEPT | 4 | — |
| PREPARING | 7 | — |
| PREPARED | 8 | — |
| OUT_FOR_DELIVERY | 10 | — |
| DELIVERED | 13 | — |
| CANCELED | 16 | — |
| REJECTED | 19 | — |
| RETURNED | 22 | — |

**WARNING**: `BUSINESS_RULES.md`, `DATABASE_SCHEMA_CORE.md`, and `.cursor/rules/safety.mdc` all use wrong numeric values (5, 10, 14, 17). Never trust doc-stated status integers. Always verify against this enum.

---

## 4. The Four Order Creation Paths

Every pricing change must be verified across all four. They are not identical.

| Path | Service method | Auth | Differences |
|------|---------------|------|-------------|
| Web/kiosk | `FrontendOrderService::myOrderStore` | Sanctum | Loyalty deduction, auto-accept for kiosk, `OrderCoupon` created even with zero discount, `delivery_charge` without `?? 0` |
| POS | `OrderService::posOrderStore` | Sanctum + Spatie | Manual discount branch, POS cash check, line-level discount from payload (unused in subtotal), throws on missing coupon |
| Table/QR | `OrderService::tableOrderStore` | apiKey only (no Sanctum) | Silent skip on missing variation/extra (vs throw in POS), same coupon/manual discount as POS |
| Online (admin) | `OrderService::myOrderStore` | Sanctum + Spatie | Same pattern as kiosk but uses `Order` model, no auto-accept, no loyalty |

---

## 5. The Four+ Status Change Paths

Every status transition change must be verified across all of these. They are NOT identical.

| Path | Method | Transaction? | Events dispatched | Branch check? | Notes |
|------|--------|-------------|-------------------|--------------|-------|
| Staff (admin/cashier) | `OrderService::changeStatus($auth=false)` | Yes | `OrderStatusChanged` after commit | Yes (non-admin) | ActionLog, cashback on cancel |
| Customer (self-service) | `OrderService::changeStatus($auth=true)` | No | `Send*` only — **NO** `OrderStatusChanged` | Owner check only | KDS/OSS will NOT update |
| Delivery boy | `OrderService::deliveryBoyOrderChangeStatus` | No | `Send*` BEFORE save, `OrderStatusChanged` after save | Delivery boy ownership | **Phantom notification risk** |
| Kiosk customer cancel | `FrontendOrderService::changeStatus` | No explicit tx | `OrderStatusChanged` after save | Owner check | Cancel-only, threshold guards |
| KDS chef | `KitchenDisplaySystemOrderService::changeStatus` | Yes | `OrderStatusChanged` after commit | Implicit (list filters) | Clean path |

---

## 6. Dangerous Patterns to Remember

### 6.1 — Docs lie about status values
Four docs use wrong enum integers. Plans built on doc values will be silently wrong.
**Rule**: Always verify against `app/Enums/OrderStatus.php`.

### 6.2 — Test existence ≠ test coverage
`BranchIsolationTest` → `assertTrue(true)`. `CouponSecurityTest` → `assertTrue(true)`. Unit "service tests" read PHP source as text strings, don't execute service logic.
**Rule**: Read test assertions before granting test-evidence credit. Never approve based on "tests pass" without verifying what they assert.

### 6.3 — Two models, one table
`Order` and `FrontendOrder` share `orders` table. Different `$fillable`, different `$casts` (notably `total_tax`), different scopes, different `items()` relationship (`withTrashed` vs not).
**Rule**: Any plan touching order models must explicitly address both models.

### 6.4 — `AUTHZ_MATRIX.md` overstates kiosk blocking
Doc says abilities "bloque nativement" admin access. Reality: Spatie permission middleware on controllers is the actual barrier, not Sanctum abilities.
**Rule**: Verify auth claims against `routes/api.php` + controller constructors, not docs.

### 6.5 — `API_MAP.md` misrepresents OSS and KDS
Doc says OSS is "api-key only" — actual: Sanctum + Spatie permission. Doc says KDS shows "PREPARING only" — actual: ACCEPT + PREPARING + PREPARED.
**Rule**: Verify API behavior claims against service implementations.

### 6.6 — Frozen zones are doc-enforced only
No CI/pre-commit enforcement. Payment gateways, `PushNotificationService`, analytics, delivery boy.
**Rule**: Flag any plan touching these paths. Require human approval.

---

## 7. Routing Rules for Thinking

### Before planning any cycle:
1. Which of the 4 order creation paths does this touch?
2. Which of the 4+ status change paths does this touch?
3. Does this touch `Order`, `FrontendOrder`, or both?
4. Does this touch a frozen zone?
5. Is there a real test (with real assertions) for the affected path?

### Before approving any plan:
1. Does the plan reference status values? → Verify against enum, not docs.
2. Does the plan claim "tested"? → Read the test file assertions.
3. Does the plan touch pricing? → All four store methods listed?
4. Does the plan touch status transitions? → All five change paths listed?
5. Does the plan touch auth? → Verified against routes + middleware, not AUTHZ_MATRIX?
6. Does the plan touch a frozen zone? → Human gate required.

### Before writing any verdict:
1. Was the evidence real (test output, screenshot, console log) or claimed?
2. Did the implementation touch both `Order` and `FrontendOrder` if needed?
3. Were event dispatch positions verified (before/after commit)?
4. Were branch isolation implications checked?
5. Is there a doc/code mismatch that needs updating?

---

## 8. What Must Never Be Approved Lightly

| Action | Why | Required gate |
|--------|-----|---------------|
| Changing `ValidStatusTransition` | Guards entire order lifecycle | Full 9×9 matrix test + review all 5 change paths |
| Modifying any `*Store()` pricing logic | Financial impact across all surfaces | Verify all 4 paths + pricing test |
| Adding `fillable` to `Order` or `FrontendOrder` | Risk of client data leak if not recalculated | Verify both models + SSOT enforcement |
| Touching `BranchScope` | Branch isolation is the multi-tenant boundary | Verify all scoped models + cross-branch test |
| Modifying event dispatch timing | KDS/OSS/POS realtime depends on correct ordering | Verify pre/post-commit position + broadcast test |
| Changing Sanctum token creation | Auth boundary for all surfaces | Verify abilities, expiration, revocation |
| Any change in frozen zones | Payment, push, analytics, delivery boy | Human approval mandatory |
| Modifying `queue_number` logic | Customer-facing, concurrency-sensitive | Verify lock mechanism + fallback safety |

---

## 9. Service Ownership Map

| Domain | Owner service | Owner model | Read-only services |
|--------|--------------|-------------|-------------------|
| POS/admin order creation | `OrderService` | `Order` | — |
| Kiosk/web order creation | `FrontendOrderService` | `FrontendOrder` | — |
| Table/QR order creation | `OrderService` | `Order` | — |
| POS/admin status changes | `OrderService` | `Order` | — |
| KDS status changes | `KitchenDisplaySystemOrderService` | `Order` | — |
| Customer cancel (frontend) | `FrontendOrderService` | `FrontendOrder` | — |
| Customer cancel (legacy) | `OrderService` ($auth=true) | `Order` | — |
| Delivery boy status | `OrderService` | `Order` | — |
| OSS display | — | `Order` | `OrderStatusScreenOrderService` (read-only) |
| Coupon validation | `CouponService` | `Coupon` | — |
| Pricing SSOT | `OrderService` / `FrontendOrderService` | `Item`, `Tax`, `ItemVariation`, `ItemExtra` | — |
| Branch isolation | `BranchScope` (global scope) | All scoped models | — |

---

## 10. Document Trust Hierarchy

When documents and code disagree, trust in this order:

1. **Code** (`app/Enums/`, `app/Services/`, `app/Models/`, `routes/api.php`) — absolute truth
2. **`CLAUDE.md`** — operating constitution, rarely wrong
3. **`MEMORY.md`** — working state, human-maintained
4. **`docs/ARCHITECTURE.md`** — structural truth, frozen zones authoritative
5. **`docs/ORDER_FLOW.md`** — status names correct, numeric values absent (safe)
6. **`docs/BUSINESS_RULES.md`** — logic correct, **numeric values WRONG** (unsafe for integers)
7. **`docs/AUTHZ_MATRIX.md`** — actor list correct, **enforcement details WRONG** (unsafe for OSS/kiosk claims)
8. **`docs/API_MAP.md`** — partial, **OSS and KDS descriptions WRONG**
9. **`docs/DATABASE_SCHEMA_CORE.md`** — **status values WRONG**, table coverage incomplete
10. **`docs/TEST_PLAN.md`** — **stale**, does not reflect actual test inventory

---

## 11. Compact Glossary (FoodKing-Specific)

| Term | Meaning |
|------|---------|
| SSOT | Server-side source of truth — backend recalculates all prices |
| BranchScope | Global Eloquent scope filtering by `branch_id` |
| `branch_id = 0` | Admin/HQ user — sees all branches, can subscribe to all WebSocket channels |
| Auto-accept | Kiosk orders auto-transition PENDING→ACCEPT if machine config allows |
| Frozen zone | Payment gateways, `PushNotificationService`, analytics, delivery boy |
| `kioskToken` | Sanctum token with `kiosk:order` ability only |
| Queue number | `A####` format, per-branch per-day, generated under `Cache::lock` |
| INV-05 | Baseline inspection that verified event dispatch timing in all store methods |
| `BroadcastableOrder` | Empty interface implemented by both `Order` and `FrontendOrder` for broadcast event typing |
