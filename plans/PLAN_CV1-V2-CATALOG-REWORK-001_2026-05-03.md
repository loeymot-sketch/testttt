# PLAN — Catalog Studio Phase α REWORK (post ultra-review)

| Champ | Valeur |
|---|---|
| TASK_ID | `CV1-V2-CATALOG-REWORK-001` |
| Date | 2026-05-03 |
| Auteur | Claude (orchestrateur) |
| Source | `reports/audit/ULTRA_REVIEW_GLOBAL_CATALOG_TREE_2026-05-03.md` |
| Verdict input | REWORK + ESCALATE (gate Schema Migration) |
| Score qualité in | 78/100 |
| Score qualité target | ≥ 90/100 |
| RUNNER_MODE | single-session |
| PHASE | EXECUTE |
| PRIMARY_EXECUTION_MODEL | mixed (`foodking-complex-implementer` complex, `foodking-routine-implementer` routine) |
| REASONING_EFFORT | high |
| EXECUTION_TIER | mixed (β-PRE-2/3 complex, β-PRE-4 routine, β-PRE-S1 routine) |
| EXECUTE_DELEGATION | sub-agents parallèles via Task tool |

---

## SUBSYSTEMS_TOUCHED

| Subsystem | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|
| Composer (`app/Services/Composer/ComposerProfileService.php`) | WRITE | non | oui (`ComposerProfileChanged` already DispatchableAfterCommit) |
| Composer Request (`app/Http/Requests/ComposerProfileRequest.php`) | WRITE | non | non |
| Composer Tests (`tests/Feature/Composer/`) | WRITE | non | non |
| Items (`app/Http/Controllers/Admin/ItemPhotoController.php`) | WRITE | non | non |
| Items Tests (`tests/Feature/Items/`) | WRITE | non | non |
| Documentation (`docs/integration/`) | WRITE | non | non |
| Tests E2E (`tests/e2e/catalog-studio-a11y-axe.spec.js`) | WRITE | non | non |
| Package config (`package.json`) | WRITE | non | non |
| Tests Vitest (`tests/js/`) | WRITE | non | non |

## SUBSYSTEMS_OFF_LIMITS

- `app/Services/Pricing/*` (frozen)
- `app/Services/Order/Lifecycle/*` (frozen)
- `app/Services/Fiscal/*` (frozen)
- `app/Services/Composer/ComposerDiffService.php` (intentionnellement non touché ce round — précondition β-PRE-1 = gate humain DDL)
- `database/migrations/*.php` (intentionnellement aucune nouvelle migration ce round — gate humain requis pour `published_steps_snapshot`)

## GATE_CONDITIONS

- **β-PRE-1** : Schema Migration gate (DDL `published_steps_snapshot` JSON column) — HUMAN GATE OUVERT par ce cycle, à clôturer dans `docs/gates/GATE_CV1-V2-CATALOG-REWORK-PUBLISHED-SNAPSHOT_2026-05-03.md`. Pas exécuté autonome.
- Aucun autre gate anticipé (β-PRE-2/3/4 + S1 = pas de DDL, pas de auth change, pas de frozen zone).

## INVARIANTS_AT_RISK

- **I3 branch_id** : N/A — items est mono-marque (audit confirmé `2022_11_17_110514_create_items_table.php:19-39`).
- **I4 dispatch after commit** : N/A — `ComposerProfileChanged` déjà conforme (audit confirmé).
- **I6 frozen zones** : protégé par `SUBSYSTEMS_OFF_LIMITS`.

---

## STRATÉGIE — 4 sub-agents parallèles + 1 gate humain

