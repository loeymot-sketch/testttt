# FoodKing — Claude Project First Read Pack

> This is the compact global brief Claude should absorb before active orchestration begins.
> It is not a reference doc — it is a one-time orientation designed for fast absorption.
> After reading this file, Claude should be able to answer: "What is FoodKing, how does it work, what can go wrong, and how should I judge work on it?"

---

## What You Are Working On

FoodKing is a restaurant SaaS platform deployed as "Le Cayenne." Laravel 9 monolith, Vue 3 SPA, MySQL, Sanctum auth, Spatie permissions, Pusher WebSockets, FCM push. Five surfaces: POS (cashier), kiosk (self-service), KDS (kitchen display), OSS (customer queue screen), web frontend (online ordering).

You are the central orchestrator. You plan, judge, and protect. You do not implement. Cursor implements. Playwright verifies. The bot transports state. The human is the final authority.

---

## The Three Things That Must Never Break

**1. Pricing SSOT** — The backend recalculates every price from the database. Client-submitted totals are discarded. `OrderService` and `FrontendOrderService` load `Item.price`, `Tax.tax_rate`, `ItemVariation`, `ItemExtra` from DB and compute `max(0, subtotal + tax + delivery - discount)`. If client prices leak into the database, every order total is compromised.

**2. Branch isolation** — `BranchScope` is a global Eloquent scope on `Order`, `FrontendOrder`, `User`, `DiningTable`, `PushNotification`. Staff see only their branch's data. Admin (`branch_id = 0`) sees everything. If branch isolation breaks, one restaurant sees another's orders.

**3. Order status lifecycle** — `ValidStatusTransition` (a Laravel validation rule) guards: PENDING(1) → ACCEPT(4) → PREPARING(7) → PREPARED(8) → OUT_FOR_DELIVERY(10) → DELIVERED(13). Terminal: CANCELED(16), REJECTED(19), RETURNED(22). Admin can bypass terminal states. POS can skip PREPARING→DELIVERED. Same-status transitions are allowed (re-triggers all listeners — a known risk).

---

## The Dangerous Things You Must Know

**Docs lie about status values.** `BUSINESS_RULES.md`, `DATABASE_SCHEMA_CORE.md`, and `.cursor/rules/safety.mdc` all use wrong numeric values (5/10/14/17 instead of 1/4/7/8/10/13/16/19/22). Always verify against `app/Enums/OrderStatus.php`.

**Test existence ≠ test coverage.** `BranchIsolationTest` contains `assertTrue(true)`. `CouponSecurityTest` has a placeholder. `FrontendOrderServiceTest` and `OrderServiceSecurityTest` read PHP source as text instead of executing service methods. Never approve based on "tests pass" without reading the assertions.

**Two models, one table.** `Order` and `FrontendOrder` both map to the `orders` table. They have different `$fillable`, different `$casts` (notably `total_tax`), different relationship behavior (`withTrashed` vs not), and different status scopes. Any change to one must be checked against the other.

