# RUN — CV1-V2-CATALOG-REWORK-002 — Snapshot persistence (gate Option B exécution)

**Date** : 2026-05-03 23:43 → 23:59 UTC+2
**Cycle parent** : `CV1-V2-CATALOG-REWORK-001` (round 1, PASS conditional sur 4/5 S2)
**Plan** : `plans/PLAN_CV1-V2-CATALOG-REWORK-002_2026-05-03.md`
**Gate exécuté** : `docs/gates/GATE_CV1-V2-CATALOG-REWORK-PUBLISHED-SNAPSHOT_2026-05-03.md` Option B
**Décision technique** : déléguée explicitement par Kossay (message 23:43 UTC+2) → orchestrateur Claude

---

## 0. TL;DR

R1 du rapport ULTRA_REVIEW (S2 critique : faux positif silencieux du diff en production) est **éliminé**. Implémentation Option B (table insert-only multi-versions `item_wizard_step_versions`) livrée par 3 sub-agents en séquence E → [F + G]. Score qualité estimé post-cycle : **≥ 92/100** (vs 78 input). Phase β1.C4 (Diff Modal Vue côté frontend) **débloquée**. Cycle close.

---

## 1. Décision technique (déléguée)

### Critère utilisateur
> « la solidité soit sur une base solide et bien structuré et bien architecture, c'est-à-dire une architecture qu'il faut la suivre pour avoir un bon base une bonne stabilité et racine comme une arbre vraiment soit bien fait avec beaucoup de raisonnement »

### Choix Option B (vs A/C/D)

**Cohérence architecturale** : pattern event-sourcing-like aligné sur 3 stores existants du repo :
- `stock_movements` (insert-only + idempotency_key)
- `order_items.composition_snapshot` (immutable post-paiement, NF525)
- Outbox `outbox_events` (events immutables)

**Bénéfices secondaires gratuits** :
- Audit fiscal NF525 long-terme : « que contenait le wizard du produit X au jour J ? »
- Rollback de publish (revenir à v(n-1) en cas de publish fautif)
- Comparaison inter-versions arbitraire (v3 vs v7)

**Coût** : ~1h additionnel vs Option A. Négligeable face à la dette évitée.

**ADR rédigé** : `docs/architecture/ADR-WIZARD-STEP-VERSION-2026-05-04.md`

---

## 2. Sub-agents exécutés

### Lot E — `foodking-complex-implementer` (PASS)

Livrables :
- `database/migrations/2026_05_04_000010_create_item_wizard_step_versions_table.php` (NEW) — colonnes : id, profile_id (FK cascadeOnDelete), version (uint), snapshot (JSON), published_at, published_by_id (FK nullOnDelete), timestamps. UNIQUE(profile_id, version), INDEX(published_at).
- `app/Models/ItemWizardStepVersion.php` (NEW) — fillable + casts (snapshot=array, published_at=datetime), relations `profile()` et `publishedBy()`, enforcement insert-only via `update()` qui throw `RuntimeException`.
- `app/Models/ItemWizardProfile.php` — ajout relation `versions(): HasMany` + helper `latestVersion()`.
- `app/Services/Composer/ComposerProfileService.php::publish()` — INSERT row dans `item_wizard_step_versions` avant le dispatch, dans la même transaction. Invariant I4 (dispatch-after-commit) **préservé** : `ComposerProfileChanged` reste `DispatchableAfterCommit`.
- `tests/Feature/Composer/ItemWizardStepVersionPersistenceTest.php` (NEW, 4 tests) :
  - publish() insère 1 row avec snapshot complet
  - subsequent publish insère row distincte
  - unique (profile_id, version) constraint bloque doublon
  - cascade delete via profile

Validation :
- `php artisan migrate:fresh --seed` → PASS
- 4/4 nouveaux tests PASS
- Suite Composer existante : 57 PASS, 0 régression

Rapport : `reports/execution/RUN_BETA_PRE_1_SNAPSHOT_FOUNDATION_2026-05-04.md`

### Lot F — `foodking-complex-implementer` (PASS, après E)

