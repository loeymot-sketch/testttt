# Plan — Panier POS : menu/formule sous le produit + édition ligne

**Architecte :** Claude  
**Date :** 2026-03-23  

## Architecture (5–10 lignes)

- Aujourd’hui `addToCart` pousse **N entrées** dans `posCart/lists` (principal + chaque addon) → deux lignes visuelles pour « sandwich + menu ».
- La caisse envoie encore **plusieurs `items`** JSON vers le paiement (compatibilité `OrderService` / `total_price` par ligne).
- Objectif UI : **une seule ligne** avec libellés verts « + Menu », « + Frites… » sous le produit ; le total colonne = somme principal + addons **liés**.
- Objectif UX : bouton **modifier** sur la ligne → rouvre le modal article avec état restauré ; validation **remplace** la ligne au lieu d’en ajouter une.

## Tâches (Kimi)

| ID | Description |
|----|-------------|
| B1 | `posCart.js` : champ `pos_line_addons[]` sur la ligne principale ; `subtotal` inclut addons × qty parent ; fusion « même produit » compare aussi les addons ; mutation + action `replaceCartLine`. |
| B2 | `ItemComponent.vue` : `addToCart` n’envoie qu’**un** payload avec `pos_line_addons` (incl. `parent_addon_id`) ; `editingCartIndex` + `openEditFromCart` ; `variationModalHide` reset edit. |
| B3 | `PosComponent.vue` : affichage addons en vert sous la ligne ; bouton crayon → `openEditFromCart` ; `orderSubmit` : **aplatir** principal + addons (quantités menu = qty addon × qty parent) ; retirer la colonne poubelle redondante. |
| B4 | Rapports `reports/execution/latest.md` + `reports/review/latest.md` (audit Claude). |

## Risques

- Lignes panier **restaurées depuis localStorage** sans `pos_line_addons` : traiter comme `[]`.
- Édition : flux **wizard HTML** non réouvert (modal Vue seulement) — acceptable phase 1 ; documenter.

## Validation manuelle

- Sandwich + Menu (Frites + Boisson) → **une ligne**, vert « + Menu… », prix = 10,50 + 3,00.
- Quantité ligne → total menu scale (ex. ×2).
- Commander : backend reçoit encore **plusieurs items** (principal + menu) avec `total_price` cohérents.
- Modifier → rouvrir modal → changer sauce → valider → ligne mise à jour.
