# Codex Final Report - Central Sync / Composer / Kiosk / POS / KDS / Stock - 2026-04-28

Date: 2026-04-28  
Executor: Codex  
Mode: autonomous S0-S8 with adversarial read-only audits before/after critical corrections  
Final verdict: PASS_SOFTWARE_LOCAL_PROCEED_TO_HARDWARE_UAT  
Release decision: HOLD_FOR_HARDWARE_UAT_ONLY

## 1. Scope

This report consolidates the latest S0-S8 loop requested by the user:

- Central product/category/photo/addon/composer management.
- Generic product wizard / composer profile projection for kiosk and POS.
- Backend pricing SSOT, quote sealing, HMAC, fiscal composition snapshot.
- Kiosk/POS/KDS/OSS runtime synchronization.
- Stock decrement/release/rupture sync.
- Queue number uniqueness.
- Cash-at-counter lifecycle.
- Delivery/geocode hardening.
- Design audits D1/D2/D3.
- Offline kiosk cash replay compatibility with quote-token enforcement.

## 2. Implementation Delta Closed In This Loop

### 2.1 Addon pricing / fiscal P0 closed

Adversarial audit found a real P0: `item_addons` were validated and persisted in `composition_snapshot`, but the addon item DB price was not included in backend totals.

Corrected:

- `app/Services/Pricing/PricingService.php`
  - Loads selected `ItemAddon::with('addonItem')`.
  - Enforces addon item branch availability through `AvailabilityService`.
  - Rejects inactive/unavailable/hidden addon items.
  - Enforces addon belongs to the main ordered item.
  - Adds `addonItem->price * quantity` into unit sum, subtotal, tax base, final total and quote total.
- `app/Services/Pricing/CompositionSnapshotBuilder.php`
  - Adds immutable `addons[]` snapshot entries with `addon_id`, `addon_item_id`, `addon_name`, `role`, `quantity`, `unit_price`, `line_total`.
- `app/Services/Pricing/PricingLineResult.php`
  - Adds `addonTotal`.
- `app/Services/Kiosk/PricingPreviewService.php`
  - Returns `addons_total` in preview lines.

Key code proof:

- `app/Services/Pricing/PricingService.php:94-107` loads addons and checks addon item branch availability.
- `app/Services/Pricing/PricingService.php:191-218` computes addon total and includes it in line total.
- `app/Services/Pricing/CompositionSnapshotBuilder.php:127-146` seals addon unit price and line total.

### 2.2 Composer projection consistency closed

Corrected:

- `app/Services/Composer/ComposerProfileProjection.php`
  - Filters variation choices by `Status::ACTIVE`.
  - Filters extra choices by `Status::ACTIVE`.
  - Filters addon choices by role, addon item existence, active status, global availability, and surface visibility.
- `app/Services/Menu/MenuProjectionService.php`
  - Eager-loads addon item `is_available`.
- `app/Services/Kiosk/KioskMenuService.php`
  - Eager-loads addon item `is_available`.

Key code proof:

- `app/Services/Composer/ComposerProfileProjection.php:63-83` filters inactive variations/extras.
- `app/Services/Composer/ComposerProfileProjection.php:95-116` filters addon choices.

### 2.3 Kiosk payload / preview hardening closed

Corrected:

- `resources/js/helpers/kioskPricingPreview.js`
  - Preserves quantities for variations/extras/addons.
  - Forwards `item_addons`.
  - Strips money fields.
- `resources/js/store/modules/kioskCart.js`
  - Sanitizes nested variations/extras/addons.
  - Strips nested `price` / money fields before quote/order payload.

Key proof:

- `resources/js/helpers/kioskPricingPreview.js:66-108` normalizes id/quantity only.

### 2.4 Offline kiosk cash replay closed

Adversarial audit found a P1: queued offline cash orders removed quote fields but replayed directly to `/frontend/order`, while kiosk commit now requires quote token/signature.

Corrected:

- `resources/js/helpers/kioskOfflineQueue.js`
  - Detects queued kiosk order payloads without quote fields.
  - Regenerates a fresh `/frontend/order/quote`.
  - Replays `/frontend/order` with fresh `quote_token`, `quote_signature`, totals.
- `resources/js/store/modules/kioskCart.js`
  - Keeps offline payload quote-free intentionally; replay layer owns fresh quote regeneration.

Key proof:

- `resources/js/helpers/kioskOfflineQueue.js:370-392` builds fresh quote.
- `resources/js/helpers/kioskOfflineQueue.js:550-555` uses fresh quote before order replay.

## 3. Adversarial Audit Loop

### Harvey audit

Verdict: REWORK

Findings:

- P0: addons accepted but not priced.
- P1: branch-unavailable addon items still orderable.
- P1: inactive variations/extras exposed in composer projection.
- P1: offline kiosk cash replay incompatible with quote-token requirement.

Action: all four were corrected.

### Wegener re-audit

Verdict: PASS

Confirmed:

- Addons are priced through backend DB price and included in quote/total/tax.
- Addon item branch availability is enforced.
- Composer projection filters inactive modifiers.
- Offline kiosk cash replay regenerates a fresh quote before commit.

