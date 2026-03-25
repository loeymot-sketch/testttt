# Revue — POS wizard / prix panier

**Revueur :** Claude (architecte)  
**Date :** 2026-03-23  
**Portée :** `ItemComponent.vue` (modal POS), `pos-wizard.js` (bridge wizard)

---

## Verdict : **APPROVED**

Après revue du correctif et du **nettoyage `data-wizard-total`** dans `openWizard` + `closeWizard`, le risque principal identifié (attribut stale après échec / fermeture) est **traité**.

---

## Logique

- Le store `posCart` agrège `(convert_price + item_variation_total + item_extra_total) * qty`.  
  Recaler `convert_price` à partir du **total wizard** (moins total addons Vue) est **algébriquement cohérent** avec cette formule.
- Le **retry** avant `addBtn.click()` réduit la course entre événements DOM et réactivité Vue.
- Les **`data-addon-*`** alignent le sélecteur du wizard avec le DOM réel des cartes addon.

---

## Risques résiduels (acceptables / à surveiller)

| Risque | Commentaire |
|--------|-------------|
| Wizard vs prix addon Vue | Si `calculateRunningTotal()` et `addon.total_price` divergent pour une formule, le découpage main vs addon peut être légèrement faux — à tracer si un cas métier remonte. |
| Parse du bouton | `readModalAddButtonTotal` dépend du texte « Ajouter - X€ » ; si i18n change fortement le format, ajuster le parse. |
| Tests auto | Pas de couverture automatisée POS dans cette PR ; validation **manuelle caisse** reste nécessaire. |

---

## Anti-Gravity

**Non requis** pour approuver ce patch. À invoquer si, après tests humains, le comportement reste **intermittent** ou si régression sur parcours multi-addons.

---

## Checklist validation humaine (rapide)

1. Sandwich / tacos avec viandes + sauces payantes → total panier = total wizard.  
2. Formule menu (addon) → somme lignes = total attendu.  
3. Quantité &gt; 1 + extras → cohérence.  
4. Forcer une erreur validation puis corriger et ajouter → pas de prix hérité d’un wizard précédent.  
5. Ajout **sans** wizard (article simple) → inchangé.

---

## Historique sprint

Les entrées historiques Sprint 22–24 restent dans les fichiers nommés du dossier ; ce `latest.md` reflète **uniquement** la revue du correctif prix wizard/panier du 2026-03-23.

---

# Revue — Layout crudités (wizard single-page)

**Revueur :** Claude  
**Date :** 2026-03-10  
**Portée :** `public/css/pos-wizard.css` (`.wizard-2col`, `.wizard-viande-list`, `.crudites-section`, garnitures)

## Verdict : **APPROVED** (sous réserve de test navigateur)

## Technique

- **`align-items: start`** sur `.pos-wizard.single-page .wizard-2col` : correct pour éviter que les blocs s’étirent en hauteur de façon trompeuse ; cohérent aussi pour la rangée sauce | suppléments (colonnes de hauteurs différentes).
- **`max-height` + `overflow-y: auto`** sur `.wizard-viande-list` : mécanisme standard pour borner la contribution verticale de la colonne viandes ; la ligne de grille reste `max(hauteur viande bornée + en-têtes/panels, hauteur crudités)` — le vide excessif sous crudités devrait nettement diminuer. Si le bloc viande contient encore un panneau « viande supplémentaire » très développé, une partie du vide peut persister : acceptable sans refonte markup.
- **Crudités** : `min-width: 0` sur la cellule + `width: 100%` sur le toggle et les boutons + `box-sizing: border-box` + `word-break` : aligné avec les causes habituelles de **débordement de grille** (min-content trop large). `z-index: 1` sur `.crudites-section` est **redondant** si le débordement est supprimé ; risque faible, peut rester.

## Logique métier

- **Aucun impact** sur calcul prix, étapes wizard, ou auth — pure présentation.

## Tests recommandés

- Sandwich avec nombreuses viandes + 3 crudités longues étiquettes ; largeur modale réduite.
- `@media (max-width: 600px)` : colonne unique — vérifier que scroll viandes et boutons restent utilisables.

---

# Revue — Audit ajout au panier (wizard → Vue → posCart) + panier visuel

