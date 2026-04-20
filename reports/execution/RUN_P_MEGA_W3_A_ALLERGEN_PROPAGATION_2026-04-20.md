# RUN — P_MEGA_W3_A_ALLERGEN_PROPAGATION_2026-04-20

EXECUTE_DELEGATION: foodking-routine-implementer

## Résumé

- **Vitest** `kioskAllergenMerge.spec.js` : **8/8** verts.
- **Vitest global** : **529/529** verts (61 fichiers).
- **PHPUnit** `OrderAllergenSnapshotComposedTest` : **FAILED** (attendu comme finding sentinelle).

## FINDING — backend snapshot (`FINDING_BACK_DEFERRED`)

`OrderAllergenSnapshotComposedTest::test_sentinel_base_item_plus_extra_with_milk_should_snapshot_lait` échoue : `allergens_snapshot` effectif `[]` au lieu de `['lait']`. Le snapshot backend ne fusionne pas encore les allergènes des extras (voir `OrderItemAllergenSnapshot::resolveSnapshot`). Verdict cycle W3.A : **CLOSED PASSED** côté front (Vitest), dette back documentée.

## Fichiers livrés

- `resources/js/helpers/kioskFilters.js` — `mergeAllergens`, `findVariationObjectById`, `findExtraObjectById`, ordre canonique.
- `resources/js/components/frontend/kiosk/ds/KsAllergenBadge.vue` — props `item`, `selections`, `effectiveAllergens`.
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` — `allergenBadgeSelections`, badge header.
- `resources/js/components/frontend/kiosk/KioskCartComponent.vue` — badge ligne panier + résolution catalogue via `allItems`.
- `tests/js/kioskAllergenMerge.spec.js` — 8 cas P-MEGA-08.
- `tests/Feature/Orders/OrderAllergenSnapshotComposedTest.php` — sentinelle PHPUnit.

## Risque résiduel

Faible : dépendance du panier à `kioskMenu.allItems` pour enrichir ids → objets extras/variations (allergènes si exposés par l’API).
