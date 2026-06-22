# Gate Brief — CV1-V1-PIVOT-WIZARD-CATEGORY-OWNER — 2026-05-04

## Trigger
**Hard Gate — Schema Migration**
- Ajout colonne `item_wizard_profiles.item_category_id` (BIGINT UNSIGNED NULL, FK vers `item_categories(id)` ON DELETE CASCADE, indexée).
- Modification `item_wizard_profiles.item_id` : `NOT NULL` → **NULL**.
- Ajout check constraint `(item_id IS NOT NULL) <> (item_category_id IS NOT NULL)` (XOR).

## Affected Subsystems
- BDD : `item_wizard_profiles` (table existante, prod).
- Backend services : `ComposerProfileService`, `ComposerProfileProjection` (résolution fallback catégorie → item legacy).
- Modèles : `ItemWizardProfile`, `ItemCategory`.

## Invariants at Risk
- I3 `branch_id` isolation : non touché (la table reste catalogue global).
- I1 pricing SSOT : non touché.
- I4 dispatch after commit : non touché.
- I6 frozen zones : non touché.

## Decision Required
Approuver la migration DDL pour permettre au wizard d'avoir un owner polymorphique (catégorie OU item legacy).

## Options
1. **Approuver** Voie A complète (FK catégorie + nullable item_id + check XOR) — recommandé par l'audit.
2. Approuver Voie B (copy on attach) — duplication data, plus de code, NON recommandé.
3. Annuler le pivot V1.

## Approval

[x] **Approved — option selected: 1 (Voie A)**
- Approuvé par : utilisateur (humain) via message conversation 2026-05-04 11:51 UTC+2 : « Là c'est toi le boss c'est toi qui décides, on va si le retour c'est à toi maintenant de tout implémenter ».
- Délégation explicite à Cursor Claude (orchestrateur) pour exécuter le pivot conforme au plan Claude (Opus 4.7 terminal).
- Trace conversation : transcript Cursor agent 2026-05-04.
- Date approbation : 2026-05-04 11:51 UTC+2.

## Resumption Protocol
Cycle 1 (`CV1-V1-PIVOT-FOUNDATIONS-001`) peut démarrer. Migration sera testée avec rollback (`migrate:rollback` + `migrate`) et tests SQLite (check constraint conditionnel via `if (DB::getDriverName() !== 'sqlite')`).
