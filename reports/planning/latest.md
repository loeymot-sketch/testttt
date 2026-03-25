# Plan — POS wizard → panier : alignement prix

**Architecte :** Claude  
**Date :** 2026-03-23  
**Test type :** **Kimi-test** (manuel POS + `npm run dev`, pas d’Anti-Gravity obligatoire pour cette itération)

---

## Compréhension architecture (5–10 lignes)

- Le panier POS (`posCart`) calcule le total ligne : `(convert_price + item_variation_total + item_extra_total) * quantity`.
- Le wizard (`public/js/pos-wizard.js`) calcule un total parallèle (`calculateRunningTotal`) et synchronise le DOM vers le modal Vue (`ItemComponent`) avant de cliquer « Ajouter au panier ».
- Si la synchro Vue est en retard ou si les addons n’ont pas `data-addon-id` / `data-addon-name`, le clic part avec des totaux Vue encore au prix de base → ligne panier affiche le prix seul alors que l’instruction KDS est riche.
- La source de vérité **commande** reste côté serveur (`OrderService`) ; ce correctif porte sur **cohérence UI panier** avant envoi.

---

## Objectif

1. Faire correspondre le **total ligne panier** au **total wizard / bouton Ajouter** après personnalisation.
2. Réduire la **course** entre synchro DOM et clic automatique.
3. Éviter un **`data-wizard-total` obsolète** après échec / fermeture wizard.

---

## Tâches (Kimi)

| ID | Description |
|----|-------------|
| P1 | `ItemComponent.vue` : `data-addon-id` / `data-addon-name` sur `.addon` ; lecture `dataset.wizardTotal` dans `addToCart` pour reconstruire `convert_price` ; nettoyage dataset à la fermeture / après succès. |
| P2 | `pos-wizard.js` : avant submit, poser `data-wizard-total` sur le modal ; retry jusqu’à alignement bouton vs wizard ; parser montants ; à l’ouverture / `closeWizard`, retirer `data-wizard-total`. |
| P3 | Rebuild `npm run dev`. |

---

## Critères de validation (manuel)

- Produit avec wizard : total wizard ≈ total ligne panier (± arrondi).
- Menu / addon : somme lignes cohérente avec le wizard.
- Après erreur validation puis ajout manuel : pas de total « fantôme » du wizard précédent.
- Sans wizard : comportement inchangé.

---

## Risques

- Divergence mineure wizard vs `addon.total_price` Vue sur certains addons (à surveiller en QA).
- Si le libellé du bouton « Ajouter » change, le parse du prix peut nécessiter un ajustement.

---

## Suite (humain)

Valider en caisse réelle ; si flakiness persiste → planifier **Anti-Gravity** E2E POS.

---

# Plan — POS wizard single-page : layout Crudités (viande | crudités)

**Architecte :** Claude  
**Date :** 2026-03-10  
**Contexte :** UI sandwich : boutons crudités perçus comme empilés / chevauchant la colonne viandes + grand vide blanc sous la colonne droite.

## Compréhension (5–10 lignes)

- La rangée `.wizard-2col` est une grille 2 colonnes ; par défaut `align-items: stretch` aligne la hauteur des deux cellules sur la plus haute → la colonne crudités « s’étire » visuellement avec du vide en bas si la liste viandes est longue.
- La liste viandes en single-page était en `max-height: none` → toute la grille monte en hauteur.
- Les boutons `.garniture-toggle-btn` (styles injectés + fichier) peuvent dépasser en largeur (`min-width: 0` insuffisant sur le conteneur) et **déborder visuellement** sur la colonne viandes si le texte est long.

## Objectif

1. Supprimer le chevauchement viandes / crudités (confinement largeur + pile verticale stable).
2. Réduire l’espace blanc inutile (plafond scroll sur la liste viandes + `align-items: start` sur la grille).

## Tâches (Kimi)

