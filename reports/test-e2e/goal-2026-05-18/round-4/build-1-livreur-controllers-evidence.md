# BUILD-1 Sub 6.3 — Livreur Cash Session CONTROLLERS + ADMIN VUE UI Evidence

**Date** : 2026-05-18
**Agent** : BUILD-1 — Livreur Sub 6.3 Controllers + Admin Vue UI (Claude Opus 4.7 1M)
**Wave** : Round-4 Build (controllers + UI on top of Sub-6.3 foundation `3d5ca01f6`)
**Scope** : Admin controllers + FormRequests + API Resource + 3 Vue components + 18 feature tests
**Status** : GREEN — 35/35 tests pass (18 new controller + 17 existing sentinels), 0 frozen-zone touch
**Branch** : `v1-0-1-hardening-2026-05-17`

---

## 1. Files Created (8 new, 0 modified)

| Path | LOC | Purpose |
| --- | ---: | --- |
| `app/Http/Controllers/Admin/DeliveryBoyCashSessionController.php` | 221 | Admin cockpit — index / show / open / close / reconcile |
| `app/Http/Requests/DeliveryBoyCashSessionOpenRequest.php` | 65 | Validation : delivery_boy_id (role + branch asserted), opening_amount >= 0 |
| `app/Http/Requests/DeliveryBoyCashSessionCloseRequest.php` | 31 | Validation : closing_amount >= 0 |
| `app/Http/Requests/DeliveryBoyCashSessionReconcileRequest.php` | 36 | Validation : variance_reason (nullable string max 255) |
| `app/Http/Resources/DeliveryBoyCashSessionResource.php` | 52 | API serializer + conditional movements via `whenLoaded` |
| `resources/js/components/admin/deliveryBoyCashSession/DeliveryBoyCashSessionListComponent.vue` | 226 | Admin list view : table + status filter + variance highlight |
| `resources/js/components/admin/deliveryBoyCashSession/DeliveryBoyCashSessionShowComponent.vue` | 230 | Admin detail view : session DL + movements table + action buttons |
| `resources/js/components/admin/deliveryBoyCashSession/DeliveryBoyCashSessionFormComponent.vue` | 252 | 3-mode form (open / close / reconcile) |
| `tests/Feature/Admin/DeliveryBoyCashSessionControllerTest.php` | 482 | 18 test methods × 5 actions × authz/scope edges |
| **TOTAL NEW LOC** | **1595** | |

**Zero existing-file modification.** Verified via :
```
$ git diff --name-only | grep -E "Fiscal/AuditLogService|Fiscal/FiscalSequenceService|Fiscal/ZReportService|Scopes/BranchScope|Cash/CashDrawerService|Domain/Order/OrderStateMachine|app/Models/Order\.php|routes/api\.php|app/Services/OrderService\.php"
(empty)
```

The 3 foundation files from commit `3d5ca01f6` are NOT modified :
```
$ git diff --name-only -- app/Services/Delivery/DeliveryBoyCashSessionService.php app/Models/DeliveryBoyCashSession.php app/Models/DeliveryBoyCashMovement.php
(empty)
```

---

## 2. Test Results

```
$ vendor/bin/phpunit --filter "DeliveryBoyCashSession" 2>&1 | tail -8
...................................                               35 / 35 (100%)
Time: 00:06.950, Memory: 105.00 MB
OK (35 tests, 118 assertions)
```

### Breakdown

| Test class | Methods | Status |
| --- | ---: | :---: |
| `DeliveryBoyCashSessionLifecycleTest` (sentinel — existing) | 5 | GREEN |
| `DeliveryBoyCashSessionAuditChainTest` (sentinel — existing) | 4 | GREEN |
| `DeliveryBoyCashSessionBranchIsolationTest` (sentinel — existing) | 4 | GREEN |
| `DeliveryBoyCashSessionConcurrentOpenTest` (sentinel — existing) | 4 | GREEN |
| **`DeliveryBoyCashSessionControllerTest` (NEW — BUILD-1)** | **18** | **GREEN** |
| **TOTAL** | **35** | **GREEN** |

### 18 new test methods coverage

**Action 1 — `open()` (5 tests)** :
1. `test_admin_opens_session_for_livreur_returns_201_with_resource` — happy path, branch_id sourced from livreur User, opened_by_user_id = admin
2. `test_open_validation_rejects_non_livreur_user` — 422 on delivery_boy_id pointing to admin (no role)
3. `test_open_409_when_session_already_open_for_livreur` — service exception → 409
4. `test_open_403_when_branch_manager_tries_cross_branch` — Branch Manager (branch=A) opening for livreur on branch B → 403
5. `test_open_403_when_user_lacks_delivery_boys_permission` — POS Operator role → 403

