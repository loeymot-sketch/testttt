# RUN T18c — CI Vitest (workflow bloquant)

**Date :** 2026-04-20  
**Tâche :** Ajouter un workflow GitHub Actions dédié à Vitest, aligné sur le style des workflows existants, sans toucher au code applicatif ni aux autres workflows.

## Verdict

**PASS** — `.github/workflows/vitest.yml` créé (le fichier n’existait pas). Aucune modification de `package.json` (le script `"test": "vitest run"` est déjà présent).

## Fichiers livrés

| Fichier | Action |
|---------|--------|
| `.github/workflows/vitest.yml` | Créé |
| `reports/execution/RUN_T18C_CI_VITEST_2026-04-20.md` | Créé (ce rapport) |

## Résumé du contenu YAML (`vitest.yml`)

- **name :** `Vitest`
- **on :**
  - `pull_request` → branches `main`, `develop`
  - `push` → branche `main` uniquement
- **jobs :** un seul job `vitest`, `runs-on: ubuntu-latest`
- **steps :**
  1. `actions/checkout@v4`
  2. `actions/setup-node@v4` — `node-version: '20'`, `cache: 'npm'`
  3. `npm ci`
  4. `npx vitest run --reporter=verbose`  
     (en GitHub Actions, un code de sortie non nul fait échouer l’étape et donc le job ; pas de secret ni de matrice Node.)

## Vérification syntaxe

- **`yamllint`** : non installé dans l’environnement local utilisé pour la livraison → non exécuté.
- **Parse YAML** : `python3` + `yaml.safe_load(...)` sur `.github/workflows/vitest.yml` → **OK**.

## Comparaison de style avec `phpunit.yml`

| Aspect | `phpunit.yml` | `vitest.yml` (T18c) |
|--------|---------------|---------------------|
| En-tête | `name:` + commentaires contextuels | `name:` minimal (pas de commentaire long requis par le périmètre) |
| `on.pull_request.branches` | `[main, develop]` | Identique |
| `on.push.branches` | `[main, develop]` | `[main]` uniquement (spécification T18c) |
| Structure `jobs` | un job principal, `ubuntu-latest` | un job `vitest`, `ubuntu-latest` |
| Checkout | `uses: actions/checkout@v4` | Identique |
| Étapes nommées | oui pour les actions non triviales | oui (`Setup Node`, `Install dependencies`, `Run Vitest`) — proche de `playwright.yml` pour Node |

Aucun service Docker, aucune variable d’environnement applicative : périmètre limité aux tests JS (Vitest + dépendances npm).

## Specs invariantes désormais couvertes par la suite Vitest en CI

La commande `vitest run` exécute la configuration du projet ; les trois fichiers suivants restent des garde-fous K-7 / K-8 à ne pas régresser :

1. `tests/js/kioskA11yButtonTypeAudit.spec.js` — audit `button type` (T18b)
2. `tests/js/kioskI18nParity.spec.js` — parité i18n kiosk
3. `tests/js/kioskK7MotionTokens.spec.js` — tokens de motion K-7

## Risques / suivi pour validateur ou planificateur

- Le **premier run CI** peut révéler des échecs Vitest sur `main` si l’arbre local diverge ; à traiter comme signal produit, pas comme défaut du workflow.
- Pas de cache Vitest dédié : conforme au brief ; le cache npm accélère `npm ci`/`node_modules` via `setup-node`.

## Chemins de référence

- Workflow : `.github/workflows/vitest.yml`
- Rapport : `reports/execution/RUN_T18C_CI_VITEST_2026-04-20.md`
