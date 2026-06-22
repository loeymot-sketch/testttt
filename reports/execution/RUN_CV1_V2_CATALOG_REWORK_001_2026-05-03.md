# RUN — CV1-V2-CATALOG-REWORK-001 — round 1 PASS
| Champ | Valeur |
|---|---|
| Date | 2026-05-03 23:30 UTC+2 |
| TASK_ID | `CV1-V2-CATALOG-REWORK-001` |
| Phase | EXECUTE → CLOSE (round 1, pas de REWORK requis) |
| Auteur | Claude (orchestrateur, in-session) |
| Plan | `plans/PLAN_CV1-V2-CATALOG-REWORK-001_2026-05-03.md` |
| Input audit | `reports/audit/ULTRA_REVIEW_GLOBAL_CATALOG_TREE_2026-05-03.md` (verdict REWORK + ESCALATE, score 78/100) |
| Verdict round 1 | **PASS** sur les 4 lots indépendants ; **ESCALATE** maintenu sur β-PRE-1 (gate humain ouvert) |
| Score qualité target | ≥ 90/100 (à confirmer post-audit consolidé) |

---

## 0. TL;DR

4 sub-agents lancés en parallèle ont livré 4 corrections en PASS sur les risques S2 R2/R3/R4/R5 + S1 R11/R12. Tests verts partout : Vitest **1054/0/2**, PHPUnit Composer **53/0/2** (incluant les 3 nouveaux tests 409 conflict), PHPUnit Items **9/0** (incluant les 2 nouveaux tests atomicité), PHPUnit I18n **2/0** (sentinelle parité), Playwright critical-flow **1 PASS 13.3s**. **R1 (S2 critique) reste bloqué** par gate humain Schema Migration ouvert (DDL `published_steps_snapshot`). Aucune frozen zone touchée, aucun invariant violé.

---

## 1. Mapping rapport ultra-review → corrections livrées

| Risque | Sévérité | Correction | Lot | Statut |
|---|---|---|---|---|
| R1 — `published_steps_snapshot` colonne absente | S2 critique | Gate humain ouvert (4 options) | β-PRE-1 | **GATE OUVERT** (humain) |
| R2 — `ItemPhotoController` non-atomique | S2 medium | Upload-then-swap pattern + 2 sentinelles | β-PRE-3 | **PASS** ✅ |
| R3 — Permission matrix design ≠ Spatie réel | S2 medium | Doc mapping + 5 exemples wiring `<v-can>` | β-PRE-4 | **PASS** ✅ |
| R4 — Backend 409 version conflict absent | S2 medium | Check `version` mismatch + 3 sentinelles | β-PRE-2 | **PASS** ✅ |
| R5 — axe-core skip silent = faux PASS CI | S2 medium | `package.json` dep + spec FAIL-not-skip + `ALLOW_AXE_SKIP=1` escape hatch | β-PRE-4 | **PASS** ✅ |
| R11 — Parité i18n studio non vérifiée | S1 mineur | PHPUnit `StudioKeyParityTest` (parité actuelle PASS) | β-PRE-S1 | **PASS** ✅ |
| R12 — `CatalogStudio` quick vs `ItemList` full divergence | S1 mineur | PHPUnit `ItemCreateContractTest` (2 payloads valides) | β-PRE-S1 | **PASS** ✅ |
| R6/R7/R8/R9/R10/R13/R14 | S0/S1 | Documentés dans rapport audit, hors scope round 1 | — | **DEFERRED V2** |

5/5 risques S2 traités (4 fixes + 1 gate). 2/9 risques S1 sentinellisés. Reste 7 risques S0/S1 documentés, déférés V2.

---

## 2. Sub-agent A — β-PRE-2 backend 409 (complex, foodking-complex-implementer)

**Livrables** :
- `app/Services/Composer/ComposerProfileService.php` — check `version` mismatch avant `DB::transaction` dans `update()` + payload optionnel `publish()`
- `app/Http/Requests/ComposerProfileRequest.php` — règle conditionnelle PUT/PATCH/publish
- `tests/Feature/Composer/ComposerProfileVersionConflictTest.php` (NEW, 3 tests)
- `reports/execution/RUN_BETA_PRE_2_BACKEND_409_2026-05-03.md`

