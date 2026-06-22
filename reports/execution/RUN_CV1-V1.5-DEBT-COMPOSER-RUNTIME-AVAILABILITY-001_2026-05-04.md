# RUN — CV1-V1.5-DEBT-COMPOSER-RUNTIME-AVAILABILITY-001 — 2026-05-04

EXECUTE_DELEGATION: foodking-complex-implementer

## Scope

- `app/Services/Stock/ChoiceAvailabilityResolver.php`
- `app/Services/Composer/ComposerProfileProjection.php`
- `tests/Feature/Stock/ChoiceAvailabilityResolverVariationIngredientRuptureTest.php`
- `tests/Feature/Composer/ComposerProfileProjectionVariationRuptureTest.php`

## Option retenue

Option A retenue.

Justification : `availabilityFromLevel(null)` conserve le comportement legacy `is_available=true`, donc consulter systématiquement `$choiceAvailability` pour `item_attribute` et `extra_group` propage `ingredient_rupture` sans rendre indisponibles les steps non-stockables dépourvus de `StockLevel`. `Item::variations()` eager-load déjà `itemAttribute`, donc aucun N+1 détecté sur le chemin standard.

## Changements

- `ChoiceAvailabilityResolver::snapshotForItems()` appelle désormais `availabilityForVariation()` pour les variations.
- `ChoiceAvailabilityResolver::assertSelectionsOrderable()` bloque aussi les variations dont l'`ItemAttribute::is_available` est faux.
- Nouvelle méthode `availabilityForVariation()` : priorité `ingredient_rupture` via `ItemAttribute::is_available`, puis fallback stock legacy.
- `ComposerProfileProjection` applique la disponibilité resolver aux sources `item_attribute` et `extra_group` même quand `stockable_choices=false`.
- Aucun changement frontend, addon, pricing, dispatch, schema, auth ou branch scope.

## Comportement avant/après

- Avant : `ItemAttribute::is_available=false` restait invisible côté wizard POS/kiosk quand le step `item_attribute` avait `stockable_choices=false`.
- Après : la variation est projetée avec `is_available=false` et `unavailable_reason=ingredient_rupture`, y compris pour les steps non-stockables. La propagation runtime bénéficie de l'invalidation cache déjà livrée par H3 via `IngredientAvailabilityChanged`.

## Validation

```text
php artisan test tests/Feature/Stock/ChoiceAvailabilityResolverVariationIngredientRuptureTest.php --colors=never
PASS — 5 passed

php artisan test tests/Feature/Composer/ComposerProfileProjectionVariationRuptureTest.php --colors=never
PASS — 3 passed

php artisan test --filter="ChoiceAvailability|ComposerProfileProjection" --colors=never
PASS — 11 passed

php artisan test --colors=never
PASS — 1421 passed, 24 skipped

npx vitest run
PASS — 193 files, 1157 passed, 2 skipped

bash .cursor/hooks/post-execute.sh
PASS — php artisan test --stop-on-failure passed; lint skipped (no script); playwright skipped
```

## Invariants checklist

- I1 Pricing SSOT : préservé, aucune logique de prix ajoutée.
- I2 OrderStatus enum : non touché.
- I3 `branch_id` isolation : préservé, seules les lectures existantes par branche de `StockLevel` sont utilisées.
- I4 Dispatch after commit : non touché.
- I5 OrderService / FrontendOrderService symmetry : non touché.
- I6 Frozen zones : aucune édition.

## Follow-up V1.5b éventuels

- N+1 `itemAttribute` : non détecté dans le chemin standard, car `Item::variations()` eager-load `itemAttribute`. À surveiller seulement si un appelant injecte manuellement des variations non chargées.
- Cache invalidation branch-scoped non granulaire : dette R-T3 héritée, hors scope D1.
