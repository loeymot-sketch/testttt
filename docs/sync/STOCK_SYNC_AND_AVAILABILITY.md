# Stock Sync and Availability — Version A

Status: canonical VA-SYS-09 document.

## User decision

Stock/rupture is supported at two levels:

1. Whole product rupture: sandwich, menu, drink, dessert, prepared item, addon target, etc.
2. Stockable wizard choice rupture: ingredient, sauce, crudite, supplement, drink choice, dessert choice, meat/fish choice, addon target.

The UI may show disabled/badged choices, but the backend must reject stale or forged submissions.

## Main files

| Concern | File |
| --- | --- |
| Product branch availability | `app/Services/Menu/AvailabilityService.php` |
| Product/choice stock levels | `app/Services/Stock/StockService.php`, `app/Models/StockLevel.php` |
| Choice availability | `app/Services/Stock/ChoiceAvailabilityResolver.php` |
| Backend orderability guard | `app/Services/Pricing/PricingService.php` |
| Menu projections | `app/Services/Menu/MenuProjectionService.php`, `app/Services/Kiosk/KioskMenuService.php` |
| Kiosk cache invalidation | `app/Listeners/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php` |
| Snapshot bump | `app/Listeners/BumpMenuSnapshotOnItemAvailabilityChanged.php` |
| Catalog outbox | `app/Events/CatalogChanged.php`, `app/Listeners/PersistCatalogChangedToOutbox.php` |
| Availability outbox | `app/Events/ItemAvailabilityChanged.php`, `app/Listeners/PersistItemAvailabilityChangedToOutbox.php` |
| Stock event | `app/Events/StockLevelChanged.php` |

## Availability states

| Level | Data source | Unavailable reason |
| --- | --- | --- |
| Global item status | `items.status` | product hidden/inactive globally |
| Branch product availability | `item_branch_availability` | `out_of_stock`, `seasonal`, `closed_today`, `manual`, etc. |
| Variation stock | `stock_levels` for `ItemVariation` | `stock_rupture` when on_hand is exhausted |
| Extra stock | `stock_levels` for `ItemExtra` | `stock_rupture` |
| Addon target stock | `stock_levels` + addon item availability | `stock_rupture` or branch/surface reason |

## Runtime flow: stock change to screens

1. Dashboard/POS/admin toggles availability or stock changes after order.
2. Backend updates `item_branch_availability` or `stock_levels`.
3. `ItemAvailabilityChanged`, `StockLevelChanged`, or `CatalogChanged` is dispatched after commit.
4. Outbox persists branch-scoped event.
5. Kiosk menu cache key `kiosk.menu.branch.{branchId}` is invalidated where applicable.
6. Menu snapshot key `menu:snapshot_version:branch:{id}` is bumped for relevant branch.
7. POS/Kiosk/KDS receive realtime event or fallback polling catches up.

## Runtime flow: order submit

1. POS/Kiosk submits cart.
2. Backend ignores client totals and recalculates.
3. `PricingService` checks item/choice availability for the order branch.
4. `StockService::decrementForOrder()` decrements product/addon stock inside the order transaction path.
5. Snapshot persists what was sold.
6. Cancel/refund release restores stock idempotently from snapshots/ledger.

## What to diagnose

| Symptom | Checks |
| --- | --- |
| Product visible but should be unavailable | `item_branch_availability`, `items.status`, projection branch_id, `kiosk.menu.branch.{id}` cache |
| Choice still selectable | `stock_levels.stockable_type`, `ChoiceAvailabilityResolver`, projected `is_available`, POS/Kiosk JS disabled state |
| Backend accepts unavailable choice | `PricingService::validateComposerSelections` / `assertSelectionsOrderable`, composer current profile |
| Kiosk stale after stock change | `domain_events` CatalogChanged/ItemAvailabilityChanged, queue worker, cache key invalidation |
| Stock negative | `StockService` locking path, stock movements, duplicate order idempotency |

## Commands

```bash
php artisan test tests/Feature/Stock
php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php
php artisan test tests/Feature/Services/Menu
npx vitest run tests/js/posRuptureUx.spec.js tests/js/kioskRuptureUx.spec.js tests/js/kioskWizardGenericComposer.spec.js
php artisan foodking:outbox:rescue
php artisan foodking:outbox:retry-failed --since=1h
```

## Non-negotiables

- Product/choice rupture must be branch-scoped where relevant.
- POS/Kiosk UI must never be the only guard.
- Backend pricing must reject stale choices.
- Historical order snapshots must stay readable after catalog changes.
- Stock release must be idempotent.

