# Admin Dashboard — HEAL S-1 IDOR STATUS

| Field | Value |
|---|---|
| Date | 2026-05-18 |
| Branch | `heal/cms-pr1-quickwins-2026-05-18` |
| Finding ID | Admin S-1 (audit AD-2-security) |
| Severity | P0 |
| Status | GREEN (closed at middleware layer; service-layer IDOR deferred to V1.0.2 — see §6) |
| Mode | TDD-first sentinel, scope-minimal route patch |

---

## 1. Vulnerability summary

Route: `GET /api/admin/my-order/show/{user}/{order}`
Controller: `App\Http\Controllers\Admin\MyOrderDetailsController::orderDetails`
File: `app/Http/Controllers/Admin/MyOrderDetailsController.php` (30 lines, single method)

**Pre-heal authz**
- Route declaration at `routes/api.php:574` — NO `permission:*` middleware on the route.
- Controller constructor — empty `parent::__construct()` only. NO `$this->middleware(['permission:*'])` calls (unlike its 6 consumer SPA peers).
- Sole authz check: `OrderService::orderDetails()` line 1414 compares **URL params to each other** (`$order->user_id == $user->id`), **not** to `auth()->id()`.

**Attack pattern**
Any authenticated user (even with zero admin permissions, or a customer-role user with no panel access) who can guess a valid `(user_id, order_id)` pair (e.g. by enumeration / referer leak / log scraping) can `GET /api/admin/my-order/show/{owner_user_id}/{their_order_id}` and receive the **full order payload**: `name`, `phone`, `email`, `address`, `payment_method`, `pos_payment_method`, `transaction`, `pos_received_amount` etc.

Verified pre-heal via sentinel test 5: response body included PII (name, phone `0605002860`, email `general44@example.com`, branch address) with HTTP 200 returned to a user with **no admin permissions whatsoever**.

---

## 2. Consumer SPA flow inventory (frontend grep)

The audit recommendation said 3 consumers. Actual count is **6**, all routed through `resources/js/store/modules/myOrderDetails.js:33`:

| Vue component | Permission gate (existing controller-level) |
|---|---|
| `CustomerOrderDetailsComponent.vue` | `customers_show` |
| `WaiterOrderDetailsComponent.vue` | `waiters_show` |
| `DeliveryBoyOrderDetailsComponent.vue` | `delivery-boys_show` |
| `ChefOrderDetailsComponent.vue` | `chefs_show` |
| `AdministratorOrderDetailsComponent.vue` | `administrators_show` |
| `EmployeeOrderDetailsComponent.vue` | `employees_show` |

All 6 permission names verified in their respective controllers (`grep -h "permission:.*_show"` over the 6 admin controllers).

---

## 3. Heal applied

**One-line route middleware patch** (`routes/api.php:574-583`):

```php
Route::prefix('my-order')->name('my-order.')->group(function () {
    // [Admin S-1 P0 IDOR heal — 2026-05-18] MyOrderDetailsController has
    // ZERO permission middleware at the controller level (unlike its 6
    // consumer SPA peers Customer/Waiter/DeliveryBoy/Chef/Administrator/
    // Employee, each of which gates `*_show`). Pre-heal, any authenticated
    // user who guessed a valid (user_id, order_id) pair could read the
    // full order payload (PII, addresses, payment). Apply alternation
    // OR-gate covering ALL 6 consumer SPA flows. Sentinel:
    // tests/Feature/Sentinels/MyOrderDetailsAuthzSentinelTest.php
    Route::get('/show/{user}/{order}', [MyOrderDetailsController::class, 'orderDetails'])
        ->middleware('permission:customers_show|waiters_show|delivery-boys_show|chefs_show|administrators_show|employees_show');
});
```

Spatie `PermissionMiddleware` (`vendor/spatie/laravel-permission/src/Middlewares/PermissionMiddleware.php:18-26`) treats pipe-separated names as OR — any one of the 6 grants access.

**Why route-level, not controller-level**: the 6 consumer SPA controllers gate their permissions in their own constructors. `MyOrderDetailsController` is a **single-method shared endpoint** consumed by all 6 SPA flows. Route-level alternation reflects the consumer reality more directly and avoids touching the controller (which would require 6 distinct middleware lines or a manual gate check). Also keeps the diff to a single annotated route line.

---

## 4. Sentinel test (TDD-first)

File: `tests/Feature/Sentinels/MyOrderDetailsAuthzSentinelTest.php` (NEW)

Six scenarios:

| # | Scenario | Expected | Pre-heal | Post-heal |
|---|---|---:|---:|---:|
| 1 | Anonymous request (no token) | 401 | 401 (sanctum already gated) | 401 |
| 2 | User with `customers_show` | 200 | 200 (un-gated, IDOR trivial) | 200 |
| 3 | User with `waiters_show` | 200 | 200 | 200 |
| 4 | User with `delivery-boys_show` | 200 | 200 | 200 |
| 5 | **User with NONE of the 6 perms (THE IDOR)** | **403** | **200 (BUG)** | **403 (FIXED)** |
| 6 | Cross-branch user (BranchScope defense) | 404 | 404 (route-model-binding scoped) | 404 |