```
┌─────────────────────────────────────────────────────────┐
│  GATE HUMAIN OUVERT — β-PRE-1 (R1 critique)            │  ← ATTENTE HUMAIN
│  Schema migration published_steps_snapshot              │
│  docs/gates/GATE_CV1-V2-CATALOG-REWORK-PUBLISHED-       │
│  SNAPSHOT_2026-05-03.md                                 │
└─────────────────────────────────────────────────────────┘

┌────────────────┐ ┌────────────────┐ ┌────────────────┐ ┌────────────────┐
│ Sub-agent A    │ │ Sub-agent B    │ │ Sub-agent C    │ │ Sub-agent D    │
│ (complex)      │ │ (complex)      │ │ (routine)      │ │ (routine)      │
│                │ │                │ │                │ │                │
│ β-PRE-2        │ │ β-PRE-3        │ │ β-PRE-4 + R5   │ │ R11 + R12      │
│ Backend 409    │ │ Atomic photo   │ │ Permission map │ │ Sentinelles    │
│ version check  │ │ upload-then-   │ │ + axe install  │ │ i18n parity +  │
│                │ │ swap pattern   │ │ + spec FAIL    │ │ Item contract  │
└────────────────┘ └────────────────┘ └────────────────┘ └────────────────┘
        │                  │                  │                  │
        └──────────────────┴──────────────────┴──────────────────┘
                                  │
                          AUDIT CONSOLIDÉ
                          (round 1, max 5 rounds)
                                  │
                    ┌─────────────┴─────────────┐
                    │                           │
              PASS  │                           │  REWORK
                    │                           │
              Tests finaux                Plan correction
              + rapport                   + relance ronde
                    │                           │
              Close cycle                 (boucle ≤5)
```

---

## β-PRE-2 — Backend 409 version conflict (Sub-agent A, complex)

**Trigger** : R4 du rapport (S2 medium). Le service `ComposerProfileService::update` incrémente `version` unconditionally sans vérifier la version envoyée par le client. Le design iter1 ⑤ promet un conflict banner 409, le backend ne l'alimente jamais.

**Livrables** :
1. `app/Http/Requests/ComposerProfileRequest.php` — ajouter règle `'version' => ['required', 'integer', 'min:0']` sur les méthodes update/publish.
2. `app/Services/Composer/ComposerProfileService.php` — dans `update($profile, $payload)` (lignes 57-80), AVANT le `DB::transaction`, comparer `$payload['version'] !== $profile->version` → `abort(409, ...)` avec body `{expected: $profile->version, got: $payload['version']}`.
3. `tests/Feature/Composer/ComposerProfileVersionConflictTest.php` (NOUVEAU) — 3 tests :
   - `test_update_with_matching_version_succeeds`
   - `test_update_with_stale_version_returns_409_with_expected_body`
   - `test_publish_with_stale_version_returns_409`
4. Adapter `tests/Feature/Composer/ComposerPublishSyncTest.php` et autres tests existants si payload `version` est désormais requis (rétro-compat best-effort).

**Critères PASS** : 6/6 PASS PHPUnit Composer (existants + 3 nouveaux), 0 régression.

**Délégation** : Task `foodking-complex-implementer` (modifie service backend critique avec dispatch + invariant I4 frôlé).

---

## β-PRE-3 — Atomic photo upload (Sub-agent B, complex)

**Trigger** : R2 du rapport (S2 medium). `ItemPhotoController::store` fait `clearMediaCollection('item')` AVANT `addMediaFromRequest('photo')`. Si la 2e étape échoue, l'item perd son image sans remplacement.

**Livrables** :
1. `app/Http/Controllers/Admin/ItemPhotoController.php` — réécrire `store()` selon pattern upload-then-swap :
   ```
   try {
       $newMedia = $item->addMediaFromRequest('photo')
           ->preservingOriginal()
           ->toMediaCollection('item_pending');
       $item->clearMediaCollection('item');
       $newMedia->setAttribute('collection_name', 'item');
       $newMedia->save();
       // OR: copy to 'item' collection then delete pending
   } catch (\Throwable $e) {
       Log::error('ItemPhotoUpload failed', ['item' => $item->id, 'error' => $e->getMessage()]);
       throw $e;
   }
   ```
   Note implémentation : si `setAttribute('collection_name')` ne suffit pas chez Spatie Media Library, utiliser un swap explicit via `Media::query()->update(['collection_name' => 'item'])` après clear, **ou** garder old, ajouter new avec prefix, supprimer old en dernier. **Le sub-agent décide la meilleure stratégie technique selon l'API Spatie installée**, l'objectif fonctionnel est : si l'upload échoue, l'image originale reste intacte.
