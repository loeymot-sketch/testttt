# Gate Brief — CV1-V2-CATALOG-REWORK-PUBLISHED-SNAPSHOT — 2026-05-03

## Trigger

`ComposerDiffService::projectPublishedProfile()` lit `$profile->getAttribute('published_steps_snapshot')` (`app/Services/Composer/ComposerDiffService.php:132,151`) — colonne **absente du schéma BDD**.

Vérifications croisées :
- `grep -r published_steps_snapshot app/ database/migrations/` → matchs uniquement dans `ComposerDiffService.php` + tests qui injectent via `setAttribute()` en mémoire seule.
- `app/Models/ItemWizardProfile.php:12-19` `$fillable` ne contient **pas** `published_steps_snapshot`.
- Aucune des 12 migrations Catalog/Stock/Wizard n'ajoute cette colonne.

**Conséquence en production** : pour tout profil `is_published=true`, la projection synthétique avec relations vides (`variations`, `extras`, `addons` = `collect()`) produit `publishedByKey === draftByKey` (même collection in-memory). Donc `is_clean=true` est retourné systématiquement → l'admin voit "no changes" alors qu'il vient de modifier des steps. **Faux positif silencieux confirmé.**

Source de la découverte : `reports/audit/ULTRA_REVIEW_GLOBAL_CATALOG_TREE_2026-05-03.md` §3 B3 (R1, sévérité S2 critique, probabilité P3 certaine).

## Affected Subsystems

| Subsystem | Impact |
|---|---|
| Database schema (`item_wizard_profiles`) | DDL (nouvelle colonne ou nouvelle table) |
| `app/Models/ItemWizardProfile.php` | `$fillable` + `$casts` |
| `app/Services/Composer/ComposerProfileService.php` | méthode `publish()` doit persister le snapshot |
| `app/Services/Composer/ComposerDiffService.php` | déjà câblé sur `published_steps_snapshot` (rien à toucher en option A) |
| `tests/Feature/Composer/ComposerDiffServiceTest.php` | migrer du `setAttribute()` mémoire vers vraie persistance DB |
| Phase β1.C4 (Diff Modal Vue) | bloquée tant que ce gate n'est pas clos |

## Invariants at Risk

