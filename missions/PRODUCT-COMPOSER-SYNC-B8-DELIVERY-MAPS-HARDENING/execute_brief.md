# PRODUCT-COMPOSER-SYNC-B8-DELIVERY-MAPS-HARDENING

Source: `reports/audit/CLAUDE_PRODUCT_COMPOSER_FINAL_EXECUTION_PLAN_2026-04-27.md` § Mission B8.

## Scope

- Add a backend delivery quote service that validates saved address coordinates against branch coordinates.
- Return JSON `GEOCODE_FAILED` on invalid geocode/coordinates.
- Keep delivery fee authority server-side.
- Add checkout/POS UI banners for invalid addresses.
- Do not touch `OrderService.php` or `FrontendOrderService.php`.

## Implementation

- Added `DeliveryQuoteService` and `GeocodeUnavailableException`.
- `OrderRequest::prepareForValidation()` now recomputes delivery distance/charge from saved address coordinates when the client sends a delivery distance, and still rejects missing `delivery_distance_km`.
- Checkout now sends `delivery_distance_km`, shows a blocking red banner on `GEOCODE_FAILED`, and focuses the address modal.
- POS inline delivery now refuses un-geocoded address text and removes the previous silent minimum-fee fallback.
- Added B8 feature tests and JS UI contract tests.

## Validation

- `php -l` on new/changed backend B8 files: PASS.
- `php artisan test tests/Feature/Delivery --colors=never`: PASS, 3 tests.
- `php artisan test tests/Feature/Frontend/OrderRequestDeliveryFeeAuthorityTest.php --colors=never`: PASS, 4 tests.
- `php artisan test tests/Feature/PosWalkInAndDeliveryFeeTest.php --colors=never`: PASS.
- `php artisan test tests/Unit/Services/DeliveryFeeServiceTest.php --colors=never`: PASS.
- `php artisan test tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php --colors=never`: PASS, 4 tests.
- `php artisan test tests/Feature/ItemAttributeComposerResourceTest.php --colors=never`: PASS, 2 tests.
- `npx vitest run tests/js/deliveryCharge.spec.js tests/js/checkoutGeocodeError.spec.js tests/js/productComposerSummary.spec.js tests/js/userReportedBlockersRuntime.spec.js --reporter=dot`: PASS, 18 tests.
- `npm run production`: PASS.
- `node tools/lint/scan_kiosk_bundles.mjs`: PASS.
- `tools/lint/forbidden_bundles.sh`: PASS.
- Scoped `git diff --check`: PASS.

## Notes

- Existing `OrderRequestDeliveryFeeAuthorityTest` expectations were updated because B8 supersedes B0's client-distance-only recompute with saved-address coordinate authority when a valid address and branch are present.
- Missing `delivery_distance_km` remains a 422 validation error, preserving the B0 contract.
