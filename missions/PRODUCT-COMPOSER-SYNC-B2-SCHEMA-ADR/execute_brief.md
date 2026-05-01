# PRODUCT-COMPOSER-SYNC-B2-SCHEMA-ADR

Source: `reports/audit/CLAUDE_PRODUCT_COMPOSER_FINAL_EXECUTION_PLAN_2026-04-27.md` § Mission B2.

## Gate References

- `HG-COMPOSER-SCHEMA-ADR`: approved in Claude final execution plan after human answers.
- `HG-STOCK-STOCKABLE-SCOPE`: approved in Claude final execution plan after human answers.
- `D-COMPOSER-01`: addon roles approved as `drink|side|dessert|menu_component|upsell`.

## Implementation

- Added `item_wizard_profiles` and `item_wizard_steps`.
- Added `stock_levels` and append-only `stock_movements`.
- Added nullable `role` enum to `item_addons` without any price field.
- Added Eloquent models and factories for composer/stock foundations.
- Added portable model-level constraints for SQLite test parity:
  - wizard `min_select <= max_select`;
  - stock `reserved <= on_hand`;
  - stock movements cannot update/delete;
  - addon role refuses unknown values.
- Added ADR at `docs/architecture/ADR-COMPOSER-STOCK-2026-04-27.md`.

## Validation

- PHP lint on B2 migrations/models/factories/tests: PASS.
- `php artisan migrate:fresh --env=testing --no-interaction`: PASS.
- `php artisan test tests/Feature/Catalog --colors=never`: PASS, 4 tests.
- `php artisan test tests/Feature/Stock --colors=never`: PASS, 6 tests.
- Scoped `git diff --check`: PASS.

## Forbidden Zones

No edits were made to `OrderService.php`, `FrontendOrderService.php`, `PricingService.php`, POS/kiosk controllers, runtime wizard, or frontend UI.