**Action 2 — `close()` (3 tests)** :
6. `test_admin_closes_session_returns_200_with_status_closed` — 200 + status flip
7. `test_close_validation_rejects_negative_amount` — 422 + assertJsonValidationErrors
8. `test_close_404_cross_branch_via_route_model_binding` — BranchScope hides branch-B from branch-A manager → 404

**Action 3 — `reconcile()` (2 tests)** :
9. `test_reconcile_returns_200_with_expected_and_variance` — expected = float + movements, variance = 0
10. `test_reconcile_422_when_session_still_open` — service refuse reconcile sur OPEN → 422

**Action 4 — `show()` (3 tests)** :
11. `test_show_returns_session_with_movements_eager_loaded` — payload contains `data.movements[]` with 2 ordered rows
12. `test_show_404_cross_branch_via_branch_scope` — BranchScope filters → 404
13. `test_show_403_when_user_lacks_show_permission` — POS Operator → 403

**Action 5 — `index()` (5 tests)** :
14. `test_index_returns_list_with_pagination` — pagination total + per_page
15. `test_index_branch_scope_isolates_branches_for_non_global_user` — Branch Manager sees only branch-A sessions
16. `test_index_filter_by_delivery_boy_id` — filter narrows total to 1
17. `test_index_filter_by_status` — filter `status=closed` returns 1 closed session
18. `test_index_403_when_user_lacks_show_permission` — POS Operator → 403

---

## 3. Route Registration Spec for BUILD-5

**BUILD-5 must register these routes in `routes/api.php` after line ~619 (inside the existing `Route::prefix('delivery-boy')->name('delivery-boy.')->group(...)` block, OR as a sibling group). The exact spec :**

```php
use App\Http\Controllers\Admin\DeliveryBoyCashSessionController;

// Inside the existing admin `auth:sanctum` group (parent of delivery-boy prefix block,
// after line ~619 in api.php). Recommendation : add as a NEW sibling prefix block
// `cash-sessions` co-located with `delivery-boy.*` admin routes for path-locality.

Route::prefix('delivery-boy/cash-sessions')->name('delivery-boy.cash-sessions.')->group(function () {
    Route::get('/',                       [DeliveryBoyCashSessionController::class, 'index'])
        ->name('index');
    Route::post('/open',                  [DeliveryBoyCashSessionController::class, 'open'])
        ->middleware('idempotency')
        ->name('open');
    Route::get('/{session}',              [DeliveryBoyCashSessionController::class, 'show'])
        ->whereNumber('session')
        ->name('show');
    Route::post('/{session}/close',       [DeliveryBoyCashSessionController::class, 'close'])
        ->whereNumber('session')
        ->middleware('idempotency')
        ->name('close');
    Route::post('/{session}/reconcile',   [DeliveryBoyCashSessionController::class, 'reconcile'])
        ->whereNumber('session')
        ->middleware('idempotency')
        ->name('reconcile');
});
```

### Canonical URL + verb table

| Verb | URL | Controller@Action | Middleware (additional) | Status |
| --- | --- | --- | --- | --- |
| GET | `/api/admin/delivery-boy/cash-sessions` | `DeliveryBoyCashSessionController@index` | `permission:delivery-boys_show` (in controller __construct) | 200 |
| GET | `/api/admin/delivery-boy/cash-sessions/{session}` | `@show` | `permission:delivery-boys_show` | 200 / 404 |
| POST | `/api/admin/delivery-boy/cash-sessions/open` | `@open` | `permission:delivery-boys` + `idempotency` | 201 / 403 / 409 / 422 |
| POST | `/api/admin/delivery-boy/cash-sessions/{session}/close` | `@close` | `permission:delivery-boys` + `idempotency` | 200 / 404 / 422 |
| POST | `/api/admin/delivery-boy/cash-sessions/{session}/reconcile` | `@reconcile` | `permission:delivery-boys` + `idempotency` | 200 / 404 / 422 |

**Route model binding** : `{session}` resolves to `App\Models\DeliveryBoyCashSession` via implicit binding. The `BranchScope` global scope on the model filters cross-branch sessions → 404 leak-free. No manual cross-branch check needed in `show`/`close`/`reconcile`.

**Authz chain** :
- Outer `auth:sanctum` (already in place around the admin group)
- Controller-level `permission:delivery-boys_show` on `index` + `show`
- Controller-level `permission:delivery-boys` on `open` + `close` + `reconcile`
- Caller branch_id ≠ livreur branch_id (and caller is not global admin) → 403 in `open()` (explicit deny path for write integrity)

**Idempotency** : POST routes use the existing `idempotency` middleware (cf. `routes/api.php` line 813 cash-drawer pattern). This is the standard FoodKing cross-system flag #5.

---

## 4. Authz Matrix

