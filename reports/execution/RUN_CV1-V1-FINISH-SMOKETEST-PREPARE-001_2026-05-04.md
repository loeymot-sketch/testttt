# RUN — CV1-V1-FINISH-SMOKETEST-PREPARE-001 — 2026-05-04

**EXECUTE_DELEGATION:** `foodking-routine-implementer`

## Scope (H5 — Master V1-FINISH)

Livraison du script de smoketest staging idempotent et de la procédure humaine associée, sans modification backend/frontend.

## Fichiers créés

| Fichier | Rôle |
|---------|------|
| `scripts/v1-pivot-staging-smoketest.sh` | Smoketest séquentiel 10 étapes (`--dry-run`, `--skip-playwright`), journaux `/tmp/v1-smoketest-step-N.log`, rapport `reports/execution/SMOKETEST_V1_*.log` |
| `docs/orchestration/V1_PIVOT_STAGING_SMOKETEST_PROCEDURE.md` | Pré-requis staging, procédure 5 étapes, échecs, critères PASS |

## Validation locale

### `bash -n` (syntaxe)

```text
(exit 0 — aucune sortie)
```

### `bash scripts/v1-pivot-staging-smoketest.sh --dry-run` (extrait)

```text
=== FoodKing V1 Pivot staging smoketest ===
started: …
REPO: …/testttt
DRY_RUN=1 SKIP_PLAYWRIGHT=0 BASE_URL=http://localhost:8000
[step 1/10] Pre-flight (php, node, npm, npx, env, playwright)...
[step 1/10] OK
[step 2/10] Migrations dry-run (pretend + status)...
[step 2/10] OK
[smoketest] DRY_RUN: stopping after step 2 (no DB mutations)
[smoketest] ALL 2 STEPS PASSED (dry-run)
[smoketest] Report: …/reports/execution/SMOKETEST_V1_YYYY-MM-DD_HHMMSS.log
```

## Limitations

- Un run **complet** (hors `--dry-run`) exige une BDD Laravel utilisable, les quotas temps pour PHPUnit + Vitest + `npm run prod`, et pour l’étape 10 un serveur joignable sur `BASE_URL`, `E2E_BACKEND_AVAILABLE=1`, et Chromium installé pour Playwright (`npx playwright install chromium`).
- L’étape 4 (`migrate:rollback --step=4`) suppose au moins quatre **batches** de migrations récents cohérents avec la procédure Pivot V1 ; sinon restaurer une sauvegarde BDD (voir procédure).
- En `--dry-run`, l’absence de CLI Playwright est un **avertissement** seulement (run complet sur une machine sans Playwright échouera à l’étape 1 sauf `--skip-playwright`).

## Trace

`EXECUTE_DELEGATION: foodking-routine-implementer`
