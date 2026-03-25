# Plan — Audit DRY panier POS (anti-duplication)

**Architecte :** Claude  
**Date :** 2026-03-23  

## Constat (audit)

| Problème | Risque |
|----------|--------|
| Formule « unité ligne » (convert + var + extra) × quantités répétée dans `posCart`, `PosComponent` (affichage + checkout) | Dérive si une branche est modifiée sans l’autre |
| `computePosLineTotal` utilisait `parseInt(qty)` sans repli → **NaN** possible si qty corrompue | Subtotal cassé |
| Objet ligne panier dupliqué entre `lists` push et `replaceCartLine` splice | Oubli de champ futur |
| Import `activityEnum` inutilisé dans `posCart.js` | Bruit / confusion |
| `openEditFromCart` `.catch` ne remettait pas `usePricedCartBase` | État pricing incohérent après échec API |

## Tâches (Kimi)

| ID | Action |
|----|--------|
| D1 | Créer `resources/js/helpers/posCartLineMath.js` : `computePosCartLineDisplayTotal`, `mainOrderLineTotal`, `bundledOrderQuantityAndTotal`, `rowUnitBundled`, `parsePositiveInt`. |
| D2 | `posCart.js` : importer helper ; `shapePosListItem(pay)` ; retirer import mort. |
| D3 | `PosComponent.vue` : checkout + `bundledLineUnitTotal` → helper. |
| D4 | `ItemComponent.vue` : reset `usePricedCartBase` dans catch `openEditFromCart`. |

## Hors scope (volontaire)

- `buildPosCartMainPayload` / pont wizard : logique **distincte** (décomposition base vs addons Vue) — ne pas fusionner aveuglément avec `computePosCartLineDisplayTotal`.