2. `app/Models/Item.php` — si nécessaire, déclarer `registerMediaCollections()` pour `'item_pending'` (ou autre collection technique) en plus de `'item'`.
3. `tests/Feature/Items/ItemPhotoUploadAtomicityTest.php` (NOUVEAU) — 2 tests :
   - `test_atomic_upload_when_storage_throws_keeps_original_image`
   - `test_successful_upload_replaces_old_image_cleanly`
4. Maintenir `tests/Feature/Items/ItemPhotoUploadTest.php` 5/5 PASS.

**Critères PASS** : 7/7 PASS (5 existants + 2 nouveaux), preuve par mock Storage qu'en cas d'échec, l'image originale est intacte.

**Délégation** : Task `foodking-complex-implementer` (atomicité = critique, raisonnement médiathèque Spatie).

---

## β-PRE-4 + R5 — Permission mapping doc + axe-core install (Sub-agent C, routine)

**Triggers** : R3 (permission misalignment design ↔ Spatie réel) + R5 (axe-core skip silent = faux PASS CI).

**Livrables** :
1. `docs/integration/STUDIO_PERMISSIONS_TO_SPATIE_MAP_2026-05-04.md` (NOUVEAU) — table de correspondance complète :
   - 3 rôles design (`super_admin`, `branch_manager`, `kitchen_manager`) → rôles Spatie réels (`Admin`, `Tenant Admin`, `Branch Manager`, `Manager`, `Cashier`, `Waiter`).
   - 9 actions design avec préfixe `catalog.*` → permissions Spatie réelles (`items_edit`, `items_show`, `items_destroy`, `composer_publish`, `item_categories_create`, `item_categories_edit`, `availability_toggle`, `stock_resolve`, `branch_override_set`).
   - Notation explicite des permissions à créer (si `kitchen_manager` ou `Brand Manager` n'existent pas) → flagger comme **gate produit** dans le doc, ne pas les créer.
   - Référence vers fichiers source : `audit-claude-ultra-review-2026-05-03/01-design-claude-v2/studio-iter2.jsx:455-465` + `app/Http/Controllers/Admin/AdminController.php:23,29,38,42` + `CLAUDE.md:67`.
2. `package.json` — ajouter `@axe-core/playwright` à `devDependencies` (latest stable). Si l'installation est non-triviale (network policy, etc.) → DÉCLARER seulement la dep dans `package.json` et noter que `npm install` est à lancer manuellement.
3. `tests/e2e/catalog-studio-a11y-axe.spec.js` — modifier le bloc try/catch du require :
   ```js
   let AxeBuilder = null;
   try {
     AxeBuilder = require('@axe-core/playwright').default;
   } catch (e) {
     // FAIL not skip : the dep is mandatory for CI a11y enforcement.
     // To run locally without the dep, set ALLOW_AXE_SKIP=1.
   }
   ```
   Et dans les `test.skip(!AxeBuilder, ...)` → conditionner :
   ```js
   if (!AxeBuilder) {
     if (process.env.ALLOW_AXE_SKIP === '1') {
       test.skip(true, '@axe-core/playwright absent (ALLOW_AXE_SKIP=1)');
     } else {
       throw new Error('@axe-core/playwright must be installed (run: npm i -D @axe-core/playwright). Set ALLOW_AXE_SKIP=1 to skip locally.');
     }
   }
   ```

**Critères PASS** : doc créé, `package.json` valide JSON, spec corrigée (syntaxe Node OK).

**Délégation** : Task `foodking-routine-implementer` (doc + config + petit patch spec, aucun service touché).

---

## β-PRE-S1 — Sentinelles parité i18n + ItemCreate contract (Sub-agent D, routine)

**Triggers** : R11 (parité i18n studio non vérifiée) + R12 (CatalogStudio quick vs ItemList full createProduct divergence).

**Livrables** :
1. `tests/js/i18nStudioKeyParityTest.spec.js` (NOUVEAU) — Vitest qui charge les 5 fichiers `lang/{ar,bn,de,en,fr}/all.php` et compare `array_keys` du namespace `studio` entre toutes les locales. Échec si une clé manque dans une locale. Approche : parser le PHP en regex simple ou utiliser un helper si déjà présent dans le repo. Si parsing PHP est trop fragile, écrire le test en PHPUnit feature à la place : `tests/Feature/I18n/StudioKeyParityTest.php`.
2. `tests/Feature/Items/ItemCreateContractTest.php` (NOUVEAU) — PHPUnit qui exerce 2 payloads sur `POST /api/admin/item` :
   - Payload **quick** (CatalogStudio quick-create) : `{name, price, description?, image?, order, item_category_id, tax_id?}`.
   - Payload **full** (legacy ItemListComponent) : payload large avec `item_type`, `channels`, etc.
   - Les 2 doivent produire un Item valide sans erreur HTTP.
   - Lire `app/Http/Controllers/Admin/ItemController.php` pour comprendre le payload accepté avant d'écrire le test (le sub-agent doit ouvrir ce fichier en lecture).
3. (Bonus optionnel si temps) `docs/integration/TOKENS_INTEGRATION_RULE_2026-05-03.md` — règle d'intégration tokens (prendre uniquement bloc `--studio-*` du `01-design-claude-v2/tokens.css`).

**Critères PASS** : 1 sentinelle parité i18n verte, 1 sentinelle ItemCreate contract verte (ou skip explicite si endpoint non testable en local — documenter pourquoi).

**Délégation** : Task `foodking-routine-implementer` (sentinelles tests, pas de logique métier).

---

## β-PRE-1 — Gate humain OUVERT (R1 critique, hors sub-agent)

**Trigger** : R1 du rapport (S2 critique). `published_steps_snapshot` colonne absente → diff faux silencieux en prod.

**Action Claude orchestrateur (cette session)** :
1. Rédiger `docs/gates/GATE_CV1-V2-CATALOG-REWORK-PUBLISHED-SNAPSHOT_2026-05-03.md` selon format `human-gates.mdc`.
2. Logger l'ouverture dans `docs/gates/GATE_LOG.md`.
3. **Pas d'exécution de migration**. Pas de modification `ItemWizardProfile`. Pas de modification `ComposerDiffService` ce round.
4. Le gate sera traité dans un prochain cycle CV1-V2-CATALOG-REWORK-002 après décision humaine.

---

## Critères CLOSE

- 4/4 sub-agents A/B/C/D livrent en PASS (audit consolidé OK).
- Tests reproduits localement : Vitest ≥ 1054, PHPUnit ≥ 50 + 5 nouveaux (3 β-PRE-2 + 2 β-PRE-3), Playwright critical-flow toujours PASS.
- Gate humain β-PRE-1 ouvert et logué (pas nécessairement clôturé pour CLOSE de ce cycle).
- 0 régression, 0 invariant violé.
- Rapport final consolidé écrit.

## Critères REWORK

- ≥ 1 sub-agent revient en échec ou test rouge.
- Régression introduite dans une suite existante.
- Audit consolidé détecte un risque S2 nouveau introduit par les corrections.
- Boucle max 5 rounds (`auto-remediation.mdc`).

## Critères ESCALATE

- Au 5e round sans PASS → HUMAN_GATE escalade.
- Découverte d'une frozen zone touchée par accident → escalade immédiate.
