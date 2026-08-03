# PRODUCT-COMPOSER-SYNC-01-SCHEMA-ADR

## Intent

Decide and document the canonical schema for manual product composition before any dashboard or order runtime patch.

## Required decision

Use a thin composer layer over the existing FoodKing catalogue:

- Existing SSOT remains `items`, `item_categories`, `item_attributes`, `item_variations`, `item_extras`, `item_addons`.
- Add `item_wizard_profiles` for per-product composition profiles.
- Add `item_wizard_steps` for ordered, configurable wizard steps.
- Add stock as a stockable model so products, variations, extras, addons, and future ingredients can be tracked without duplicating stock logic.

## Proposed tables

`item_wizard_profiles`:

- `id`
- `item_id` unique FK
- `template` enum-like string: `sandwich`, `assiette`, `menu`, `drink`, `dessert`, `custom`
- `enabled` boolean
- `version` unsigned big integer
- `published_at` nullable timestamp
- timestamps

`item_wizard_steps`:

- `id`
- `profile_id` FK
- `step_key` string: `size`, `bread`, `crudites`, `sauces`, `extras`, `addons`, `drink`, `side`, `note`
- `label`
- `help_text` nullable
- `source_type` string: `attribute`, `extra_group`, `addon_group`, `category`, `custom`
- `source_ref` nullable string or JSON reference
- `min_select_override` nullable integer
- `max_select_override` nullable integer
- `allow_repeat_override` nullable boolean
- `included_qty` integer default 0
- `free_qty` integer default 0
- `default_selected` JSON nullable
- `visible_on` JSON nullable
- `sort` integer
- `enabled` boolean
- timestamps

`stock_levels`:

- `branch_id`
- `stockable_type`
- `stockable_id`
- `available_qty`
- `low_threshold`
- `status`
- `track_stock`
- `version`
- unique `(branch_id, stockable_type, stockable_id)`

## Gates

- `HG-COMPOSER-SCHEMA-ADR` before migrations.
- `HG-STOCK-STOCKABLE-SCOPE` before stock migrations.
- No OrderService edit in this mission.

## Validation

- PHP syntax on new model files.
- `php artisan migrate:fresh --env=testing`
- `php artisan test tests/Feature/Catalog/ProductComposerSchemaSentinelTest.php tests/Feature/Stock/StockableStockSchemaSentinelTest.php`
- GPT self-audit with `VERDICT: PASS|NEEDS_FIX|ESCALATE`.

## Exit criteria

- Schema supports sandwich, assiette, menu, custom product, weekly offers, image changes, and stockable supplements.
- No final price is calculated in the dashboard.
- Branch isolation is enforced at stock level.
