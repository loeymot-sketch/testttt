# PRODUCT-COMPOSER-SYNC-B0-P0-HOTFIX Report

REPORT_VERDICT: PASS
EXECUTE_DELEGATION: codex-extension

## Changes

- `app/Http/Requests/OrderRequest.php`
  - Casts `order_type` to int before delivery/takeaway comparisons.
  - Requires `delivery_distance_km` for delivery orders.
  - Recomputes `delivery_charge` through `DeliveryFeeService::fromDistanceKm()` during request preparation.
  - Keeps kiosk `branch_id` resolved from the kiosk token.
- `resources/js/helpers/deliveryCharge.js`
  - Aligns preview calculation to 5 EUR per started 5 km.
  - Keeps frontend helper as display-only; backend remains authoritative.
- Deleted:
  - `public/js/kiosk-admin.js`
  - `public/js/kiosk-admin.js.LICENSE.txt`
  - `resources/js/components/frontend/kiosk/KioskAdminComponent.vue`
- Added:
  - `tools/lint/forbidden_bundles.sh`
  - `tests/Feature/Frontend/OrderRequestDeliveryFeeAuthorityTest.php`
  - `tests/Feature/KioskBundleLockdownTest.php`
  - `tests/js/deliveryCharge.spec.js`
  - `tests/js/kioskRouterLockdown.spec.js`

## Validation

- PASS: `php -l app/Http/Requests/OrderRequest.php`
- PASS: `php -l tests/Feature/Frontend/OrderRequestDeliveryFeeAuthorityTest.php`
- PASS: `php -l tests/Feature/KioskBundleLockdownTest.php`
- PASS: `php artisan test tests/Feature/Frontend/OrderRequestDeliveryFeeAuthorityTest.php --colors=never`
- PASS: `php artisan test tests/Feature/KioskBundleLockdownTest.php --colors=never`
- PASS: `php artisan test tests/Feature/PosWalkInAndDeliveryFeeTest.php --colors=never`
- PASS: `php artisan test tests/Unit/Services/DeliveryFeeServiceTest.php --colors=never`
- PASS: `php artisan test tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php --colors=never`
- PASS: `php artisan test tests/Feature/ItemAttributeComposerResourceTest.php --colors=never`
- PASS: `npx vitest run tests/js/deliveryCharge.spec.js tests/js/kioskRouterLockdown.spec.js`
- PASS: `npx vitest run tests/js/productComposerSummary.spec.js tests/js/userReportedBlockersRuntime.spec.js tests/js/deliveryCharge.spec.js tests/js/kioskRouterLockdown.spec.js`
- PASS: `npm run production`
- PASS: `tools/lint/forbidden_bundles.sh`
- PASS: `git diff --check` on the B0 allowlist before mission artifacts.

## Residuals Routed To B7

- A stale non-manifest public bundle `public/js/kiosk.js` still contains old kiosk admin signatures. This is not the `kiosk-admin*.js` P1 hotfix item from B0, and it is exactly the class of issue assigned to B7 via `tools/lint/scan_kiosk_bundles.mjs`.
- `tools/perf/check_bundle_budget.mjs` still lists old budget keys for `kiosk.js` and `kiosk-admin.js`; leave for B7 bundle release audit unless the B7 scan requires cleanup.

## Invariants

- Pricing SSOT: PASS. Delivery fee is recomputed server-side before order service consumption.
- Branch isolation: PASS. Kiosk `branch_id` still comes from `KioskMachine`.
- Frozen order services: PASS. No B0 edit to `OrderService` or `FrontendOrderService`.
- Dispatch after commit: NOT TOUCHED.
- Gate discipline: PASS. No later gate self-approved.
