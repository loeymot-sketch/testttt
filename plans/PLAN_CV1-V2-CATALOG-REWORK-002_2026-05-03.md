# PLAN — Catalog Studio REWORK-002 — Snapshot persistence (gate Option B exécution)

| Champ | Valeur |
|---|---|
| TASK_ID | `CV1-V2-CATALOG-REWORK-002` |
| Date | 2026-05-03 23:43 UTC+2 |
| Auteur | Claude (orchestrateur, décision technique déléguée explicitement par utilisateur) |
| Précédent | `CV1-V2-CATALOG-REWORK-001` (round 1 PASS sur β-PRE-2/3/4/S1) |
| Source décision | `docs/gates/GATE_CV1-V2-CATALOG-REWORK-PUBLISHED-SNAPSHOT_2026-05-03.md` (Option B approved) |
| RUNNER_MODE | single-session |
| PHASE | EXECUTE |
| EXECUTION_TIER | complex (DDL + service refactor + invariants persistance) |
| EXECUTE_DELEGATION | sub-agents `foodking-complex-implementer` (E, F) + `foodking-routine-implementer` (G) parallèles |
| PRIMARY_EXECUTION_MODEL | mixed |
| REASONING_EFFORT | high |

---

## 0. TL;DR mission

Exécuter Option B du gate β-PRE-1 : création de la table insert-only `item_wizard_step_versions`, refactor de `ItemWizardProfile::publish()` pour persister un snapshot immuable, refactor de `ComposerDiffService` pour lire ce snapshot vraiment-en-BDD, migration des tests existants depuis l'injection mémoire `setAttribute()` vers vraie persistance. Résultat attendu : R1 (S2 critique) **fixé**, score qualité ≥ 90/100, Phase β1.C4 (Diff Modal Vue) débloquée.

---

## 1. Vision architecturale (raisonnement utilisateur "racine arbre solide")

### Pourquoi Option B est cohérente avec l'arbre FoodKing

Le repo applique déjà 3 patterns event-sourcing-like :
1. **`stock_movements`** : table append-only + `idempotency_key` unique + jamais d'UPDATE.
2. **`order_items.composition_snapshot`** : JSON figé à `payment_complete`, jamais altéré rétroactivement (NF525).
3. **Outbox pattern** : `PersistCatalogChangedToOutbox` + `DispatchDomainEventsJob` = events immutables persistés avant broadcast.

Option B = même philosophie pour les wizards : chaque `publish()` produit une **row immutable** qui horodate exactement ce qui était live à ce moment-là. C'est le pattern correct, même quand on n'en exploite qu'une seule version pour le diff au début.

### Schéma final visé

```
item_wizard_profiles (existant)
   │
   │ 1:N
   │
   ▼
item_wizard_step_versions (NEW)
   ├─ id (PK)
   ├─ profile_id (FK item_wizard_profiles, cascadeOnDelete)
   ├─ version (uint, no_unsigned)
   ├─ snapshot (JSON, array of step rows complète)
   ├─ published_at (timestamp)
   ├─ published_by_id (FK users, nullable, nullOnDelete)
   ├─ created_at, updated_at
   │
   UNIQUE (profile_id, version)
   INDEX (published_at)
```