**Revueur :** Claude  
**Date :** 2026-03-10  
**Portée :** `pos-wizard.js` (`submitWhenSynced`, `readModalAddButtonTotal`), `ItemComponent.vue` (`addToCart`, bridge), `posCart.js` (formule ligne), `PosComponent.vue` (affichage)

## Verdict : **APPROVED** (après correctifs K1–K4)

## Logique — chaîne de prix

1. **`calculateRunningTotal()`** : `(basePrice + extras + formuleAddon + frites upgrades) * itemQuantity` ; cohérent avec une ligne « tout-en-un » côté wizard.
2. **`data-wizard-total`** sur la racine `.modal` : même élément que `ref="itemVariationModal"` → `dataset.wizardTotal` côté Vue est **correct**.
3. **`addToCart`** : si pont présent, `effectiveLineTotal = bridgedWizardTotal`, puis `mainLineTotal = effectiveLineTotal - addonTotalVue`, répartition en `adjustedBaseConvertPrice` pour que  
   `(convert_price + item_variation_total + item_extra_total) * qty ≈ mainLineTotal`.  
   Algèbre **correcte** si `bridgedWizardTotal` ≈ `temp.total_price` et si les addons Vue correspondent aux montants inclus dans le wizard.
4. **`posCart` subtotal** : `(convert_price + item_variation_total + item_extra_total) * quantity` par ligne — **aligné** avec l’intention du bridge.

## Technique — correctifs revus

| Correctif | Commentaire |
|-----------|-------------|
| **SYNC-01** | Retry lorsque total bouton = 0 alors que wizard > 0 : **nécessaire** ; après épuisement des essais, le clic reste acceptable car `addToCart` s’appuie sur `data-wizard-total`. |
| **PARSE-01** | Fallback dernier nombre : réduit les faux 0 ; risque théorique si plusieurs montants dans le libellé — faible sur le bouton « Ajouter » actuel. |
| **CART-01** | Nettoyage en `catch` : bonne hygiène (évite double submit mental avec total fantôme). |
| **UI-01** | `break-words` sur instruction : pertinent pour tickets KDS longs. |

## Risques résiduels

- **Addons multiples / pré-sélection** : le wizard ne « décoche » pas tous les addons Vue avant de sélectionner la formule ; cas limite si l’utilisateur a manipulé le corps Vue avant le wizard.
- **Formule** : prix wizard parsé depuis chaîne currency vs `total_convert_price` addon Vue — surveiller écarts d’arrondi ou de devise.
- **Tests auto** : toujours **manuel** sur parcours réel caisse + Anti-Gravity si régression intermittent.

## Synthèse

La logique d’ajout au panier avec wizard est **techniquement cohérente** pour le scénario nominal (sync DOM + pont + décomposition main vs addons). Les correctifs portent sur la **robustesse temporelle** (retry) et la **résilience** (parse, cleanup), sans contourner validations serveur.

---

# Vérification d’exécution — Claude (plans dans `reports/planning/latest.md`)

**Exécuteur revue :** Claude (architecte)  
**Date :** 2026-03-10  
**Mode :** audit statique du dépôt + build front (pas de session navigateur dans cet environnement).

## Matrice tâches ↔ implémentation

| Plan (section) | ID | Statut | Vérification |
|----------------|-----|--------|----------------|
| POS wizard → panier (prix) | P1 | **OK** | `admin/pos/ItemComponent.vue` : `data-addon-id` / `data-addon-name`, `bridgedWizardTotal` / `adjustedBaseConvertPrice`, cleanup `dataset.wizardTotal` au succès + `variationModalHide`. |
| | P2 | **OK** | `pos-wizard.js` : `data-wizard-total` avant clic, `submitWhenSynced`, retrait attribut dans `openWizard` / chemins `closeWizard`. |
| Single-page crudités | C1–C3 | **OK** | `pos-wizard.css` : `.wizard-2col` `align-items: start`, `.wizard-viande-list` plafond + scroll, `.crudites-section` / garniture boutons pleine largeur. |
| Audit panier | K1–K2 | **OK** | `pos-wizard.js` : `deltaBad` (bouton 0 ou écart > 0,011), `readModalAddButtonTotal` avec fallback dernier nombre. |
| | K3 | **OK** | `ItemComponent.vue` : `.catch()` sur `posCart/lists` → suppression `wizardTotal` + `itemArrays = []`. |
| | K4 | **OK** | `PosComponent.vue` : instruction ligne panier `break-words max-w-[220px]`. |

