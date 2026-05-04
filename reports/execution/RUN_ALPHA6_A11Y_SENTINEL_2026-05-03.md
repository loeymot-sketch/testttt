# RUN α6 — Sentinel Playwright + axe-core (Catalog Studio)

**Date** : 2026-05-03  
**Mission** : chantier α6 — `CV1-V2-REMAINING-MISSIONS-001` (plan parent §3 `reports/audit/CATALOG_STUDIO_AUDIT_AND_REMEDIATION_PLAN_2026-05-03.md`).

## Fichier livré

| Élément | Chemin |
|--------|--------|
| Spec Playwright | `tests/e2e/catalog-studio-a11y-axe.spec.js` |

## Dépendance `@axe-core/playwright`

- **Statut** : **non installée** dans `package.json` (le dépôt expose seulement **`axe-core`** en devDependency, utilisé ailleurs avec injection manuelle, ex. `tests/e2e/design/_shared/design-audit-helpers.js`).
- **NOTE** : avant exécution réelle des deux tests axe, installer :
  ```bash
  npm i -D @axe-core/playwright
  ```
  (hors périmètre de ce run — demande explicite de ne pas installer de dépendance npm.)

### Vérification demandée

```text
$ node -e "require('@axe-core/playwright')" 2>&1 | head -5
Error: Cannot find module '@axe-core/playwright'
```

## Commande Playwright

```bash
npx playwright test tests/e2e/catalog-studio-a11y-axe.spec.js
```

## Résultat du run (environnement CI/sandbox)

```text
Running 3 tests using 1 worker
  -  2 × tests axe — SKIPPED (@axe-core/playwright not installed)
  ✘  1 × focus ring — FAILED (timeout attente [data-testid="catalog-studio-page"])
```

**Cause attendue** sans stack locale complète : Laravel / SPA non joignable ou compte `E2E_ADMIN_USER` / données permissions `items` absentes — le test reste sur une attente propre (pas de corruption d’état). Avec `php artisan serve` (ou équivalent) + seed admin cohérente (même convention que `central-management-va-sys05.spec.js` : `admin@lecayenne.fr` / `123456` ou `E2E_*`), la page `http://localhost:8000/admin/items/studio` doit monter le composant avec `data-testid="catalog-studio-page"`.

## Autres notes

- **`playwright.config.js`** : aucune modification (la spec réutilise `use.baseURL`, identique à la navigation `BASE_URL + '/admin/items/studio'`).
- **Anti-dérive respectée** : pas de changement Vue/Laravel/migrations ; pas d’`npm install`.

## Validation syntaxe

```bash
node --check tests/e2e/catalog-studio-a11y-axe.spec.js  # exit 0
ls -la tests/e2e/catalog-studio-a11y-axe.spec.js
```
