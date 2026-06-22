# POS menu / wizard regression fix - 2026-05-01

## Verdict

AUDIT_VERDICT: PASS
RUNTIME_DECISION: POS_MENU_AND_WIZARD_LOCAL_PASS

The POS regression reported on `pos@lecayenne.fr` is fixed in code and validated by backend, frontend unit tests, API smoke, and a real Playwright browser flow.

## Symptoms covered

- POS operator login showed an empty POS menu.
- Admin/POS path could show menu data, but category selection behaved like products were leaking across categories.
- Clicking an item or bag did not open the POS product modal/wizard.
- Stale sessions could keep showing an empty POS because 401 cleanup did not clear the auth token.

## Root causes

1. POS runtime reads were protected by `items_show`, but the `POS Operator` role has `pos` and not `items_show`.
   Result: `/api/admin/item`, `/api/admin/item/details/{id}`, `/api/admin/item/lookup-barcode/{code}` could return 403 for the cashier UI.

2. `admin-mutation` throttle was applied to the whole admin API group, including GET runtime reads.
   Result: fast POS usage and audit loops could hit 429 on menu list/category/detail calls, leaving stale product lists and preventing the modal from opening.

3. `ItemComponent.vue` swallowed `item/details` errors with an empty `catch`.
   Result: product click failed silently.

4. POS `item/details` did not send active `branch_id`.
   Result: branch-scoped composer/stock availability context could be lost in the detail modal.

5. Staff-only login redirect could hard-land an authenticated POS user on dashboard instead of the role landing URL.

6. The 401 axios handler dispatched `auth/logout`, but the auth module is not namespaced.
   Result: stale/expired tokens were not reliably cleared.

## Code changes

- `app/Http/Controllers/Admin/ItemController.php`
  - Allows runtime POS reads with `pos` OR `items_show`.
  - Keeps create/update/delete under catalog management permissions.
  - Forces branch scope for POS-only runtime reads when `branch_id` is missing.
  - Rejects item details hidden from the requested surface.
  - Adds SQLite fallback for JSON channel checks in local tests.

- `app/Http/Controllers/Admin/PosCategoryController.php`
  - Allows category reads with `pos` OR `items_show`.
  - Adds SQLite fallback for POS channel filtering.

- `app/Services/ItemService.php`
  - Keeps exact `item_category_id` filtering.
  - Adds SQLite fallback for surface/channel filtering.

- `app/Providers/RouteServiceProvider.php`
  - Keeps `admin-mutation` at 30/min for mutations.
  - Raises GET/HEAD admin runtime reads to 300/min to prevent POS menu/detail 429.

- `resources/js/components/admin/pos/PosComponent.vue`
  - Stops the initial branchless item fetch.
  - Clears text search when selecting a category.

- `resources/js/components/admin/pos/ItemComponent.vue`
  - Sends `surface=pos` and active `branch_id` on detail requests.
  - Normalizes missing detail collections before opening the modal.
  - Shows/logs a visible error instead of silently swallowing failed product clicks.

- `resources/js/store/modules/item.js`
  - Supports `branch_id` in `item/details` params.

- `resources/js/app.js` and `resources/js/pos-app.js`
  - Dispatch root `logout` action on 401 because `auth` module is not namespaced.

- `resources/js/router/index.js`
  - Staff-only authenticated login/root redirect now respects role landing URL, e.g. POS -> `/admin/pos`.

## Tests added/updated

- `tests/Feature/Pos/PosMenuRuntimeAccessTest.php`
  - POS operator can read categories/items/details/barcode without `items_show`.
  - POS runtime reads stay branch-scoped.
  - Non-POS user cannot read POS runtime categories.
  - POS details reject items hidden from POS surface.
  - 35 loops of item list + item details do not trip mutation throttle.

- `tests/js/posAvailabilityLiveGuard.spec.js`
  - Product click calls detail load.
  - Missing collection fields are normalized before modal open.
  - Failed detail load is visible and does not silently open a broken modal.

- `tests/js/PosComponent.spec.js`
  - Category selection clears text search before fetching category products.

- `tests/js/authLogoutInterceptor.spec.js`
  - Guards against reintroducing `auth/logout`.

- `tests/js/staffOnlyLandingRedirect.spec.js`
  - Guards staff-only login redirect to role landing instead of hardcoded dashboard.

## Validation commands

PASS:

```bash
php artisan test tests/Feature/Pos/PosMenuRuntimeAccessTest.php
php artisan test tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php
php artisan test tests/Feature/Menu/AdminItemBranchAvailabilityProjectionTest.php
php artisan test tests/Feature/PosUITest.php
npx vitest run tests/js/authLogoutInterceptor.spec.js tests/js/staffOnlyLandingRedirect.spec.js tests/js/posAvailabilityLiveGuard.spec.js tests/js/PosComponent.spec.js tests/js/posWizardComposerProfile.spec.js tests/js/posRuptureUx.spec.js
node tools/lint/pos_pricing_guard.mjs && node tools/lint/pos_orderstatus_guard.mjs
npm run development
```

Browser runtime PASS:

- Login `pos@lecayenne.fr`.
- Landing URL: `http://127.0.0.1:8000/admin/pos`.
- Initial POS: 13 category cards, 5 best seller item tiles.
- Category click `Nos Tacos`: item API 200, filtered tiles = `Tacos M`, `Tacos L`, `Tacos XL`, `Tacos XXL`.
- Product click `Tacos M`: details API 200, POS modal active with meat/sauce/crudites/menu sections.

## Non-blocking observations

- Local WebSocket/Reverb was not running: browser reports connection refused on `127.0.0.1:6001`. This does not block menu rendering or modal opening, but realtime needs the WS service for production-like sync.
- `php artisan route:list` is currently blocked by missing gateway class `App\Http\PaymentGateways\Gateways\Senangpay`. This is pre-existing route-list tooling debt, not part of the POS menu regression.
- Vitest prints expected shallow-mount warnings for unresolved stubs (`router-link`, `vue-select`) in `PosComponent.spec.js`; tests pass.