## Contrôles automatisés exécutés

| Contrôle | Résultat |
|----------|----------|
| `npm run production` (Laravel Mix) | **OK** — compilation réussie (≈ 22 s), `app.js` / `app.css` générés. |

## Hors périmètre de cette session (recommandé humain / Anti-Gravity)

- Parcours **navigateur** : wizard sandwich, formule, quantité, instruction longue, article à 0 €.
- **PHPUnit** / API commande (non modifiés par ces patches front).
- Rapport **Anti-Gravity** dans `reports/antigravity/` si flakiness ou régression multi-addons.

## Conclusion

Les tâches listées dans le plan courant sont **présentes en code** et le **build front passe**. **Go / No-Go** exploitation : valider encore en **caisse réelle** selon la checklist du plan (prix panier ≈ wizard, pas de total fantôme).

---

# Revue — Panier POS : menu sous la ligne + édition

**Revueur :** Claude  
**Date :** 2026-03-23  
**Plan :** `reports/planning/POS-CART-BUNDLE-EDIT-2026-03-23.md`

## Verdict : **APPROVED** (validation caisse requise)

## Logique

- **Affichage** : une entrée `lists[]` avec `pos_line_addons[]` ; `computePosCartLineDisplayTotal` (helper) = principal × qty + Σ (unité addon × qty addon × qty parent). Cohérent avec l’ancien total « deux lignes ».
- **Commande** : `orderSubmit` **aplatit** à nouveau en N objets `items` (principal + chaque addon) avec `quantity` / `total_price` alignés sur l’ancien contrat API — **pas de changement backend attendu**.
- **Fusion** : signature `parent_addon_id:item_id:quantity` sur `pos_line_addons` évite de fusionner deux sandwichs différents (menu vs sans menu).
- **Édition** : `replaceCartLine` évite doublon ; `usePricedCartBase` + `bumpPricingToCatalog` évitent d’écraser le `convert_price` ajusté (wizard) tant que l’utilisateur n’a pas modifié prix/catalogue-sensitive.

## Technique

- `ItemComponent` toujours monté : nécessaire pour `ref` + `v-if` vide → corrigé (`v-if` sur premier empty state).
- Ancien panier localStorage avec **deux lignes séparées** : pas migré automatiquement — l’utilisateur peut vider le panier une fois.

## Risques résiduels

- Édition **sans** réouverture du **wizard HTML** (modal Vue seulement) — acceptable phase 1.
- Si un libellé i18n manque pour `button.edit`, le `title` du crayon retombe sur « Modifier ».

## Tests recommandés

- Menu + quantité ligne 2 → total menu ×2.
- Modifier puis valider → ligne remplacée, pas de doublon.
- Paiement / création commande : vérifier côté serveur les 2 `order_items` (burger + menu) comme avant.

---

# Revue — Audit DRY / anti-duplication panier POS

**Revueur :** Claude  
**Date :** 2026-03-23  
**Plan :** `reports/planning/POS-CART-DRY-AUDIT-2026-03-23.md`

## Verdict : **APPROVED** après correctifs D1–D4

## Logique

- **Une source de vérité** pour le total affiché et les montants checkout (principal + addons regroupés) : `posCartLineMath.js`. Alignement **store `subtotal`** ↔ **JSON `orderSubmit`** ↔ **libellé vert** (via `rowUnitBundled`).
- **Quantités** : `parsePositiveInt` évite NaN sur qty absente / invalide (subtotal stable).
- **`shapePosListItem`** : même forme pour `lists` et `replaceCartLine` — pas de duplication de champs.

## Technique

- Import `activityEnum` supprimé de `posCart.js` (inutilisé).
- `openEditFromCart` : catch remet `usePricedCartBase = false` — pas d’état fantôme après erreur `item/details`.

## Duplication résiduelle (acceptée)