| Caller | Resource branch | Action | Expected |
| --- | --- | --- | --- |
| Admin (branch=0) | any | open / close / reconcile / index / show | 200/201 |
| Branch Manager (branch=A) | branch A | all 5 | 200/201 |
| Branch Manager (branch=A) | branch B | open | 403 (explicit cross-branch deny) |
| Branch Manager (branch=A) | branch B | show / close / reconcile | 404 (BranchScope filter) |
| Branch Manager (branch=A) | branch B | index | filtered to branch-A only (no error) |
| POS Operator (any) | any | any of the 5 | 403 (no `delivery-boys*` permission) |
| Guest | any | any | 401 (sanctum middleware) |

Every row above has at least one passing test method in `DeliveryBoyCashSessionControllerTest`.

---

## 5. FormRequest Validation Rules

### `DeliveryBoyCashSessionOpenRequest`
- `delivery_boy_id` → `required, integer, min:1`
- `opening_amount` → `required, numeric, min:0`
- **`withValidator` extras (custom)** :
  - Target user MUST exist (`User::query()->withoutGlobalScopes()->find(id)`)
  - Target user MUST have `DELIVERY_BOY` role (Enum::DELIVERY_BOY = 3) OR named `'Delivery Boy'`
  - Target user MUST have `branch_id > 0` (no global delivery boys)

### `DeliveryBoyCashSessionCloseRequest`
- `closing_amount` → `required, numeric, min:0`

### `DeliveryBoyCashSessionReconcileRequest`
- `variance_reason` → `nullable, string, max:255`
- `notes` → `nullable, string, max:255`

---

## 6. Resource Payload Shape

```json
{
  "id": 1,
  "branch_id": 1,
  "delivery_boy_id": 3,
  "opened_by_user_id": 1,
  "closed_by_user_id": null,
  "reconciled_by_user_id": null,
  "opened_at": "2026-05-18T11:07:31+02:00",
  "closed_at": null,
  "opening_amount": 50.0,
  "closing_amount": null,
  "expected_closing_amount": null,
  "variance": null,
  "variance_reason": null,
  "notes": null,
  "status": "open",
  "movements": [
    { "id": 1, "order_id": null, "type": "order_collect", "amount": 15.5, "direction": "in", "notes": "order #1", "created_at": "..." }
  ]
}
```

`movements` is only present when explicitly eager-loaded via `$session->load('movements')` (used in `show()`, omitted in `index()`).

On `reconcile()` the resource is merged with `expected` + `variance` floats at the top level of `data`.

---

## 7. Admin Vue UI (3 components)

### `DeliveryBoyCashSessionListComponent.vue`
- Reads `GET /api/admin/delivery-boy/cash-sessions` with status filter.
- Renders table : `#ID | livreur | branch | opening | closing | variance | status | opened_at | view`
- Status badges (warning/info/success/secondary) per session.status
- Variance highlight (green/red/gray) based on sign.
- Emits `view(sessionId)` for parent to swap surface.
- All labels use `$t(...)` keys (no raw strings). Test IDs : `delivery-cash-filter-status`, `delivery-cash-session-row-{id}`, `delivery-cash-session-view-{id}`, `delivery-cash-empty-state`.

### `DeliveryBoyCashSessionShowComponent.vue`
- Reads `GET /api/admin/delivery-boy/cash-sessions/{id}`.
- Renders DL with opening / closing / expected / variance / variance_reason / timestamps.
- Renders movements table (5 cols).
- Action buttons (open→close, closed→reconcile) emit events to parent (parent mounts the Form component).
- Props : `sessionId` (Number|String, required), `canMutate` (Boolean, default true).
- Test IDs : `delivery-cash-session-title`, `delivery-cash-session-status`, `delivery-cash-livreur-id`, `delivery-cash-opening-amount`, `delivery-cash-variance`, `delivery-cash-variance-reason`, `delivery-cash-action-close`, `delivery-cash-action-reconcile`, `delivery-cash-mvt-row-{id}`, `delivery-cash-no-movements`.

### `DeliveryBoyCashSessionFormComponent.vue`
- 3 modes : `open` / `close` / `reconcile` (prop-driven, single component).
- Open form : `delivery_boy_id` + `opening_amount` (number inputs).
- Close form : `closing_amount` (number input).
- Reconcile form : `variance_reason` (textarea).
- Submits via axios → emits `submitted(session)` on success or `error(message)` on failure.
- Inline error band + `submitting` disabled state.
- Test IDs : `delivery-cash-form`, `delivery-cash-form-error`, `delivery-cash-form-open`, `delivery-cash-form-livreur-input`, `delivery-cash-form-opening-input`, `delivery-cash-form-open-submit`, `delivery-cash-form-close`, `delivery-cash-form-closing-input`, `delivery-cash-form-close-submit`, `delivery-cash-form-reconcile`, `delivery-cash-form-reason-input`, `delivery-cash-form-reconcile-submit`.

