# PRODUCT-COMPOSER-SYNC-03A-PROJECTION-CONSTRAINTS-LITE

## Intent

Expose existing composer selection constraints in POS/kiosk menu projections without adding schema or changing order runtime.

## Scope

- Add `itemAttributes` to `KioskMenuService` item payload.
- Add `itemAttributes` to `MenuProjectionService` item payload.
- Ensure legacy kiosk projection and canonical kiosk projection agree on the attribute ids.
- Preserve backend pricing SSOT: no price calculation is introduced.

## Non-goals

- No `item_wizard_profiles` / `item_wizard_steps`.
- No migrations.
- No routes.
- No `OrderService` / `FrontendOrderService`.
- No frontend wizard behavior changes.

## Validation

- `php artisan test tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php`
- `php artisan test tests/Feature/ItemAttributeComposerResourceTest.php`
- `bash .cursor/hooks/safety-check.sh`
- targeted `git diff --check`

## Exit Criteria

- POS and kiosk canonical projections expose `min_select`, `max_select`, `allow_repeat`.
- Legacy kiosk menu exposes the same attribute id list as canonical kiosk projection.
- Full composer runtime migration remains explicitly blocked until schema/gates.
