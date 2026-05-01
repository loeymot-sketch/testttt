# Item Image Catalog Refresh Audit — 2026-04-27

TASK_ID: ITEM-IMAGE-CATALOG-REFRESH-2026-04-27
EXECUTE_DELEGATION: codex-extension
AUDIT_VERDICT: PASS_TARGETED_FIX

## 1. Demande

Verifier et renforcer la gestion des photos produit dans le dashboard central, avec priorite borne :

- le gestionnaire doit pouvoir voir/changer l'image d'un produit,
- l'image doit alimenter le catalogue borne,
- un changement d'image doit etre visible sans attendre un cache stale,
- ne pas casser prix, stock, wizard POS/kiosk.

## 2. Audit avant correction

| Point | Etat trouve |
| --- | --- |
| Upload image creation/update produit | Deja present dans `ItemCreateComponent.vue` + `ItemRequest.php`. |
| Changement image produit existant | Deja present dans `ItemShowComponent.vue`, onglet `Images`, endpoint `/admin/item/change-image/{item}`. |
| Ressources catalogue | `ItemResource`, `SimpleItemResource`, `NormalItemResource` exposent deja `thumb`, `cover`, `preview`. |
| Borne | Consomme les images catalogue via les payloads frontend. |
| Liste admin produits | Ne montrait pas la miniature : le gestionnaire ne voyait pas vite l'image active. |
| Cache/refresh borne apres changement image | Gap reel : `ItemService::changeImage()` remplaçait l'image mais ne dispatchait pas `ItemAvailabilityChanged`, donc cache/snapshot borne pouvaient rester anciens jusqu'au refresh naturel. |
| Permission | `changeImage` etait sous permission large `items`; resserree a `items_edit`. |

## 3. Correction livree

| Fichier | Changement |
| --- | --- |
| `app/Http/Controllers/Admin/ItemController.php` | `changeImage` passe sous permission `items_edit`. |
| `app/Services/ItemService.php` | Apres changement image, refresh du modele puis event global `ItemAvailabilityChanged::fromItem($item, 'full')`. |
| `resources/js/components/admin/items/ItemListComponent.vue` | Ajout d'une colonne miniature produit dans la liste admin. |
| `tests/Feature/Menu/ItemImageCatalogRefreshTest.php` | Sentinel : changement image => event global `full` pour refresh catalogue borne/POS. |

Note scope : `app/Services/ItemService.php` contenait deja des modifications preexistantes dans le worktree sur l'overlay disponibilite par branche. Elles ne sont pas revertees et ne font pas partie de cette correction image.

## 4. Validation

`php artisan test tests/Feature/Menu/ItemImageCatalogRefreshTest.php`

Resultat : 1 PASS.

`php artisan test --filter='BumpMenuSnapshotListenerTest|InvalidateKioskMenuCacheListenerTest|FrontendSurfaceFilteringTest|ItemAttributeComposerResourceTest|ItemAttributeRequestTest'`

Resultat : 9 PASS, 6 SKIPPED attendus. Les skips dependent de MySQL `JSON_CONTAINS` alors que l'environnement local courant est SQLite.

`npx vitest run tests/js/KioskWizard.spec.js tests/js/kioskWizardNavigation.spec.js tests/js/posKioskVariationParity.spec.js`

Resultat : 110 PASS.

`npm run production`

Resultat : PASS.

`git diff --check` cible

Resultat : PASS.

## 5. Impact projet

| Invariant | Resultat |
| --- | --- |
| Backend pricing SSOT | Respecte. Aucun calcul prix ajoute cote frontend. |
| Catalogue central | Ameliore : l'image changee declenche un refresh global `full`. |
| Borne | Ameliore : cache/snapshot menu peuvent etre invalides par l'event existant. |
| POS | Non casse : build et tests JS POS/Kiosk parity passent. |
| Stock | Non touche. |
| Order / Payment / KDS | Non touche directement. |
| D-M13 | Non touche. |

## 6. UX dashboard

Le dashboard produits affiche maintenant une miniature dans la liste. Le changement complet d'image reste disponible sur la fiche produit, onglet `Images`, avec preview avant sauvegarde.

Prochaine amelioration possible : ajouter une action rapide "Changer image" directement depuis la liste, mais ce serait une tranche UI supplementaire. Le besoin critique "voir l'image active + changer depuis le dashboard + refresh borne" est couvert.

## 7. Risque restant

Le build modifie les assets compiles `public/js/pos-app.js`, `public/js/kiosk-shell.js`, `public/mix-manifest.json`. C'est attendu apres modification Vue.

Le safety-check global reste bloque par `app/Services/OrderService.php` staged en zone frozen, preexistant et hors scope de cette mission.