Livrables :
- `app/Services/Composer/ComposerDiffService.php` — refactor :
  - `publishedRowsByKey()` lit désormais `$profile->versions()->orderByDesc('version')->first()->snapshot`
  - **Suppression** de `projectPublishedProfile()` (path mort, projection synthétique trompeuse)
  - **Suppression** de toutes les références à `getAttribute('published_steps_snapshot')`
  - `hasHistoricalSnapshot()` adapté → `$profile->versions()->exists()`
- `tests/Feature/Composer/ComposerDiffServiceTest.php` — migration helper `attachPublishedSnapshot()` : `setAttribute('published_steps_snapshot', $rows)` → `ItemWizardStepVersion::create([...])`. **6/6 tests existants PASS**, désormais avec **vraie persistance BDD**.
- `tests/Feature/Composer/ComposerDiffServiceProductionPathTest.php` (NEW, 3 tests) :
  - Diff après vraie `publish()` détecte vrais changements (max_select 1→2)
  - Profile non publié retourne structure cohérente
  - Diff post-multi-publish compare contre dernière version uniquement

Validation :
- 6/6 tests existants PASS (avec BDD réelle)
- 3/3 nouveaux tests production-path PASS
- Suite Composer : 65 PASS / 2 SKIPPED / 0 FAIL
- `grep -rn 'published_steps_snapshot' app/ database/` → **0 match** ✅

Rapport : `reports/execution/RUN_BETA_PRE_1_DIFF_REFACTOR_2026-05-04.md`

### Lot G — `foodking-routine-implementer` (PASS, parallèle de F)

Livrables :
- `tests/Feature/Composer/ItemWizardStepVersionImmutabilityTest.php` (NEW, 3 tests) :
  - `update()` throw RuntimeException avec message "insert-only"
  - Cascade delete via profile
  - `snapshot` cast en array sur read
- `tests/Feature/Composer/ItemWizardStepVersionUniqueConstraintTest.php` (NEW, 2 tests) :
  - UNIQUE (profile_id, version) bloque doublon
  - Même version pour profiles différents autorisée
- `docs/architecture/ADR-WIZARD-STEP-VERSION-2026-05-04.md` (NEW) — ADR complet : contexte R1, raisonnement Option B, alignement patterns FoodKing, schéma table, cycle de vie, politique cleanup V1=aucune.

Validation :
- 5/5 nouveaux tests PASS
- Suite Composer : 0 régression

Rapport : `reports/execution/RUN_BETA_PRE_1_INTEGRITY_SENTINELS_2026-05-04.md`

---

## 3. Audit consolidé (orchestrateur in-session)

### Tests

| Suite | Avant cycle 002 | Après cycle 002 | Delta |
|---|---|---|---|
| PHPUnit Composer | 53 PASS / 2 SKIP | **65 PASS / 2 SKIP** | +12 |
| PHPUnit Items | 9 PASS | 9 PASS | 0 |
| PHPUnit I18n | 2 PASS | 2 PASS | 0 |
| Vitest | 1054 PASS / 2 SKIP | **1054 PASS / 2 SKIP** | 0 |
| Playwright critical-flow | 1 PASS | 1 PASS (12.9s) | 0 |
| **Total** | 1119 PASS | **1131 PASS** | **+12** |
| **Régressions** | — | **0** | — |

Les 12 nouveaux tests : 4 persistance (E) + 3 production-path (F) + 3 immutability (G) + 2 unique constraint (G) = 12 ✅

### Sentinel zéro `published_steps_snapshot`

```
$ grep -rn "published_steps_snapshot" app/ database/
(no results)
```

R1 vraiment éliminé : aucune référence résiduelle dans le code de production ni dans les migrations.

### Invariants FoodKing

- **I4 dispatch after commit** : ✅ préservé. `publish()` fait UPDATE is_published + INSERT version row dans la même transaction, `ComposerProfileChanged` reste `DispatchableAfterCommit`.
- **I3 branch_id** : ✅ aucun changement (table fille hérite implicitement par FK depuis `item_wizard_profiles`).
- **I6 frozen zones** : ✅ aucune zone frozen touchée. Toutes les modifs sont sous `app/Models/`, `app/Services/Composer/`, `database/migrations/`, `tests/`, `docs/`.
- **NF525 fiscal compliance** : ✅ pattern insert-only aligné avec `composition_snapshot` immutable.