**Contrat retour 409** : `{message: 'Profile version conflict', expected: int, got: int}` (HTTP 409).

**Tests** : Composer suite **53 passed / 2 skipped** (les 2 skipped sont pré-existants `PLAN_CV1-LIFECYCLE-UX-001 §2.2`, hors scope α/β).

**Rétro-compat** : payload sans champ `version` continue de fonctionner (vieux clients passent). Test `test_update_without_version_field_still_succeeds_for_back_compat` couvre.

**Invariants** : I4 dispatch-after-commit préservé (check 409 placé AVANT `DB::transaction`, aucun event dispatché si conflict).

---

## 3. Sub-agent B — β-PRE-3 atomic photo upload (complex, foodking-complex-implementer)

**Livrables** :
- `app/Http/Controllers/Admin/ItemPhotoController.php` — réécriture en pattern upload-before-delete
- `tests/Feature/Items/ItemPhotoUploadAtomicityTest.php` (NEW, 2 tests)
- `reports/execution/RUN_BETA_PRE_3_ATOMIC_PHOTO_2026-05-03.md`

**Stratégie atomicité** : la nouvelle media est ajoutée **avant** toute suppression de l'ancienne ; sur succès confirmé, l'ancienne est supprimée ; sur échec, l'ancienne reste intacte avec retour 500 `{message: 'Photo upload failed', error_code: 'UPLOAD_FAILED'}`.

**Tests** : Items suite **9 passed / 0 failed** = 5 tests ItemPhotoUploadTest existants (préservés) + 2 nouveaux atomicité + 2 autres déjà présents.

**Invariants** : signature retour JSON (`id`, `thumb_url`, `cover_url`, `preview_url`) inchangée pour compatibilité frontend.

---

## 4. Sub-agent C — β-PRE-4 + R5 (routine, foodking-routine-implementer)

**Livrables** :
- `docs/integration/STUDIO_PERMISSIONS_TO_SPATIE_MAP_2026-05-04.md` (NEW) — matrice 3 rôles design × 9 actions, mappés aux rôles Spatie réels (`Admin`, `Tenant Admin`, `Branch Manager`) et permissions concrètes (`items_*`, `composer_*`, `item_categories_*`, `settings`). 5 exemples `<v-can :permission="...">`.
- `package.json` — ajout `"@axe-core/playwright": "^4.10.0"` en devDependencies
- `tests/e2e/catalog-studio-a11y-axe.spec.js` — `beforeAll` FAIL si dep absente, sauf `ALLOW_AXE_SKIP=1` (escape hatch local)
- `reports/execution/RUN_BETA_PRE_4_PERM_AXE_2026-05-03.md`

**Vérifs syntaxe** : `node --check` sur la spec → exit 0. `JSON.parse(package.json)` → valide.

**Note finding** : `ComposerProfileController::__construct` n'enregistre pas de middleware permissions ; les gardes `catalog.compose` / `catalog.publish` sont dans `routes/api.php`. Documenté dans la doc mapping.

**Note opérationnelle** : `npm install` doit être lancé **manuellement** par DevOps avant prochain run CI a11y. Documenté.

---

## 5. Sub-agent D — β-PRE-S1 sentinelles parité (routine, foodking-routine-implementer)

**Livrables** :
- `tests/Feature/I18n/StudioKeyParityTest.php` (NEW, 2 tests) — vérifie présence + parité clés flatten du namespace `studio` entre 5 locales (FR/EN/DE/AR/BN).
- `tests/Feature/Items/ItemCreateContractTest.php` (NEW, 2 tests) — payload quick (CatalogStudio) et full (ItemListComponent legacy) → POST `/api/admin/item` → 201 + Item persisté.
- `reports/execution/RUN_BETA_PRE_S1_SENTINELS_2026-05-03.md`

**Tests** :
- I18n suite : **2 passed** (parité actuelle confirmée OK — sert maintenant de regression-guard)
- ItemCreate contract : **2 passed** (les 2 payloads sont valides backend)

**Aucune modification** des fichiers `lang/`, ni des controllers, ni des requests — sentinelles seulement.

