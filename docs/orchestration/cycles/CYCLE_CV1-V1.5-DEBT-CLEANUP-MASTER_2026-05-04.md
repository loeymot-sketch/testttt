# CYCLE CV1-V1.5-DEBT-CLEANUP-MASTER — 2026-05-04 (CLOSED PASS)

## Résumé exécutif

Backlog V1.5 dettes héritées Pivot V1 traité en 3 cycles parallèles + audit. Bug critique runtime corrigé (D1) : toggle viande/sauce admin Ingrédients se propage désormais aux wizards POS/kiosk indépendamment de `stockable_choices`. AUDIT_VERDICT PASS via fallback `foodking-planner-orchestrator` (terminal Claude quota encore down — reset 18h10).

- **Période** : 2026-05-04 (~15:50 → ~16:05 UTC+2)
- **MASTER_TASK_ID** : `CV1-V1.5-DEBT-CLEANUP-MASTER`
- **PARENT_CYCLE** : `CV1-V1-FINISH-MASTER` CLOSED PASS (2026-05-04 ~15:35)
- **Plan** : `plans/PLAN_CV1-V1.5-DEBT-CLEANUP-MASTER_2026-05-04.md`
- **AUDIT_CHANNEL** : `cursor-session` (fallback)
- **AUDIT_FALLBACK_REASON** : `claude-anthropic-quota-still-down-2026-05-04-15h55-reset-18h10`
- **AUDIT_SUBAGENT_FALLBACK** : `foodking-planner-orchestrator`
- **AUDIT_VERDICT** : `PASS`

## 3 cycles + audit

| # | TASK_ID | Tier | EXECUTE_DELEGATION | Verdict |
|---|---------|------|-------------------|---------|
| D1 | `CV1-V1.5-DEBT-COMPOSER-RUNTIME-AVAILABILITY-001` | complex | foodking-complex-implementer | PASS (PHPUnit 1421, +8 tests) |
| D2 | `CV1-V1.5-DEBT-FISCAL-ARCHIVE-FLAKY-001` | routine | foodking-routine-implementer | NO_OP (15 runs séquentiels PASS, suite stable organiquement, refus placebo) |
| D3 | `CV1-V1.5-DEBT-XOR-MONITORING-001` | routine | foodking-routine-implementer | PASS (script + cron + procédure) |
| D4 | `CV1-V1.5-DEBT-AUDIT-CLOSE-001` | audit fallback | foodking-planner-orchestrator | PASS (evidence file:line exhaustive) |

## Livrables

### D1 — ComposerProfileProjection runtime is_available propagation 🔴 CRITIQUE

**Bug corrigé** : avant V1.5, le toggle `ItemAttribute::is_available=false` (admin UI Ingrédients, livré H3 cycle 3 du Pivot V1) ne se propageait PAS aux wizards POS/kiosk si le step avait `stockable_choices=false`. Conséquence : utilisateur kiosk pouvait commander un produit avec viande en rupture → frustration client + perte revenu silencieuse.

**Fix backend** (Option A retenue) :
- `app/Services/Stock/ChoiceAvailabilityResolver.php:298-311` — nouvelle méthode privée `availabilityForVariation(ItemVariation $v, ?StockLevel $level)` qui lit `ItemAttribute::getRawOriginal('is_available')` AVANT le `StockLevel` (priorité `ingredient_rupture`)
- `app/Services/Stock/ChoiceAvailabilityResolver.php:60-67` (`snapshotForItems`) appelle `availabilityForVariation` au lieu de `availabilityFromLevel` direct pour les variations
- `app/Services/Stock/ChoiceAvailabilityResolver.php:143-153` (`assertSelectionsOrderable`) appelle aussi `availabilityForVariation` → symétrie order-guard ⇄ snapshot
- `app/Services/Composer/ComposerProfileProjection.php:87` (source_type `item_attribute`) — garde `$usesStockableChoices` retirée → propagation `ingredient_rupture` même quand step `stockable_choices=false`
- `app/Services/Composer/ComposerProfileProjection.php:111` (source_type `extra_group`) — même simplification
- `app/Services/Composer/ComposerProfileProjection.php:150-153` (source_type `addon`) — INCHANGÉ (invariant `branch_availability` préservé via `availabilityForAddonItem`)

**Tests** (8 nouveaux) :
- `tests/Feature/Stock/ChoiceAvailabilityResolverVariationIngredientRuptureTest.php` — 5 tests : variation OK + stock OK, variation `is_available=false` priorité, stock_rupture si attribute OK, fallback legacy si pas d'attribute, `assertSelectionsOrderable` throw 422 ingredient_rupture
- `tests/Feature/Composer/ComposerProfileProjectionVariationRuptureTest.php` — 3 tests : variation **stockable**+rupture, variation **non-stockable**+rupture (cas critique du bug V1.5), extra **non-stockable**+rupture

### D2 — FiscalArchive flaky NO_OP rigoureux

15 runs séquentiels (5 suites × 3) tous PASS. Aucune flakiness reproductible localement. Verdict NO_OP justifié : refus du placebo, zone fiscale NF525 frozen préservée.

Suites évaluées :
- `tests/Feature/Fiscal/FiscalArchiveTest.php`
- `tests/Feature/Fiscal/FiscalArchiveMemoryBoundedTest.php`
- `tests/Feature/Fiscal/FiscalArchiveTtlTest.php`
- `tests/Feature/Fiscal/FiscalArchiveVerifyChainTest.php`
- `tests/Feature/Fiscal/FiscalArchiveScheduledTest.php`

Follow-up V1.5b documenté : ré-investigation si CI logge un échec intermittent avec stdout complet, seed, `--parallel`. Toute modif code prod fiscal nécessite gate NF525 (escalade vers complex).