Insert-only : Eloquent surcharge `update()` et `delete()` avec exception. Suppression cascade via FK uniquement quand le profile parent est supprimé (suppression légitime de tout l'arbre).

---

## 2. SUBSYSTEMS_TOUCHED

| Subsystem | Read/Write | branch_id | Dispatch |
|---|---|---|---|
| **Schema DB** (`database/migrations/2026_05_04_*_create_item_wizard_step_versions_table.php`) | WRITE (DDL) | non | non |
| **Model** (`app/Models/ItemWizardStepVersion.php`) | WRITE (NEW) | non | non |
| **Model** (`app/Models/ItemWizardProfile.php`) | WRITE (`publish()` + relation `versions()`) | non | non |
| **Service** (`app/Services/Composer/ComposerProfileService.php`) | WRITE (passer `userId` à `publish()`) | non | oui (déjà DispatchableAfterCommit, préservé) |
| **Service** (`app/Services/Composer/ComposerDiffService.php`) | WRITE (lire `ItemWizardStepVersion` au lieu de `getAttribute('published_steps_snapshot')`) | non | non |
| **Tests existants** (`tests/Feature/Composer/ComposerDiffServiceTest.php`) | WRITE (migration `setAttribute` → vraie persistance) | non | non |
| **Tests nouveaux** (`tests/Feature/Composer/ItemWizardStepVersion*.php` + `ComposerDiffServiceWithRealSnapshotTest.php`) | WRITE (NEW) | non | non |

## SUBSYSTEMS_OFF_LIMITS

- `app/Services/Pricing/*`, `app/Services/Order/Lifecycle/*`, `app/Services/Fiscal/*` (frozen)
- `app/Services/Composer/ComposerStepService.php` (round 1, ne pas re-toucher)
- `app/Services/Composer/ComposerProfileProjection.php` (n'est pas concernée par le snapshot)
- `app/Http/Controllers/Admin/ItemPhotoController.php` (round 1, fini)
- Toute table autre que `item_wizard_step_versions` (NEW) et `item_wizard_profiles` (lecture only sur la table existante, pas de DDL dessus)

## GATE_CONDITIONS

- **β-PRE-1 Schema Migration cleared** : Option B approuvée par Kossay (délégué) le 2026-05-03 23:43 UTC+2. Migration autorisée.
- Aucun autre gate anticipé.

## INVARIANTS_AT_RISK

- **I4 Dispatch after commit** : `ComposerProfileService::publish()` dispatche `ComposerProfileChanged`. Le INSERT `ItemWizardStepVersion` doit se faire **avant** le dispatch ; reste dans la même transaction si possible, ou dispatch reste DispatchableAfterCommit comme aujourd'hui.
- **I6 Frozen zones** : RAS (Composer/* non frozen).
- **NF525** : la table append-only respecte le principe d'immuabilité fiscale.

---

## 3. STRATÉGIE — 3 sub-agents parallèles

```
┌────────────────────────────────────┐ ┌────────────────────────────────────┐
│ Sub-agent E (complex)              │ │ Sub-agent F (complex)              │
│                                    │ │                                    │
│ Lot 1 : Migration + Model          │ │ Lot 2 : Refactor ComposerDiffSvc + │
│ + ItemWizardProfile::publish()     │ │ Migration tests existants vers     │
│ + relation versions()              │ │ vraie persistance + nouveau test   │
│                                    │ │ "no false-positive in prod"        │
└────────────────────────────────────┘ └────────────────────────────────────┘
                  │                                  │
                  └──────────────┬───────────────────┘
                                 │
                                 ▼
                ┌────────────────────────────────────┐
                │ Sub-agent G (routine)              │
                │ Lot 3 : Sentinelles d'intégrité    │
                │ (insert-only enforcement,          │
                │ unique (profile_id, version),      │
                │ cascade on profile delete)         │
                └────────────────────────────────────┘
                                 │
                                 ▼
                       AUDIT CONSOLIDÉ ROUND 2
                       Tests + invariants + matrice
                                 │
                  ┌──────────────┴──────────────┐
                  │                             │
              PASS │                             │ REWORK
                  │                             │
            CLOSE cycle                  Plan correction
            score ≥ 90/100               Relance ronde
                                         (max 5 rounds)
```

**Note séquencement** : E doit finir avant que F puisse adapter `ComposerDiffServiceTest` (F lit le model créé par E). On lance E et F en parallèle quand même — F a explicitement instructions d'attendre que la migration et le model soient mergés (le sub-agent peut interroger via `Schema::hasTable` ou simplement importer le model). G dépend de E (pour la table) mais peut tourner en parallèle de F.

**Décision** : lancer **E + G en parallèle**, puis **F seul** quand E a terminé (sub-agent F a besoin du model `ItemWizardStepVersion` opérationnel pour réécrire les tests). G n'a besoin que de la migration + model immutable, pas du refactor diff.

→ **Order final** : E lancé d'abord en blocking moderé, puis F + G en parallèle dès que E a livré.

Alternative plus rapide : E + F + G en parallèle si chacun gère ses dépendances proprement. Je vais opter pour **E seul d'abord** (~5 min), puis **F + G en parallèle** (~5-10 min). C'est le pattern le plus sûr pour éviter conflits sur `ItemWizardStepVersion.php`.

---

## 4. Sub-agent E — Lot 1 (complex)

**Mission** : créer la fondation BDD + model + persistance dans `publish()`.

**Livrables** :
1. **Migration** `database/migrations/2026_05_04_000010_create_item_wizard_step_versions_table.php` :
   ```
   Schema::create('item_wizard_step_versions', function (Blueprint $table) {
       $table->id();
       $table->foreignId('profile_id')->constrained('item_wizard_profiles')->cascadeOnDelete();
       $table->unsignedInteger('version');
       $table->json('snapshot');
       $table->timestamp('published_at');
       $table->foreignId('published_by_id')->nullable()->constrained('users')->nullOnDelete();
       $table->timestamps();

       $table->unique(['profile_id', 'version'], 'item_wizard_step_versions_profile_version_unique');
       $table->index('published_at');
   });
   ```
2. **Model** `app/Models/ItemWizardStepVersion.php` :
   - `$fillable = ['profile_id', 'version', 'snapshot', 'published_at', 'published_by_id']`.
   - `$casts = ['snapshot' => 'array', 'published_at' => 'datetime', 'profile_id' => 'integer', 'version' => 'integer', 'published_by_id' => 'integer']`.
   - `belongsTo(ItemWizardProfile::class, 'profile_id')` → relation `profile()`.
   - `belongsTo(User::class, 'published_by_id')` → relation `publishedBy()`.
   - **Insert-only enforcement** : surcharger les méthodes mutantes :
     ```php
     public function update(array $attributes = [], array $options = [])
     {
         throw new \RuntimeException('ItemWizardStepVersion is insert-only and cannot be updated.');
     }

     public static function boot()
     {
         parent::boot();
         static::updating(function (): void {
             throw new \RuntimeException('ItemWizardStepVersion is insert-only.');
         });
         static::deleting(function ($model) {
             // Allow only cascade-deletes from the profile FK (not direct $model->delete()).
             // The cascade happens at SQL level, not via Eloquent → this hook is bypassed.
             // For Eloquent-direct-delete protection :
             if (!app()->runningUnitTests() || !static::$allowDirectDeleteForTesting) {
                 throw new \RuntimeException('ItemWizardStepVersion is insert-only and cannot be deleted directly. Cascade via profile delete only.');
             }
         });
     }
     ```
     **Important** : l'enforcement boot avec `deleting` empêcherait les tests `RefreshDatabase` de fonctionner si on n'ajoute pas un escape de test. **Solution propre** : ne pas bloquer `delete()` via Eloquent boot — le hook PHP ne peut pas distinguer cascade SQL vs delete direct. Faire une enforcement **uniquement sur update** (qui est la vraie violation d'immuabilité). La cascade via FK reste possible (légitime). Le `delete()` direct via Eloquent reste possible mais le sub-agent ajoute un commentaire `@internal` clair + un test d'intégrité qui vérifie que **le service publish() n'appelle jamais `delete()` ou `update()` sur ItemWizardStepVersion**.
   - **Décision finale enforcement (recommandation Claude orchestrateur)** : surcharge **`update()`** instance method seulement. Pas de hook `deleting`. Documenter le pattern dans la PHPdoc du model. Test sentinel séparé qui prouve qu'aucun appel `update()` n'est fait par le service.
3. **Adapter** `app/Models/ItemWizardProfile.php` :
   - Ajouter relation `versions()` → `hasMany(ItemWizardStepVersion::class, 'profile_id')`.
   - Helper `latestVersion()` → `versions()->orderByDesc('version')->first()`.
4. **Adapter** `app/Services/Composer/ComposerProfileService.php` :
   - Méthode `publish()` (à localiser) → après `$profile->update(['is_published' => true])`, INSERT row dans `item_wizard_step_versions` avec :
     - `profile_id = $profile->id`
     - `version = $profile->version` (la version actuellement publiée)
     - `snapshot = $profile->steps()->get()->map->toArray()->values()->all()` ou équivalent (array sérialisable)
     - `published_at = now()`
     - `published_by_id = auth()->id() ?: null` (ou paramètre méthode)
   - Le INSERT se fait **dans la même transaction** que l'UPDATE `is_published`. Si le wrapper `DB::transaction` couvre déjà publish, parfait ; sinon ajouter `DB::transaction(function () use ($profile) { … })`.
   - Le dispatch `ComposerProfileChanged` reste **après** l'INSERT, dans la transaction → DispatchableAfterCommit défère correctement.
5. **Tests d'intégration côté sub-agent E** (pour valider la fondation) :
   - `tests/Feature/Composer/ItemWizardStepVersionPersistenceTest.php` (NEW) :
     - `test_publish_inserts_one_version_row` — appel `publish()` sur un profile avec 3 steps → assert 1 row en BDD avec `version = profile.version`, `snapshot` contient 3 step arrays.
     - `test_subsequent_publish_inserts_additional_version_row` — publish v1, modifier steps, publish v2 → assert 2 rows en BDD avec versions distinctes.
     - `test_unique_profile_version_constraint_blocks_duplicate` — tenter `ItemWizardStepVersion::create(['profile_id' => X, 'version' => 1, …])` 2 fois → 2e doit throw QueryException unique constraint.
     - `test_cascade_delete_removes_versions` — supprimer le profile → versions disparaissent (FK cascade).

**Périmètre (allowlist)** :
- `database/migrations/2026_05_04_000010_create_item_wizard_step_versions_table.php` (NEW)
- `app/Models/ItemWizardStepVersion.php` (NEW)
- `app/Models/ItemWizardProfile.php` (relation + helper)
- `app/Services/Composer/ComposerProfileService.php` (publish())
- `tests/Feature/Composer/ItemWizardStepVersionPersistenceTest.php` (NEW)
- `reports/execution/RUN_BETA_PRE_1_SNAPSHOT_FOUNDATION_2026-05-04.md` (NEW)

**Critères PASS** :
- Migration tourne (`php artisan migrate` sans erreur).
- 4/4 tests `ItemWizardStepVersionPersistenceTest` PASS.
- Suite Composer existante (53 + 2 nouveaux β-PRE-2 = 55) toujours verte.
- Aucun fichier hors allowlist modifié.

**Critères ESCALATE** :
- Migration casse une suite existante non couverte par RefreshDatabase → halt.
- Edge case : un profile a `version = 0` initialement → adapter le service pour incrémenter avant snapshot ou snapshotter version=0 ; documenter le choix.

---

## 5. Sub-agent F — Lot 2 (complex, après E)

**Mission** : refactor `ComposerDiffService` pour lire la vraie persistance, migrer les tests existants depuis injection mémoire vers BDD, prouver que le faux-positif silencieux est éliminé.

**Livrables** :
1. **Refactor** `app/Services/Composer/ComposerDiffService.php` :
   - Supprimer **toutes** les références à `published_steps_snapshot` (lignes 132, 151 actuelles).
   - Remplacer le helper `publishedRowsByKey()` :
     ```php
     private function publishedRowsByKey(ItemWizardProfile $profile, array $draftByKey): array
     {
         $latestVersion = $profile->versions()->latest('version')->first();
         if (!$latestVersion) {
             // Pas de version publiée enregistrée encore (premier publish post-migration ou jamais publié)
             return [[], false];
         }

         return [$this->arrayRowsByKey($latestVersion->snapshot ?? []), true];
     }
     ```
   - Supprimer `projectPublishedProfile()` complètement (path mort si on a vraiment le snapshot persisté). **Justification** : la projection synthétique avec `Item` synthétique et relations vides était un fallback qui produisait des résultats faux. Avec une vraie source de vérité (table `item_wizard_step_versions`), ce fallback devient inutile et trompeur.
   - Adapter `hasHistoricalSnapshot()` → check `$profile->versions()->exists()`.
   - `payload()` reste identique en signature.
2. **Migration des tests existants** `tests/Feature/Composer/ComposerDiffServiceTest.php` :
   - Remplacer chaque appel `$profile->setAttribute('published_steps_snapshot', $rows)` par :
     ```php
     ItemWizardStepVersion::create([
         'profile_id' => $profile->id,
         'version' => $profile->version,
         'snapshot' => $rows,
         'published_at' => now(),
     ]);
     $profile->refresh();
     ```
   - Le helper `attachPublishedSnapshot` (ligne 199-202 actuelle) → réécrire pour insérer une vraie row.
   - Tous les 6 tests doivent rester PASS, mais **avec vraie persistance BDD, pas injection mémoire**.
3. **Nouveau test** `tests/Feature/Composer/ComposerDiffServiceProductionPathTest.php` (NEW) :
   - `test_diff_against_production_persisted_snapshot_detects_real_changes` :
     - Setup : profile + 3 steps. Appel `publish()` (qui insère version row via Lot 1). Modifier 1 step (max_select 1→2). Appeler `diff()`.
     - Assert : `is_clean=false`, `modified` contient l'entry avec `step_key` du step modifié, `changed_fields` contient `'max_select'`.
     - **Évidence directe** que le path production fonctionne, contrairement au cas `setAttribute()` mémoire qui n'était jamais exercé en prod.
   - `test_diff_for_unpublished_profile_returns_clean_with_all_steps_as_added` :
     - Setup : profile sans aucune version, draft avec 5 steps.
     - Assert : retour `{is_clean: true, added: [], removed: [], modified: [], …}` ou structure équivalente cohérente avec le contrat.
     - Vérifier que le service ne crash pas et retourne une structure utilisable.
   - `test_diff_after_two_publishes_compares_against_latest_version_only` :
     - Publish v1 (3 steps), publish v2 (4 steps), modifier draft. Diff → compare draft vs v2, pas v1.
4. **Lance** `php artisan test tests/Feature/Composer/`.

**Périmètre (allowlist)** :
- `app/Services/Composer/ComposerDiffService.php` (refactor)
- `tests/Feature/Composer/ComposerDiffServiceTest.php` (migration setAttribute → DB)
- `tests/Feature/Composer/ComposerDiffServiceProductionPathTest.php` (NEW)
- `reports/execution/RUN_BETA_PRE_1_DIFF_REFACTOR_2026-05-04.md` (NEW)

**Critères PASS** :
- 6/6 tests `ComposerDiffServiceTest` (existants) toujours PASS, **mais maintenant avec vraie BDD**.
- 3/3 tests `ComposerDiffServiceProductionPathTest` (nouveaux) PASS.
- Suite Composer complète (≥ 58 tests) verte.
- Aucun `published_steps_snapshot` restant dans `app/` ou `database/` (`grep -r published_steps_snapshot app/ database/` → 0 match).

**Critères ESCALATE** :
- Suppression de `projectPublishedProfile()` casse un test que je ne contrôle pas → flag, ne pas supprimer, garder commenté.

---

## 6. Sub-agent G — Lot 3 (routine, après E)

**Mission** : sentinelles d'intégrité immuabilité + cascade.

**Livrables** :
1. **Test** `tests/Feature/Composer/ItemWizardStepVersionImmutabilityTest.php` (NEW) :
   - `test_update_method_throws_runtime_exception` :
     - Créer une version row.
     - Tenter `$version->update(['snapshot' => [...]])` → assert RuntimeException avec message "insert-only".
   - `test_save_after_setting_attribute_throws_or_no_op` :
     - Idem en mode `$version->snapshot = [...]; $version->save()`.
     - Vérifier le comportement attendu (soit no-op silencieux, soit exception).
   - `test_cascade_delete_via_profile_works_legitimately` :
     - Créer profile + 2 versions.
     - `$profile->delete()`.
     - Assert `ItemWizardStepVersion::count() === 0` (cascade FK).
2. **Test** `tests/Feature/Composer/ItemWizardStepVersionUniqueConstraintTest.php` (NEW) :
   - `test_unique_profile_version_blocks_duplicate_insert` :
     - Insert version 1.
     - Tenter insert version 1 même profile → QueryException sur `item_wizard_step_versions_profile_version_unique`.
3. **Doc** `docs/architecture/ADR-WIZARD-STEP-VERSION-2026-05-04.md` (NEW) :
   - Décision Option B documentée : pourquoi insert-only multi-versions, alignement avec `stock_movements` + `composition_snapshot`, NF525, rollback offert.
   - Schéma table.
   - Cycle de vie (publish → INSERT row, jamais UPDATE, jamais DELETE direct, cascade si profile delete).
   - Politique cleanup V1 (aucune) → V2 (à mesurer).
4. **Rapport** `reports/execution/RUN_BETA_PRE_1_INTEGRITY_SENTINELS_2026-05-04.md`.

**Périmètre (allowlist)** :
- `tests/Feature/Composer/ItemWizardStepVersionImmutabilityTest.php` (NEW)
- `tests/Feature/Composer/ItemWizardStepVersionUniqueConstraintTest.php` (NEW)
- `docs/architecture/ADR-WIZARD-STEP-VERSION-2026-05-04.md` (NEW)
- `reports/execution/RUN_BETA_PRE_1_INTEGRITY_SENTINELS_2026-05-04.md` (NEW)

**Critères PASS** :
- 4/4 nouveaux tests PASS.
- ADR rédigé et lisible.

**Critères ESCALATE** :
- L'enforcement immuabilité empêche `RefreshDatabase` de fonctionner → flag + propose alternative (purge SQL au lieu d'Eloquent delete).

---

## 7. Audit consolidé round 2 (orchestrateur in-session)

Une fois E + F + G PASS, je lance :
1. `php artisan migrate:fresh` (ou équivalent qui réinitialise toute la BDD test).
2. `php artisan test tests/Feature/Composer/ tests/Feature/Items/ tests/Feature/I18n/` (suites séparées pour contournement bug `php artisan test multi-args`).
3. `npm run vitest -- --run` (global).
4. `npm run dev` rebuild bundles.
5. `npx playwright test tests/e2e/catalog-studio-create-product-flow.spec.js` (regression critical-flow).
6. **Vérification finale** : `grep -r published_steps_snapshot app/ database/` → 0 match.
7. Rédaction `reports/execution/RUN_CV1_V2_CATALOG_REWORK_002_2026-05-03.md` (output consolidé).

**Critère CLOSE round 2** :
- 6 + 3 + 4 + 5 + 9 + 2 = ≥ 79 tests Composer/Items/I18n verts (incluant 13 nouveaux tests cycle 002).
- Vitest 1054 toujours vert.
- Playwright critical-flow vert.
- 0 référence à `published_steps_snapshot` dans le code.
- 0 régression.

**Critère REWORK round 3** :
- 1 sub-agent revient en échec.
- Régression introduite.
- Faux-positif silencieux non éliminé (sentinel ProductionPathTest fail).

**Critère ESCALATE** :
- 5e round atteint → HUMAN_GATE.

---

## 8. Fin de cycle

### Si CLOSE
- Rapport final consolidé écrit.
- ACTIVE_CYCLE phase = `CLOSED` ou pivot vers prochain cycle (Phase β intégration UI).
- Activity-log `done`.
- Gate β-PRE-1 status = `Approved Option B + EXECUTED`.
- Mémoire Graphiti facts à graver (5-7 facts).

### Si REWORK
- Plan correction localisé.
- Relance sub-agents.
- Max 5 rounds.

---

## 9. Risques restants après ce cycle (vision globale)

Après round 2 PASS, l'état du projet sera :
- **R1** S2 critique → **FIXED**
- **R2/R3/R4/R5** S2 → **FIXED** (round 1)
- **R6/R7/R8/R9/R10/R13/R14** S0/S1 → documentés/déférés V2 ou roadmap

Le score qualité visé post-cycle 002 : **≥ 90/100** (vs 78 input).
La fidélité design ↔ base : **≥ 95/100** (le diff Modal Vue β1.C4 sera fonctionnel branché sur vraie BDD).
Le plug-and-play : **≥ 85/100** (bouton "Publier" devient enfin honnête : montre les vrais changements).

**Phase β1 totale débloquée** : C1 drag&drop, C2 branch overrides, C3 image upload (β-PRE-3 backend prêt), C4 diff modal (β-PRE-1 backend prêt), C5 conflict 409 (β-PRE-2 backend prêt).
