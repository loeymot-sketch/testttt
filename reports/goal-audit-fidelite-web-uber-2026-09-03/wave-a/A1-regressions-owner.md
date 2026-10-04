# A1 — Les trois défauts signalés par le propriétaire, vérifiés sur HEAD `a91f95e2e`

Branche `pos/category-first-caisse-2026-06-23`. Audit **lecture seule** : aucun fichier de
production touché, aucun commit, aucun checkout. Base interrogée : `foodking_e2e` (MySQL local).
Playwright MCP et Chrome MCP étaient **hors service** : les verdicts reposent sur le code servi,
des requêtes SQL réelles et des bancs Vitest rejoués — **jamais sur un écran**. Aucun des trois
« NON REPRODUIT » n'est une validation visuelle.

---

## Défaut 1 — « le premier choix de viande » → **NON REPRODUIT**

```
[VERDICT-OK] resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue:152 — aucune présélection du premier choix
  état: NON REPRODUIT
```

`grep -c '\[0\]'` sur `KioskStepViandeComponent.vue` (823 lignes) = **0**. Aucun `preselect`,
`defaultChoice`, `auto_select` dans les quatre fichiers du parcours. État initial vide partout :
`KioskStepViandeComponent.vue:152` (`localSelections: { ...this.selections.viandes }`) et
`public/js/pos-wizard.js:935,941,961` (`selections.viandes = {}`, `totalViandes = 0`). Seules
écritures : les handlers `+`/`−` (`pos-wizard.js:6154,6166`).

L'obligation existe, et elle est **serveur**. SQL réel sur `item_attributes` :

```
id=1 "Viande 1" min_select=1 max_select=1 · id=2 "Viande 2" min=1 · id=3 "Viande 3" min=1 · id=4 "Viande 4" min=0
```

Le message que le propriétaire a pu voir vient de `lang/fr/validation.php:171`
(`'Sélectionnez au moins :min :attribute (actuel : :actual).'`), levé par
`app/Rules/MultiVariationConstraint.php:225`. Vérifié : la règle regroupe sur
`$var->item_attribute_id` (`:210`), pas sur la clé du client, et les attributs **absents** du
payload sont couverts (`:139-158` + `presentAttributeIds()` `:167-183`). Ni faux 422 sur un
produit mono-viande, ni possibilité de commander zéro viande.

```
[P2] resources/js/components/frontend/kiosk/KioskWizardComponent.vue:1924 — le seul index-0 spécial est un repli
  état: DÉJÀ CORRIGÉ (fix 2026-06-30, verrouillé par tests/js/kioskWizardMultiViande.spec.js)
  preuve: `const varId = match ? match.id : (idx === 0 ? v.id : null);`
```

Ancêtre du défaut « 2ᵉ viande perdue au panier » : le repli faisait survivre le seul slot 0 et
droppait les suivants (→ « Viande 2 actuel : 0 » au paiement). Le correctif aplatit
`item.variations` (`:1911-1916`). **Banc rejoué :**
`npx vitest run kioskWizardMultiViande posWizardTacosXlTroisViandes posWizardViandeSupplementUnified`
→ **3 fichiers, 14 tests, 0 rouge**.

```
[P2] public/js/pos-wizard.js:363-380 — le quota de viandes incluses est déduit du NOM du produit
  état: RÉEL (risque latent, non actif)
  preuve: `if (/tacos\s*xl\b/.test(n)) return 3;` — la base porte bien « Tacos M | Tacos L | Tacos XL »
  correctif proposé: aucun maintenant ; porter le quota en colonne catalogue = V1.0.X
```

Un renommage ferait retomber la caisse à une viande incluse et **facturerait les 2ᵉ/3ᵉ à 2,50 €**
en silence. **Correctif défaut 1 : aucun.**

---

## Défaut 2 — « le cornichon mis en gratuit » → **NON REPRODUIT** (aucune mise à zéro n'a eu lieu)

```
[VERDICT-OK] app/Console/Commands/MenuResetLeCayenneCommand.php:72 — le cornichon n'a JAMAIS été payant
  état: NON REPRODUIT
```

