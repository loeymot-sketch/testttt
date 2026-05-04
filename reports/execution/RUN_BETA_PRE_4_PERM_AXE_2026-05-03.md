# RUN — CV1-V2-CATALOG-REWORK-001 — sous-tâche β-PRE-4 + R5 (permissions + axe)

**Date** : 2026-05-03

## Lot 1 — Permission mapping (R3)

- **Doc créée** : `docs/integration/STUDIO_PERMISSIONS_TO_SPATIE_MAP_2026-05-04.md`
- Référence design `studio-iter2.jsx`, `AdminController` (scopes `Admin` / `Tenant Admin`), routes `catalog.compose` / `catalog.publish`, `CLAUDE.md:67` (clarifié comme section Role Separation orchestration dans l’édition courante).

## Lot 2 — `@axe-core/playwright` + spec (R5)

- **`package.json`** : ajout de `"@axe-core/playwright": "^4.10.0"` dans `devDependencies`.
- **`tests/e2e/catalog-studio-a11y-axe.spec.js`** : remplacement du silence `catch` sans échec par `beforeAll` qui **fail** si le module est absent sauf `ALLOW_AXE_SKIP=1`.
- **À lancer manuellement** : `npm install` (pour matérialiser `@axe-core/playwright` avant un run CI / Playwright incluant ces tests).

## Contrôles automatiques prévus livrés

- `node -e "JSON.parse(require('fs').readFileSync('package.json'))"` — OK (exit 0, session EXECUTE du 2026-05-03).
- `node --check tests/e2e/catalog-studio-a11y-axe.spec.js` — OK (exit 0, même session).

## Note CI

Pour que le sentinel axe ne soit **pas** contourné en CI : garantir **`npm ci` ou `npm install`** qui installe `devDependencies` et **ne pas** définir `ALLOW_AXE_SKIP` sur la pipeline prod.
