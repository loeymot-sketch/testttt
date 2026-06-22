# Re-vérification — Catalogue / Categories / Produits / Stock Central → POS + Borne

Date: 2026-04-27  
Portée: deuxième audit technique après correction de synchronisation catalogue/stock.  
Verdict: `PASS`

## Ce qui a été revérifié

1. Action centrale de rupture stock via endpoint admin.
2. Persistance dans `item_branch_availability`.
3. Invalidation du cache borne `kiosk.menu.branch.{branch_id}`.
4. Projection borne `/api/frontend/menu` avec `is_available=false`.
5. Projection POS `/api/admin/item?branch_id=...` avec `is_available=false`.
6. Rejet backend d'une quote kiosk qui tente de commander l'article en rupture.

## Nouvelle sentinelle ajoutée

Fichier: `tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php`

Test: `central_stock_toggle_syncs_to_kiosk_pos_and_order_guard`

Ce test simule le flux complet:

- création branche + catégorie + produit;
- création machine borne liée à la branche;
- mise en cache volontaire d'un menu borne stale;
- `POST /api/admin/menu/availability/toggle` vers rupture `stock_rupture`;
- vérification DB;
- vérification cache borne invalidé;
- `GET /api/frontend/menu` voit le produit indisponible;
- `GET /api/admin/item?branch_id=...` voit le produit indisponible côté POS;
- `POST /api/frontend/order/quote` est rejeté en 422.

## Résultats de tests

PHP ciblé:

- `php artisan test tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php` -> 1 PASS
- `php artisan test --filter='CatalogStockCentralSyncEndToEndTest|AdminItemBranchAvailabilityProjectionTest|OrderRejectsUnavailableBranchItemTest|AvailabilityControllerTest|InvalidateKioskMenuCacheListenerTest|CacheInvalidationTest|KioskEndpointsTest'` -> 32 PASS

JS ciblé:

- `npx vitest run tests/js/posItemAvailabilityHandler.spec.js tests/js/adminAvailabilityToggle.spec.js tests/js/KioskCategoriesRestyle.spec.js tests/js/kioskOfflineQueueV2.spec.js` -> 41 PASS

Static:

- `php -l tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php` -> PASS
- `git diff --check` sur les fichiers de cette passe -> PASS

## Points techniques confirmés

| Point | Preuve | Verdict |
| --- | --- | --- |
| Central peut marquer un produit en rupture branche | `AvailabilityController::toggle` + sentinelle E2E | OK |
| La rupture est branch-scopée | `branch_id` persisté dans `item_branch_availability` | OK |
| La borne ne garde pas un cache stale | cache `kiosk.menu.branch.{id}` invalidé | OK |
| La borne affiche/reçoit la rupture | `/api/frontend/menu` retourne `is_available=false` + raison | OK |
| Le POS reçoit la rupture | `/api/admin/item?branch_id=...` retourne `is_available=false` | OK |
| Le backend bloque les commandes stale/forgées | quote kiosk rejetée 422 | OK |
| Events et outbox restent compatibles | `AvailabilityControllerTest` + `InvalidateKioskMenuCacheListenerTest` | OK |

## Limites non bloquantes

- Le test local SQLite ne valide pas `surface=pos` avec `whereJsonContains`; ce point reste un contrat CI MySQL déjà identifié dans `FrontendSurfaceFilteringTest`.
- `bash .cursor/hooks/safety-check.sh` reste bloqué par un état préexistant hors périmètre: `Frozen zone staged: app/Services/OrderService.php`.
- `git diff --check` global sur `reports/audit/` tombe sur un whitespace préexistant dans `_TERMINAL_CONTEXT_BRIEF.md`; le diff-check limité aux fichiers de cette passe est PASS.

## Conclusion

La synchronisation catalogue/stock centrale vers POS et borne est techniquement assurée sur le flux critique V1:

centrale rupture produit -> DB branche -> cache borne invalidé -> borne voit rupture -> POS voit rupture -> backend refuse la vente.

Le système peut donc continuer vers l'étape suivante, avec une note d'amélioration future uniquement si le business veut gérer des ruptures par supplément/variation de façon indépendante de l'article.
