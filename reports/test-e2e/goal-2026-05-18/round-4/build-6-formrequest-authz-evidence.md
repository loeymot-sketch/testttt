# BUILD-6 — FormRequest Authz Unification (Critical Subset) — Evidence

**Wave**: V1.0.2 BUILD-6
**Branch**: heal/cms-pr1-quickwins-2026-05-18 (worktree)
**Date**: 2026-05-18
**Sentinel**: `tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php` (Wave 8 commit `68b63c090`)

---

## 1. Mission scope

Apply the unified Spatie-permission `authorize()` pattern (established Wave 5H, commit `46fb4ef2d`) to a CRITICAL SUBSET of 8 highest-risk FormRequests still on the legacy `return true;` shortcut. The remaining ~69 FormRequests stay on the V1.0.2 backlog for incremental waves.

The unified pattern (per Wave 5H precedent):

```php
public function authorize(): bool
{
    // V1.0.2 BUILD-N heal: defense-in-depth — <Controller> middleware enforces
    // `permission:<gate>`; FormRequest doubles down so any future route bypass
    // still authz-checks.
    return $this->user()?->can('<gate>') ?? false;
}

// Multi-verb variant for FormRequests injected on both store + update:
public function authorize(): bool
{
    $user = $this->user();
    if ($user === null) {
        return false;
    }
    return $user->can('<gate>_create') || $user->can('<gate>_edit');
}
```

---

## 2. Sentinel test result — BEFORE / AFTER

| Snapshot | `RETURN_TRUE_BASELINE` | Live count | Δ | Status |
|---|---|---|---|---|
| BEFORE BUILD-6 (Wave 5H baseline) | 74 | 74 (+3 untracked* = 77 actual) | 0 | PASS |
| AFTER BUILD-6 (this wave) | 69 | 69 | -5 sentinel / -8 actual† | PASS |

\* 3 untracked `DeliveryBoyCashSession{Open,Close,Reconcile}Request.php` were added by
parallel session work after the Wave 5H baseline-set commit, each ships with `return true;`
guarded by controller middleware. They are noted in the sentinel docblock as backlog
candidates for the next wave (folded into LIVREUR Z-4 wire-up follow-up).

† Net delta of the live count from BUILD-6 alone = -8 (74 → 66 of the originally tracked
74). The sentinel snapshot reads 69 because the 3 new untracked requests bumped the
floor up by +3 between baseline-set and this wave.

Test run after edits (from working dir):

```
> php artisan test --filter FormRequestAuthzDriftSentinel
  PASS  Tests\Feature\Sentinels\FormRequestAuthzDriftSentinelTest
  ✓ form request return true count does not grow past baseline
  Tests:  1 passed
  Time:   0.12s
```

---

## 3. FormRequests fixed (8 critical subset)

| # | File | Gate(s) added | Controller middleware mirrored | Notes |
|---|---|---|---|---|
| 1 | `app/Http/Requests/PosOrderRequest.php` | `can('pos')` | `PosController::__construct() → middleware(['permission:pos'])->except('quote')` | Highest blast — POS order creation. PosOrderRequest is only injected on `store` (the gated path). |
| 2 | `app/Http/Requests/DeliveryBoyRequest.php` | `can('delivery-boys_create') OR can('delivery-boys_edit')` | `DeliveryBoyController` `->only('store')` + `->only('update')` | Staff create/edit — same class injected on both verbs. Spatie dash-form `delivery-boys_*`. |
| 3 | `app/Http/Requests/CouponRequest.php` | `can('coupons_create') OR can('coupons_edit')` | `CouponController` create/edit | Promo CRUD — dual-verb pattern. |
| 4 | `app/Http/Requests/OfferRequest.php` | `can('offers_create') OR can('offers_edit')` | `OfferController` create/edit | Promo offers — dual-verb. |
| 5 | `app/Http/Requests/PermissionRequest.php` | `can('settings')` | `PermissionController::__construct() → ->middleware(['permission:settings'])->only('update')` | Spatie RBAC root — only injected on `update`. |
| 6 | `app/Http/Requests/KioskMachineRequest.php` | `can('settings')` | `KioskMachineController` `->middleware(['permission:settings'])->only('show', 'store', 'update', 'destroy', 'logout', 'changeStatus')` | Kiosk pairing → mints sanctum `kiosk:order` tokens. Must never bypass settings gate. |
| 7 | `app/Http/Requests/DiningTableRequest.php` | `can('dining_tables_create') OR can('dining_tables_edit')` | `DiningTableController` create/edit | Dual-verb. Spatie underscore-form `dining_tables_*`. |
| 8 | `app/Http/Requests/ItemRequest.php` | `can('items_create') OR can('items_edit')` | `ItemController` `->only('store', 'import', 'duplicate')` + `->only('update', 'changeImage')` | Catalog mutation — high blast (Spatie scopes branch-scoped item ownership). |