## 4. Validation Matrix

### Backend pricing / composer / quote

PASS:

- `php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php --stop-on-failure` -> 9 passed.
- `php artisan test tests/Feature/Services/Pricing/PricingServiceTest.php --stop-on-failure` -> 21 passed.
- `php artisan test tests/Feature/Services/Pricing/PricingServiceMultiQtyTest.php --stop-on-failure` -> 12 passed.
- `php artisan test tests/Feature/KioskPhase1/KioskEndpointsTest.php --stop-on-failure` -> 17 passed.
- `php artisan test tests/Feature/QuoteReplayIdempotencyTest.php --stop-on-failure` -> 3 passed.
- `php artisan test tests/Feature/KioskQuoteIntegrityTest.php --stop-on-failure` -> 2 passed.
- `php artisan test tests/Feature/KioskQuoteTokenRequiredOnCommitTest.php --stop-on-failure` -> 4 passed.
- `php artisan test tests/Feature/QuoteTamperTest.php --stop-on-failure` -> 3 passed.
- `php artisan test tests/Feature/QuoteExpirationTest.php --stop-on-failure` -> 2 passed.

### Composer / central catalog / photo / projection

PASS:

- `php artisan test tests/Feature/Composer --stop-on-failure` -> 15 passed.
- `php artisan test tests/Feature/Services/Menu --stop-on-failure` -> 22 passed.
- `php artisan test tests/Feature/Catalog --stop-on-failure` -> 10 passed.
- `php artisan test tests/Feature/Menu/ItemImageCatalogRefreshTest.php --stop-on-failure` -> 1 passed.
- `php artisan test tests/Feature/Menu/CatalogMutationSnapshotCoverageTest.php --stop-on-failure` -> 3 passed.
- `php artisan test tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php --stop-on-failure` -> 1 passed.
- `php artisan test tests/Feature/ItemAttributeComposerResourceTest.php --stop-on-failure` -> 5 passed.
- `php artisan test tests/Feature/Http/Admin/MenuProjectionControllerTest.php --stop-on-failure` -> 9 passed.

### Frontend wizard / kiosk cart / offline / sync helpers

PASS:

- `npm test -- tests/js/kioskOfflineQueueV2.spec.js tests/js/kioskCartOfflinePaymentScope.spec.js tests/js/kioskPricingPreview.spec.js tests/js/kioskCartSendPayload.spec.js` -> 38 passed.
- `npm test -- tests/js/kioskOfflineQueueV2.spec.js tests/js/kioskCartOfflinePaymentScope.spec.js tests/js/kioskPricingPreview.spec.js tests/js/kioskCartSendPayload.spec.js tests/js/kioskWizardGenericComposer.spec.js tests/js/KioskWizard.spec.js` -> 137 passed.
- `npm test -- tests/js/kioskOfflineQueue.spec.js tests/js/sentinels/kioskOfflineIdPrefix.spec.js tests/js/kioskWaitingAutoReturn.spec.js tests/js/kioskConfirmationCountdown.spec.js` -> 16 passed.
- `npm test -- tests/js/productComposerEditor.spec.js tests/js/productComposerSummary.spec.js tests/js/kioskOfflineQueueV2.spec.js tests/js/posItemAvailabilityHandler.spec.js` -> 40 passed.
- `npm test -- tests/js/kioskPricingPreview.spec.js tests/js/kioskCartSendPayload.spec.js tests/js/kioskWizardGenericComposer.spec.js tests/js/eventContractDedupe.spec.js` -> 26 passed.

### Stock / queue / payment / fiscal / outbox / KDS / sync

PASS:

- `php artisan test tests/Feature/Stock --stop-on-failure` -> 20 passed.
- `php artisan test tests/Feature/QueueNumberConcurrencyTest.php --stop-on-failure` -> 5 passed.
- `php artisan test tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php --stop-on-failure` -> 1 passed.
- `php artisan test tests/Feature/Payment --stop-on-failure` -> 14 passed.
- `php artisan test tests/Feature/Fiscal/FiscalCashAtCounterLifecycleTest.php --stop-on-failure` -> 3 passed.
- `php artisan test tests/Feature/KDS --stop-on-failure` -> 7 passed.
- `php artisan test tests/Feature/KDSOrderItemsTest.php --stop-on-failure` -> 2 passed.
- `php artisan test tests/Feature/KDSScopeRestrictionTest.php --stop-on-failure` -> 1 passed.
- `php artisan test tests/Feature/SyncComprehensiveTest.php --stop-on-failure` -> 6 passed.
- `php artisan test tests/Feature/OutboxTest.php --stop-on-failure` -> 6 passed.
- `php artisan test tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php --stop-on-failure` -> 9 passed.
- `php artisan test tests/Feature/OutboxRescueTest.php --stop-on-failure` -> 2 passed.

### Delivery / maps

PASS:

- `php artisan test tests/Feature/Delivery --stop-on-failure` -> 3 passed.
- `npm test -- tests/js/checkoutGeocodeError.spec.js tests/js/deliveryCharge.spec.js` -> 12 passed.

### Playwright runtime

PASS:

