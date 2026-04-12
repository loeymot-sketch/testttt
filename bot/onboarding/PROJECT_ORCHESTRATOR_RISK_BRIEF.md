# FoodKing — Orchestrator Risk Brief

> Purpose: Pre-autonomous-cycle intelligence for the Claude orchestrator.
> Generated 2026-04-12 from deep code + doc + test audit.
> Categories: **OPEN** (needs cycle), **LEGACY/DEBT** (accepted tech debt), **CLOSED** (resolved or mitigated).

---

## Table of Contents

1. [Architecture Drift Risks](#1-architecture-drift-risks)
2. [Business Logic Fragility](#2-business-logic-fragility)
3. [Synchronization Fragility](#3-synchronization-fragility)
4. [Unclear Ownership Between Services](#4-unclear-ownership-between-services)
5. [Dead Code / Stale Branches](#5-dead-code--stale-branches)
6. [Doc/Code Mismatches](#6-doccode-mismatches)
7. [Missing Test Coverage on Critical Paths](#7-missing-test-coverage-on-critical-paths)
8. [Areas Likely to Cause False Approvals](#8-areas-likely-to-cause-false-approvals)
9. [Areas Likely to Cause Hidden Regressions](#9-areas-likely-to-cause-hidden-regressions)

---

## 1. Architecture Drift Risks

### ORB-001 — `Order` vs `FrontendOrder` dual-model divergence on shared table

**Severity**: HIGH
**Status**: OPEN
**Evidence**: `app/Models/Order.php`, `app/Models/FrontendOrder.php` — both declare `$table = "orders"`. 7+ duplicated relationships (`orderItems`, `items`, `user`, `address`, `branch`, `deliveryBoy`, `coupon`, `transaction`), 7 duplicated status scopes (`scopePending`, `scopePreparing`, etc.). Structural differences: `Order.items()` uses `withTrashed()`, `FrontendOrder` does not. `Order` has `scopeAccept`/`scopePrepared`, `FrontendOrder` does not. `Order` casts `total_tax` as `decimal:6`, `FrontendOrder` does not. Different `$fillable` sets (POS fields vs kiosk fields).
**Why orchestrator must know**: Any cycle touching order models risks introducing inconsistency between the two. A field added to `Order.$fillable` but forgotten on `FrontendOrder` (or vice versa) creates a silent data bug. The duplicated scopes and relationships are a maintenance trap — fixing a relationship in one model but not the other causes surface-specific regressions.
**Next cycle**: `static-inspection` — produce a diff matrix of fillable/casts/relationships/scopes between the two models. Determine if a shared trait or abstract base is warranted.

---

### ORB-002 — `BranchScope` not registered on child models

**Severity**: MEDIUM
**Status**: OPEN
**Evidence**: `app/Models/Scopes/BranchScope.php` registered on `Order`, `FrontendOrder`, `User`, `DiningTable`, `PushNotification`. NOT registered on `OrderItem`, `OrderCoupon`, `OrderAddress`, `Transaction`, `Item`, `ItemCategory`.
**Why orchestrator must know**: Direct queries like `OrderItem::where('branch_id', ...)` or `Transaction::where(...)` will cross branch boundaries. Currently these models are accessed through relationships from scoped parents, but any new code that queries them directly will bypass branch isolation. Claude must flag any plan that introduces direct queries on unscoped models.
**Next cycle**: `static-inspection` — grep for direct queries on unscoped models outside of relationship chains.

---

### ORB-003 — Frozen zone boundary enforcement is doc-only

**Severity**: MEDIUM
**Status**: OPEN
**Evidence**: `docs/ARCHITECTURE.md` declares payment gateways, `PushNotificationService`, analytics, and delivery boy as frozen. No code-level enforcement (no `@frozen` annotation, no CI check, no pre-commit hook). Git history check was inconclusive in the audit environment.
**Why orchestrator must know**: Any implementation plan that touches files in `app/Http/PaymentGateways/`, `app/Services/PushNotificationService.php`, `app/Services/AnalyticService.php`, or `app/Services/DeliveryBoyService.php` must be flagged as frozen-zone violation. Claude must enforce this at plan review time since there is no automated gate.
**Next cycle**: `local-validation` — run `git log --oneline --since="2026-01-01"` on frozen paths to detect any recent violations.

---

### ORB-004 — `BroadcastableOrder` interface is empty (structural typing)

**Severity**: LOW
**Status**: LEGACY/DEBT
**Evidence**: `app/Contracts/BroadcastableOrder.php` — intentionally empty interface used for type-safe polymorphism. `Order` and `FrontendOrder` implement it, but the interface guarantees nothing about the properties events actually access (`branch_id`, `queue_number`, `status`, `order_type`, `total`, `token`).
**Why orchestrator must know**: If a future refactor changes or removes a property from one model, broadcast events will fail at runtime, not at compile/lint time. The empty interface gives false safety.
**Next cycle**: No immediate action — flag if broadcast payloads change.

---

## 2. Business Logic Fragility

### ORB-005 — Delivery boy status change fires events before DB save

**Severity**: CRITICAL
**Status**: OPEN
**Evidence**: `app/Services/OrderService.php` — `deliveryBoyOrderChangeStatus()` dispatches `SendOrderMail`, `SendOrderSms`, `SendOrderPush` **before** `$order->save()`, then dispatches `OrderStatusChanged` after save. No `DB::transaction` wrapper.
**Why orchestrator must know**: If `$order->save()` fails after notification events are dispatched, phantom notifications reach customers for a status change that never persisted. This is one of the highest-risk code patterns in the entire codebase. Any cycle that reviews or modifies delivery boy flows must verify this is fixed or explicitly accepted.
**Next cycle**: `local-validation` — write test simulating save failure after dispatch.

---

### ORB-006 — Coupon `start_date` not validated in `couponChecking`

**Severity**: MEDIUM
**Status**: OPEN
**Evidence**: `app/Services/CouponService.php` — `couponChecking()` only checks `end_date >= now`. `couponDateWise()` (display) checks both `start_date` and `end_date`. Early redemption before `start_date` is possible through the checkout path.
**Why orchestrator must know**: Any cycle that claims coupon logic is "correct" without verifying `start_date` enforcement is a false approval. This is a known gap that must be on the inspection checklist for any coupon-related plan.
**Next cycle**: `local-validation` — add `start_date >= now` check and unit test.

---

### ORB-007 — `OrderCoupon.discount` stores combined coupon + loyalty amount

**Severity**: MEDIUM
**Status**: OPEN
**Evidence**: `app/Services/FrontendOrderService.php` — `myOrderStore()` sets `OrderCoupon::create(['discount' => $calculatedDiscount])` where `$calculatedDiscount` is the sum of coupon discount AND loyalty discount.
**Why orchestrator must know**: Any reporting or analytics cycle that reads `OrderCoupon.discount` to attribute revenue impact to coupons will overcount. Claude must flag this whenever a plan touches reporting, coupon analytics, or loyalty point reconciliation.
**Next cycle**: `static-inspection` — determine if separation is needed or if a `loyalty_discount` field should be added.

---

### ORB-008 — Same-status transition allowed and re-triggers all listeners

**Severity**: MEDIUM
**Status**: OPEN
**Evidence**: `app/Rules/ValidStatusTransition.php` — line 37: `$current === $newStatus` returns `true`. All `changeStatus()` methods proceed to dispatch `OrderStatusChanged` without checking if the status actually changed.
**Why orchestrator must know**: A UI bug or API retry that re-submits the same status can cause duplicate FCM pushes, redundant WebSocket broadcasts, and (though mitigated by idempotency sentinel) potential loyalty point edge cases. Any cycle reviewing notification reliability must account for this.
**Next cycle**: `local-validation` — add early return when `$oldStatus === $newStatus` in service methods.

---

### ORB-009 — Admin can bypass terminal status restrictions without audit

**Severity**: MEDIUM
**Status**: OPEN
**Evidence**: `app/Rules/ValidStatusTransition.php` — lines 79-81: Admin role can transition from CANCELED/REJECTED/RETURNED to **any** status with no secondary confirmation, no reason requirement, and no specialized audit log (only generic `ActionLog`).
**Why orchestrator must know**: An admin recovering a canceled order and re-marking it DELIVERED could re-trigger loyalty points. The idempotency sentinel in `AwardLoyaltyPointsOnDelivery` uses `loyalty_points_awarded IS NULL` — if a previous award already set this field, the sentinel blocks. But if the order was canceled before delivery (no points awarded), then recovered and delivered, points are awarded correctly. The risk is operational (unintended order resurrection), not strictly a code bug.
**Next cycle**: `static-inspection` — verify `AwardLoyaltyPointsOnDelivery` handles all recovery scenarios.

---

### ORB-010 — Kiosk auto-accept runs outside creation transaction

**Severity**: HIGH
**Status**: OPEN
**Evidence**: `app/Services/FrontendOrderService.php` — `myOrderStore()` commits the order creation transaction, then performs auto-accept (kiosk machine + KIOSK/TAKEAWAY order type) in a separate `$this->frontendOrder->save()` + `OrderStatusChanged` dispatch outside any transaction.
**Why orchestrator must know**: If the process crashes between the creation commit and auto-accept, the kiosk order is stuck in PENDING and never appears on KDS (which filters for ACCEPT+). This is a P0 reliability risk for kiosk-heavy deployments. Any cycle touching kiosk order creation must either fix this or document the accepted risk.
**Next cycle**: `local-validation` — test order creation with simulated crash after commit.

---

### ORB-011 — `OrderService::changeStatus` customer path (`$auth=true`) does NOT dispatch `OrderStatusChanged`

**Severity**: HIGH
**Status**: OPEN
**Evidence**: `app/Services/OrderService.php` — `changeStatus()` with `$auth === true` (customer self-service, e.g., canceling their own order) dispatches `SendOrderMail/Sms/Push` but does **not** dispatch `OrderStatusChanged`. Contrast: `FrontendOrderService::changeStatus` (customer cancel on `FrontendOrder`) **does** dispatch `OrderStatusChanged`.
**Why orchestrator must know**: When a customer cancels their own `Order` (non-frontend path), KDS/OSS do not receive a broadcast, and `AwardLoyaltyPointsOnDelivery` / `SendFcmOnOrderStatusChange` do not fire. This is a **silent desync** between the order state in DB and the state displayed on KDS/OSS. Any cycle reviewing KDS/OSS reliability or customer cancellation must verify this path.
**Next cycle**: `local-validation` + `playwright-critical-flow` — test customer cancel on Order model and verify KDS/OSS update.

---

### ORB-012 — Queue lock fallback uses non-unique `microtime` modulo

**Severity**: MEDIUM
**Status**: OPEN
**Evidence**: `app/Services/OrderService.php` and `app/Services/FrontendOrderService.php` — queue_number fallback on lock timeout: `'A' . str_pad((int)(microtime(true)*10) % 9999 + 1, ...)`. Under high concurrency, this can collide with existing queue numbers.
**Why orchestrator must know**: Queue number duplicates cause operational confusion at the counter. Any cycle deploying to high-volume branches should verify the cache driver supports atomic locks (not `file` driver).
**Next cycle**: `static-inspection` — verify `.env` cache driver.

---

## 3. Synchronization Fragility

### ORB-013 — `BROADCAST_DRIVER` defaults to `null` — all broadcasts silently discarded

**Severity**: CRITICAL
**Status**: OPEN
**Evidence**: `config/broadcasting.php` — `'default' => env('BROADCAST_DRIVER', 'null')`. If `.env` does not set `BROADCAST_DRIVER`, all `ShouldBroadcastNow` events (the backbone of KDS/OSS/POS realtime) are silently dropped with no error.
**Why orchestrator must know**: This is the single most dangerous configuration risk. A deployment with missing or wrong `.env` value means the entire realtime layer (KDS, OSS, POS notifications) silently stops. Claude must verify `.env` broadcast configuration is correct before approving any deployment-related cycle.
**Next cycle**: `human-verification` — confirm production `.env` has `BROADCAST_DRIVER=pusher`.

---

### ORB-014 — `QUEUE_CONNECTION` defaults to `sync` — FCM jobs block HTTP requests

**Severity**: HIGH
**Status**: OPEN
**Evidence**: `config/queue.php` — `'default' => env('QUEUE_CONNECTION', 'sync')`. With `sync`, `SendFcmNotificationJob` runs inline during order creation/status change. If FCM fails and throws, the exception propagates into the try/catch around notifications, potentially skipping broadcast events that follow.
**Why orchestrator must know**: The documented P0 risk ("queue workers + realtime reliability") is directly caused by this default. Any cycle that touches notification behavior must verify the actual queue driver. Under `sync`, job retries (`$tries = 3`) cause 3 sequential HTTP calls to FCM during the order request — adding seconds of latency.
**Next cycle**: `human-verification` — confirm production `.env` has `QUEUE_CONNECTION=database` and workers are running.

---

### ORB-015 — `ShouldBroadcastNow` has no retry — Pusher failure loses the event

**Severity**: HIGH
**Status**: OPEN
**Evidence**: `app/Events/OrderCreated.php`, `app/Events/OrderStatusChanged.php`, `app/Events/ItemAvailabilityChanged.php` — all implement `ShouldBroadcastNow` (synchronous broadcast). No try/catch around the broadcast dispatch in most service methods (the broadcast happens inside Laravel's event dispatcher).
**Why orchestrator must know**: A transient Pusher failure (network blip, rate limit, timeout) means KDS/OSS permanently miss that event. There is no compensating mechanism (no polling fallback, no retry, no missed-event recovery). Claude should recommend `ShouldBroadcast` (queued with retries) over `ShouldBroadcastNow` for any reliability-focused cycle, or implement a polling fallback on KDS/OSS.
**Next cycle**: `static-inspection` — assess impact of switching to `ShouldBroadcast` + queue.

---

### ORB-016 — `FirebaseService` inner catch is empty — per-token FCM failures invisible

**Severity**: MEDIUM
**Status**: OPEN
**Evidence**: `app/Services/FirebaseService.php` — inner `catch` block around per-token POST to FCM API is empty (no logging, no counter, no rethrow). Outer catch only `Log::info`.
**Why orchestrator must know**: FCM token-level failures (invalid token, expired token, wrong project) are completely invisible. Debugging "notifications not received" is nearly impossible without adding logging. Any cycle touching FCM reliability must fix this.
**Next cycle**: `local-validation` — add `Log::warning` in inner catch with token prefix and error.

---

### ORB-017 — `ItemAvailabilityChanged` not dispatched on item destroy

**Severity**: LOW
**Status**: OPEN
**Evidence**: `app/Services/ItemService.php` — `update()` dispatches `ItemAvailabilityChanged`; `destroy()` does not. Kiosks displaying a deleted item will not receive a realtime removal signal.
**Why orchestrator must know**: Low severity because menu caching/TTL on kiosks will eventually catch up. But in a fast-paced environment, a deleted item could remain visible until cache refresh.
**Next cycle**: No immediate action — flag if kiosk freshness issues are reported.

---

## 4. Unclear Ownership Between Services

### ORB-018 — Three services change order status with different post-transition behavior

**Severity**: HIGH
**Status**: OPEN
**Evidence**:
- `OrderService::changeStatus` — staff: `DB::transaction` + branch check + `ActionLog` + `OrderStatusChanged` after commit. Customer (`$auth=true`): no transaction, no `OrderStatusChanged` (see ORB-011).
- `OrderService::deliveryBoyOrderChangeStatus` — no transaction, events before save (see ORB-005).
- `FrontendOrderService::changeStatus` — owner-only, cancel-only, `OrderStatusChanged` after save, no transaction wrapper.
- `KitchenDisplaySystemOrderService::changeStatus` — `DB::transaction` around save, `OrderStatusChanged` after commit.
**Why orchestrator must know**: The same logical operation ("change order status") has 4+ code paths with materially different transaction safety, event dispatch timing, branch checking, and audit logging. Any plan that modifies status transition behavior must identify ALL paths. A fix applied to one service but not the others creates inconsistency.
**Next cycle**: `static-inspection` — produce a comparison matrix of all `changeStatus` implementations.

---

### ORB-019 — Price recalculation is duplicated across 4 store methods

**Severity**: MEDIUM
**Status**: LEGACY/DEBT
**Evidence**: `OrderService::myOrderStore`, `OrderService::posOrderStore`, `OrderService::tableOrderStore`, `FrontendOrderService::myOrderStore` — all contain nearly identical item/variation/extra/tax/coupon calculation loops. Differences: POS allows line-level discount; table silently skips missing variations; frontend creates `OrderCoupon` even with zero discount.
**Why orchestrator must know**: A pricing bug fix applied to one store method must be replicated across all four. The subtle differences (silent skip vs throw, zero-discount `OrderCoupon`, `delivery_charge ?? 0` vs bare `delivery_charge`) mean mechanical copy-paste is dangerous. Claude must enforce that any pricing-related plan explicitly lists all four methods and their behavioral differences.
**Next cycle**: `static-inspection` — document the behavioral differences between the four store methods and assess extraction into a shared pricing calculator.

---

## 5. Dead Code / Stale Branches

### ORB-020 — Routes to missing controller methods

**Severity**: LOW
**Status**: OPEN
**Evidence**:
- `routes/api.php` line ~630: `Route::delete('/{order}', [OnlineOrderController::class, 'destroy'])` — `OnlineOrderController` has no `destroy` method.
- `routes/api.php` line ~641: `Route::delete('/{order}', [AdminTableOrderController::class, 'destroy'])` — `TableOrderController` has no `destroy` method.
**Why orchestrator must know**: DELETE requests to these endpoints return 500 (BadMethodCallException). If debug mode is on in production, stack traces are exposed. Any cycle touching route cleanup or error handling should fix these.
**Next cycle**: `local-validation` — either implement or remove these routes.

---

### ORB-021 — Unused enum `NotificationType`

**Severity**: LOW
**Status**: LEGACY/DEBT
**Evidence**: `app/Enums/NotificationType.php` — no `use` statement or reference found anywhere in the codebase outside this file.
**Why orchestrator must know**: Cosmetic. Only relevant if a cleanup cycle is scoped.
**Next cycle**: No immediate action.

---

### ORB-022 — Broken import: `AmountType` in `AppLibrary`

**Severity**: LOW
**Status**: OPEN
**Evidence**: `app/Libraries/AppLibrary.php` — `use App\Enums\AmountType;` but no `AmountType.php` exists under `app/Enums/`. Never referenced in the file body.
**Why orchestrator must know**: Autoloader resolves this lazily (class not loaded unless referenced). No runtime error currently, but a static analysis tool or future use would fail. Any cycle running `phpstan` or `psalm` will flag this.
**Next cycle**: `local-validation` — remove the dead import.

---

### ORB-023 — Emergency/operational migrations in schema history

**Severity**: LOW
**Status**: LEGACY/DEBT
**Evidence**: `database/migrations/2026_03_11_000000_reset_menu_french.php` and `2026_03_11_999999_emergency_purge_english_menu.php` — data reset scripts mixed into the migration history. Both use `!app()->environment('testing')` for verbosity.
**Why orchestrator must know**: Running `php artisan migrate:fresh` will re-execute these destructive data operations. Any cycle involving database setup or test seeding should be aware.
**Next cycle**: No immediate action — flag if migration cleanup is scoped.

---

### ORB-024 — Redundant `DB::rollBack()` in `OrderService` catch blocks

**Severity**: LOW
**Status**: LEGACY/DEBT
**Evidence**: `app/Services/OrderService.php` — catch blocks in `myOrderStore`, `posOrderStore`, `tableOrderStore`, `destroy` call `DB::rollBack()` after `DB::transaction()` closures, which already auto-rollback on exception.
**Why orchestrator must know**: Extra rollback can decrement Laravel's transaction nesting counter below zero, potentially breaking savepoints in nested transaction scenarios. Low risk with current code but a latent issue.
**Next cycle**: `local-validation` — remove redundant rollbacks and test.

---

## 6. Doc/Code Mismatches

### ORB-025 — `BUSINESS_RULES.md` and `DATABASE_SCHEMA_CORE.md` use wrong status numeric values

**Severity**: HIGH
**Status**: MITIGATED (2026-04-12, REAL-CYCLE-001)
**Evidence (historical)**:
- Avant correctif : `BUSINESS_RULES.md`, `DATABASE_SCHEMA_CORE.md`, `.cursor/rules/safety.mdc`, `DEBUG_GUIDE.md`, `ARCHITECTURE_TECHNIQUE.md`, `MASSIVE_TEST_PLAN.md`, `GUIDE_DEVELOPPEUR.md`, `CONTRIBUTING_QA_BOTS.md` utilisaient des entiers erronés (ex. 5/10/14/17).
- **Code source de vérité** (`app/Enums/OrderStatus.php`): `PENDING=1, ACCEPT=4, PREPARING=7, PREPARED=8, OUT_FOR_DELIVERY=10, DELIVERED=13, CANCELED=16, REJECTED=19, RETURNED=22`.
**Mitigation**: Docs et règle Cursor `safety.mdc` alignés sur l’enum ; toujours vérifier l’enum avant tout plan qui touche `orders.status`.
**Why orchestrator must know**: Toute nouvelle doc ou copier-coller depuis d’anciens rapports peut réintroduire de mauvais entiers — croiser systématiquement `OrderStatus.php`.
**Next cycle**: `static-inspection` — re-grep périodique sur motifs `status.*=.*1[0-9]` hors enum.

---

### ORB-026 — `API_MAP.md` misrepresents OSS auth and KDS filter

**Severity**: MEDIUM
**Status**: OPEN
**Evidence**:
- `docs/API_MAP.md` says OSS (`/api/admin/oss-order`) uses `api-key` auth only. **Actual**: route requires `installed` + `apiKey` + `auth:sanctum` + `localization`, and controller enforces `permission:order-status-screen`.
- `docs/API_MAP.md` says KDS list is "filtered PREPARING". **Actual**: `KitchenDisplaySystemOrderService::list()` uses `whereIn('status', [ACCEPT, PREPARING, PREPARED])`.
**Why orchestrator must know**: If Claude approves a plan based on "OSS is api-key only" or "KDS shows only PREPARING", the plan is based on false premises.
**Next cycle**: `local-validation` — update `API_MAP.md`.

---

### ORB-027 — `AUTHZ_MATRIX.md` overstates kiosk ability enforcement

**Severity**: MEDIUM
**Status**: OPEN
**Evidence**: `docs/AUTHZ_MATRIX.md` says kiosk abilities "bloque nativement" admin access. **Actual**: `tokenCan('kiosk:order')` is checked in `OrderRequest.php`, `OrderStatusRequest.php`, `LoyaltyController.php`, `SettingResource.php`, and `channels.php` — but NOT in any route middleware. Sanctum does not globally deny admin routes for kiosk tokens; Spatie permission middleware on admin controllers provides the actual barrier.
**Why orchestrator must know**: A plan that relies on "kiosk token can't access admin endpoints because of abilities" is partially wrong. The defense-in-depth is Spatie permissions, not Sanctum abilities.
**Next cycle**: `static-inspection` — verify Spatie middleware on all admin controller constructors.

---

### ORB-028 — `CORE_MODULES.md` says "Flutter" for kiosk, project uses Electron

**Severity**: LOW
**Status**: OPEN
**Evidence**: `docs/CORE_MODULES.md` section title: "Flux Kiosk (Flutter ↔ API)". `docs/PROJECT_CONTINUITY_AND_VISION.md` describes kiosk as Electron Windows.
**Why orchestrator must know**: Minor terminology drift but could cause confusion in kiosk-related plans.
**Next cycle**: `local-validation` — align terminology.

---

### ORB-029 — `TEST_PLAN.md` is stale — says "OrderFlowTest à écrire" but it exists

**Severity**: LOW
**Status**: OPEN
**Evidence**: `docs/TEST_PLAN.md` line ~14 says `OrderFlowTest` needs to be written. `tests/Feature/OrderFlowTest.php` exists and contains actual tests. Many other test files (30+) are not mentioned in the plan at all.
**Why orchestrator must know**: If Claude reads `TEST_PLAN.md` to determine test coverage, it will get a false picture. The plan is not a reliable inventory of existing tests.
**Next cycle**: `local-validation` — regenerate test plan from actual `tests/` directory.

---

## 7. Missing Test Coverage on Critical Paths

### ORB-030 — `ValidStatusTransition` has no dedicated unit test

**Severity**: HIGH
**Status**: OPEN
**Evidence**: No `tests/**/ValidStatusTransitionTest.php`. `OrderStateTransitionTest.php` tests KDS status change via HTTP but does not exhaustively test the 9×9 transition matrix or the Admin bypass or the POS ACCEPT→DELIVERED shortcut.
**Why orchestrator must know**: The transition rule is the single guard for the entire order lifecycle. Any change to it without a full matrix test risks opening illegal transitions. Claude must require a dedicated test for any plan that modifies `ValidStatusTransition`.
**Next cycle**: `local-validation` — write `ValidStatusTransitionTest` covering all 81 combinations.

---

### ORB-031 — `CouponService` has zero test coverage

**Severity**: HIGH
**Status**: OPEN
**Evidence**: No test file imports or references `CouponService`. `CouponSecurityTest` has `$this->assertTrue(true)` — a placeholder that always passes.
**Why orchestrator must know**: Coupon validation directly affects revenue. Without tests, any change to `CouponService` (even a bugfix like adding `start_date` check) could introduce regressions that pass CI.
**Next cycle**: `local-validation` — write `CouponServiceTest` covering `couponChecking`, `couponDateWise`, per-user limits, minimum order.

---

### ORB-032 — `RefreshTokenController` has no test coverage

**Severity**: MEDIUM
**Status**: OPEN
**Evidence**: No test references `/api/refresh-token` or `RefreshTokenController`. The controller mints new tokens without revoking old ones — a security-relevant behavior that has never been verified by automation.
**Why orchestrator must know**: Any cycle reviewing auth security should flag this gap. A plan that claims "auth is tested" without covering refresh is a false approval.
**Next cycle**: `local-validation` — write test for token refresh including old-token validity check.

---

### ORB-033 — `TransactionService` has no test coverage

**Severity**: LOW
**Status**: OPEN
**Evidence**: No test file references `TransactionService`. The service is simple (list + filter), so risk is low.
**Why orchestrator must know**: Low priority but should be on the coverage map.
**Next cycle**: No immediate action.

---

### ORB-034 — `BranchIsolationTest` is a placeholder

**Severity**: MEDIUM
**Status**: OPEN
**Evidence**: `tests/Feature/BranchIsolationTest.php` contains only `$this->assertTrue(true)`. It does not test actual cross-branch isolation.
**Why orchestrator must know**: The test file's existence creates false confidence. CI reports "BranchIsolationTest: passed" but nothing was verified. Any cycle claiming "branch isolation is tested" is a false approval if it only checks test file existence.
**Next cycle**: `local-validation` — implement real branch isolation assertions.

---

### ORB-035 — Unit tests named after services don't actually test the services

**Severity**: HIGH
**Status**: OPEN
**Evidence**:
- `tests/Unit/Services/FrontendOrderServiceTest.php` — does NOT instantiate `FrontendOrderService`. Instead, it reads the service's PHP source code as text and searches for string markers (`PLAN_01`, `Item::select`). Simulates pricing logic in the test, not in the service.
- `tests/Unit/Services/OrderServiceSecurityTest.php` — same pattern: reads `OrderService.php` as text, searches for variation/extra pricing markers.
**Why orchestrator must know**: These tests verify that certain strings exist in source code, not that the service behaves correctly. They pass even if the service has a logic bug that doesn't remove those strings. Claude must treat these as `static-inspection` artifacts, not as `local-validation` evidence.
**Next cycle**: `local-validation` — rewrite as actual integration tests that call service methods.

---

## 8. Areas Likely to Cause False Approvals

### ORB-036 — Test file existence ≠ test coverage

**Severity**: HIGH (orchestrator-meta risk)
**Status**: OPEN
**Evidence**: 36 feature test files + 3 unit test files exist. But `BranchIsolationTest` is a placeholder, `CouponSecurityTest` has a `assertTrue(true)`, and the unit "service tests" are source-code scanners. Several critical paths (coupon validation, token refresh, delivery boy dispatch, full transition matrix) have no real coverage.
**Why orchestrator must know**: When reviewing a cycle's test evidence, Claude must **read the test assertions**, not just check that test files exist and CI passes. The pattern "test file exists → path is tested" is dangerously wrong in this codebase.
**Mitigation**: For any `APPROVED` verdict, Claude should verify that referenced tests contain real assertions on the claimed behavior.

---

### ORB-037 — Doc status values accepted as code truth

**Severity**: CRITICAL (orchestrator-meta risk)
**Status**: OPEN
**Evidence**: See ORB-025. Four doc files use wrong numeric status values. If Claude or Cursor reads these docs during planning and uses the wrong values, implementations will silently use incorrect status integers.
**Mitigation**: Claude must always cross-reference `app/Enums/OrderStatus.php` when any plan involves status values. Never trust doc-stated values.

---

### ORB-038 — `AUTHZ_MATRIX.md` OSS entry can mislead security reviews

**Severity**: MEDIUM (orchestrator-meta risk)
**Status**: OPEN
**Evidence**: See ORB-026. Doc says "api-key uniquement" but code requires Sanctum + Spatie permission. A security review based on the doc would conclude OSS has weak auth, leading to unnecessary "fix" proposals that break the actual (stronger) auth.
**Mitigation**: Claude must verify auth claims against `routes/api.php` + controller constructor middleware.

---

## 9. Areas Likely to Cause Hidden Regressions

### ORB-039 — Variation/extra resolution differs between POS and table order creation

**Severity**: MEDIUM
**Status**: OPEN
**Evidence**: `OrderService::posOrderStore` throws if a variation/extra ID doesn't exist in DB. `OrderService::tableOrderStore` silently skips missing variations/extras (only adds if `$dbVar` / `$dbExt` is truthy). If a plan "unifies" these paths or "fixes" error handling, it may break the silent-skip behavior that table ordering currently relies on.
**Why orchestrator must know**: Any plan modifying variation/extra handling must explicitly state which behavior is intended for each order type. A "defensive" fix that adds throws to the table path could break QR ordering.
**Next cycle**: `static-inspection` — document intended behavior per path.

---

### ORB-040 — `FrontendOrder` missing `total_tax` cast can cause type mismatches

**Severity**: LOW
**Status**: OPEN
**Evidence**: `app/Models/FrontendOrder.php` — `total_tax` is in `$fillable` but not in `$casts`. `Order.php` casts it as `decimal:6`. Code comparing `$order->total_tax === $frontendOrder->total_tax` may get inconsistent types (string vs float).
**Why orchestrator must know**: Any plan that processes orders from both models (e.g., reporting across surfaces) should be aware of this inconsistency.
**Next cycle**: `local-validation` — add cast and test.

---

### ORB-041 — `KioskMachine.password` is mass-assignable

**Severity**: MEDIUM
**Status**: OPEN
**Evidence**: `app/Models/KioskMachine.php` — `password` is in `$fillable`. If any code path calls `KioskMachine::create()` or `->fill()` with unhashed input, plaintext passwords will be stored. `Hash::check` in `KioskMachineLoginController` will then always fail.
**Why orchestrator must know**: Any plan touching kiosk machine management (create/update) must verify password hashing. A mutator on the model would provide defense-in-depth.
**Next cycle**: `static-inspection` — verify all `KioskMachine::create` / `update` call sites hash passwords.

---

### ORB-042 — `RefreshTokenController` accumulates tokens without revocation

**Severity**: HIGH
**Status**: OPEN
**Evidence**: `app/Http/Controllers/Auth/RefreshTokenController.php` — `refreshToken()` creates a new Sanctum token but does not delete the old one. If `config/sanctum.php` has no `expiration` value, tokens live forever.
**Why orchestrator must know**: Any security review cycle that doesn't verify token expiration config is incomplete. A plan claiming "auth is secure" without addressing token accumulation is a false approval.
**Next cycle**: `static-inspection` — check `config/sanctum.php` for `expiration` setting.

---

### ORB-043 — `TableOrderController::tokenCreate` missing Spatie permission

**Severity**: MEDIUM
**Status**: OPEN
**Evidence**: `app/Http/Controllers/Admin/TableOrderController.php` — `tokenCreate` is not listed in the constructor's `middleware()->only([...])` for `permission:table-orders`. It inherits only `auth:sanctum` from the route group.
**Why orchestrator must know**: Any authenticated user can call this endpoint regardless of role. If `tokenCreate` creates customer-facing tokens for table orders, this is a privilege escalation path.
**Next cycle**: `local-validation` — add `tokenCreate` to permission middleware.

---

## Priority Matrix for Orchestrator

| Priority | Risk IDs | Theme |
|----------|----------|-------|
| **Inspect first** | ORB-025, ORB-037 | Doc/code status mismatch — blocks correct planning |
| **Fix next** | ORB-005, ORB-013, ORB-014 | Critical dispatch/config bugs — data integrity + realtime |
| **Strengthen** | ORB-011, ORB-015, ORB-018, ORB-030, ORB-031 | Status path consistency + test coverage |
| **Harden** | ORB-001, ORB-002, ORB-010, ORB-042 | Architecture drift + security |
| **Clean up** | ORB-020, ORB-022, ORB-024, ORB-029 | Dead code + stale docs |
| **Monitor** | ORB-003, ORB-004, ORB-017, ORB-021, ORB-023 | Low-risk debt |

---

## Orchestrator Operating Rules (derived from this audit)

1. **Never trust doc-stated status values.** Always verify against `app/Enums/OrderStatus.php`.
2. **Never assume test file existence = coverage.** Read assertions before granting test-evidence credit.
3. **Any plan touching status transitions must list ALL 4+ changeStatus implementations.**
4. **Any plan touching pricing must list ALL 4 store methods and their behavioral differences.**
5. **Any plan touching order models must check BOTH `Order` and `FrontendOrder`.**
6. **Any plan touching frozen zones must be flagged and require explicit human approval.**
7. **Any deployment-related plan must verify `.env` values for `BROADCAST_DRIVER` and `QUEUE_CONNECTION`.**
8. **Any auth security review must verify actual route middleware, not `AUTHZ_MATRIX.md` claims.**
9. **Placeholder tests (`assertTrue(true)`) must be treated as zero coverage, not passing tests.**
10. **Source-scanning unit tests (reading PHP as text) must be treated as `static-inspection`, not `local-validation`.**