`MenuResetLeCayenneCommand.php:72` — `private const CRUDITES = ['Salade','Tomate','Oignon',
'Cornichon'];` — introduit le 2026-05-13 (`4f1cc7e50`) ; `:872` écrit `'price' => 0` en dur pour
les quatre. `config/menu.php:127` : *« Owner mandate 2026-05-21: added Cornichon as 4th canonical
crudité. »* Les crudités payantes ne sont pas dans cette constante — la ligne de partage tient :

```
Cornichon 0 (n=18) · Oignon 0 (19) · Oignons cuits 0 (18) · Salade 0 (19) · Tomate 0 (19)
Maïs 0,90 (19) · Olives 0,90 (19) · Poivrons cuits 0,90 (19)
```

**Preuve qu'aucune session n'a reprixé quoi que ce soit :** sur **100 % des lignes crudité**,
`created_at == updated_at`. Aucun `UPDATE` n'a jamais touché ces prix. Même les trois Cornichon
effacés le 2026-06-23 (`status=10`) étaient déjà à 0.

```
[P2] app/Console/Commands/MenuHealLightV2Command.php:941 — ce qui s'est réellement passé : 11 cornichons AJOUTÉS hier
  état: RÉEL (mutation catalogue non demandée ; le prix n'a jamais été en cause)
  preuve: 11 lignes créées le 2026-09-02 18:32:43-44 (ids 616-620, 625-630) sur Cayenne, Suprême,
          Méga, Terminator, Sandwich Classique, Chicken Burger, Cheese Burger, Double Cheese,
          Fish Burger, Big Burger, Grill Burger — toutes price=0, jamais modifiées depuis.
```

Le même lot a créé Boursin @0,90 et le groupe `supplement_bol`. Signature propre à
`menu:heal-light-v2` : seule commande contenant à la fois « Cornichon » (`:941`) et « Boursin »
@0,90 (`:973`). Quelqu'un l'a jouée hier soir.

**La gêne réelle est en aval.** Le cornichon n'a pas de symbole :
`app/Services/Hardware/KitchenTicketSymbolicFormatter.php:54-63` ne connaît que `/salade/→S`,
`/tomate/→T`, `/oignon.*cuit/→O̲`, `/oignon/→O`. À `:355`, le filtre qui replie les garnitures
gratuites teste `cruditeSymbol($name) !== '' && isFreeExtra($e)` : le cornichon échoue au premier
test et **sort en ligne de supplément sur le ticket cuisine**, au milieu des payants. Côté caisse
il s'écrit « STO Cornichon » (`resources/js/helpers/posCartCompactDisplay.js:66`, AB-001 assumé).

**Correctif proposé (gate propriétaire) :** décider d'abord si le cornichon reste sur ces 11
produits. S'il reste, ajouter `['/cornichon/', 'C']` aux **deux** tables jumelles
(`KitchenTicketSymbolicFormatter.php:54` **et** `resources/js/helpers/kdsSymbolic.js:76`) plus les
deux `CRUDITE_ORDER` — jamais l'une sans l'autre, la parité écran↔papier en dépend. Sinon c'est un
retrait catalogue. **C'est le menu, pas un bug : ne rien faire sans arbitrage.**

---

## Défaut 3 — « on modifie un produit dans le panier, le prix ne change pas »

Le symptôme n'est pas celui décrit, mais **il y a bien un défaut de prix à l'édition** — voisin,
prouvé, et il produit exactement la sensation « le prix ne suit pas ce que je vois ».

```
[VERDICT-OK] resources/js/store/modules/posCart.js:318 — l'édition recalcule bien la ligne (caisse ET borne)
  état: NON REPRODUIT
```

Caisse : `pos-wizard.js:4651` recalcule `calculateRunningTotal()` **à chaque soumission**, depuis
`selections`, sans mémoire (`:1501-1520`) → `:4654` pose `data-wizard-total` ;
`ItemComponent.vue:1786-1789` le relit ; `:1726` `dispatch('posCart/replaceCartLine')` ;
`posCart.js:475-481` `splice(index, 1, shapePosListItem(pay))` (remplacement complet, aucun prix
conservé) ; `:318-321` commit `subtotal`, qui réécrit `.total` de **chaque** ligne via
`resources/js/helpers/posCartLineMath.js:44-54`. Borne : `KioskWizardComponent.vue:2207`
reconstruit `lineTotal` et `:2353` remplace la ligne.