- `npx playwright test tests/e2e/kiosk-post-payment-auto-return.spec.js tests/e2e/kiosk-full-process/c1-kiosk-process-audit.spec.js tests/e2e/pos-full-process/c2-pos-process-audit.spec.js tests/e2e/c3-runtime-multi-surface.spec.js --repeat-each=3` -> 39 passed.
- Post-P0-fix replay: same C0/C1/C2/C3 set with `--repeat-each=1` -> 13 passed.

Covered runtime scenarios:

- Kiosk card simple order.
- Kiosk composition order with immutable snapshot.
- Kiosk cash-at-counter order.
- Kiosk rupture projection.
- Kiosk abandon/reset path.
- POS dine-in cash.
- POS takeaway card.
- POS delivery quote forge protection.
- POS counter-collect confirm.
- POS counter-collect cancel.
- Kiosk order appears on KDS/POS/OSS without manual reload.
- POS order appears on KDS/OSS without manual reload.

### Playwright design

PASS:

- `npx playwright test tests/e2e/design/kiosk/d1-kiosk-design-audit.spec.js tests/e2e/design/pos/d2-pos-design-audit.spec.js tests/e2e/design/kds/d3-kds-oss-design-audit.spec.js --repeat-each=2` -> 6 passed.
- Post-P0-fix replay with `--repeat-each=1` -> 3 passed.

### Build

PASS:

- `npm run production` -> Mix compiled successfully.

Note: `npm run build` does not exist in this repo; canonical build command is `npm run production`.

### Prod-like concurrency

PASS under real MySQL + Redis local prod-like harness:

- Test database: `foodking_prodlike_codex_20260428` (temporary MySQL database, isolated from `foodking`).
- Redis database: `15` (flushed before the run set).
- Command: `env APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=foodking_prodlike_codex_20260428 DB_USERNAME=root DB_PASSWORD= CACHE_DRIVER=redis CACHE_STORE=redis REDIS_CLIENT=predis REDIS_HOST=127.0.0.1 REDIS_PORT=6379 REDIS_DB=15 REDIS_CACHE_DB=15 php artisan test tests/Feature/ProdLike/ProdLikeConcurrencyTest.php --stop-on-failure`

Run-many result: 3/3 PASS.

- Run 1: 2 passed, stock 50 parallel workers + queue 50 mixed POS/kiosk workers.
- Run 2: 2 passed, stock 50 parallel workers + queue 50 mixed POS/kiosk workers.
- Run 3: 2 passed, stock 50 parallel workers + queue 50 mixed POS/kiosk workers.

This closes the previous environment-only P2. The concurrency proof is no longer skipped locally.

## 5. S0-S8 Status

| Slice | Domain | Status |
| --- | --- | --- |
| S0 | Global invariants / branch authz / plan adversarial loop | PASS |
| S1 | Composer publish -> catalog event/outbox/cache invalidation | PASS |
| S2 | POS/Kiosk projection and live menu refresh branch scoping | PASS |
| S3 | Generic wizard composer runtime | PASS after REWORK |
| S4 | Central product/category/photo/addon/stock management sync | PASS after REWORK |
| S5 | Stock V2 decrement/release/rupture branch isolation | PASS |
| S6 | Queue/payment/fiscal/KDS/OSS/history/outbox | PASS |
| S7 | Playwright runtime + design C0/C1/C2/C3/D1/D2/D3 | PASS |
| S8 | Consolidated report and final decision | PASS_SOFTWARE_LOCAL |

## 6. Invariants

Backend pricing SSOT: PASS  
Evidence: frontend payload strips money; backend recomputes from DB item/variation/extra/addon prices.

OrderStatus / payment status: PASS  
Evidence: payment and KDS suites pass, no string status path introduced in this loop.

Branch isolation: PASS  
Evidence: composer authz, projection controller, stock, queue, payment, KDS scope, catalog branch events all pass.

Dispatch after commit / outbox: PASS  
Evidence: outbox tests and catalog/order event tests pass.

OrderService / FrontendOrderService symmetry: PASS in current tested scope  
Evidence: stock symmetry test passes; quote/tamper tests pass.

Fiscal / NF525 cash-at-counter: PASS local  
Evidence: fiscal sequence remains null before counter confirm, allocated on confirm, not allocated on cancel, idempotent confirm, reprint no new sequence.

## 7. Remaining Non-Software Gates

The code is locally software-pass. Still required before commercial release:

- Physical TPE / external payment terminal success/refusal/timeout.
- Fiscal printer physical ticket and reprint behavior.
- Kiosk OS lockdown and URL bar/touch behavior on the real device.
- Real KDS screen readability under kitchen conditions.
- Network loss/reconnect with real router/Wi-Fi.
- Google Maps live geocoding in production key/quota conditions.

## 8. Final Decision

AUDIT_VERDICT: PASS  
CODE_LOCAL_DECISION: PASS_SOFTWARE_LOCAL_PROCEED_TO_HARDWARE_UAT  
RELEASE_DECISION: HOLD_FOR_HARDWARE_UAT_ONLY  
P0_OPEN: 0  
P1_OPEN: 0  
P2_OPEN: 0
