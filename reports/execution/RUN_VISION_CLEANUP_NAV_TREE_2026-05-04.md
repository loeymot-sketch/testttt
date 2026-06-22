# RUN — CV1-V2-CATALOG-VISION-CLEANUP-001 Lot T — Nav tree + stock route

Date: 2026-05-04  
TASK_ID: CV1-V2-CATALOG-VISION-CLEANUP-001-T

## Tâche 1 — Articles legacy caché (sidebar DB `url=items`)

- `resources/js/config/v1-hidden-modules.js` : export `V1_HIDDEN_BACKEND_MENU_URLS = ['items']`.
- `BackendMenuComponent.vue` : `menusForSidebar` conserve le bloc lorsqu’il a des enfants (virtuels Studio + attributs) ; `showSidebarParentNavRow` masque uniquement la ligne parent « Articles » (`/admin/items`), pas les lignes enfants.

## Tâche 2 — « Liste Produits » → « Catalogue »

- Enfant virtuel `language: 'catalog'` (label `menu.catalog`).
- Meta breadcrumb Studio : `breadcrumb: 'catalog'` dans `itemRoutes.js`.

## Tâche 3 — Paramètres → Catégories

- RAS : `settings.item-categories` déjà dans `V1_HIDDEN_MENU_MODULES` ; `MenuComponent.vue` ligne Catégories protégée par `v-if="!isSettingHidden('itemCategories')"`.

## Tâche 4 — Route SPA `/admin/stock/rupture`

- Nouveau module `resources/js/router/modules/stockRoutes.js`, enregistré dans `resources/js/router/index.js` après `itemRoutes`.

## Tâche 5 — i18n `menu.catalog` + `menu.stock_rupture`

- Ajoutés dans `resources/js/languages/fr.json`, `en.json`, `de.json`, `bn.json`, `ar.json` (la clé existante `menu.product_list` est conservée).

## Tâche 6 — Redirect legacy `/admin/items`

- Route parent `admin.items` : `redirect: { name: 'admin.items.studio' }`.  
- **`admin.items.list`** et flux `admin.items.create` / `CatalogStudioComponent` inchangés (liste legacy toujours nommée et utilisable).

## Tests

- Ciblés Lot T : **13 PASS** (`v1HiddenMenuModules` + trois nouveaux specs).
- Vitest globale : **1117 PASS, 2 SKIP** (sans régression sur la base 1109+8).

## Statut

**PASS**
