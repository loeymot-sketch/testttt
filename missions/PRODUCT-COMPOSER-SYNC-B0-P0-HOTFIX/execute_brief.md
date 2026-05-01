# PRODUCT-COMPOSER-SYNC-B0-P0-HOTFIX

Source plan: `reports/audit/CLAUDE_PRODUCT_COMPOSER_FINAL_EXECUTION_PLAN_2026-04-27.md`

## Scope

1. Restore web/frontend delivery pricing authority without touching frozen order services.
2. Align the frontend delivery preview helper to the FoodKing V1 rule: 5 EUR per started 5 km.
3. Delete the public kiosk-admin bundle and orphan kiosk admin source.
4. Add regression tests and a release guard preventing `kiosk-admin*.js` and `KioskAdminComponent.vue` from returning.

## Forbidden

- No `app/Services/OrderService.php`.
- No `app/Services/FrontendOrderService.php`.
- No migrations.
- No runtime wizard migration.
- No product composer schema work.
- No self-approval of later gates.

## Validation Required

- `php -l app/Http/Requests/OrderRequest.php`
- `php -l tests/Feature/Frontend/OrderRequestDeliveryFeeAuthorityTest.php`
- `php -l tests/Feature/KioskBundleLockdownTest.php`
- `php artisan test tests/Feature/Frontend/OrderRequestDeliveryFeeAuthorityTest.php --colors=never`
- `php artisan test tests/Feature/KioskBundleLockdownTest.php --colors=never`
- `php artisan test tests/Feature/PosWalkInAndDeliveryFeeTest.php --colors=never`
- `php artisan test tests/Unit/Services/DeliveryFeeServiceTest.php --colors=never`
- `php artisan test tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php --colors=never`
- `php artisan test tests/Feature/ItemAttributeComposerResourceTest.php --colors=never`
- `npx vitest run tests/js/deliveryCharge.spec.js tests/js/kioskRouterLockdown.spec.js`
- `npx vitest run tests/js/productComposerSummary.spec.js tests/js/userReportedBlockersRuntime.spec.js tests/js/deliveryCharge.spec.js tests/js/kioskRouterLockdown.spec.js`
- `npm run production`
- `tools/lint/forbidden_bundles.sh`
- `git diff --check` on the B0 allowlist.

## Invariants

- Backend remains pricing SSOT.
- Delivery charge is recomputed server side before `FrontendOrderService` consumes request data.
- Kiosk `branch_id` remains server-resolved from the kiosk token.
- Frozen `OrderService` and `FrontendOrderService` remain untouched in B0.
- B7 remains responsible for full public bundle scanning beyond the `kiosk-admin*.js` hotfix.