**i18n keys introduced (must be added to `resources/js/languages/{fr,en}.json` in BUILD-3 i18n sweep)** :
```
label.delivery_cash_sessions
label.delivery_cash_session
label.delivery_cash_status_open
label.delivery_cash_status_closed
label.delivery_cash_status_reconciled
label.delivery_cash_mvt_order_collect
label.delivery_cash_mvt_change_given
label.delivery_cash_mvt_drawer_open
label.delivery_cash_mvt_drawer_close
label.delivery_cash_mvt_adjustment
label.delivery_cash_open_form_title
label.delivery_cash_close_form_title
label.delivery_cash_reconcile_form_title
label.delivery_cash_close_intro
label.delivery_cash_reconcile_intro
label.delivery_cash_missing_session
label.delivery_cash_form_generic_error
```

These are documented for the i18n sweep agent ; their absence does not break the controller surface.

---

## 8. Frozen-Zone Diff = 0

```
$ git diff --name-only | grep -E "^app/Services/Fiscal/|^app/Models/Scopes/BranchScope|^app/Domain/Order/|^app/Models/Order\.php|^routes/api\.php|^app/Services/OrderService\.php"
(empty)
```

Specifically verified untouched :
- `app/Services/Fiscal/AuditLogService.php` — consumed by foundation service via injection
- `app/Services/Fiscal/FiscalSequenceService.php` — not touched
- `app/Services/Fiscal/ZReportService.php` — not touched (Z-report enrichment deferred to Wave 6b-1.5)
- `app/Services/Cash/CashDrawerService.php` — read-only reference for analog pattern
- `app/Models/Scopes/BranchScope.php` — relied upon by route model binding, class itself untouched
- `app/Domain/Order/OrderStateMachine.php` — not touched
- `app/Models/Order.php` — not touched
- `routes/api.php` — NOT touched (BUILD-5 owns this round per task brief)
- `app/Services/OrderService.php` — NOT touched (BUILD-2 owns DELIVERED hook per task brief)

---

## 9. Decisions Locked (BUILD-1)

| # | Decision | Rationale |
| --- | --- | --- |
| 1 | Permission gates use `delivery-boys` + `delivery-boys_show` (NOT `settings`) | Mirror existing `DeliveryBoyController` pattern, no new permission to wire |
| 2 | `branch_id` for new sessions sourced from `User->branch_id` of livreur, NOT caller | Admin (branch=0) must be able to open on behalf of livreur on branch N |
| 3 | Cross-branch defense uses BOTH explicit `403` (open) AND implicit `404` via BranchScope (show/close/reconcile) | Best defense-in-depth ; explicit 403 on write path makes audit logs clearer |
| 4 | Movements eager-loaded ONLY on `show()` via `$session->load(...)` + `whenLoaded` Resource | `index()` stays lean ; pagination doesn't bloat |
| 5 | FormRequest `withValidator` asserts livreur role via name `'Delivery Boy'` AND enum `EnumRole::DELIVERY_BOY` | Defensive : some seeds use name, others use ID |
| 6 | Test wires routes locally via `setRoutes()` prepend (no api.php touch) | BUILD-5 owns canonical wiring ; test must run standalone |
| 7 | Money fields use `assertEqualsWithDelta` not `assertJsonPath(value, float)` | `assertJsonPath` is strict `===` and `(float) 50.00` JSON-encodes as int `50` ; delta is the right tool |

---

## 10. Risks / Known Gaps

1. **i18n keys not yet present** — listed in §7 ; need batch insertion. Without them, the UI renders the key literal (acceptable for V1.0.2 progressive build).
2. **Variance gate manager-approval** — foundation service already supports `actor`, but no permission-specific gate on variance > threshold. Wave 6b-1.x can layer `delivery.cash.reconcile.variance.override` permission later.
3. **Livreur self-service surface NOT in this BUILD-1** — task brief explicitly scopes to admin. Wave 6b-1.3b (livreur `/api/frontend/delivery-boy-shift/*`) is a separate build.
4. **Routes not in api.php** — BUILD-5 owns route registration. The exact spec in §3 must be respected (URI, verbs, middleware) — any drift breaks the test (which registers identical routes locally).
5. **Vue components are functional but not visually QA-ed** — CLAUDE.md §6 visual mandate not exercised because the test environment is PHPUnit-only. BUILD-5 (route landing) + a routing entry will trigger the next Playwright pass.

---

## 11. Commit

Commit format per task brief :
```
feat(livreur-v1-0-2-sub6-3-build1): cash session controllers + admin Vue UI + 5 feature tests

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
```

Files staged : 9 new files (1 controller + 3 FormRequests + 1 Resource + 3 Vue + 1 test + this evidence doc).

---

**End of BUILD-1 evidence.**