**Note** : `php artisan test file1 file2` ne lance qu'un fichier en local — relance par dossier ou par fichier individuel.

---

## 6. Tests consolidés round 1 — toutes suites

| Suite | Résultat | Évolution vs cycle précédent |
|---|---|---|
| Vitest global | **1054 passed / 0 failed / 2 skipped** (163 fichiers) | identique (les sentinelles ajoutées sont PHPUnit) |
| PHPUnit Composer (`tests/Feature/Composer/`) | **53 passed / 0 failed / 2 skipped** | **+3 nouveaux tests** ComposerProfileVersionConflictTest, vs 50 baseline |
| PHPUnit Items (`tests/Feature/Items/`) | **9 passed / 0 failed** | **+2 nouveaux tests** ItemPhotoUploadAtomicityTest, vs 5 + 2 baseline |
| PHPUnit I18n (`tests/Feature/I18n/`) | **2 passed / 0 failed** | **NEW** suite (parité studio namespace) |
| Playwright critical-flow `catalog-studio-create-product-flow` | **1 passed (13.3s)** | rejoue identique post-rebuild (admin-shell.js + 18 steps) |
| Playwright a11y axe sentinel `catalog-studio-a11y-axe` | n'est pas tournée ce round (deps optionnel non installé localement) ; spec patchée pour FAIL-not-skip si CI sans dep | comportement attendu |

**Bilan** : **+5 nouveaux tests automatisés** sur cette ronde (3 backend 409 + 2 atomicité photo) + **2 sentinelles** (i18n parité, ItemCreate contract). Zéro régression.

---

## 7. Invariants FoodKing — table de conformité round 1

| Invariant | Statut | Évidence |
|---|---|---|
| I1 Pricing SSOT | ✅ OK | aucune logique prix Vue introduite ; les 2 controllers backend touchés (`ComposerProfileService`, `ItemPhotoController`) ne touchent pas au pricing |
| I2 OrderStatus enum | ✅ N/A | hors scope |
| I3 branch_id | ✅ OK | items mono-marque (audit confirmé) ; check 409 et atomic photo ne traversent aucun boundary branch ; `ItemPhotoController` reste sous `permission:items_edit` Spatie |
| I4 Dispatch after commit | ✅ OK | check 409 placé AVANT `DB::transaction` ; `ComposerProfileChanged` reste DispatchableAfterCommit ; aucun event dispatché si conflict |
| I5 OrderService symmetry | ✅ N/A | hors scope |
| I6 Frozen zones | ✅ OK | `app/Services/Pricing/`, `app/Services/Order/Lifecycle/`, `app/Services/Fiscal/` non touchés (vérifié par `find` git status) |

**6/6 invariants respectés.** Aucun nouveau risque introduit.

---

## 8. Gate humain ouvert — β-PRE-1 (R1, S2 critique)

