# Plan — Wizard multi-step : sync addon pour menuChoice 'full'/'frites'/'boisson'

**Date :** 2026-03-10  
**Priorité :** P0  
**Fichier principal :** `public/js/pos-wizard.js`

---

## Diagnostic root cause

Il existe **deux modes de wizard** qui encodent `selections.menuChoice` différemment :

| Mode | Format `menuChoice` |
|---|---|
| Single-page (`.formule-card`) | `"addon_42"` (format `addon_N`) |
| Multi-step (`.menu-choice-card`) | `"full"`, `"frites"`, `"boisson"` |

L'ancien code de sync d'addon (clic `.addon` card) et le pont `data-wizard-pos-line-addons` ne géraient que le format `addon_N`. Résultat : quand l'utilisateur utilisait le wizard multi-step et choisissait "Menu (Frites + Boisson)", `menuChoice = "full"` n'était pas traduit → aucun clic, aucun JSON bridge → `pos_line_addons = []` → le menu apparaissait comme produit séparé dans le panier.

---

## Changements appliqués (2026-03-10, Claude)

### Fonction 1 — Sync clic addon card (`syncAndSubmit`, section « 4. Click addon cards »)

Avant : `if (match) { ... }` — uniquement `addon_N`

Après : résolution en deux temps :
1. Si `addon_N` → recherche par ID dans `lastItemData.addons`
2. Sinon (`full`/`frites`/`boisson`) → recherche par nom dans `lastItemData.addons`
   - `'full'` → addon dont le nom contient `"menu"` (fallback : `"frites"` ou `"boisson"`)
   - `'frites'` → addon `"frites"` sans `"menu"`
   - `'boisson'` → addon `"boisson"`, `"coca"`, `"fanta"` ou `"sprite"`

### Fonction 2 — `buildWizardPosLineAddonsPayload()`

Même logique de résolution à deux temps. La fonction `addonToPayload()` est extraite pour réutilisation.

---

## Risques

- **Faible** : la résolution par nom est flexible mais dépend des conventions de nommage en base. Si un addon s'appelle "Frites Grandes" et non "Frites", le match `includes('frites')` fonctionnera quand même.
- Pas de régression pour le mode single-page (chemin `addon_N` en premier).

---

## Build

`npm run production` : OK.

---

## Validation suggérée

1. Hard refresh (vider cache navigateur, ou Cmd+Shift+R).
2. POS → produit avec wizard multi-step + formule "Menu (Frites + Boisson)".
3. Résultat attendu : **une seule ligne panier**, libellé vert "+ Menu (Frites + Boisson)" sous le sandwich, total = sandwich + menu.
4. POS → même produit via single-page wizard (formule-card) → même comportement attendu.
