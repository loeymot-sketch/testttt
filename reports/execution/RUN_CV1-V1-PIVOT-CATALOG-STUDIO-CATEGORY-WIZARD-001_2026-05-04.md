# RUN — CV1-V1-PIVOT-CATALOG-STUDIO-CATEGORY-WIZARD-001 — 2026-05-04

## Scope

EXECUTE_DELEGATION: foodking-complex-implementer

Cycle 5 Pivot V1 moves the Catalog Studio wizard entry from product cards to the selected category. No backend files were modified. Cycle 4 reserved ingredient files and language JSON files were not touched.

## entityType Decision

`ProductComposerEditorComponent.vue` now accepts `entityType: 'item' | 'category'` with default `item`, plus optional `entityId`. Back-compat is preserved through the existing `itemId` prop.

Entity type is derived in this order:

1. Vue Router route metadata/name/path/query via `currentRoute()` (`$router.currentRoute`, falling back to `$route` when present).
2. Browser query params from `window.location.search` for iframe/query loading.
3. Explicit prop fallback (`entityType`, default `item`).

Entity id is derived from `entityId`, then `itemId`, then route params/query, then `window.location.search`. The category route uses `props` with `entityType = category` and `entityId = route.params.id`.

Verified backend route reality in `routes/api.php`: category profile read/create is `/admin/composer/categories/{category}/profile`; template application is `/admin/composer/categories/{category}/apply-template`.

## Files Modified

- `resources/js/components/admin/items/CatalogStudioComponent.vue`
  - Removed product-card wizard button/test id pattern.
  - Added selected-category wizard entry in sidebar.
  - Drawer now opens category composer URL `/admin/categories/{id}/composer` while preserving `data-testid="catalog-studio-composer-overlay"`.
  - Added explanatory drawer text: `Ce wizard s'applique à TOUS les produits de cette catégorie.`

- `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue`
  - Added category-aware entity resolution.
  - Category mode uses `/admin/composer/categories/{id}/profile` and `/admin/composer/categories/{id}/apply-template`.
  - Item mode keeps existing item endpoints.
  - Header displays `Wizard de la catégorie : {name}` in category mode.
  - Category publish path adds the V1 confirmation warning for replacing product custom wizards.

- `resources/js/router/modules/itemRoutes.js`
  - Added `/admin/categories/:id/composer` as `admin.categories.composer`.
  - Preserved existing item composer route in `adminRoutes.js`.

- `tests/js/catalogStudioCategoryWizardEntry.spec.js`
  - Added selected-category button visibility, all-categories invisibility, drawer URL, and anti per-product wizard sentinel.

- `tests/js/categoryComposerEditorContract.spec.js`
  - Added category endpoint/header/apply-template contract and item regression contract.

- `tests/js/catalogStudioRouting.spec.js`
  - Updated routing/static sentinels for category composer entry.

- `tests/js/productComposerEditor.spec.js`
  - Updated static composer API sentinel for `resolvedEntityId` and category endpoint coverage.

## Validation

Targeted Vitest:

```text
Test Files  15 passed (15)
Tests  65 passed | 2 skipped (67)
```

Full Vitest:

```text
Test Files  189 passed (189)
Tests  1143 passed | 2 skipped (1145)
```

Build:

```text
npm run dev
Compiled Successfully in 8312ms
webpack compiled successfully
```

Notes: Vitest still prints existing baseline/browserlist freshness warnings and known noisy stderr from unrelated kiosk/POS/safeHtml tests. They did not fail the suite.

## Invariants

- I1 pricing SSOT: preserved. No frontend price calculation added; composer tests still assert no pricing fields in editor payload.
- I3 branch_id: not changed. Category wizard is catalog-level; existing branch scope selector behavior remains unchanged.
- Dispatch/order/auth/frozen zones: not touched.

## Limitation

`catalog-studio-create-product-flow.spec.js` E2E adaptation remains out of scope and is deferred to Cycle 7 as planned.
