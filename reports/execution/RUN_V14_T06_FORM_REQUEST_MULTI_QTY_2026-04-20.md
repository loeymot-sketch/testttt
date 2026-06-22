# RUN — V14 T06 — Form Request multi-variation validation

**Date :** 2026-04-20  
**Ticket :** `V14_05_T06_FORM_REQUEST_MULTI_QTY`

## Form Requests modifiées

| Fichier | Changement |
|---------|------------|
| `app/Http/Requests/OrderRequest.php` | Trait `ValidatesOrderItemVariations` + appel `validateOrderItemVariationsAfter()` dans `withValidator` (`after`) |
| `app/Http/Requests/PosOrderRequest.php` | Idem |
| `app/Http/Requests/TableOrderRequest.php` | Idem |
| `app/Http/Requests/Kiosk/PricingPreviewRequest.php` | Trait + `withValidator` (`after`) ; règle `items.*.item_variations.*.quantity` (sometimes, nullable, integer, min:1) |

## Nouveaux fichiers

- `app/Rules/MultiVariationConstraint.php` — logique min_select / max_select / allow_repeat ; chargement groupé via `validateCollectionKeyedByItemIndex()` (deux `whereIn` : `item_variations`, puis `item_attributes`).
- `app/Http/Requests/Concerns/ValidatesOrderItemVariations.php` — factorise le hook `after` (JSON string ou tableau `items`).

`app/Rules/ValidJsonOrder.php` : **non modifié** (validation structurelle inchangée ; variations traitées dans le hook `after`).

## Règle — résumé comportement

- Entrée : lignes d’items avec `item_variations` au format SSOT `[{ id, quantity? }]` ; `quantity` absente ⇒ 1.
- IDs de variation inconnus : ignorés pour cette règle (pas d’erreur min/max/repeat dérivée d’eux).
- Réponses 422 kiosk preview : `errors` inclut la clé plate `items.N.item_variations` (tableau de messages), conforme au contrat JSON du `PricingPreviewRequest::failedValidation`.
- **Laravel 9** : pas d’interface `ValidationRule` (introduite en L10+) ; la classe est un utilitaire invoqué depuis le hook `after`, pas une rule enregistrée dans le tableau `rules`.

## Traductions

Clés ajoutées dans `lang/{fr,en,ar}/validation.php` : `multi_variation.min`, `multi_variation.max`, `multi_variation.no_repeat`.

## Tests

```text
php artisan test tests/Feature/MultiVariationValidationTest.php
# Tests: 8 passed
```

Régression filtre demandée :

```text
php artisan test --filter='Multi|Pricing|OrderItem|FrontendOrder|PosOrder|ItemAttribute'
# Tests: 117 passed
```

## N+1 / requêtes SQL

Pour un payload donné, `validateCollectionKeyedByItemIndex()` :

1. Agrège tous les `id` de variations présents sur toutes les lignes.
2. Exécute **un** `SELECT` sur `item_variations` (`whereIn` ids).
3. Exécute **un** `SELECT` sur `item_attributes` (`whereIn` sur les `item_attribute_id` distincts).
4. Boucle en mémoire par index de ligne.

Aucune requête par variation dans la boucle. Pas d’exécution Debugbar dédiée dans ce RUN ; la structure du code garantit le plafond ci-dessus lorsque des IDs sont présents (0 requête si aucun id valide collecté).

## Defense-in-depth

Aucune modification de `app/Services/Pricing/PricingService.php` ni des services de commande : la validation SSOT existante (`assertVariationConstraints` / exceptions 422 côté calcul) reste le garde-fou en aval du Form Request.
