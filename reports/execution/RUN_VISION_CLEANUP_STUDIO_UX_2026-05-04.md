# RUN — Vision Cleanup Studio UX — 2026-05-04

TASK_ID: `CV1-V2-CATALOG-VISION-CLEANUP-001-R`
EXECUTE_DELEGATION: `foodking-complex-implementer`
STATUS: PASS

## 3 Fix Appliqués

1. P2 add category: le bouton `catalog-studio-add-category` appelle désormais `onAddCategoryClick`, ouvre le formulaire, scroll vers `categoryQuickForm`, puis focus `categoryQuickFormNameInput`.

2. P3 quick-create produit universel: le bouton `catalog-studio-add-product` n'est plus désactivé sur "Toutes les catégories"; le formulaire affiche un dropdown catégorie obligatoire quand `selectedCategoryId` est absent; `buildQuickProductPayload()` et `createProduct()` utilisent `productQuickForm.categoryId || selectedCategoryId`.

3. P5/P8 stock dashboard: le lien `catalog-studio-stock-link` pointe vers la route SPA nommée `admin.stock.rupture`.

## Extraits Diff

```diff
- @click="showCategoryQuickForm = !showCategoryQuickForm"
+ @click="onAddCategoryClick"
```

```diff
- :disabled="!selectedCategoryId" data-testid="catalog-studio-add-product"
- @click="showProductQuickForm = !showProductQuickForm"
+ data-testid="catalog-studio-add-product"
+ @click="onAddProductClick"
```

```diff
- :to="{ name: 'admin.items.list', query: { focus: 'availability' } }"
+ :to="{ name: 'admin.stock.rupture' }"
```

## Tests

- `npx vitest run tests/js/catalogStudioAddCategoryUx.spec.js tests/js/catalogStudioQuickCreateUniversal.spec.js` → PASS, 2 files, 9 tests.
- `npx vitest run` → PASS, 175 files, 1109 tests passed, 2 skipped.

## Invariants

- Pricing SSOT: non touché.
- OrderStatus: non touché.
- `branch_id`: non touché.
- Dispatch after commit: non touché.
- OrderService / FrontendOrderService symmetry: non touché.
