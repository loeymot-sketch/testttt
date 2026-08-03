# Catalog / Composer Data Flow — Version A

Status: canonical VA-SYS-09 document.

## Scope

This file maps the real data path for category, product, photo, composer profile, stock availability, and menu projection across Dashboard, POS, Kiosk, KDS, and OSS.

## Write side: Dashboard to backend

| Operation | Entry point | Service/model path | Event/outbox effect | Notes |
| --- | --- | --- | --- | --- |
| Create/update product | `app/Http/Controllers/Admin/ItemController.php` | `Item`, `ItemService` | `ItemCreated`, `ItemUpdated`, `CatalogChanged` via listener chain | Product catalog is global state; branch-specific availability is separate. |
| Change product photo | `ItemController::changeImage` | `ItemService::changeImage` | Catalog refresh / invalidation tests in `PhotoEndToEndKioskInvalidationTest` | VA-SYS-07B policy: global catalog roles only (`Admin`, `Tenant Admin`). |
| Create/update category | `app/Http/Controllers/Admin/ItemCategoryController.php` | `ItemCategory` | `CategoryCreated`, `CategoryUpdated`, `CatalogChanged` | Global category data; projection filters by surface/branch. |
| Variations | `ItemVariationController` | `ItemVariation` | catalog/profile projection affected | Choice availability resolved by `ChoiceAvailabilityResolver`. |
| Extras | `ItemExtraController` | `ItemExtra` | catalog/profile projection affected | Stockable extras can be unavailable. |
| Addons | `ItemAddonController` | `ItemAddon` + addon item | catalog/profile projection affected | Addon target item can be stocked/decremented. |
| Composer profile | `ComposerProfileController` | `ComposerProfileService` | `ComposerProfileChanged`, `ComposerProfilePublished`, `CatalogChanged` | Branch-scoped; `show` defaults to actor branch/global scope after VA-SYS-07B. |
| Composer steps/options | `ComposerStepController` | `ComposerStepService` | `ComposerProfileChanged`, `CatalogChanged` | Branch isolation enforced on store/update/destroy. |

## Read side: projections

| Surface | Projection/file | Branch source | Important fields |
| --- | --- | --- | --- |
| Kiosk | `app/Services/Kiosk/KioskMenuService.php` | kiosk machine token | `is_available`, `unavailable_reason`, `composer_profile`, choice availability |
| POS/admin menu | `app/Services/Menu/MenuProjectionService.php` | staff branch/admin query | categories/items, surface visibility, composer profile |
| API resources | `ItemResource`, `NormalItemResource`, `ItemExtraResource`, `ItemAddonResource` | explicit request context | choice snapshots from `ChoiceAvailabilityResolver` |
| Pricing | `app/Services/Pricing/PricingService.php` | order branch | backend validates profile, choices, stock, price |
| KDS/OSS | order snapshots + status API | order branch | KDS should not recompute current catalog price for historical order lines |

## Cache and invalidation

| Cache/key | Owner | Invalidated by |
| --- | --- | --- |
| `kiosk.menu.branch.{branchId}` | `Frontend\MenuController`, kiosk menu bootstrap | `InvalidateKioskMenuCacheOnItemAvailabilityChanged`; catalog/stock events force refresh paths |
| `menu:snapshot_version:branch:{id}` | `MenuSnapshot` | `BumpMenuSnapshotOnItemAvailabilityChanged` |
| Kiosk IndexedDB/offline snapshot | `resources/js/store/modules/kioskMenu.js` | realtime `CatalogChanged` / `ItemAvailabilityChanged`, force fetch, TTL |
| POS local items array | `PosComponent.vue` | `_onCatalogChanged`, `_onItemAvailabilityChanged`, manual reload/fallback |

## Event map

| Source event | Canonical aggregate | Listener | Outbox broadcast | Surface consumers |
| --- | --- | --- | --- | --- |
| `CategoryCreated/Updated/Deleted` | category | `PersistCatalogChangedToOutbox` | `CatalogChanged` | POS, Kiosk, KDS refresh/fallback |
| `ItemCreated/Updated/Deleted` | item | `PersistCatalogChangedToOutbox` | `CatalogChanged` | POS, Kiosk, KDS refresh/fallback |
| `ComposerProfileChanged/Published` | composer_profile | `PersistCatalogChangedToOutbox` | `CatalogChanged` | POS/Kiosk wizard projection refresh |
| `StockLevelChanged` | stock_level | `PersistCatalogChangedToOutbox` | `CatalogChanged` | POS/Kiosk catalog refresh |
| `ItemAvailabilityChanged` | item availability | `PersistItemAvailabilityChangedToOutbox` + catalog listener | `ItemAvailabilityChanged` and catalog refresh | POS/Kiosk immediate rupture state; KDS refresh |
| `OrderCreated` | order | `PersistOrderCreatedToOutbox` | `OrderCreated` | KDS, POS, OSS |
| `OrderStatusChanged` | order | `PersistOrderStatusChangedToOutbox` | `OrderStatusChanged` | KDS, POS, OSS, Kiosk waiting |

Channel convention:

- Outbox stores `private-branch.{branch_id}`.
- Echo clients subscribe through Laravel mapping as `branch.{branch_id}` / private branch channel depending helper.
- Branch authorization lives in `routes/channels.php`.

## Order of truth

1. Database rows and snapshots.
2. Backend projection services.
3. Outbox/realtime events.
4. Frontend local cache/UI state.

If these disagree, trust backend DB/projection first, then replay/recover outbox, then refresh frontend state.

