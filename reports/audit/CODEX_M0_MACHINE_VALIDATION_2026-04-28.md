# CODEX M0 Machine Validation — Super Audit Rework — 2026-04-28

Date: 2026-04-28  
Mission: `SUPER-AUDIT-M0-MACHINE-VALIDATION-2026-04-28`  
Plan: `plans/PLAN_SUPER_AUDIT_REWORK_ORCHESTRATION_2026-04-28.md`  
Scope: validation machine only, no product code changes.

## Verdict

`M0_STATUS: COMPLETED`  
`M0_VERDICT: NEEDS_FIX`  
`RELEASE_DECISION: HOLD_HARDWARE_UAT`

Reason: M0 confirms several Claude risks as real evidence gaps, and finds one concrete FoodKing invariant risk in stock availability dispatch. No direct pricing, branch leak, or fiscal cash-at-counter regression was proven in this pass.

---

## Commands Run

Greps:

```bash
rg -n "page\\.route\\(|route\\.fulfill\\(|route\\.abort\\(|mock|fixture" tests/e2e tests/Playwright
rg -n -- "->status\\s*=\\s*'|->payment_status\\s*=\\s*'|==\\s*'pending'|===\\s*'pending'" app
rg -n "afterCommit|\\$afterCommit|ShouldQueueAfterCommit" app/Providers app/Listeners app/Events app/Jobs
rg -n "throttle|counter-collect|kds|order-status|oss" routes app/Http
rg -n "delivery_charge|delivery_fee|DeliveryFeeService|deliveryCharge" app resources/js tests
```

Validation runs:

```bash
php artisan test tests/Feature/Payment/CounterDeferredPaymentLifecycleTest.php
php artisan test tests/Feature/Delivery/DeliveryFeeForgeWebTest.php
php artisan test tests/Feature/Delivery/DeliveryFeeForgePosTest.php
php artisan test tests/Feature/Delivery/GeocodeFailureBlocksOrderTest.php
php artisan test tests/Feature/Stock/StockConcurrentDecrementTest.php
php artisan test tests/Feature/QueueNumberConcurrencyTest.php
npx playwright test tests/e2e/kiosk-post-payment-auto-return.spec.js --project=chromium --retries=0
```

Results:

- Payment counter deferred: 5 passed.
- Delivery forge web: 1 passed.
- Delivery forge POS: 1 passed.
- Geocode failure blocks order: 1 passed.
- Stock current suite: 2 passed.
- Queue number current suite: 4 passed.
- C0 kiosk post-payment auto-return: initial run failed because `localhost:8000` was not running; after temporary `php artisan serve --host=127.0.0.1 --port=8000`, rerun passed: 1 passed.

---

## Hypothesis Classification