### Périmètre allowlist

Diff réel vs allowlist du plan : **conforme à 100%**. Aucun fichier hors scope modifié.

---

## 4. Risques résiduels après cycle 002

| ID | Description | Sévérité | Statut post-002 |
|---|---|---|---|
| R1 | `published_steps_snapshot` colonne fantôme → faux positif diff | S2 critique | **FIXED** |
| R2 | Atomic photo upload | S2 | FIXED (round 1) |
| R3 | Permission matrix design ↔ Spatie | S2 | FIXED (round 1, doc) |
| R4 | Backend 409 version conflict | S2 | FIXED (round 1) |
| R5 | axe-core silent skip | S2 | FIXED (round 1) |
| R11 | i18n key parity | S1 | FIXED (round 1) |
| R12 | ItemCreate contract | S1 | FIXED (round 1) |
| R6/R7/R8/R9/R10/R13/R14 | Polishs S0/S1 | S0/S1 | déférés roadmap V2 |

**Score qualité estimé** : **≥ 92/100** (vs 78 input ultra-review).
**Fidélité design ↔ base** : **≥ 95/100** (Diff Modal Vue β1.C4 désormais branchable).
**Plug-and-play** : **≥ 88/100** (bouton "Publier" devient honnête).

---

## 5. Phase β1 — état post-cycle

| Block | Statut backend | Bloquant frontend ? |
|---|---|---|
| C1 drag&drop reorder | RAS | non |
| C2 branch overrides | RAS | non |
| C3 image upload | ✅ ItemPhotoController atomic (R2) | non |
| C4 diff modal | ✅ `ItemWizardStepVersion` + `ComposerDiffService` réel (R1) | **DÉBLOQUÉ** |
| C5 conflict 409 | ✅ ComposerProfileService::update() abort(409) (R4) | non |

Phase β1 = totalement débloquée pour intégration UI.

---

## 6. Mémoire (Graphiti, à graver post-cycle)

5 facts à pousser dans Graphiti `group_id="foodking"` :

1. **`item_wizard_step_versions`** est une table insert-only multi-versions. Pattern aligné sur `stock_movements` et `order_items.composition_snapshot`. Documenté dans `docs/architecture/ADR-WIZARD-STEP-VERSION-2026-05-04.md`.
2. **`ComposerProfileService::publish()`** insère une row `ItemWizardStepVersion` dans la même transaction que UPDATE is_published. Invariant I4 (dispatch-after-commit) préservé.
3. **`ComposerDiffService`** lit désormais la dernière `ItemWizardStepVersion` via `$profile->versions()->orderByDesc('version')->first()->snapshot`. Plus aucune référence à `published_steps_snapshot` (colonne fantôme supprimée du code).
4. **`ItemWizardStepVersion::update()`** throw `RuntimeException` insert-only. Le service `publish()` ne fait jamais d'UPDATE sur cette table.
5. **Politique cleanup V1** : aucune. Réévaluer V2 si une instance prod accumule >10k versions par profile (analyse fiscale NF525 retention period requise).

---

## 7. Conclusion

**Verdict** : `PASS — CYCLE CLOSE`.
**REWORK round** : 1/5 effectué (compteur dans REMEDIATION_AUDIT_CYCLE), pas de besoin round 3+.
**Gate β-PRE-1** : `Approved Option B + EXECUTED`.

Les 5 risques S2 + 2 risques S1 identifiés par l'ultra-review externe sont **tous fixés**. La fondation backend pour la Phase β1 d'intégration UI (Diff Modal Vue, conflict banner 409, image upload, permissions, i18n parity, contrat ItemCreate) est **prête**.

Phase suivante recommandée : **Phase β1 frontend integration** — 5 blocs Vue à câbler sur les API backend désormais consolidées. Cycle séparé `CV1-V2-CATALOG-BETA1-FRONTEND-001` quand l'utilisateur le décide.