Pre-heal RED state captured (1 of 6 failing — scenario 5):
```
FAIL  Tests\Feature\Sentinels\MyOrderDetailsAuthzSentinelTest
  ⨯ user without any show permission is forbidden 403
[Admin S-1 IDOR] User WITHOUT any *_show permission MUST be denied 403.
Pre-heal: this returned 200 (the IDOR). Body: {"data":{"id":1,...
"user":{"name":"Bernadine Predovic","phone":"0605002860","email":"general44@example.com",...
"branch":{"address":"15751 Gottlieb Lodge Apt. 370",...}}}
Failed asserting that 200 is identical to 403.
```

Post-heal GREEN state:
```
PASS  Tests\Feature\Sentinels\MyOrderDetailsAuthzSentinelTest
  ✓ anonymous request is unauthenticated 401
  ✓ customer show permission user can access
  ✓ waiter show permission user can access
  ✓ delivery boy show permission user can access
  ✓ user without any show permission is forbidden 403
  ✓ cross branch requester cannot see order 404
Tests: 6 passed
Time: 1.25s
```

---

## 5. Regression verification

| Suite | Pre-heal | Post-heal | Delta |
|---|---:|---:|---:|
| `tests/Feature/Sentinels/` (245 tests, full sentinel suite) | n/a baseline | **243 pass / 2 skip / 0 fail** | 0 |
| `tests/Feature/Admin/` (65 tests, all admin controllers) | n/a baseline | **65 pass / 0 fail** | 0 |
| `tests/Feature/Pos/` (66 tests, POS create/destroy/payments) | n/a baseline | **66 pass / 0 fail** | 0 |

Adjacent tests run individually (`DeliveryBoyAddressPermissionSplitTest`, `EmployeeRequestAuthorizeTest`, new sentinel): 14/14 pass.

No frontend bundle change required — `resources/js/store/modules/myOrderDetails.js` axios call is unchanged. The 6 SPA flows continue to work because the calling user always already has their controller's `*_show` permission (they were on a permission-gated entry page before reaching the detail tab).

---

## 6. V1.0.2 follow-up (NOT in this heal)

The heal closes the **practical** IDOR (narrowed attack pool from any-authenticated to "users with at least one admin `*_show` permission"), but **a conceptual hole remains**:

`OrderService::orderDetails()` (`app/Services/OrderService.php:1411-1423`) still authorizes by comparing the two URL params (`$order->user_id == $user->id`) rather than comparing to `auth()->id()`. A waiter on branch A who has `waiters_show` could in theory request the order-detail URL of a customer X who placed a takeaway in branch A even though waiter is not the owner — the alternation gate accepts the request, BranchScope confirms the order belongs to their branch, and the service signs off because the URL claims user X owns order Y.

Whether this remaining surface qualifies as IDOR depends on the multi-tenant trust model. For a single-restaurant V1 deployment (Le Cayenne), any branch-scoped staff with a `*_show` permission can view that branch's orders anyway via the customer/waiter detail pages — so the heal is **sufficient for V1**.

**V1.0.2 follow-up ticket** (cannot be done in this heal — `OrderService.php` is in the PROJECT_BRAIN DIRTY list and requires owner gate):
- Replace `if ($order->user_id == $user->id)` with `if ($order->user_id === auth()->id())` OR keep URL-param compare but additionally assert `auth()->user()->can('customers_show|waiters_show|...')` for the type of relationship being requested.
- Or: split into 3 distinct endpoints (`/customer/order/show/...`, `/waiter/order/show/...`, etc.) each with single `permission:` gate and explicit `(order->user_id == route('user'))` semantics — eliminates the need for `MyOrderDetailsController` entirely.

This is a **V1.0.2 candidate** under "Phase A: largest blast-radius authz refactors" (already tracked in `FormRequestAuthzDriftSentinelTest::RETURN_TRUE_BASELINE` comment block).

---

## 7. Files touched

| File | Status | Lines | Note |
|---|---|---:|---|
| `routes/api.php` | modified | +10 / -1 | Route alternation middleware + annotation |
| `tests/Feature/Sentinels/MyOrderDetailsAuthzSentinelTest.php` | new | +183 | 6-scenario sentinel |
| `reports/audit/admin-dashboard-2026-05-18/round-2/HEAL_S1_IDOR_STATUS.md` | new | this file | Heal report |

Frozen-zone touch: **none** (route file `routes/api.php` not in CLAUDE.md §7 list; `MyOrderDetailsController.php` not in CLAUDE.md §7).
DIRTY file touch: **none** (`OrderService.php` NOT touched; only the route middleware was added).
NF525 chain impact: **none** (read-only endpoint; no fiscal sequence, no audit_logs writes).

---

## 8. Commit

```
fix(admin-authz-P0): close MyOrderDetailsController IDOR — alternation permission gate (Admin S-1)
```