- **I4 dispatch after commit** : N/A — pas d'event nouveau dispatché.
- **I6 frozen zones** : N/A — Composer/* n'est pas frozen.
- **Schema integrity** : la migration doit être idempotente, backfillable, rollback-safe.
- **NF525 fiscal** : si Option B (table d'historique), envisager de figer le snapshot pour audit fiscal long-terme. Si Option A (colonne JSON), le snapshot est écrasé à chaque publish → pas d'historique mais conforme à la promesse "diff entre published et draft".

## Decision Required

Quelle stratégie de schema migration adopter pour permettre à `ComposerDiffService::diff()` de produire un diff non-faux entre la version publiée et la version brouillon d'un `ItemWizardProfile` ?

## Options

### Option A — Colonne JSON sur table existante (recommandée V1, auditeur)

- **Migration** : `ALTER TABLE item_wizard_profiles ADD COLUMN published_steps_snapshot JSON NULL;`
- **Backfill** : optionnel — pour les profiles existants déjà publiés, on peut soit (a) laisser `NULL` et accepter que le premier publish post-migration alimente le snapshot, soit (b) backfill au déploiement via `UPDATE … SET published_steps_snapshot = (SELECT json_agg(steps) WHERE profile_id = id)` (équivalent SQL selon driver).
- **Code** : `ItemWizardProfile::publish()` → `$this->published_steps_snapshot = $this->steps->map->toArray();` puis `save()`.
- **Avantages** : léger, idempotent, rollback simple (`DROP COLUMN`), backfill faisable en quelques minutes en prod.
- **Inconvénients** : pas d'historique multi-versions (uniquement la dernière publication).
- **Effort estimé** : S (migration + 1 méthode model + adaptation tests = ~2h).

### Option B — Nouvelle table d'historique multi-versions

- **Migration** : nouvelle table `item_wizard_step_versions(id, profile_id, version, snapshot_json, published_at, published_by_id, FK(profile_id))` insert-only.
- **Code** : `ItemWizardProfile::publish()` → `ItemWizardStepVersion::create(['profile_id' => …, 'version' => $this->version, 'snapshot_json' => …, 'published_at' => now()])`.
- **Avantages** : historique complet, aligné stratégie audit fiscal NF525 long-terme, possibilité de rollback de publish.
- **Inconvénients** : table + index + cleanup policy à définir, plus de surface attaque.
- **Effort estimé** : M (migration + model + service + tests + cleanup = ~5h).

### Option C — Pas de migration ; refactor `ComposerDiffService::diff()` pour retourner explicitement "snapshot indisponible"

- **Code** : retirer le path `projectPublishedProfile()` ; si pas de snapshot, retourner `{is_clean: null, reason: 'snapshot_unavailable'}`.
- **Avantages** : zéro risque DDL, livrable immédiat.
- **Inconvénients** : la fonctionnalité Diff Modal de design iter1 ④ doit être désactivée en V1 (UX dégradée). Le bouton "Publier" perd sa valeur ajoutée.
- **Effort estimé** : XS (~30 min de code + adaptation Vue Diff Modal pour cacher la modale).

### Option D — Annuler le cycle β1.C4 entièrement

- Décision produit : pas de Diff Modal en V1, l'admin publie sans confirmation visuelle des changements.
- **Avantages** : aucun travail technique requis.
- **Inconvénients** : régression UX par rapport au design Claude Design v2 livré.

## Recommandation auditeur

**Option A** pour V1 (rapide, débloque β1.C4, idempotent).
**Option B** envisagée V2 si l'audit fiscal NF525 demande historique multi-versions.

## Approval

- [ ] **Approuvé Option A** — migration JSON column, backfill optionnel
- [x] **Approuvé Option B** — nouvelle table d'historique multi-versions `item_wizard_step_versions`
- [ ] **Approuvé Option C** — refactor sans DDL (snapshot_unavailable)
- [ ] **Approuvé Option D** — annulation β1.C4
- [ ] **Cancelled** — gate refusé, cycle stoppé

Approuvé par : **Kossay (utilisateur kossayelbenna8)** — décision technique déléguée à l'orchestrateur Claude par message du 2026-05-03 23:43 UTC+2 :
> « tiens-toi de prendre ces décisions techniques parce que moi je suis comme un utilisateur je veux le produit final et je m'en fous du code technique le plus important ça soit bien fait et le maximum possible fait correctement et sans faute entre le design et technique »

Critère explicite utilisateur : « la solidité soit sur une base solide et bien structuré et bien architecture, c'est-à-dire une architecture qu'il faut la suivre pour avoir un bon base une bonne stabilité et racine comme une arbre vraiment soit bien fait avec beaucoup de raisonnement ».

Date : 2026-05-03 23:43 UTC+2

Décision technique additionnelle :
1. **Cleanup policy V1** : aucune (table strictement insert-only, pas de purge automatique). Si la table grossit en prod, la décision de cleanup viendra V2 selon le coût mesuré (probablement aucun avant 10 000 publishes par profil).
2. **Backfill** : aucun. Les profiles déjà publiés sans snapshot prendront leur première version row au prochain `publish()`. Le bouton "Diff" sera affiché grisé avec tooltip "Aucune version publiée enregistrée — publie une fois pour activer le diff" jusqu'au premier publish post-migration.
3. **Cycle d'exécution** : `CV1-V2-CATALOG-REWORK-002`, plan `plans/PLAN_CV1-V2-CATALOG-REWORK-002_2026-05-03.md`.
4. **Désactivation `published_steps_snapshot`** : la colonne théorique référencée par `ComposerDiffService` (ligne 132, 151) — qui n'existait pas en BDD — sera **supprimée du code** côté `ComposerDiffService`. Plus aucun `getAttribute('published_steps_snapshot')` après ce cycle. Source de vérité unique = `ItemWizardStepVersion`.

## Resumption Protocol

Une fois ce gate approuvé :
1. Cocher l'option ci-dessus + signer.
2. Logger dans `docs/gates/GATE_LOG.md` (ligne avec décision et option choisie).
3. Ouvrir un nouveau cycle `CV1-V2-CATALOG-REWORK-002` qui exécute la migration + adaptation model/service/tests selon l'option retenue.
4. À la fin de ce cycle, débloquer β1.C4 (Diff Modal Vue intégration design) dans le backlog Phase β.

Ce cycle (`CV1-V2-CATALOG-REWORK-001`) **continue son exécution** sur β-PRE-2/3/4 + S1 sans attendre ce gate (lots indépendants).
