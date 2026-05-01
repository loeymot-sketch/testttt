# Plan Train 1/2 - Catalogue projection + Dashboard control plane V1 - 2026-04-27

Scope:
- Finir Train 2 PH2-04.
- Livrer Dashboard V1 utilisable pour catalogue/produit/categorie/prix/offres/disponibilite.
- Ne pas introduire stock quantitatif dans ce train.

## 1. Mission C1 - Consumer migration MenuProjectionService

TASK_ID: `FK-REM-C1-MENU-PROJECTION-CONSUMERS`

Objectif:
- `KioskMenuService` devient wrapper de `MenuProjectionService`.
- POS menu/list consomme la projection ou un endpoint compatible.
- `X-Menu-Version` est expose.

Allowlist:

```text
app/Services/Kiosk/KioskMenuService.php
app/Services/Menu/MenuProjectionService.php
app/Http/Controllers/Frontend/MenuController.php
app/Http/Controllers/Admin/MenuProjectionController.php
resources/js/store/modules/kioskMenu.js
resources/js/store/modules/posOrder.js
tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php
tests/Feature/Catalog/CatalogVersioningHttpHeadersSentinelTest.php
tests/js/kioskCatalogVersionSyncSpec.js
reports/audit/FK_REM_C1_MENU_PROJECTION_CONSUMERS.md
```

Interdictions:
- Pas de logique prix frontend.
- Pas de modification `PricingService`.
- Pas de migration DB.

Validation:

```bash
php artisan test tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php
php artisan test tests/Feature/Http/Admin/MenuProjectionControllerTest.php
php artisan test tests/Feature/KioskPhase1/KioskEndpointsTest.php
npx vitest run tests/js/kioskMenuStore.spec.js tests/js/kioskMenuCache.spec.js
```

Definition of Done:
- Kiosk boot menu et POS list utilisent les memes champs contractuels.
- `snapshot_version`/header version visible.
- Kiosk stale/reconnect peut detecter version superieure.

## 2. Mission C2 - Category branch scope ADR + implementation V1

TASK_ID: `FK-REM-C2-CATEGORY-BRANCH-SCOPE`

Decision recommandee:
- Categories globales par defaut.
- Visibilite par branche via pivot ou channel/sort existant.
- Pas de duplication categories par branche.

Gate:
- `HG-CATEGORY-BRANCH-SCOPE`.

Allowlist apres gate:

```text
docs/decisions/D-CATEGORY-BRANCH-SCOPE-2026-04-27.md
app/Services/ItemCategoryService.php
app/Http/Requests/ItemCategoryRequest.php
app/Http/Resources/ItemCategoryResource.php
app/Services/Menu/MenuProjectionService.php
tests/Feature/Catalog/CategoryBranchVisibilitySentinelTest.php
tests/Feature/Requests/ItemCategoryRequestTest.php
```

Validation:
- Category hidden branch A reste visible branch B si configure.
- Kiosk/POS projection respecte branch_id.
- Admin global voit tout; manager branche ne modifie que son scope.

## 3. Mission D1 - Dashboard authz catalog/ops

TASK_ID: `FK-REM-D1-DASHBOARD-AUTHZ-CATALOG-OPS`

Objectif:
- Permissions claires pour modifier catalogue, prix, photos, disponibilite, stock.

Roles recommandes:

| Role | Catalogue | Prix | Stock availability | Stock quantitatif futur | Caisse |
| --- | --- | --- | --- | --- | --- |
| Admin global | write | write | write | write | all |
| Branch manager | branch write | branch write if allowed | write | write | all branch |
| POS operator | read | no write | override vente audite only | no | sell |
| Kiosk | read only | no | no | no | order |

Allowlist:

```text
database/seeders/PermissionSeeder.php
docs/AUTHZ_MATRIX.md
routes/api.php
tests/Feature/Sentinels/DashboardCatalogAuthzSentinelTest.php
tests/Feature/Sentinels/DashboardStockAuthzSentinelTest.php
```

