# Gate Brief — CV1-V1-PIVOT-INGREDIENT-AVAILABILITY-COLUMNS — 2026-05-04

## Trigger
**Hard Gate — Schema Migration**
- Ajout colonnes sur `item_attributes` :
  - `is_available` BOOLEAN DEFAULT TRUE.
  - `unavailable_reason` VARCHAR(64) NULL.
- Ajout colonnes identiques sur `item_extras`.
- Aucune modif `item_addons` (cascade naturelle via `addon_item_id → items`).

## Affected Subsystems
- BDD : `item_attributes`, `item_extras` (catalogue global).
- Backend : `ChoiceAvailabilityResolver`, `IngredientService` (NEW), `IngredientAvailabilityService` (NEW), `ComposerProfileProjection`.
- Frontend : `IngredientListComponent.vue` (NEW), `IngredientAvailabilityToggleComponent.vue` (NEW).

## Invariants at Risk
- I3 `branch_id` isolation : **NON** — V1 mono-filiale décidée Cycle 0. V2 ajoutera `ingredient_branch_availability` sœur.
- I1 pricing SSOT : non touché (colonnes booléennes).
- I4 dispatch after commit : event `IngredientAvailabilityChanged` Cycle 3 utilisera trait `DispatchableAfterCommit`.

## Decision Required
Approuver l'ajout des 4 colonnes (2 par table × 2 tables).

## Options
1. **Approuver Option I.2** (vue agrégée + colonnes minimales sur tables existantes) — recommandé.
2. Approuver Option I.1 (nouvelle table `ingredients` + migration consolidation) — XL, NON recommandé.
3. Approuver Option I.3 (refactor complet, suppression progressive) — XXL, NON recommandé.
4. Annuler le concept Ingrédient en V1 (le repousser V1.5).

## Approval

[x] **Approved — option selected: 1 (Option I.2)**
- Approuvé par : utilisateur (humain) via message conversation 2026-05-04 11:51 UTC+2.
- Délégation explicite à Cursor Claude (orchestrateur).
- Date approbation : 2026-05-04 11:51 UTC+2.

## Resumption Protocol
Cycle 1 démarre avec les 4 colonnes (deux migrations distinctes pour traçabilité rollback indépendante).