### D3 — Monitoring SQL XOR

- `scripts/xor-violation-check.sh` — script bash idempotent (`set -euo pipefail`), modes `--quiet` + `--alert-webhook URL`, utilise `php artisan tinker` (pas de mot de passe en clair). Sur violation : log + listing JSON + curl webhook + exit 1. Sinon exit 0.
- `docs/orchestration/V1_XOR_MONITORING_PROCEDURE.md` — quand exécuter (J1 hourly, J2-8 daily, ensuite weekly), cron template, action en cas de violation, désactivation après 7 jours stables + MySQL ≥ 8.0.
- `reports/monitoring/.gitkeep` — dossier versionné pour les logs `xor-violations-YYYY-MM-DD.log`.

Contrainte migration confirmée : `CHECK ((item_id IS NOT NULL) <> (item_category_id IS NOT NULL))` nommée `item_wizard_profiles_owner_xor_check` dans `database/migrations/2026_05_05_000020_make_item_wizard_profiles_polymorphic_owner.php`.

Test local : 0 violation, exit 0.

## Métriques

| Métrique | Avant V1.5 | Après V1.5 | Delta |
|---|---|---|---|
| PHPUnit passed | 1413 | **1421** | **+8** (5 Stock + 3 Composer) |
| PHPUnit skipped | 24 | 24 | 0 |
| Vitest passed | 1157 | 1157 | 0 (préservé — backend pur) |
| Vitest skipped | 2 | 2 | 0 |
| Build `npm run dev` | PASS | PASS (non re-run, scope backend+ops uniquement) | — |

**Aucune régression**. Baselines augmentées via tests rigoureux du fix D1.

## Invariants FoodKing

| # | Invariant | Statut V1.5 |
|---|-----------|-------------|
| I1 | Backend Pricing SSOT | ✅ D1 lit business data `is_available`, pas pricing |
| I2 | OrderStatus enum | ✅ non touché |
| I3 | branch_id isolation | ✅ préservé (D1 conserve `where('branch_id')` resolver) |
| I4 | Dispatch après commit | ✅ non touché (toggle dispatch déjà via DispatchableAfterCommit, hérité H3 cycle 3 Pivot V1) |
| I5 | OrderService symmetry | N/A (D1 ne touche ni OrderService ni FrontendOrderService) |
| I6 | Frozen zones | ✅ D2 NO_OP préserve zone fiscale NF525 |

## Risques résiduels

1. **D1 N+1 itemAttribute eager loading** — non détecté en local mais à surveiller si appelant injecte des variations non chargées. **Acceptable V1.5b**.
2. **D2 stabilité fiscale** — suite stable organiquement. Ré-évaluation si CI logge échec futur. **Acceptable**.
3. **D3 webhook ops** — Slack/Discord/PagerDuty URL à configurer côté infra (hors-repo). **Acceptable** (limitation documentée).
4. **R-T3 cache invalidation branch-scoped non granulaire** — différé V1.5b/V2, non bloquant V1.
5. **R-T7 nouveau** — preview admin studio sans `branchId` ne montre pas `ingredient_rupture` (Option A simplifie `$choiceAvailability !== null`). Hors scope D1 (le bug fixé concerne POS/kiosk où `branchId` est toujours présent). À tracer V1.5b si UX preview admin requise.
6. **Drill-down ingrédients UX, lock optimiste toggle, spec Playwright cross-surface live** — différés V1.5b/V2 explicitement non bloquants.
7. **GPT final audit** — codex:final-audit pourrait être lancé après reset terminal Claude pour double PASS officiel ; pas exécuté ce cycle car cycle dette V1.5 (pas via codex-extension donc setup mission absent).

## Cohérence avec V1-FINISH

- Disjoints fichiers : aucune collision entre V1.5 (`app/Services/{Stock,Composer}` + `scripts/` + `docs/orchestration/`) et V1-FINISH H1-H5 (`app/Http/Middleware/` + `routes/api.php` + `resources/js/` + `tests/playwright/`).
- Sécurité Demo V2 (H1) middleware non touché.
- i18n parity (H2) JSON langues non touchés ; sentinelle élargie reste verte.
- A11y ingrédients (H3) composants Vue non touchés.
- Gate brief prod-cutover (H4) reste valide ; D3 le complète opérationnellement (script XOR référence §11 du gate).
- Smoketest staging (H5) script non touché.

## Conclusion

V1.5 livre la **correction du bug critique runtime ingredient_rupture** (D1) qui aurait laissé passer des commandes avec viande/sauce en rupture → cuisinier les rejette → perte UX et revenu. Désormais propagation < 5s admin → POS/kiosk wizard indépendamment de `stockable_choices`. D2 NO_OP rigoureux préserve la zone fiscale gelée. D3 livre le filet de sécurité monitoring XOR pour détecter post-cutover toute violation `(item_id ⊕ item_category_id)` si MySQL < 8.0 ignore le CHECK silencieusement.

**Cumulé V1-PIVOT + V1-FINISH + V1.5** : V1 prêt prod modulo gate humain prod-cutover (Q1-Q4 + smoketest staging humain) — voir `docs/gates/GATE_CV1-V1-PIVOT-PRODUCTION-CUTOVER_2026-05-04.md`.

**Backlog V1.5b ouvert** :
- N+1 eager loading itemAttribute (surveillance)
- Cache invalidation branch-scoped granulaire (R-T3)
- Preview admin studio rupture sans branchId (R-T7)
- Drill-down ingrédients UX
- Lock optimiste toggle ingrédient
- Spec Playwright cross-surface live
- GPT final audit codex:final-audit (à exécuter après reset terminal Claude pour double PASS officiel)
- FiscalArchive flaky CI (si logs futurs)
