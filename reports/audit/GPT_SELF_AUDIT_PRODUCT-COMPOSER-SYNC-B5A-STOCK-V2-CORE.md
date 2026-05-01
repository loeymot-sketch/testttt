# GPT Self Audit — PRODUCT-COMPOSER-SYNC-B5A-STOCK-V2-CORE

Date: 2026-04-27
Delegation: codex-extension

## Verdict

VERDICT: PASS

## Scope Audit

Implemented Stock V2 core without adding frontend pricing logic. The order-flow touchpoints are symmetric:

- `OrderService::posOrderStore`: quote is sealed first, then `StockService::decrementForOrder($this->order, $idempotencyKey)` runs inside the same save transaction before persistence.
- `FrontendOrderService::myOrderStore`: kiosk quote is sealed first, then `StockService::decrementForOrder($this->frontendOrder, $idempotencyKey)` runs inside the same save transaction before persistence.

The first implementation attempted the decrement before quote sealing. Validation caught the regression: the item was marked `stock_rupture` before the current order finished sealing, so the final unit order rejected itself. The final implementation fixes the ordering.

## Invariants Checked

- Pricing SSOT: PASS. No frontend pricing logic added. `OrderQuoteService::sealForCommit` remains the backend authority before stock decrement.
- Branch isolation: PASS. Stock reads/writes are filtered by `branch_id`, `stockable_type`, and `stockable_id`. Rupture projection writes `item_branch_availability` with the same branch id.
- Dispatch after commit: PASS. `StockLevelChanged` and `ItemAvailabilityChanged` use the existing after-commit event trait; dispatch sentinels still pass.
- OrderService/FrontendOrderService symmetry: PASS. `tools/audit/order-service-symmetry.mjs` enforces one stock decrement call in each service.
- Manual admin rupture protection: PASS. Automatic stock release restores only `stock_rupture` / legacy `out_of_stock`; it does not override `admin_86`.

## Tests

PASS:
- `php -l app/Services/Stock/StockService.php`
- `php -l app/Services/OrderService.php`
- `php -l app/Services/FrontendOrderService.php`
- `node tools/audit/order-service-symmetry.mjs`
- `php artisan test tests/Feature/Stock --colors=never` → 17 passed
- `php artisan test tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php --colors=never` → 1 passed
- `php artisan test tests/Feature/Menu/OrderRejectsUnavailableBranchItemTest.php --colors=never` → 3 passed
- `php artisan test tests/Feature/PosWalkInAndDeliveryFeeTest.php --colors=never` → 1 passed
- `php artisan test tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php --colors=never` → 5 passed
- `php artisan test tests/Feature/DispatchAfterCommitTest.php --colors=never` → 8 passed
- `php artisan test tests/Feature/AfterCommitDispatchTest.php --colors=never` → 11 passed
- `npx vitest run tests/js/kioskMenuStore.spec.js tests/js/posItemAvailabilityHandler.spec.js tests/js/posAvailabilityLiveGuard.spec.js` → 21 passed
- `npm run production` → passed
- `bash tools/lint/forbidden_bundles.sh` → passed
- `node tools/lint/scan_kiosk_bundles.mjs` → passed
- `git diff --check` on B5a files → passed

## Risks / Notes

- Stock V2 currently tracks `Item`, `ItemVariation`, and `ItemExtra` when matching `stock_levels` rows exist. If no row exists, the stockable remains untracked by design.
- The realtime visual path reuses `ItemAvailabilityChanged` and `item_branch_availability` instead of adding a second frontend stock channel. This avoids duplicate bus semantics while satisfying kiosk/POS rupture sync now.
- B5b must preserve this ordering: payment lifecycle changes must not move stock decrement before quote sealing or fiscal/payment guards.
