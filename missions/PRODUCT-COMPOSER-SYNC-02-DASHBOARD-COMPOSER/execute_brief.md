# PRODUCT-COMPOSER-SYNC-02-DASHBOARD-COMPOSER

## Intent

Add a real dashboard interface for building a product composition without editing code.

## User-facing target

From an existing product detail page, the operator can:

- choose a base template: sandwich, assiette, menu, drink, dessert, custom;
- add/remove/reorder wizard steps;
- choose sources from existing item attributes, variation groups, extras, addon groups, or category items;
- configure min/max selection, repeat, included quantity, free quantity, visible surfaces, and defaults;
- upload or replace the product photo using the existing media flow;
- preview POS and kiosk flow without changing the POS/kiosk designs;
- publish a version that bumps catalogue sync later.

## Technical rules

- The dashboard edits composition metadata and catalogue records only.
- It must not calculate final order price.
- Any price preview must call backend quote/projection endpoints or display stored base/additional prices as data.
- Keep existing `ItemShowComponent.vue` tabs; add one `Composition` tab instead of replacing the page.
- Reuse existing item attribute min/max/repeat semantics.

## API shape

- `GET /api/admin/item/{item}/composer`
- `PUT /api/admin/item/{item}/composer`
- `POST /api/admin/item/{item}/composer/publish`
- `POST /api/admin/item/{item}/composer/preview`

## Validation

- `php artisan test tests/Feature/Catalog/ProductComposerApiSentinelTest.php`
- `npm run test -- productComposerTab`
- `npm run test -- productComposerPreviewParity`
- Manual UI check: create one sandwich and one assiette profile, then verify the preview differs by step set.

## Exit criteria

- A non-developer can compose or modify a product from the dashboard.
- A product image can be changed from the dashboard and remains visible in kiosk payload.
- No product code outside the allowlist is touched.
