# Central Management Runbook — Version A

Status: canonical VA-SYS-09 operations runbook.

## Fast map

| Area | Primary files |
| --- | --- |
| Product CRUD/photo | `ItemController`, `ItemService`, `ItemResource`, `NormalItemResource` |
| Category CRUD | `ItemCategoryController`, `ItemCategory` |
| Composer | `ComposerProfileController`, `ComposerStepController`, `ComposerProfileService`, `ComposerStepService` |
| Projections | `MenuProjectionService`, `KioskMenuService`, `ComposerProfileProjection` |
| Stock/availability | `AvailabilityService`, `StockService`, `ChoiceAvailabilityResolver` |
| Outbox/realtime | `DomainEvent`, `DispatchDomainEventsJob`, `Persist*ToOutbox` listeners |
| KDS fallback | `KdsSyncController`, `KdsSyncService.php`, `KdsSyncService.js` |
| Branch auth | `AdminController::authorizeBranchScope`, routes middleware, `routes/channels.php` |

## Symptom runbook

### Product not visible on kiosk

Check:

1. `items.status` is active.
2. Category is active and visible on `kiosk`.
3. Product `channels` is null or contains `kiosk`.
4. Kiosk machine belongs to expected branch.
5. `item_branch_availability` is not unavailable for that branch.
6. Kiosk cache `kiosk.menu.branch.{branchId}` is invalidated or force refresh.
7. `CatalogChanged` or `ItemAvailabilityChanged` exists in `domain_events`.

Commands:

```bash
php artisan test tests/Feature/Services/Menu
php artisan test tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php
php artisan foodking:outbox:rescue
```

### Product visible on POS but not kiosk

Check:

1. Channel filters differ: `pos` vs `kiosk`.
2. Kiosk-specific category label/sort does not hide category.
3. Kiosk projection uses branch from machine token, not query input.
4. POS may have stale local items; kiosk may have offline snapshot.

Files:

- `MenuProjectionService`
- `KioskMenuService`
- `resources/js/store/modules/kioskMenu.js`
- `resources/js/components/admin/pos/PosComponent.vue`

### Wizard not visible

Check:

1. A published `ItemWizardProfile` exists for the product.
2. Profile `branch_id_scope` matches actor/branch.
3. `ComposerProfileController::show` returns own/global profile, not latest foreign.
4. `ComposerProfileProjection` includes steps/options.
5. Kiosk/POS projection has `composer_profile`.
6. Browser is not using stale menu cache.

Commands:

```bash
php artisan test tests/Feature/Composer
php artisan test tests/Feature/ItemAttributeComposerResourceTest.php
php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php
```

### Photo not updated

Check:

1. Actor has global photo permission policy (`Admin`/`Tenant Admin` in V1).
2. `ItemController::changeImage` succeeded.
3. Stored media URL changed.
4. `CatalogChanged`/photo invalidation event exists.
5. Kiosk/POS refreshed projection or cache was invalidated.

Commands:

```bash
php artisan test tests/Feature/Catalog/ProductPhotoAuthzTest.php
php artisan test tests/Feature/Catalog/PhotoEndToEndKioskInvalidationTest.php
```

### Stock not synced

Check:

1. Is it product-level or choice-level stock?
2. Product-level: inspect `item_branch_availability`.
3. Choice-level: inspect `stock_levels` with stockable type/id.
4. `StockLevelChanged`, `ItemAvailabilityChanged`, or `CatalogChanged` exists in `domain_events`.
5. Kiosk cache key and menu snapshot bumped.
6. POS/Kiosk frontend shows disabled state; backend pricing rejects direct submit.

Commands:

```bash
php artisan test tests/Feature/Stock
php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php
npx vitest run tests/js/posRuptureUx.spec.js tests/js/kioskRuptureUx.spec.js
```

### KDS not receiving order

Check:

1. Order exists and branch is correct.
2. `OrderCreated` row exists in `domain_events`.
3. `dispatched_at` is not null, or `last_error` explains provider failure.
4. Queue worker processed `DispatchDomainEventsJob`.
5. KDS user/channel auth can subscribe to branch.
6. Fallback `KdsSyncService` can fetch the order without WebSocket.
7. No throttle/429 on KDS sync.

Commands:

```bash
php artisan test tests/Feature/KioskRealtimeBroadcastTest.php
php artisan test tests/Feature/SyncComprehensiveTest.php
php artisan foodking:outbox:rescue
php artisan foodking:outbox:retry-failed --since=1h
npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --repeat-each=2 --retries=0
```

### Queue number duplicate alert

Check:

1. DB unique constraint for branch/day/queue number path is present in current rollout.
2. Redis/cache lock driver is shared in production.
3. POS and Kiosk both use symmetric allocation/retry.
4. Idempotency key did not replay as a new order.
5. Inspect retry logs and failed inserts.

Commands:

```bash
php artisan test tests/Feature/ProdLike/ProdLikeConcurrencyTest.php
php artisan test --filter=QueueNumber
```

## Branch isolation checklist

| Actor | Expected behavior |
| --- | --- |
| Branch admin/user | Own branch data only |
| Branch user with null branch mutation | Scope to own branch, not all branches |
| Admin/Tenant Admin | Global or all branches where route allows |
| Kiosk machine | Machine branch only |
| KDS/POS user | Own branch private channel only |

Critical VA-SYS-07B tests:

- `DashboardBranchScopeMatrixTest`
- `AvailabilityToggleAuthzMatrixTest`
- `ProductPhotoAuthzTest`
- `ComposerAuthzMinimalTest`
- `AdminItemBranchAvailabilityProjectionTest`

## Final support rule

If a symptom is runtime sync related, inspect in this order:

1. Backend DB state.
2. Backend projection response.
3. `domain_events` row.
4. Queue/provider delivery.
5. Frontend dedupe/cache/fallback.
6. Browser DOM.