| Champ | Valeur |
|---|---|
| Gate file | `docs/gates/GATE_CV1-V2-CATALOG-REWORK-PUBLISHED-SNAPSHOT_2026-05-03.md` |
| GATE_LOG | `docs/gates/GATE_LOG.md` ligne ajoutée (status `PENDING_HUMAN_GATE`) |
| Type | Schema Migration (DDL) |
| Trigger | `ComposerDiffService::projectPublishedProfile()` lit `$profile->getAttribute('published_steps_snapshot')` mais cette colonne n'existe pas en BDD |
| Conséquence prod | diff publish toujours `is_clean=true` pour profil publié → faux positif silencieux UX |
| Options | A (colonne JSON, recommandée V1) / B (table d'historique multi-versions) / C (refactor sans DDL, désactiver Diff Modal) / D (annuler β1.C4) |
| Bloquant pour | β1.C4 (Diff Modal Vue intégration design Claude v2 iter1 ④) |
| Non bloquant pour | tout le reste de Phase β (peut continuer en parallèle) |

**Action humaine requise** pour décision et signature.

---

## 9. Risques résiduels round 1

| # | Risque | Sévérité | Statut |
|---|---|---|---|
| R1 | `published_steps_snapshot` absent | S2 critique | **GATE OUVERT** humain |
| R6 | `comparable('min_select')` cast `(int)null === 0` | S1 mineur | accepté (en pratique `null` ne survient jamais via `normalize()`) |
| R7 | iframe drawer composer = double Vue app | S1 mineur | déféré V2 (`V2-WIZARD-COMPOSER-DRAWER`) |
| R8 | `tokens.css` design contient mirrors `--cv1-*` `--fk-*` (preview only) | S1 mineur | sentinel α3 protège `cv1-tokens.css` prod ; à appliquer règle d'intégration β1 |
| R9 | `item_branch_availability.branch_id` sans FK formelle | S1 mineur | déféré V2 (gate Schema Migration secondaire) |
| R10 | Friction caissier arabophone admin AR / POS LTR | S1 mineur | accepté design, formation utilisateur |
| R13 | E2E coverage trou : pas de `create-product-end-to-end` complet | S1 mineur | déféré β5 (extension Playwright après seed tax + branch défaut) |
| R14 | `KioskPosWizardComponent.vue` 18 lignes wrapper redondant | S0 cosmetic | déféré V2 cleanup |

**Bilan résiduel** : 0 S3, 1 S2 (R1 = gate humain), 6 S1 acceptés/déférés, 1 S0 cosmétique. Verdict round 1 : **prêt à clôture conditionnelle au gate β-PRE-1**.

---

## 10. Phase β — backlog re-confirmé

Le backlog β1→β5 du handoff `RUN_CATALOG_STUDIO_FINAL_DESIGN_HANDOFF_2026-05-03.md` §6 reste **valide** avec ces ajustements post round 1 :

| Cycle | Statut |
|---|---|
| **β-PRE-1** | OUVERT, attente humain (Schema Migration `published_steps_snapshot`) |
| **β-PRE-2** | ✅ DONE round 1 |
| **β-PRE-3** | ✅ DONE round 1 |
| **β-PRE-4** | ✅ DONE round 1 |
| **β-PRE-S1** | ✅ DONE round 1 |
| β1 — Critiques (5 angles) | Prêt sauf **β1.C4** (Diff Modal) qui dépend de β-PRE-1 |
| β2 — Importantes (7 angles) | Prêt — β2.I12 utilise mapping perm β-PRE-4 |
| β3 — Polish (5 angles) | Prêt sauf décision humaine `J/K/D/⌫` keybindings |
| β4 — Caveats config | Prêt (Noto Sans Arabic font-face) |
| β5 — Verification | Étendu : `npm i -D @axe-core/playwright` mandatoire pre-CI ; ajouter critical-flow `create-tacos-end-to-end` |

---

## 11. Verdict final round 1

**PASS conditionnel.**

- 4/4 sub-agents PASS (β-PRE-2/3/4/S1).
- 1054 + 53 + 9 + 2 + 1 = **1119 tests verts**, +5 tests automatisés ajoutés cette ronde, 0 régression.
- 6/6 invariants FoodKing respectés.
- 4/5 risques S2 du rapport ultra-review fixés ce round (R2 atomicité, R3 perm map, R4 conflict 409, R5 axe-core).
- 1/5 risque S2 (R1 critique) reste bloqué par **gate humain ouvert** (β-PRE-1, Schema Migration).

**Pas de REWORK round 2 nécessaire** sur le périmètre fixé. Le cycle peut **CLOSED** maintenant si l'humain accepte de poursuivre β-PRE-1 dans un cycle séparé `CV1-V2-CATALOG-REWORK-002`. Sinon, le cycle reste ouvert en attente de la décision DDL.

---

## 12. Mémoire Graphiti — facts à graver post-cycle

À ajouter via `add_memory(group_id="foodking")` après commit :

1. "Round 1 REWORK Catalog Studio post ultra-review : 4 sub-agents parallèles PASS — backend 409 conflict (β-PRE-2, ComposerProfileService check version + 3 tests), atomic photo upload-before-delete (β-PRE-3, ItemPhotoController + 2 tests), permissions Studio→Spatie mapping doc + axe-core dep mandatory CI (β-PRE-4), sentinelles i18n parity + ItemCreate contract (β-PRE-S1). Tests +5 automatisés, 0 régression."
2. "Catalog Studio R1 critique = `published_steps_snapshot` colonne absente → diff publish faux silencieux en prod. Gate humain Schema Migration ouvert : `docs/gates/GATE_CV1-V2-CATALOG-REWORK-PUBLISHED-SNAPSHOT_2026-05-03.md`. Recommandation auditeur Option A (JSON column). Bloque β1.C4 Diff Modal."
3. "ComposerProfileService::update accepte payload `version` optionnel ; si fourni et stale → 409 `{expected, got}`. Rétro-compat : sans `version` → comportement legacy. Test contract `ComposerProfileVersionConflictTest`."
4. "ItemPhotoController upload-before-delete : nouvelle media uploadée avant suppression ancienne ; sur échec retour 500 `{message:'Photo upload failed', error_code:'UPLOAD_FAILED'}` avec image originale intacte. Sentinel `ItemPhotoUploadAtomicityTest` 2 cas (storage throw, success swap)."
5. "Permission mapping Studio design (`super_admin`/`branch_manager`/`kitchen_manager` × `catalog.*`) → Spatie réel : doc `docs/integration/STUDIO_PERMISSIONS_TO_SPATIE_MAP_2026-05-04.md`. `kitchen_manager` ABSENT du repo, gate produit pending. Permissions composer gates dans `routes/api.php`, pas controller."
6. "Sentinel `catalog-studio-a11y-axe.spec.js` patché : FAIL si `@axe-core/playwright` absent en CI ; escape hatch local `ALLOW_AXE_SKIP=1`. `package.json` declares dep `^4.10.0`, install manuel à lancer."
7. "i18n parité studio namespace OK actuellement (sentinel `StudioKeyParityTest` 2/2 PASS) entre FR/EN/DE/AR/BN. Sert de regression-guard désormais."

---

## 13. Fichiers modifiés / créés round 1

### Modifiés (4)
- `app/Services/Composer/ComposerProfileService.php` — check 409
- `app/Http/Requests/ComposerProfileRequest.php` — règle version conditionnelle
- `app/Http/Controllers/Admin/ItemPhotoController.php` — pattern atomique
- `package.json` — devDependency axe-core
- `tests/e2e/catalog-studio-a11y-axe.spec.js` — FAIL-not-skip pattern

### Créés (8)
- `plans/PLAN_CV1-V2-CATALOG-REWORK-001_2026-05-03.md`
- `docs/gates/GATE_CV1-V2-CATALOG-REWORK-PUBLISHED-SNAPSHOT_2026-05-03.md`
- `docs/integration/STUDIO_PERMISSIONS_TO_SPATIE_MAP_2026-05-04.md`
- `tests/Feature/Composer/ComposerProfileVersionConflictTest.php`
- `tests/Feature/Items/ItemPhotoUploadAtomicityTest.php`
- `tests/Feature/I18n/StudioKeyParityTest.php`
- `tests/Feature/Items/ItemCreateContractTest.php`
- `reports/execution/RUN_BETA_PRE_2_BACKEND_409_2026-05-03.md`
- `reports/execution/RUN_BETA_PRE_3_ATOMIC_PHOTO_2026-05-03.md`
- `reports/execution/RUN_BETA_PRE_4_PERM_AXE_2026-05-03.md`
- `reports/execution/RUN_BETA_PRE_S1_SENTINELS_2026-05-03.md`
- `reports/execution/RUN_CV1_V2_CATALOG_REWORK_001_2026-05-03.md` (ce fichier)

### Mis à jour (2)
- `.cursor/ACTIVE_CYCLE.md` — pivot vers `CV1-V2-CATALOG-REWORK-001`
- `docs/gates/GATE_LOG.md` — entry `PENDING_HUMAN_GATE` β-PRE-1

---

## 14. Action utilisateur requise (synthèse 1 ligne)

**Choisis Option A/B/C/D dans le gate `docs/gates/GATE_CV1-V2-CATALOG-REWORK-PUBLISHED-SNAPSHOT_2026-05-03.md` pour débloquer β1.C4** ; tout le reste est prêt à intégration UI.
