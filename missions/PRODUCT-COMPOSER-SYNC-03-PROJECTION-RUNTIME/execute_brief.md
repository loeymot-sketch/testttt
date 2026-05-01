# PRODUCT-COMPOSER-SYNC-03-PROJECTION-RUNTIME

## Intent

Make POS and kiosk consume the same backend product composition contract while keeping their UI designs separate.

## Architecture

- `MenuProjectionService` becomes the canonical read model for composed products.
- `KioskMenuService` may remain as a wrapper/adaptor during migration.
- POS and kiosk receive the same composition metadata:
  - profile id and version;
  - ordered steps;
  - choice source;
  - min/max/repeat;
  - included/free quantity;
  - visible surfaces;
  - price-bearing option ids.
- POS and kiosk may render differently, but they must not invent different business rules.

## Realtime contract

- Publishing a composition emits `CatalogCompositionChanged`.
- Listener persists an outbox event after commit.
- Menu/catalog version is bumped.
- Kiosk and POS detect version mismatch and refresh projection.

## Forbidden

- No frontend price calculation beyond displaying backend-provided values.
- No duplicate wizard rule hardcoding in kiosk and POS.
- No broad redesign of the POS order wizard.
- No hidden branch leakage in `/frontend/menu`.

## Validation

- `php artisan test tests/Feature/Catalog/ProductComposerProjectionParitySentinelTest.php`
- `php artisan test tests/Feature/Catalog/CatalogCompositionVersionBumpSentinelTest.php`
- `npm run test -- kioskComposerRuntime`
- `npm run test -- posComposerRuntime`
- Manual: a sandwich asks bread/crudites/sauces; an assiette only asks configured assiette steps.

## Exit criteria

- POS and kiosk compose the same product profile from one projection.
- A dashboard publish reaches both surfaces by versioned refresh.
- Existing kiosk/POS layout logic is preserved except where it reads composition metadata.