| ID | Description |
|----|-------------|
| C1 | `public/css/pos-wizard.css` : `.wizard-2col` → `align-items: start`. |
| C2 | `.wizard-viande-list` (single-page) : `max-height: min(360px, 48vh)`, `overflow-y: auto`. |
| C3 | `.crudites-section` + `.garniture-toggle` : `width: 100%`, `min-width: 0`, `flex-wrap: nowrap` ; boutons `width: 100%`, `box-sizing`, texte multiligne si besoin. |

## Validation manuelle

- Ouvrir un sandwich avec viandes + crudités : pas de recouvrement sur la 3e colonne viandes ; crudités restent dans la colonne droite.
- Faire défiler la liste viandes si nombreuses options ; crudités restent en haut (colonne courte).
- Vue &lt; 600px : grille 1 colonne (régression visuelle rapide).

## Risques

- **Mineur :** hauteur max viandes impose un scroll — acceptable pour densité POS ; ajuster `360px` / `48vh` si retour terrain.

---

# Plan — Audit POS : ajout panier (wizard → Vue → posCart) + affichage panier

**Architecte :** Claude  
**Date :** 2026-03-10  

## Périmètre

- `public/js/pos-wizard.js` : `syncAndSubmit`, `calculateRunningTotal`, `data-wizard-total`, `submitWhenSynced`, `readModalAddButtonTotal`
- `resources/js/components/admin/pos/ItemComponent.vue` : `addToCart`, bridge `dataset.wizardTotal`
- `resources/js/store/modules/posCart.js` : agrégation ligne `(convert_price + item_variation_total + item_extra_total) * quantity`
- `resources/js/components/admin/pos/PosComponent.vue` : rendu lignes panier (prix, instructions)

## Constat (audit)

| ID | Problème | Gravité |
|----|----------|---------|
| SYNC-01 | Si `wizardTotalBeforeSubmit > 0` et total parsé du bouton = 0, l’ancienne logique ne **retry** pas → clic « Ajouter » avant que Vue ait mis à jour le libellé (course DOM). Le pont `data-wizard-total` compensait souvent en silencieux, mais comportement incorrect / fragile. | Moyen |
| PARSE-01 | `readModalAddButtonTotal` : un libellé i18n avec **plusieurs tirets** peut faire échouer le parse (segment final sans montant). | Faible |
| CART-01 | `dispatch('posCart/lists').catch()` vide : en échec rare, `data-wizard-total` / `itemArrays` pouvaient rester **stales**. | Faible |
| UI-01 | Instructions KDS longues dans le panier : pas de `break-words` → débordement horizontal possible. | Faible |

## Tâches (Kimi)

| ID | Description |
|----|-------------|
| K1 | `pos-wizard.js` : corriger `submitWhenSynced` (retry si wizard > 0 et bouton 0 ou delta > seuil). |
| K2 | `pos-wizard.js` : `readModalAddButtonTotal` — fallback dernier nombre dans le texte. |
| K3 | `ItemComponent.vue` : dans `.catch()` du dispatch panier, nettoyer `dataset.wizardTotal` + `itemArrays`. |
| K4 | `PosComponent.vue` : `break-words` (et largeur max raisonnable) sur le texte d’instruction ligne panier. |

## Validation manuelle

- Wizard sandwich + formule addon : total bouton ≈ total ligne(s) panier ; pas de double-clic nécessaire.
- Changer quantité dans le wizard puis valider.
- Article gratuit / prix très bas : pas de blocage infini sur retries (wizard total 0 → pas d’attente).
- Instruction très longue : lisible dans le panier sans casser la mise en page.

## Risques résiduels (à documenter en revue)

- Si un addon reste coché côté Vue alors que le wizard en choisit un autre **sans** désélection explicite, divergence possible (hors scope : nécessiterait « reset addons » avant sync).
- `calculateRunningTotal` utilise `addon_item_currency_price` parsé pour certaines formules ; `addToCart` soustrait les `addon.total_price` Vue — alignement à surveiller si devises / arrondis diffèrent.