Each fix preserves the existing rules() / withValidator() / messages() bodies untouched
(scope-minimal). Net change ≈ +90 LOC (mostly explanatory docblocks).

---

## 4. Skipped candidates with justification

| Candidate considered | Skipped because |
|---|---|
| `PaymentRequest.php` (Frontend) | Used by public unauthenticated payment flow `PaymentController::payment` — NO `permission:*` middleware on this controller. Adding `can()` would 403 the public payment route. Stays on backlog with note. |
| `PaymentMethodRequest.php` | Dead — class defined but never injected by any controller (verified via `grep -rln PaymentMethodRequest app/`). Stays on backlog pending audit. |
| `OrderRequest.php` (Frontend) | Already has explicit `tokenCan('kiosk:order')` authz logic — does NOT match `return true;` baseline pattern. Already production-grade per iter15-P0-08 heal. |
| `EmployeeRequest`, `ChefRequest`, `WaiterRequest`, etc. | Phase B backlog — same `can('<role>_create|edit')` pattern, scheduled for next BUILD-7. |

---

## 5. Side-effect test impact

One regression test was tightened to reflect the new earlier-defense behaviour:

`tests/Feature/Branch/OssAdminBranchPolicyTest.php::test_branch_id_zero_non_admin_is_not_global_for_pos_order_store`

