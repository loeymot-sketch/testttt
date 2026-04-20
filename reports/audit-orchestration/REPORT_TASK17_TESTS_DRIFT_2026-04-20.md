# T17 — Tests drift Vitest + PHPUnit (audit statique)

**Date.** 2026-04-20  
**Racine.** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`  
**Mode.** Audit **statique** uniquement — PHPUnit/Vitest **non exécutés**.  
**Verdict.** **FAIL** (impossible de prouver "0 régression" sans run).

## Section A — Résultat brut

| Suite | Statut | Passed | Failed | Skipped | Durée |
|-------|--------|--------|--------|---------|-------|
| PHPUnit `tests/Feature` | Non exécuté | — | — | — | — |
| Vitest (`npx vitest run`) | Non exécuté | — | — | — | — |

### Prérequis (lecture seule)
- **`.env.testing`** : **absent** à la racine.
- **`reports/execution/RUN_K10_ACCEPTANCE_2026-04-19.md`** : **absent** dans ce dépôt (l'index T01 indique des livrables K10 dans l'autre worktree `testttt-kiosk-p93`).

### Configuration PHPUnit (`phpunit.xml`)
- Suites Unit + Feature ; cible T17 : `./vendor/bin/phpunit tests/Feature`.
- Env : `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, drivers test, `TELESCOPE_ENABLED=false`, `PUSHER_*` vidés, secrets fiscaux test non vides.

### Configuration Vitest (`vitest.config.mjs`)
- `environment: 'happy-dom'`.
- `include: ['tests/js/**/*.spec.js']` — Playwright `tests/e2e/*.spec.js` **hors** Vitest.

### Inventaire statique
- Fichiers Feature : **130** `*Test.php` sous `tests/Feature/`.
- Fichiers Vitest : **53** `*.spec.js` sous `tests/js/`.
- Occurrences `^\s*(it|test)\(` dans specs Vitest : **~410** lignes (sous-estime à cause des `it.each`).
- Aucun `it.skip` / `describe.skip` / `test.skip` détecté dans `tests/js/`.

## Section B — Delta vs baseline K-10 (510 / 8 PHPUnit ; 718 / 1 Vitest)

| Indicateur | Baseline K-10 | État statique `testttt` |
|------------|----------------|--------------------------|
| PHPUnit Feature passed | 510 | Non vérifiable sans exécution |
| PHPUnit Feature skipped | 8 | **7** appels `markTestSkipped` repérés (6 fichiers) — écart **±1** plausible |
| Vitest passed | 718 | Non vérifiable (sous-estimé par `it.each`) |
| Vitest skipped | 1 | Aucun skip explicite ; non confirmable sans run |
| Document baseline | `RUN_K10_ACCEPTANCE_2026-04-19.md` | **Manquant** |

### Points de dérive structurelle vs intention T17
- **Allergènes / i18n / snapshot** — `NormalItemResourceAllergensTest`, `ItemResourceAllergensTest`, `OrderAllergenSnapshotTest`, `KDSAllergenVisibilityTest`, seeders allergènes : zone à surveiller post renommage FR.
- **Vitest** : peu de référence directe à `allergen` ; **`PosComponent.spec.js`** assert encore un libellé `Allergenes:` — sensible à un changement i18n.
- **`sentry.js` / `kioskPerf` / `postEvent`** — aucune occurrence dans `tests/js/`. Une régression liée à la suppression/déplacement de modules se manifesterait surtout au montage des composants ou imports → détectable uniquement par run Vitest ou build.
- **`type="button"`** — aucune assertion explicite dans les specs JS ; risque plutôt E2E / a11y, hors Vitest unitaire.

## Section C — Hypothèses prioritaires (à confirmer après run)

1. Feature allergènes + snapshot commande + KDS (alignement codes FR, `OrderDetailsResource`, migration snapshot DB test).
2. **`FrontendSurfaceFilteringTest`** — skip systématique si DB test reste SQLite (cf. `setUp`).
3. **`PosComponent.spec.js`** — texte "Allergenes" / structure détails.
4. Composants kiosk modifiés récemment (paiement, badge allergène, filtres) — risque Vitest sur stubs/mocks ou i18n.
5. Playwright `tests/e2e/` (cf. traces d'échec KDS dans le statut git utilisateur) — hors `vitest.config.mjs` mais pertinent pour drift global.

## Top 3 actions

1. **Exécuter** les deux commandes T17 (après `composer install` / `npm ci` et `.env.testing`), capturer passed/failed/skipped/temps.
2. **Récupérer** la baseline K-10 depuis le worktree / commit où elle existe (`VERIFY_K10_ACCEPTANCE_*` ou équivalent), coller les counts en Section B.
3. **En cas d'échecs**, trier par thème (allergènes/i18n, sentry/perf supprimés, paiement) et trancher corriger-test vs corriger-code (T17b).

## Décision

**T17 FAIL** — pas de preuve d'absence de régression possible sans exécution réelle. Statique : structure cohérente, mais **risques explicites** sur allergènes/i18n et imports `sentry.js`/`kioskPerf.js` (récemment refaits).