**Aucun risque NF525.** `app/Services/Pricing/PricingService.php:190` —
`$extraTotal += (float) $dbExt->price * $extraQuantity;` — facture depuis la base, et les totaux
client sont retirés du payload (`tests/js/kioskWizardEditRoundtrip.spec.js:181-182`,
`tests/Feature/Pos/PosOrderRequestNoClientTotalsTest.php:10-25`). Le montant encaissé reste juste
même si l'affichage dérive. **Ce n'est donc pas un P0 fiscal.** Bancs rejoués : les 5 specs
d'édition → **40 tests, 0 rouge**.

```
[P1] resources/js/components/admin/pos/ItemComponent.vue:1490 — rouvrir une ligne contenant le supplément « Cheddar » facture 1,00 € au lieu de 0,90 €, et décoche la tuile
  état: RÉEL
  preuve: `else if (extraLower.includes('cheddar')) { restore.fritesCheddar = true; }` — chaîne
          else-if NON scopée (elle tourne pour TOUT extra de TOUT article), placée AVANT le
          catch-all supplément payant `:1518` (`restore.supplements['p_' + extra.id] = true`).
          SQL: « Cheddar » = 30 lignes actives à 0,900000 (22 `supplement` + 8 `supplement_bol`).
          `pos-wizard.js:194` FRITES_CHEDDAR_PRICE = 1.00 ; réglage `order_setup_frites_cheddar_price`
          = NULL en base → le repli 1,00 € s'applique. `pos-wizard.js:1564`
          `if (selections.fritesCheddar) addonTotal += FRITES_CHEDDAR_PRICE;` — sans condition.
          Conséquences : `supplements['p_<id>']` n'est jamais posé → tuile Cheddar rendue
          NON sélectionnée (`pos-wizard.js:3749-3752`), et +1,00 € au lieu de +0,90 €.
  correctif proposé: borner la branche aux frites (exiger `extraLower.includes('frites')`, comme
          la branche sauce-frites `:1479`) ou tester `group_label`, et la placer APRÈS le
          catch-all payant. `ItemComponent.vue` n'est PAS en zone gelée ; `pos-wizard.js` n'est
          pas à toucher. Même schéma à vérifier sur `:1487-1489` (« grande portion »).
```

```
[P1] resources/js/store/modules/posCart.js:318 — le recalcul du panier à l'édition n'est gardé par AUCUN banc unitaire
  état: RÉEL (trou de couverture mesuré)
  preuve: `grep -rln "replaceCartLine" tests/` → un seul fichier,
          tests/e2e/test-e2e-goal-4chantiers-wave-A.spec.js. Zéro spec Vitest. Et l'unique
          assertion e2e (`:271`) vérifie l'INVERSE : `expect(priceAfterEdit).toBe(priceAtAdd)`,
          round-trip SANS changement.
  correctif proposé: banc Vitest sur le store posCart — ajouter une ligne, la remplacer avec un
          item_extra payant, assert que `subtotal` AUGMENTE du prix de l'extra. ~20 lignes, zéro
          fichier de production, zéro zone gelée. C'est ce banc qui aurait attrapé le P1 ci-dessus.
```

Rien n'assert que le prix **bouge** quand il doit bouger — la direction même signalée.

```
[P2] resources/js/components/admin/pos/ItemComponent.vue:1787 — `if (wizardTotal > 0)` garde silencieusement l'ancien prix
  état: RÉEL (fragilité, non déclenchée aujourd'hui)
  preuve: si `data-wizard-total` vaut 0 ou manque, `this.temp.total_price` conserve la valeur de la
          ligne en cours d'édition — l'ANCIEN prix, sans aucun signal.
  correctif proposé: à couvrir par le banc du P1 ci-dessus avant d'y toucher.
```

L'hygiène existe (`finishSuccess`/`finishError` `:1701,1719` suppriment `dataset.wizardTotal`) :
rien ne traîne d'une opération à l'autre. Trou étroit, mais du bon côté pour produire le symptôme.