- **`buildPosCartMainPayload`** : calcule `adjustedBaseConvertPrice` à partir du **pont wizard** / `temp.total_price` — responsabilité différente du total « ligne panier stockée » ; documenté dans le plan.

## Vérification build

- `npm run production` : **OK** (2026-03-23).

---

# Revue — Sync wizard → addon Vue (`data-addon-active`)

**Revueur :** Claude  
**Date :** 2026-03-23  
**Plan :** `reports/planning/POS-MENU-WIZARD-SYNC-2026-03-23.md`

## Verdict : **APPROVED**

## Logique

- L’état « addon sélectionné » ne peut pas être déduit d’un **sélecteur CSS sur les ancêtres** (`primary`) sans faux positifs massifs en Tailwind.
- **`data-addon-active`** reflète directement `addons[addon.id]` côté Vue → le wizard synchronise l’intention **menu / formule** en cliquant **si et seulement si** l’état DOM ne correspond pas à Vue.

## Technique

- `pos_cart_v2` : évite de recharger d’anciennes listes « burger + menu » en deux entrées ; coût = **panier local perdu une fois** (acceptable).
- `parseInt(..., 10)` explicite sur `data-addon-id` : robustesse.

## Risque résiduel

- Si une autre surface POS duplique les cartes addon **sans** `data-addon-active`, la sync wizard resterait aveugle — hors scope admin `ItemComponent` actuel.

---

# Audit Claude — synthèse globale POS (état dépôt)

**Revueur :** Claude (architecte)  
**Date :** 2026-03-23  
**Type :** audit transversal (logique + technique), exécution sur code source actuel.

## Verdict global : **CONFORME** — poursuivre validation manuelle caisse

## Chaîne de données (résumé)

| Étape | Fichiers / mécanisme | Statut |
|--------|----------------------|--------|
| Wizard → total & sync DOM | `public/js/pos-wizard.js` : `data-wizard-total`, `submitWhenSynced`, `readModalAddButtonTotal`, sync addon via **`data-addon-active`** | **Cohérent** |
| Modal Vue → payload panier | `admin/pos/ItemComponent.vue` : `buildPosCartMainPayload`, `pos_line_addons`, `parent_addon_id`, pont wizard / `usePricedCartBase` | **Cohérent** |
| Store panier | `posCart.js` : `shapePosListItem`, fusion + signature addons, `computePosCartLineDisplayTotal` (via helper), **`pos_cart_v2`** | **Cohérent** |
| Affichage + checkout | `PosComponent.vue` : ligne unique + verts, `orderSubmit` aplati via `posCartLineMath` | **Cohérent** |
| Formules partagées | `helpers/posCartLineMath.js` | **DRY** |

## Points d’attention (non bloquants)

| ID | Sujet | Action si incident |
|----|--------|-------------------|
| A1 | `buildPosCartMainPayload` reste une voie **distincte** du helper (pont wizard) | Sur écart prix, comparer `bridgedWizardTotal` vs somme Vue |
| A2 | Fusion `lists` : logique variations/extras historique (comparaisons `!== "undefined"`) | Refactor prudent si bugs de fusion rares |
| A3 | Wizard sans clic addon si DOM sans `data-addon-active` | Garantir build Mix à jour + composant admin POS |
| A4 | Commande API : toujours **N items** JSON pour N lignes métier (principal + menu) | Côté produit attendu ; pas « une seule ligne serveur » |

## Contrôle rapide exécuté (cette session)

- Relecture ciblée : `posCartLineMath.js`, `posCart.js`, grep `pos_line_addons` / `data-addon-active` / `pos_cart_v2`, extrait `pos-wizard.js` sync addon.

## Suite obligatoire (humain / Anti-Gravity)

1. Parcours réel : wizard + menu → **une** ligne panier, sous-total = somme attendue.  
2. Commander → vérifier **2** `order_items` côté BO si menu séparé métier.  
3. Édition crayon + sauvegarde → pas de doublon.  
4. `npm run production` / `npm run dev` déployé sur l’environnement testé (éviter `app.js` obsolète).

---

*Note : sections plus haut dans ce fichier détaillent chaque chantier (prix wizard, crudités CSS, bundle menu, DRY, sync addon).*
