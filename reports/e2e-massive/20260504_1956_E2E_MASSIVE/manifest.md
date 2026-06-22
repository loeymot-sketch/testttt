# Manifest — RUN `20260504_1956_E2E_MASSIVE`

| Ordre | Plan | Step / lot | Automate | Verdict | Capture / artefact |
| --- | --- | --- | --- | --- | --- |
| 1 | P0 | PHPUnit Config + Order SSOT + Stock + Ingredients | `php artisan test` (voir log) | **PASS** (71 passed, 4 skipped PHPUnit) | `logs/phpunit_P0_stock_ingredients.log` |
| 2 | P1–P5 | Playwright `tests/Playwright` (sans `E2E_BACKEND_AVAILABLE`) | `npx playwright test tests/Playwright` | **PARTIEL** — 9 passed, 9 skipped (critical-flow skippés sans flag) | `logs/playwright_P1_P5_all.log` |
| 3 | P1–P4 | Playwright `tests/Playwright/critical-flow` avec `E2E_BACKEND_AVAILABLE=1` | `npx playwright test tests/Playwright/critical-flow` | **PARTIEL** — 2 passed, 1 skipped, **3 failed** | `logs/playwright_critical_flow_with_backend.log` |
| 4 | P0 UI | Capture échecs Playwright (preuve visuelle) | copie PNG | **FAIL documenté** | `screenshots/Playwright-critical-flow-*_test-failed-1.png` |

## Légende verdict

- **PASS** : seuil succès complet.
- **PARTIEL** : exécution technique OK mais couverture incomplète ou dépendances données/outils.
- **FAIL** : au moins un test rouge — voir rapport consolidé.

## Variables d’environnement utilisées

- `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000`
- `E2E_BACKEND_AVAILABLE=1` (vague 3 uniquement)

## Captures « humain à valider »

Les PNG dans `screenshots/` proviennent des **échecs** Playwright (config `only-on-failure` + retry). Revue visuelle recommandée pour P1 (login admin / page ingrédients vide).
