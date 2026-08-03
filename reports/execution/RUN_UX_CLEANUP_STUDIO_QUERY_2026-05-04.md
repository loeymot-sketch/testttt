# RUN_UX_CLEANUP_STUDIO_QUERY_2026-05-04

## Sommaire

Écart Lot O (redirect `admin.settings.itemCategory.show` → `admin.items.studio?item_category_id=…`) comblé : `CatalogStudioComponent` applique `item_category_id` au montage via `selectCategory` avant `refreshData()` ; la grille filtre déjà côté client (`filteredProducts`).

## Modification

Extrait `mounted()` dans `resources/js/components/admin/items/CatalogStudioComponent.vue` :

```js
mounted() {
    const queryCategoryId = this.$route?.query?.item_category_id;
    if (queryCategoryId) {
        const numericId = parseInt(queryCategoryId, 10);
        if (!Number.isNaN(numericId) && numericId > 0) {
            this.selectCategory(numericId);
        }
    }
    this.refreshData();
},
```

## Test

- `tests/js/catalogStudioCategoryQuery.spec.js` : **2 PASS** (sentinel sur le SFC : query + `selectCategory(numericId)` avant `refreshData`, garde `parseInt` / `numericId > 0` / `if (queryCategoryId)`).
- Note : mont Vue complet évité (import `LoadingComponent` sans extension non résolu par Vite dans ce runner) ; aligné sur `catalogStudioRouting.spec.js`.

## Vitest globale

`npx vitest run` : **172 fichiers, 1093 tests PASS**, 2 skipped, **0 échec**.

## Statut

**PASS**
