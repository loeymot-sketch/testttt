# Plan — Menu affiché comme 2ᵉ produit : cause racine + correctifs

**Architecte :** Claude  
**Date :** 2026-03-23  

## Audit (symptôme)

Le menu apparaît encore comme **ligne séparée** au lieu d’être sous le sandwich (`pos_line_addons` + vert « + Menu »).

## Cause racine identifiée

1. **`pos-wizard.js` sync addon** : `isSelected = card.closest('.selected, [class*="primary"]')` — en layout Tailwind, des **ancêtres** du DOM portent souvent `text-primary`, `border-primary`, etc. → **faux positif** → le wizard **ne clique pas** la carte addon → **`this.addons` reste vide** dans Vue au moment de `addToCart`.
2. Sans addon dans Vue, `pos_line_addons` est vide ; le pont prix peut tout mettre sur la ligne principale **ou** l’utilisateur voit encore un panier **ancien** (deux lignes) issu du **localStorage** (`pos_cart_v1`) enregistré avant le regroupement.

## Tâches (Kimi)

| ID | Action |
|----|--------|
| W1 | `ItemComponent.vue` (POS admin) : `data-addon-active="1|0"` sur `.addon` pour un état fiable. |
| W2 | `pos-wizard.js` : utiliser `data-addon-active` au lieu de `closest('[class*="primary"]')` (formule + boisson). |
| W3 | `posCart.js` : clé localStorage `pos_cart_v2` pour abandonner les paniers au format « 2 lignes ». |
| W4 | Tests `posCart.spec.js` : clé `pos_cart_v2`. |

## Validation

- Wizard : sandwich + menu → **une** ligne panier, vert « + Menu », pas de 2ᵉ ligne.
- `npm run production` OK.

## Note produit

Le panier en cours est **réinitialisé** une fois au passage v2 (nouvelle clé localStorage).
