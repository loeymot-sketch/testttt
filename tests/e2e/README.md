# Tests E2E Playwright (FoodKing)

## Prérequis

- Application Laravel + front compilés, base seedée (items POS, opérateur caisse, **identité fiscale branche** si le test vérifie SIRET/TVA sur le reçu).
- Navigateur Chromium pour Playwright :  
  `npx playwright install chromium`
- Variables d’environnement (optionnelles) :
  - **`PLAYWRIGHT_BASE_URL`** — URL de l’app (défaut `http://localhost:8000`, voir `playwright.config.js` à la racine du dépôt).
  - **`E2E_POS_USER`** / **`E2E_POS_PASS`** — compte opérateur POS (défaut alignés sur `tests/e2e/02-pos-cash.spec.js` : `pos@lecayenne.fr` / `123456`).
  - **Sélecteurs catalogue** (regex, sans délimiteurs `/`) :
    - `E2E_POS_TACOS_ITEM_RE` — libellé item tacos au catalogue (défaut `tacos`).
    - `E2E_POS_MEAT_A_RE` — viande « A » pour 3 clics `+` (défaut `steak|bœuf|boeuf`).
    - `E2E_POS_MEAT_B_RE` — viande « B » pour 1 clic `+` (défaut `poulet|chicken`).
    - `E2E_POS_EXTRA_RE` — extra type cheddar (défaut `cheddar`).

## Dépendances npm

Le dépôt liste déjà `@playwright/test` en `devDependencies`. Si le package n’est pas installé dans un environnement CI vierge :

```bash
npm install
```

(en cas d’absence totale : `npm i -D @playwright/test playwright` — **gate dépendance** côté ops.)

## Lancer le scénario T22 partiel (tacos 4 viandes → espèces)

```bash
export PLAYWRIGHT_BASE_URL=http://localhost:8000
mkdir -p reports/e2e
npx playwright test tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts
```

Capture pleine page en fin de scénario : `reports/e2e/pos-tacos-4-viandes-cash-end.png`.

## Notes techniques

- Connexion admin : **`/login`** avec `#formEmail` / `#formPassword` (pas `/admin/login`).
- Le total panier est lu via la ligne **Total** dans `#pos-cart` (pas de `data-testid="pos-cart-total"` à ce jour — voir plan T18 / hooks de test).
- Le dépôt n’inclut pas forcément le compilateur **`typescript`** npm : la syntaxe du spec est validée par Playwright (`npx playwright test … --list`).
