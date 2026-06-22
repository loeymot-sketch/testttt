# ADR — ItemWizardStepVersion : insert-only history table

| Champ | Valeur |
|---|---|
| Date | 2026-05-04 |
| Statut | Accepted |
| Décideur | Kossay (utilisateur) — décision technique déléguée à Claude orchestrateur, message du 2026-05-03 23:43 UTC+2 |
| Contexte | `docs/gates/GATE_CV1-V2-CATALOG-REWORK-PUBLISHED-SNAPSHOT_2026-05-03.md` |
| Cycle d'exécution | `CV1-V2-CATALOG-REWORK-002` |

## Contexte

`ComposerDiffService` (introduit cycle CV1-V2-CATALOG-REWORK-001) doit calculer le diff entre la version draft d'un `ItemWizardProfile` et sa dernière version publiée. L'ultra-review du 2026-05-03 (`reports/audit/ULTRA_REVIEW_GLOBAL_CATALOG_TREE_2026-05-03.md` R1) a identifié une faille critique : le service lisait `$profile->getAttribute('published_steps_snapshot')`, colonne inexistante en BDD → toujours `null` → diff toujours `is_clean=true` en production (faux positif silencieux).

3 options évaluées dans le gate :

- **A** : ajouter une colonne JSON `published_steps_snapshot` sur `item_wizard_profiles` (mono-version, simple)
- **B** : créer une table d'historique insert-only `item_wizard_step_versions` (multi-versions, append-only)
- **C** : refactor sans DDL (pas de persistance, retourne `snapshot_unavailable`)

## Décision

**Option B** retenue.

## Raisonnement

### Critères utilisateur

> « la solidité soit sur une base solide et bien structuré et bien architecture, c'est-à-dire une architecture qu'il faut la suivre pour avoir un bon base une bonne stabilité et racine comme une arbre vraiment soit bien fait avec beaucoup de raisonnement »

### Cohérence avec patterns FoodKing existants

L'option B s'aligne sur 3 patterns event-sourcing-like déjà en production dans le repo :

1. **`stock_movements`** : table append-only, `idempotency_key` unique, jamais d'UPDATE (`StockService`).
2. **`order_items.composition_snapshot`** : JSON figé à `payment_complete`, jamais altéré (NF525 fiscal compliance).
3. **Outbox pattern** (`outbox_events` + `PersistCatalogChangedToOutbox` + `DispatchDomainEventsJob`) : événements immutables persistés avant broadcast.

Choisir Option A (colonne unique mono-version) aurait introduit un pattern incohérent (mutable vs append-only) dans une zone fonctionnelle qui partage des contraintes proches (auditabilité, NF525, lifecycle).

### Bénéfices secondaires offerts gratuitement par Option B

- **Audit fiscal NF525 long terme** : possibilité de répondre à « que contenait le wizard du produit X au jour J ? ».
- **Rollback de publish** : si un admin publie une v5 fautive, possibilité de revenir à v4 (V2 feature, mais la donnée existe).
- **Comparaison inter-versions arbitraire** : v3 vs v7 = trivial.

### Coût

~1h additionnel vs Option A. Négligeable face à la dette évitée.

## Schéma table

```
item_wizard_step_versions
├─ id (PK)
├─ profile_id (FK item_wizard_profiles, cascadeOnDelete)
├─ version (uint)
├─ snapshot (JSON, array of step rows)
├─ published_at (timestamp)
├─ published_by_id (FK users, nullable, nullOnDelete)
├─ created_at, updated_at

UNIQUE (profile_id, version)
INDEX (published_at)
```

## Cycle de vie

1. **CREATE** : `ComposerProfileService::publish()` insert une row à chaque publish. Dans la même transaction que `UPDATE item_wizard_profiles SET is_published=true`. L'event `ComposerProfileChanged` reste `DispatchableAfterCommit` après l'INSERT (invariant I4 préservé).
2. **READ** : `ComposerDiffService::publishedRowsByKey()` lit la dernière row via `$profile->versions()->orderByDesc('version')->first()`. Si aucune row → premier publish → `is_clean=true`, `added=[]`.
3. **UPDATE** : interdit. `ItemWizardStepVersion::update()` throw `RuntimeException`. Le service publish ne fait jamais d'update.
4. **DELETE** : interdit en direct (cascade SQL via FK seulement, légitime quand le profile parent est supprimé).

## Politique cleanup

- **V1** : aucune. Table strictement insert-only.
- **V2** : à mesurer. Si une instance prod accumule >10k versions par profile, envisager :
  - Soit purge des versions > 365 jours hors NF525 retention period.
  - Soit policy "garde les N dernières versions par profile" (typiquement N=50).
  - Mais ça nécessite une analyse fiscale (durée de rétention NF525).

## Conséquences

### Positives

- ✅ R1 (S2 critique) éliminé : diff opère sur vraie BDD, pas projection synthétique.
- ✅ Pattern cohérent avec stock_movements / composition_snapshot.
- ✅ Phase β1.C4 (Diff Modal Vue côté frontend) débloquée.
- ✅ Audit fiscal NF525 long-terme assuré.
- ✅ Rollback de publish possible (V2 feature).

### Négatives

- ❌ +1 table à maintenir.
- ❌ Croissance linéaire avec le nombre de publishes.
- ❌ Le snapshot dupliquerait des données déjà dans `item_wizard_steps` (acceptable : c'est le but, conserver l'état historique même après modification du draft).

## Tests sentinels

- `tests/Feature/Composer/ItemWizardStepVersionPersistenceTest.php` (4 tests, Lot E) : publish() insère, multi-publish insère séparément, unique constraint, cascade delete.
- `tests/Feature/Composer/ItemWizardStepVersionImmutabilityTest.php` (3 tests, Lot G) : update() throw, cascade delete, snapshot cast array.
- `tests/Feature/Composer/ItemWizardStepVersionUniqueConstraintTest.php` (2 tests, Lot G) : unique (profile_id, version), cross-profile autorisé.
- `tests/Feature/Composer/ComposerDiffServiceProductionPathTest.php` (3 tests, Lot F) : prouve élimination du faux positif.

## Références

- Gate brief : `docs/gates/GATE_CV1-V2-CATALOG-REWORK-PUBLISHED-SNAPSHOT_2026-05-03.md`
- Plan : `plans/PLAN_CV1-V2-CATALOG-REWORK-002_2026-05-03.md`
- Ultra-review trigger : `reports/audit/ULTRA_REVIEW_GLOBAL_CATALOG_TREE_2026-05-03.md` R1