| ID | Claude hypothesis / risk | Classification | Evidence |
|---|---|---|---|
| H-001 | C1/C2 may be fixture-assisted, not true full runtime submit | FACT | `tests/e2e/helpers/process-audit.js:87-193` creates orders with `php artisan tinker` and `Order::forceCreate`; `tests/e2e/kiosk-full-process/c1-kiosk-process-audit.spec.js:29-47` uses that fixture then opens waiting page; `tests/e2e/pos-full-process/c2-pos-process-audit.spec.js:57-76` same for POS. |
| H-002 | C0 auto-return should be verified after bug fix | FACT PASS | `tests/e2e/kiosk-post-payment-auto-return.spec.js:97-105` navigates waiting -> confirmation -> idle. Rerun passed after server start. |
| H-003 | E2E mocks may fake C1/C2 | PARTIAL/FALSE | Grep found no `page.route()` mocks in C1/C2. However fixtures bypass real submit and order services, so proof is still partial. |
| H-004 | `clearTransientUi()` may mask serious design errors | PARTIAL | `tests/e2e/design/_shared/design-audit-helpers.js:71-83` removes toast containers before axe. It does not disable axe rules, but can hide real toast contrast/content problems. |
| H-005 | Status magic strings in app code | FALSE for searched pattern | Required grep returned no app hits for `->status = '...'`, `->payment_status = '...'`, or `'pending'` equality. |
| H-006 | Delivery fee SSOT may still be forged | MOSTLY FALSE | Web `OrderRequest` recomputes delivery at `app/Http/Requests/OrderRequest.php:39-64`; POS `PosOrderRequest` recomputes at `app/Http/Requests/PosOrderRequest.php:18-25`; `PosController` quote recomputes at `app/Http/Controllers/Admin/PosController.php:111-114`; tests pass. |
| H-007 | Delivery frontend helper may be old 1 EUR/km rule | FALSE | `resources/js/helpers/deliveryCharge.js:11-13` and backend `DeliveryFeeService.php:14` both use 5 EUR per started 5 km tranche. |
| H-008 | Cash-at-counter fiscal guard unproven | PARTIAL, current targeted tests PASS | `PaymentService::confirmCounterPayment` allocates fiscal sequence inside transaction at `app/Services/PaymentService.php:141-173`; cancel path does not allocate at `app/Services/PaymentService.php:214-271`; `CounterDeferredPaymentLifecycleTest` passed. Full C6 HMAC/Z/outbox still not complete. |
| H-009 | Counter-collect routes inline and risky | PARTIAL | Inline closures exist at `routes/api.php:654-713`. They enforce `can('pos')` and branch is enforced by `PaymentService::assertCounterOrderVisible` at `app/Services/PaymentService.php:276-281`. Architecture cleanup remains useful; no direct P0 leak proven. |
| H-010 | Kiosk admin route still exposed | FALSE for current source | `resources/js/router/modules/kioskRoutes.js:228-233` redirects `kiosk.admin` to `kiosk.idle`. |
| H-011 | Stock current test is not true high concurrency | FACT | `tests/Feature/Stock/StockConcurrentDecrementTest.php:31-53` is a sequential loop of 5 decrements, not parallel workers. |
| H-012 | Queue current test is not true runtime stress | FACT | `tests/Feature/QueueNumberConcurrencyTest.php:17-111` verifies DB uniqueness/dates/branches/nulls. It does not create POS+kiosk orders concurrently. |
| H-013 | Queue algorithm has app lock + DB retry | FACT | POS `OrderService.php:2050-2155` and kiosk/frontend `FrontendOrderService.php:828-933` use cache locks, unique violation retry, and business date. Stress still missing. |
| H-014 | MySQL surface filtering remains unvalidated locally | FACT | `tests/Feature/Menu/FrontendSurfaceFilteringTest.php:57-64` skips unless DB driver is MySQL. |
| H-015 | KDS/OSS rate limit may be too low | FACT/P1 | Whole admin API group has `throttle:admin-mutation` at `routes/api.php:239`, defined as 30/min in `app/Providers/RouteServiceProvider.php:77-79`. KDS/OSS GET routes live under that group at `routes/api.php:899-917`, so polling/repeated screens can hit 429. |
| H-016 | Branch isolation on composer step fixes exists | FACT PASS | `ComposerStepController.php:21`, `:28`, `:35` calls `authorizeBranchScope`; `AdminController.php:15-27` enforces branch except Admin/Tenant Admin. |
| H-017 | KDS branch isolation exists in service | FACT | `KitchenDisplaySystemOrderService.php:63-80` scopes list by branch; `:158-160` guards change-status cross-branch. |
| H-018 | OSS branch isolation exists for list | FACT | `OrderStatusScreenOrderService.php:40-70` applies branch scope; `:91-108` rejects invalid non-admin branch scope. Note: `mostPopularItems()` remains global at `:81-89` and should be evaluated in M6. |

---

## Findings

### P0-A — Evidence Gap: C1/C2 Are Not True Full Submit Runtime

This is not a product bug by itself, but it is a UAT blocker because the current "full process" proof bypasses the actual customer/cashier submit paths.

Evidence:

- `tests/e2e/helpers/process-audit.js:87-193` creates orders directly through `php artisan tinker` and `Order::forceCreate`.
- `tests/e2e/helpers/process-audit.js:163-165` pre-allocates fiscal sequence for paid fixture orders.
- `tests/e2e/helpers/process-audit.js:217-219` manually decrements stock through `StockService`.
- C1 then opens `/kiosk/waiting/{id}` and validates waiting/confirmation behavior.
- C2 opens POS and tests counter-collect confirm/cancel real API actions, but most initial POS orders are fixtures.

Impact:

- C1/C2 prove display/lifecycle assertions around prepared data.
- They do not prove real UI cart -> quote -> order submit -> backend order services -> outbox -> KDS/OSS.

Required next work:

- M1 must create true runtime multi-surface Playwright tests.
- Add at least one real kiosk submit and one real POS submit scenario, not just fixture-created orders.

### P0-B — Invariant Risk: `ItemAvailabilityChanged` Dispatch Inside Stock DB Transaction

Evidence:

- `StockService::mutateForOrder()` wraps mutation in `DB::transaction` at `app/Services/Stock/StockService.php:45-47`.
- Availability events are collected during the transaction at `app/Services/Stock/StockService.php:119-122`.
- The service dispatches them with raw `event($event)` before the transaction closes at `app/Services/Stock/StockService.php:125-127`.
- The event class has `DispatchableAfterCommit`, but raw `event($event)` bypasses the trait's static `dispatch()` behavior.
- Synchronous listeners do real side effects:
  - `BumpMenuSnapshotOnItemAvailabilityChanged.php:28-38`
  - `InvalidateKioskMenuCacheOnItemAvailabilityChanged.php` is registered in `EventServiceProvider.php:143`
  - `PersistCatalogChangedToOutbox.php:23-79` persists domain events then schedules dispatch after commit.

Impact:

- The outbox job dispatch is protected by `DB::afterCommit`, but cache/snapshot side effects can run before commit.
- If the stock transaction rolls back after the availability event fires, a kiosk/POS menu snapshot can be bumped or cache invalidated for state that did not commit.
- This violates the FoodKing invariant: events/side effects after DB commit.

Required correction:

- Create `M0-P0-DISPATCH-AFTER-COMMIT`.
- Refactor stock availability event dispatch so all `ItemAvailabilityChanged` side effects fire after commit.
- Add a rollback test proving no snapshot/cache/outbox side effect occurs when stock mutation rolls back.

### P1-A — KDS/OSS Admin Throttle Is Too Low For Polling Surfaces

Evidence:

- Admin group applies `throttle:admin-mutation`: `routes/api.php:239`.
- That limiter is 30 req/min: `app/Providers/RouteServiceProvider.php:77-79`.
- KDS and OSS GET endpoints are inside the same group: `routes/api.php:899-917`.
- Prior D3 report observed transient `Too Many Attempts.`

Impact:

- Multiple KDS/OSS screens, fallback polling, or repeated audit runs can hit 429.
- This can freeze kitchen/order-status visibility during rush.

Required correction:

- In M1/M6, add a dedicated read throttle for KDS/OSS list/sync routes, or move them off mutation throttle.
- Add a feature test that performs realistic KDS/OSS polling without 429.

### P1-B — Stock Stress Not Proved

Evidence:

- Current stock test loops sequentially 5 times: `tests/Feature/Stock/StockConcurrentDecrementTest.php:31-53`.
- `StockService.php:75-80` uses `lockForUpdate`, which is good, but not stress-proven.

Required next:

- M4: 50-worker stress and rupture live tests under MySQL where possible.

### P1-C — Queue Stress Not Proved

Evidence:

- Current queue test verifies DB unique guard only: `tests/Feature/QueueNumberConcurrencyTest.php:17-111`.
- App allocation code has lock/retry in both POS and frontend services:
  - `OrderService.php:2050-2155`
  - `FrontendOrderService.php:828-933`

Required next:

- M4: concurrent POS + kiosk order creation test, same branch/date, asserting unique queue numbers and retry behavior.

### P1-D — MySQL Surface Filtering Is A Known Test Gap

Evidence:

- `FrontendSurfaceFilteringTest` explicitly skips non-MySQL at `tests/Feature/Menu/FrontendSurfaceFilteringTest.php:57-64`.

Required next:

- M7 or M4 infra pass: run MySQL 8 test job or local Docker MySQL before UAT.

### P2-A — Counter-Collect Inline Routes Are Not A Proven Leak, But Should Be Refactored

Evidence:

- Inline routes: `routes/api.php:654-713`.
- Permission gate exists: `routes/api.php:655`, `:671`, `:695`.
- Branch guard exists in `PaymentService.php:276-281`.
- Targeted counter-deferred tests pass.

Decision:

- Not a P0 now.
- Refactor to controller in M6 after P0/P1 runtime evidence is closed.

### P2-B — OSS `mostPopularItems()` Global Scope Needs Policy Decision

Evidence:

- `OrderStatusScreenOrderService::list()` is branch-scoped.
- `OrderStatusScreenOrderService::mostPopularItems()` returns global active items with order counts at `app/Services/OrderStatusScreenOrderService.php:81-89`.

Decision:

- Not proven as current UAT blocker.
- M6 should decide whether OSS popular items must be branch-scoped.

---

## What Is Strongly Validated By M0

- C0 kiosk auto-return: PASS after server start.
- Delivery fee forge web: PASS.
- Delivery fee forge POS quote: PASS.
- Geocode failure blocks order: PASS.
- Counter-deferred payment lifecycle: PASS.
- Counter-deferred branch scoping/permission tests: PASS.
- Stock basic atomic guard/rollback: PASS.
- Queue DB uniqueness by branch/business date: PASS.
- Kiosk admin route source redirects to idle.
- No status magic strings found by required grep pattern.
- Composer step branch isolation fix exists.

## What Remains Not Proven

- Real kiosk submit full order flow.
- Real POS submit full order flow.
- C3 Kiosk/POS/KDS/OSS live multi-surface propagation.
- Stock 50+ concurrency.
- Queue POS+kiosk concurrency.
- Full C6 fiscal HMAC/Z/outbox replay.
- C8 payment/order atomic side effects matrix.
- MySQL 8 surface filtering.
- KDS/OSS polling without 429.
- Dashboard C9 category/product/photo/stock/composer E2E.

---

## Immediate Next Mission

`M0-P0-DISPATCH-AFTER-COMMIT` must run before deep C3/C4 stress because stock rupture/catalog sync can otherwise publish freshness side effects before the transaction commits.

Then execute:

1. M1 C3 runtime multi-surface.
2. M2 fiscal/outbox/persistence.
3. M4 stock/queue stress.
4. M5 delivery/pricing final hardening.
5. M6 authz/rate limit/counter-collect cleanup.

