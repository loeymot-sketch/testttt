# Ultra Audit — Synchronisation Catalogue / Stock POS + Borne

Date: 2026-04-27  
Mandat: verifier et securiser la synchronisation produits, categories, supplements, options, rupture stock entre centrale/admin, POS et borne kiosk.  
Verdict: `PASS_WITH_RESIDUAL_GATES`

## Résumé Exécutif

La synchronisation critique est maintenant verrouillee sur trois niveaux:

1. Projection UI: la borne lit en priorite `/api/frontend/menu`, scoping branche derive de `KioskMachine`, avec `item_branch_availability`.
2. Projection POS: le POS demande `surface=pos` et transmet `branch_id`; `SimpleItemResource` expose maintenant l'etat effectif `is_available`.
3. Backend SSOT: quote/commande rejettent les articles inactifs, globalement indisponibles, en rupture branche, ainsi que les variations/supplements inactifs ou caches pour la surface.

Le point important: meme si une UI est stale, le backend bloque maintenant la commande avant creation. La borne et le POS sont aussi mieux synchronises visuellement.

## Matrice Technique

| Domaine | Source de vérité | POS | Borne | Backend commande | Statut |
| --- | --- | --- | --- | --- | --- |
| Categories | `item_categories.status`, `channels` | `PosCategoryController` filtre `channels null/pos` | `/frontend/menu` filtre `channels null/kiosk` | N/A | OK |
| Produits actifs | `items.status` | `admin/item?status=5&surface=pos` | `/frontend/menu` filtre `status=ACTIVE` | `AvailabilityService::assertItemsOrderableForBranch` rejette inactive | OK |
| Disponibilite globale | `items.is_available` | `SimpleItemResource.is_available` | `/frontend/menu.items[].is_available` | rejet 422 si false | OK |
| Rupture branche | `item_branch_availability` | `branch_id` overlay dans `ItemService::simpleList` | `KioskMenuService` branche machine | rejet 422 avec lock si commit | OK |
| Supplements | `item_extras.status`, `visible_on` | details POS `surface=pos` | details kiosk `surface=kiosk` | `PricingService` rejette inactive/hidden | OK |
| Variations | `item_variations.status`, `visible_on` | details POS `surface=pos` | details kiosk `surface=kiosk` | `PricingService` rejette inactive/hidden | OK |
| Prix | DB + `PricingService` | quote POS + commit recalcules | quote kiosk + commit recalcules | client price ignore | OK |
| Cache borne | `kiosk.menu.branch.{id}` | N/A | listener invalidation sur `ItemAvailabilityChanged` | N/A | OK |
| Temps reel | `ItemAvailabilityChanged` + outbox | POS prune/greyout live | kiosk prune/greyout live | event apres commit | OK |
| Compteurs stock | `max_daily_qty`, `daily_consumed_qty` | via `AvailabilityService` | via menu refresh/event | decrement/release service | OK partiel |

## Corrections Appliquées

### 1. Verrou serveur article

Fichier: `app/Services/Menu/AvailabilityService.php`

Avant: `assertItemsOrderableForBranch()` ne regardait que `item_branch_availability`. Un ID d'article inactif ou `items.is_available=false` pouvait encore passer si aucune ligne branche n'existait.

Apres:
- article introuvable => 422;
- `items.status != ACTIVE` => 422;
- `items.is_available=false` => 422;
- rupture branche conservee avec `lockForUpdate()` au commit.

### 2. Verrou serveur supplements / variations

Fichier: `app/Services/Pricing/PricingService.php`

Avant: les IDs de variations/extras etaient charges par ID, puis prices. Un client pouvait forger un supplement inactive ou cache hors surface.

Apres:
- variation introuvable/inactive/cachee pour `pos|kiosk|web` => 422;
- supplement introuvable/inactif/cache pour `pos|kiosk|web` => 422;
- le pricing SSOT reste la seule source de prix.

### 3. Borne: menu machine-scopé comme source primaire

