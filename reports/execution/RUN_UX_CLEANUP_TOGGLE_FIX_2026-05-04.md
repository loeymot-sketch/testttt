# RUN — UX Cleanup Toggle Fix — 2026-05-04

## Sommaire bug
Le toggle "Marquer indisponible" persistait bien l'override par filiale, mais la liste items était rechargée sans `branch_id`, donc l'UI réaffichait la disponibilité globale.

## Cause racine confirmée
Avant, `item/lists` construisait `admin/item` uniquement depuis le payload appelant. Après correction, l'action enrichit la query avec `auth.authBranchId` quand le caller n'a pas fourni de `branch_id`, ce qui permet au backend d'appliquer l'overlay `ItemBranchAvailability`.

## Modifications apportées
- `resources/js/store/modules/item.js` : propagation automatique de `branch_id` depuis le contexte auth, sans écraser une query explicite.
- `resources/js/components/admin/items/AvailabilityToggleComponent.vue` : état optimiste réversible, `lastErrorMessage`, émission `toggle-error`, appel optionnel `appService.alertError`.
- `tests/js/itemListBranchAvailability.spec.js` + `tests/js/availabilityToggleErrorSurfacing.spec.js` : 6 sentinelles Vitest ajoutées.

## Tests
- `npx vitest run tests/js/itemListBranchAvailability.spec.js tests/js/availabilityToggleErrorSurfacing.spec.js` : PASS, 2 files, 6 tests.
- `npx vitest run` : PASS, 171 files, 1091 passed, 2 skipped.
- PHPUnit optionnel non ajouté : le bug et le correctif sont côté propagation Vuex/UX, backend overlay déjà hors scope produit.

## Statut
PASS — branch_id présent dans la query `item/lists`, erreurs toggle surfacées, aucune régression Vitest globale.