The test creates a `Chef`-role user (no `pos` permission in seed) and expects
`OrderService::posOrderStore()` to throw 422 at the service-level branch-policy check.
With BUILD-6, the FormRequest now rejects EARLIER (`AuthorizationException`) — which is
the entire defense-in-depth point of this wave. To keep the test exercising the
SERVICE-LAYER policy guard (which is the test's documented intent), I added a single
line: `$misconfiguredChef->givePermissionTo('pos');` so the actor passes the FormRequest
gate and we still cover the deeper branch-policy logic in `OrderService`. The test's
assertion (`assertSame(422, $exception->getCode())`) is unchanged. Verified:

```
> php artisan test --filter "OssAdminBranchPolicyTest"
  PASS  Tests\Feature\Branch\OssAdminBranchPolicyTest
  ✓ branch id zero non admin is not global for pos order store
  ✓ branch id zero non admin is not global for destroy
  Tests:  2 passed
```

Also verified — tests that directly instantiate my modified FormRequests via `::create()`:

```
> php artisan test --filter "ItemRequestTest|PosOrderRequestNoClientTotals|PosSingleTenderCardTerminalIdSentinel|ItemRequestBarcodeKdsStation|UserMgmtRoleTargetSentinel"
  Tests:  24 passed
```

---

## 6. Constraints respected

| Constraint | Status |
|---|---|
| No `routes/api.php` modification (BUILD-5 owns) | RESPECTED — zero route diff. |
| No `app/Http/Controllers/**` modification | RESPECTED — only FormRequest authz bodies + sentinel docblock + 1 test fixture line. |
| Anti-fiction (read each file before editing) | RESPECTED — every FormRequest was read in full before Edit. |
| Scope-minimal (authorize() only, no rule changes) | RESPECTED — each Edit replaces only the `return true;` body + adds a docblock comment. |
| Verify each fix individually | RESPECTED — sentinel test + 24 directly-related tests run after edits. |
| Frozen-zone touch | NONE — none of the 8 FormRequests are listed in frozen zones (CLAUDE.md §7). |

---

## 7. Remaining work estimate

Sentinel baseline now at 69 (live count = 69 actual). Remaining FormRequests on
the `return true;` legacy pattern are clustered as follows (rough lines-of-change
per file ≈ 8-12 LOC for single-verb, 12-18 LOC for multi-verb with explanatory
docblock):

| Cluster | Count | Est. LOC | Suggested gate |
|---|---|---|---|
| Customer authn (Signup, Otp, VerifyPhone, GuestSignupPhone, SignupPhone) | 5 | ~50 | Public — DO NOT add `can()`; backlog with note, like PaymentRequest |
| Staff create/edit (Employee, Chef, Waiter, +Address variants) | 7 | ~85 | `can('<role>_create|edit')` |
| Settings family (Site, Theme, Language, Currency-done, Slider, Page, SocialMedia, SmsGateway, Company, OrderSetup, KioskSetup, LoyaltySetup, MailRequest, License) | 12-13 | ~135 | `can('settings')` |
| Catalog family (ItemCategory, ItemAttribute, ItemExtra, ItemAddon, ItemVariation, ItemPhotoUpload, ItemCategoryImport, ItemImport, OfferItem, MenuTemplate, ComposerProfile, ComposerStep) | 12 | ~130 | `can('items_create|edit')` or `can('settings')` per controller |
| Admin profile (Profile, ChangePassword, ChangeImage, UserChangePassword, AdministratorAddress) | 5 | ~55 | `can()` against own profile or `can('administrators_edit')` |
| Misc admin (Tax-done, PushNotification, Notification, Message, Subscriber, SubscriberEmail, Cookies, CookiesSet, Analytic, AnalyticSection, Page, Permission-done, TimeSlot, TokenStore) | ~15 | ~150 | varies — needs controller-by-controller mapping |
| Delivery cash-session NEW (3 untracked Open/Close/Reconcile) | 3 | ~35 | `can('delivery-boys_edit')` + cash-session permission |
| POS-area legacy (TableOrder, TableOrderToken, AvailabilityToggle, ToggleExtraAvailability, ToggleVariationAvailability, PrinterRequest, PaymentTerminalRequest, PaymentMethodRequest dead) | ~8 | ~80 | `can('pos')` or `can('settings')` |

**Aggregate remaining**: ~70 FormRequests, ≈ 700-820 LOC across ~7 follow-up waves of
8-10 FormRequests each. The Wave 5H + BUILD-6 template makes each follow-up wave
mechanical: list controllers, mirror their `permission:*` middleware in `authorize()`,
lower the sentinel baseline by the count. Public-flow forms (PaymentRequest, customer
authn) stay on the `return true;` baseline and are tracked in the sentinel docblock as
"intentionally not gated — public surface" exclusions.

---

## 8. Commit attribution

Suggested commit message:

```
fix(formrequest-authz-v1-0-2): unified authz on 8 critical FormRequests + sentinel allowlist update

V1.0.2 BUILD-6 — extend Wave 5H pattern to 8 highest-blast FormRequests.

FormRequests refactored from `return true;` → $this->user()?->can('xxx'):
- PosOrderRequest    → can('pos')
- DeliveryBoyRequest → can('delivery-boys_create'|'_edit')
- CouponRequest      → can('coupons_create'|'_edit')
- OfferRequest       → can('offers_create'|'_edit')
- PermissionRequest  → can('settings')
- KioskMachineRequest→ can('settings')
- DiningTableRequest → can('dining_tables_create'|'_edit')
- ItemRequest        → can('items_create'|'_edit')

Sentinel baseline 74 → 69 (FormRequestAuthzDriftSentinelTest).

Side-effect test heal: OssAdminBranchPolicyTest::pos_order_store now grants
Chef the `pos` permission so the FormRequest passes and we keep covering
the SERVICE-LAYER branch policy guard (the test's documented intent).

No controller / route / business-rule changes. Frozen-zone touch = 0.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
```
