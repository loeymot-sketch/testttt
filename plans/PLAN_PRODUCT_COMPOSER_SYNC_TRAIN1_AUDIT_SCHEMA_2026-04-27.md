# TRAIN 1 - Audit Schema ADR + Data Contract Composer

TASK_ID: PRODUCT-COMPOSER-SYNC-01-SCHEMA-ADR  
MODE: plan then execute after gate  
GOAL: transformer la demande "composer Shopify" en schema auditable.

## 1. Pre-audit

Lire:

- `app/Models/Item.php`
- `app/Models/ItemCategory.php`
- `app/Models/ItemAttribute.php`
- `app/Models/ItemVariation.php`
- `app/Models/ItemExtra.php`
- `app/Models/ItemAddon.php`
- `app/Services/Pricing/PricingService.php`
- `app/Services/Menu/MenuProjectionService.php`
- `app/Services/Kiosk/KioskMenuService.php`
- `resources/js/components/admin/items/ItemShowComponent.vue`
- `resources/js/components/admin/pos/ItemComponent.vue`
- `resources/js/components/frontend/kiosk/steps/*`

## 2. Decisions a prendre

### ADR-01 composer

Option recommandee:

- Ajouter `item_wizard_profiles` et `item_wizard_steps`.
- Garder les prix dans variations/extras/addons.
- Step reference une source: `attribute`, `extra_group`, `addon_group`, `catalog_category`, `custom_static`.

### ADR-02 stock

Option recommandee:

- `stock_levels(branch_id, stockable_type, stockable_id, available_qty, status, track_stock, version)`.
- `stock_movements(branch_id, stockable_type, stockable_id, delta, reason, reference, actor, correlation_id)`.

## 3. Schema propose

`item_wizard_profiles`:

- `id`
- `item_id` unique FK
- `template` enum `simple|tacos|sandwich|burger|assiette|salade|omelette|snacking|menu|custom`
- `enabled` boolean
- `version` unsigned big int
- `published_at`
- timestamps

`item_wizard_steps`:

- `id`
- `profile_id` FK
- `step_key` enum `pain|viande|crudites|sauces|supplements|menu|boisson|addons|notes`
- `label`
- `help_text`
- `source_type` enum `attribute|extra_group|addon_group|catalog_category|custom_static`
- `source_ref` nullable string
- `min_select`, `max_select`, `allow_repeat` nullable overrides
- `included_qty` unsigned int default 0
- `free_qty` unsigned int default 0
- `default_selected` json nullable
- `visible_on` json nullable
- `sort` unsigned int
- `enabled` boolean
- timestamps

## 4. Sentinels fail-first

- `ProductComposerSchemaSentinelTest`
- `ProductComposerDoesNotStorePricesSentinelTest`
- `ProductComposerStepSourceContractTest`
- `StockLevelStockableSchemaSentinelTest`
- `PricingServiceIgnoresComposerPriceFieldsSentinelTest`

## 5. Exit

- ADR ecrit.
- Gate humain pret.
- Tests fail-first rouges ou PASS si schema implemente dans meme mission autorisee.
