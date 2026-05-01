# Execute Brief — PRODUCT-COMPOSER-SYNC-B5A-STOCK-V2-CORE

Mission executed from `reports/audit/CLAUDE_PRODUCT_COMPOSER_FINAL_EXECUTION_PLAN_2026-04-27.md`.

Gate cited: `HG-FROZEN-ORDERSERVICE-UNLOCK` approved strict. The only order-flow product edits are the symmetric `StockService::decrementForOrder(..., $idempotencyKey)` call inside `OrderService::posOrderStore` and `FrontendOrderService::myOrderStore`, after quote sealing and before persistence in the same transaction.

Implementation:
- Added `StockService` with transactional `decrementForOrder` and idempotent `releaseForOrder`.
- Added `StockUnavailableException`, `StockLevelChanged`, and stock listeners for order created/canceled/refund events.
- Wired listeners in `EventServiceProvider`.
- Reused the existing branch availability/read-plane contract by syncing item stock depletion to `item_branch_availability` with reason `stock_rupture`, so kiosk/POS rupture UI continues through the existing `ItemAvailabilityChanged` outbox path.
- Added a symmetry audit script and stock sentinel tests.

Validation:
- `php -l` on B5a PHP files.
- `node tools/audit/order-service-symmetry.mjs`.
- `php artisan test tests/Feature/Stock --colors=never` → 17 passed.
- `php artisan test tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php --colors=never` → 1 passed.
- `php artisan test tests/Feature/Menu/OrderRejectsUnavailableBranchItemTest.php --colors=never` → 3 passed.
- `php artisan test tests/Feature/PosWalkInAndDeliveryFeeTest.php --colors=never` → 1 passed.
- `php artisan test tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php --colors=never` → 5 passed.
- `php artisan test tests/Feature/DispatchAfterCommitTest.php --colors=never` → 8 passed.
- `php artisan test tests/Feature/AfterCommitDispatchTest.php --colors=never` → 11 passed.
- `npx vitest run tests/js/kioskMenuStore.spec.js tests/js/posItemAvailabilityHandler.spec.js tests/js/posAvailabilityLiveGuard.spec.js` → 21 passed.
- `npm run production` → passed.
- `bash tools/lint/forbidden_bundles.sh` and `node tools/lint/scan_kiosk_bundles.mjs` → passed.
- `git diff --check` on B5a allowlist → passed.