Interdictions:
- Pas de reuse permission `pos` pour tout le dashboard.
- Pas de route write accessible kiosk.

## 4. Mission D2 - Catalog Manager Shell

TASK_ID: `FK-REM-D2-CATALOG-MANAGER-SHELL`

Objectif UI:
- Ecran admin unique, dense et exploitable.
- Sidebar categories.
- Grille produits.
- Drawer edition.
- Badges disponibilite/stock.
- Recherche/filtre.

Allowlist:

```text
resources/js/components/admin/catalog/CatalogManagerComponent.vue
resources/js/components/admin/catalog/CategorySidebar.vue
resources/js/components/admin/catalog/ItemGrid.vue
resources/js/components/admin/catalog/ItemEditDrawer.vue
resources/js/store/modules/catalogManager.js
resources/js/router/modules/catalogRoutes.js
tests/js/catalogManagerComponentSpec.js
```

UX contract:
- Pas de landing page.
- Interface outil directe.
- Pas de carte dans carte.
- Actions avec icons.
- Texte compact, pas marketing.

Validation:

```bash
npx vitest run tests/js/catalogManagerComponentSpec.js
npm run production
```

## 5. Mission D3 - Product composer builder V1

TASK_ID: `FK-REM-D3-PRODUCT-COMPOSER-BUILDER-V1`

Objectif:
- Creer/modifier produit avec:
  - nom FR;
  - categorie;
  - prix base;
  - image;
  - variations;
  - extras;
  - addons/offres;
  - allergenes;
  - canaux POS/Kiosk;
  - ordre affichage.

Allowlist:

```text
resources/js/components/admin/catalog/ProductComposer.vue
resources/js/components/admin/catalog/VariationEditor.vue
resources/js/components/admin/catalog/ExtraEditor.vue
resources/js/components/admin/catalog/AddonEditor.vue
resources/js/components/admin/catalog/AllergenEditor.vue
resources/js/store/modules/catalogManager.js
app/Http/Requests/ItemRequest.php
app/Http/Requests/ItemAttributeRequest.php
app/Http/Resources/ItemResource.php
tests/Feature/Requests/ItemRequestTest.php
tests/Feature/ItemAttributeComposerResourceTest.php
tests/js/productComposerBuilderSpec.js
```

Interdictions:
- Ne pas modifier `PricingService` sauf bug prouve.
- Ne pas calculer prix final dans le builder.

Definition of Done:
- Un produit "offre/menu" peut etre cree comme produit normal avec addons/options.
- Apres save, POS/kiosk voient le produit selon canaux.
- Image refresh fonctionne sans F5 force.

## 6. Mission D4 - Availability Manager V1

TASK_ID: `FK-REM-D4-AVAILABILITY-MANAGER-V1`

Objectif:
- Dashboard permet d'activer/desactiver produit par branche avec raison.
- Kiosk garde item visible avec etat indisponible/rupture.
- POS voit badge et bloque/override audite si existant.

Allowlist:

```text
resources/js/components/admin/stock/AvailabilityManagerComponent.vue
resources/js/store/modules/stockManager.js
app/Services/Menu/AvailabilityService.php
app/Http/Controllers/Admin/AvailabilityController.php
tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php
tests/js/stockManagerComponentSpec.js
tests/js/kioskRuptureUiContractSpec.js
```

Validation:
- Toggle admin -> POS + kiosk update.
- Backend quote/commit rejette stale item.
- Branch A ne change pas Branch B.

## 7. Rapport de train attendu

Fichier:

```text
reports/audit/FK_REM_TRAIN1_CATALOG_DASHBOARD_V1_CLOSEOUT_2026-04-27.md
```

Doit contenir:
- toutes missions PASS/REWORK;
- captures ou Playwright si UI livree;
- liste routes/API ajoutees;
- tests executés;
- risques residuels avant Stock V2.