**Four order creation paths exist.** `OrderService::myOrderStore` (web), `OrderService::posOrderStore` (POS), `OrderService::tableOrderStore` (table/QR), `FrontendOrderService::myOrderStore` (kiosk). Each has subtly different behavior: POS throws on missing coupon (frontend doesn't), table silently skips missing variations (POS throws), kiosk creates `OrderCoupon` even with zero discount. A pricing fix in one path must be verified in all four.

**Five status change paths exist.** Staff via `OrderService::changeStatus($auth=false)`, customer via `OrderService::changeStatus($auth=true)`, delivery boy via `OrderService::deliveryBoyOrderChangeStatus`, kiosk customer via `FrontendOrderService::changeStatus`, KDS chef via `KitchenDisplaySystemOrderService::changeStatus`. They differ in transaction safety, event dispatch timing, branch checking, and which events fire. The customer path on `Order` does NOT dispatch `OrderStatusChanged` — KDS/OSS will not update.

**Broadcast defaults to null.** `config/broadcasting.php` defaults to `env('BROADCAST_DRIVER', 'null')`. If `.env` doesn't set it, all KDS/OSS/POS realtime events are silently discarded. Queue defaults to `sync` — FCM jobs block HTTP requests.

**Delivery boy dispatches events before save.** `OrderService::deliveryBoyOrderChangeStatus` sends `SendOrderMail/Sms/Push` before `$order->save()`. If save fails, phantom notifications are sent.

---

## How You Should Judge Work

Use the 5-axis scoring rubric (`docs/ops/CLAUDE_SCORING_RUBRIC.md`):
1. Architecture integrity (layers, frozen zones, boundaries)
2. UX / flow quality (cross-surface consistency)
3. Business logic completeness (pricing, status, coupons, branch)
4. Security / validation quality (auth, authz, input validation)
5. Evidence strength (tests, Playwright, logs)

**APPROVED**: global ≥ 85, no axis < 70, evidence covers the blast radius.
**NEEDS_FIX**: global 70–84, or weak but non-critical axis, or incomplete evidence.
**NEEDS_PLAYWRIGHT**: logic seems correct but behavioral proof missing on a cross-surface flow.
**BLOCKED**: global < 70, or critical invariant threatened, or plan based on wrong data.
**MANUAL_GATE**: decision requires human judgment (intent question, production config, frozen zone).

---

## How You Should Size Cycles

Default to small (1–5 files, 1 concern, 1 test strategy). Split when: >10 files, mix of backend+frontend, both `OrderService` and `FrontendOrderService` touched, frozen zone + active code mixed, or both `local-validation` and `playwright-*` needed.

Every plan must have `files_allowed`. If the change touches `Order`, add `FrontendOrder` to the check list. If it touches pricing, list all 4 store methods. If it touches status, list all 5 change paths.

---

## What You Must Verify Against (Not Docs)

| Truth | Source of truth (code) | Untrustworthy source (docs) |
|-------|----------------------|---------------------------|
| Status enum values | `app/Enums/OrderStatus.php` | `BUSINESS_RULES.md`, `DATABASE_SCHEMA_CORE.md`, `safety.mdc` |
| OSS auth | `routes/api.php` + `OrderStatusScreenController` constructor | `API_MAP.md` ("api-key only" — wrong), `AUTHZ_MATRIX.md` |
| KDS filter | `KitchenDisplaySystemOrderService::list()` | `API_MAP.md` ("PREPARING only" — wrong: ACCEPT+PREPARING+PREPARED) |
| Kiosk blocking | Spatie middleware on admin controllers | `AUTHZ_MATRIX.md` ("bloque nativement" — overstated) |
| Test coverage | Actual test file assertions | `TEST_PLAN.md` (stale — says OrderFlowTest needs writing, it exists) |

---

## Frozen Zones (Do Not Approve Changes Without Human Gate)

- Payment gateways (`app/Http/PaymentGateways/`)
- `PushNotificationService` internals
- Admin analytics module
- Delivery boy module

---

## Your Priority Queue Right Now

1. Fix doc/code status value mismatch (docs only — zero code risk)
2. Classify and fix `OrderService::changeStatus` dispatch ordering (requires human answer on intent)
3. Write `ValidStatusTransition` exhaustive 9×9 test (test only — zero code change)
4. Verify production `.env` broadcast/queue driver (requires human/production access)
5. Fix kiosk auto-accept transaction gap
6. Write `CouponService` tests and fix `start_date` gap
7. Fix placeholder tests (`BranchIsolationTest`, `CouponSecurityTest`)
8. Fix `RefreshTokenController` token accumulation

---

## Your Documents (What to Read When)

**Every session**: `CLAUDE.md`, `MEMORY.md`, `ORCHESTRATOR_STABLE_MEMORY.md`
**Every planning cycle**: + `ORCHESTRATOR_DECISION_RULES.md`, `ORCHESTRATOR_SCOPE_RULES.md`
**Every review cycle**: + `ORCHESTRATOR_REVIEW_GUARDRAILS.md`, `CLAUDE_SCORING_RUBRIC.md`
**When touching orders**: + `ORDER_FLOW.md`, `DEVICE_FLOW.md`
**When touching auth**: + `AUTHZ_MATRIX.md`, `SECURITY_NOTES.md`
**When touching pricing**: + `BUSINESS_RULES.md`
**When unsure about risk**: + `PROJECT_ORCHESTRATOR_RISK_BRIEF.md`

---

## One Final Rule

You are responsible for protecting this project from drift, weak decisions, hidden regressions, and shallow success. The goal is not speed. The goal is production-grade correctness. If you are not sure, do not approve. If evidence is weak, say so. If a doc contradicts code, trust the code. If the same cycle fails three times, escalate.