Fichiers:
- `resources/js/store/modules/kioskMenu.js`
- `app/Services/Kiosk/KioskMenuService.php`
- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue`

Avant: le store borne chargeait encore `frontend/item-category` + `frontend/item` pour categories/items, puis `/frontend/menu` seulement pour promos/flags. Ces endpoints legacy ne portaient pas la rupture branche machine dans la grille.

Apres:
- `kioskMenu.fetchMenu()` appelle d'abord `frontend/menu`;
- `/frontend/menu` expose aussi les champs UI necessaires: `item_category_id`, `convert_price`, `currency_price`, `thumb`, `image`, `offer`, `variations/extras` enrichis;
- la grille borne bloque les produits `is_available=false` ou status inactive.

### 4. POS: projection branche et surface

Fichiers:
- `resources/js/components/admin/pos/PosComponent.vue`
- `app/Services/ItemService.php`
- `app/Http/Resources/SimpleItemResource.php`
- `app/Http/Controllers/Admin/PosCategoryController.php`

Avant:
- le POS listait `admin/item` sans `surface=pos` et sans `branch_id`;
- la resource simple n'exposait pas `is_available`;
- les categories POS n'appliquaient pas `channels`.

Apres:
- le POS demande `surface=pos` et, apres resolution defaultAccess, `branch_id`;
- `ItemService::simpleList()` applique un overlay `item_branch_availability`;
- `SimpleItemResource` expose `is_available` et `availability_reason`;
- `PosCategoryController` filtre `channels null/pos`.

## Tests Exécutés

PHP:
- `php artisan test tests/Feature/Menu/OrderRejectsUnavailableBranchItemTest.php` -> 3 PASS
- `php artisan test --filter='MenuProjectionServiceTest|InvalidateKioskMenuCacheListenerTest|CacheInvalidationTest|AvailabilityControllerTest|OrderRejectsUnavailableBranchItemTest'` -> 27 PASS
- `php artisan test --filter='KioskFullFlowE2ETest|KioskQuoteIntegrityTest|KioskQuoteTokenRequiredOnCommitTest|PosKioskPricingParityTest|PosPricingSsotProofTest|FrontendOrderTest|QuoteTamperTest|QuoteReplayIdempotencyTest|QuoteExpirationTest'` -> 20 PASS
- `php artisan test tests/Feature/KioskPhase1/KioskEndpointsTest.php --filter='menu'` -> 5 PASS
- `php artisan test --filter='SsotInjectionHardeningTest|MenuControllerRateLimitTest'` -> 8 PASS
- `php artisan test tests/Feature/Menu/AdminItemBranchAvailabilityProjectionTest.php` -> 1 PASS

JS / build:
- `npx vitest run tests/js/KioskCategoriesRestyle.spec.js tests/js/posItemAvailabilityHandler.spec.js tests/js/adminAvailabilityToggle.spec.js tests/js/kioskOfflineQueueV2.spec.js` -> 41 PASS
- `npx vitest run tests/js/KioskWizard.spec.js tests/js/kioskWizardNavigation.spec.js tests/js/kioskDrinkAddons.spec.js` -> 107 PASS
- `npm run production` -> PASS

Static:
- `php -l` sur les fichiers PHP modifies -> PASS
- `git diff --check` sur le perimetre modifie -> PASS

## Limites / Risques Résiduels

1. `bash .cursor/hooks/safety-check.sh` reste bloque par un etat preexistant: `[HALT] Frozen zone staged: app/Services/OrderService.php`. Je n'ai pas revert ni touche ce staging.
2. Les tests locaux SQLite ne prouvent pas completement `whereJsonContains(channels, 'pos')`; ce contrat est deja documente comme MySQL-only dans `FrontendSurfaceFilteringTest`. La CI MySQL doit rester obligatoire pour le filtre `channels`.
3. Il n'existe pas encore de stock branche par supplement individuel (`item_extra_branch_availability`) ni par variation. Le systeme garantit aujourd'hui: active/inactive, visible_on, prix SSOT, item-level branch rupture. Si tu veux "Cheddar en rupture sur branche A mais disponible branche B" independamment du produit, il faut une mission schema dediee avec gate.
4. Je n'ai pas lance `php artisan test` complet a cause de l'etat global connu du depot (D-M13/gouvernance/frozen staged). Le perimetre catalogue/stock critique a ete valide par suites ciblees.

## Conclusion

La promesse "si c'est en rupture ou desactive cote centrale/POS, la borne ne doit pas le vendre" est maintenant couverte par:

- projection borne machine-scopée;
- projection POS branche-scopée;
- invalidation cache/event;
- rejet serveur sur quote/commit;
- tests sentinelles.

Le seul manque fonctionnel restant n'est pas un bug de sync actuel mais une fonctionnalite de stock plus fine: disponibilite par supplement/variation et par branche. A traiter separement si le business veut piloter les boissons/sauces/supplements comme stock atomique.
