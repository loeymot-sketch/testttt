# AUDIT PROFOND — KIOSK WIZARD (Dynamic vs Static, logique étape par étape)

**Date** : 2026-04-17
**Scope** : Parcours complet de prise de commande borne — de `kiosk.categories` → `kiosk.wizard/:itemId` → `kiosk.cart`
**Méthode** : Lecture exhaustive des 10 composants Vue + 4 helpers + contrat backend (`NormalItemResource`, canal `surface=kiosk`)
**Statut** : Audit CLOS — prêt pour exécution du plan de correction avant intégration du nouveau design

---

## 0. Carte du wizard (ce qui existe)

```
/kiosk/categories
  └─ KioskCategoriesComponent.vue
       ├─ sidebar catégories (fetchMenu ?surface=kiosk)
       ├─ grille produits (filteredProducts)
       └─ openProduct(product)
            ├─ GET frontend/item/details/:id?surface=kiosk   → NormalItemResource
            ├─ hasOptions() → oui  → ouvre wizard overlay
            └─ hasOptions() → non → ajoute direct au panier

/kiosk/wizard/:itemId  (overlay ou route directe)
  └─ KioskWizardComponent.vue
       ├─ activeSteps = switch(effectiveWizardTemplate())
       │     ├─ tacos     → [taille?] viande sauce garnitures supplements menu recap
       │     ├─ sandwich  → pain     [viande?] sauce garnitures supplements menu recap
       │     ├─ burger    → [viande?]          sauce garnitures supplements menu recap
       │     ├─ assiette  → [viande?]          sauce garnitures supplements       recap
       │     ├─ snacking  →                    sauce           supplements        recap
       │     ├─ omelette  →                          garnitures supplements        recap
       │     ├─ salade    →                          garnitures sauce supplements  recap
       │     └─ simple    →                                     supplements        recap
       │
       ├─ KioskStepTaille   (S / M / L / XL, viandeCount dérivé)
       ├─ KioskStepPain
       ├─ KioskStepViande   (compteur ± limité par maxViandes)
       ├─ KioskStepSauce    (1ʳᵉ gratuite, suivantes payantes)
       ├─ KioskStepGarnitures (pré-cochées, on décoche ce qu'on ne veut pas)
       ├─ KioskStepSupplements (extras payants non-sauce)
       ├─ KioskStepMenu     ★ 4 sous-blocs : choix formule / upgrade frites / boisson / sauce frites
       └─ KioskOrderSummary (récap + quantité ± + total)
```

**Source de vérité** : Backend `NormalItemResource.toArray()` filtre `extras` et `variations` par `surface=kiosk`, expose :
- `itemAttributes` (pain, viande, sauce, taille…)
- `variations` (groupées par `item_attribute_id`)
- `extras` (garnitures gratuites et suppléments payants, tagués `group_label`)
- `addons` (menus, boissons, accompagnements)
- `wizard_template`, `has_menu`, `default_menu_kiosk`, `sauce_included_menu` (policies catégorie)

---

## 1. BUGS MAJEURS — Dynamic vs Static

### 1.1. ❌ P0 — **Listes hardcodées (fallback) quand le catalogue est vide**

Quand l'API ne renvoie pas d'attribut ou quand les variations sont filtrées hors-canal, **le wizard masque la faute en affichant une liste codée en dur** :

| Étape | Fichier | Liste figée | Taille | Comportement actuel |
|---|---|---|---|---|
| Sauce | `KioskStepSauceComponent.vue` → `getDefaultSauceList()` | algérienne, blanche, ketchup, mayo, biggy, samouraï | **6** | Affiché si attribut `Sauce` absent |
| Pain | `KioskStepPainComponent.vue` → `getDefaultPainList()` | "Pain", "Galette" | **2** | Affiché si attribut `Pain/Galette` absent |
| Viande | `KioskStepViandeComponent.vue` → `getDefaultViandeList()` | poulet, boeuf, merguez, nuggets | **4** | Affiché si attribut `Viande` absent |
| Sauce frites | `KioskStepMenuComponent.vue` → `fritesSauceRows()` (branche `legacy`) | ketchup, mayo, algérienne, bbq, samouraï, sans | **6** | Affiché si `kioskSauceVariationRowsForItem` vide |
| Taille | `KioskStepTailleComponent.vue` → branche `else` du `tailleOptions` | S(1), M(2), L(3), XL(4) | **4** | Affiché si attribut `Taille/Size` absent |

**Impact UX** :
1. Le client voit **6 sauces figées** sur un produit dont le catalogue expose 18 sauces → il commande un produit inexistant.
2. À l'inverse, un produit avec **3 sauces réelles** affiche malgré tout **6 fallback** si le filtrage `surface=kiosk` masque accidentellement l'attribut.
3. Sur Sauce frites : la carte affiche 6 boutons, mais `canAdvance` n'impose la sélection QUE si `kioskSauceVariationRowsForItem(item).length > 0`. Résultat : **hint rouge "choisissez une sauce" visible mais bouton Suivant actif**. Incohérence totale.

**Fix obligatoire** :
- Supprimer les `getDefault*List()` des 4 composants.
- Si la liste est vide → **masquer l'étape complète** via `shouldShowStep()` dans `KioskWizardComponent`.
- `shouldShowStep('sauce')` doit vérifier `variations[sauceAttr.id].length > 0`, pas seulement la présence de l'attribut.
- `shouldShowStep('pain')` existe déjà dans le template sandwich mais ne vérifie pas la présence de variations → même correction.
- `fritesSauceRows` : **ne jamais tomber dans `legacy`** — s'il n'y a pas de catalogue sauce, **cacher la section sauce-frites** et ne pas bloquer `canAdvance`.
- Taille fallback statique : acceptable UNIQUEMENT si la catégorie « Tacos » n'a aucun attribut Taille configuré, sinon dynamique.

---

### 1.2. ⚠️ P0 — **"Viande en supplément" ignorée**

L'utilisateur a demandé explicitement la logique : *"logique de prix de viande en supplément nos listes de supplément"*.

**État actuel** :
- `KioskStepViande.viandeList` ne lit QUE `variations[viandeAttr.id]` — donc seulement les viandes déclarées comme **variations** (prix 0, rotation simple).
- Les viandes qui sont des **extras** (`extras` avec `group_label='viande'` ou nom contenant "viande" et prix > 0) **n'apparaissent pas dans l'étape Viande**. Elles tombent dans **Suppléments**.
- `hasViandeVariations()` dans le wizard détecte pourtant bien les deux cas (attribut OU extra) → `viande` step se **lance** même s'il n'y a que des extras → écran vide (fallback 4 items hardcodés) → confusion totale.

**Impact** :
- Produit avec viandes payantes (ex. burger "choose your meat" avec Beef +0€ / Chicken +0,50€ / Double Steak +2€) → la carte `Viande` montre la fausse liste "poulet/boeuf/merguez/nuggets" au lieu des 3 vraies viandes. Le surplus prix n'est jamais appliqué.
- Dans `Suppléments`, le client voit "Double Steak +2€" à côté de "Fromage +1€" → il pense que Double Steak est une option optionnelle supplémentaire alors que c'est un choix exclusif de la viande.

**Fix** :
1. Enrichir `viandeList` pour fusionner `variations[viandeAttr.id]` **ET** `extras` qualifiés viande, en marquant le prix.
2. Refléter le surplus dans `runningTotal` en distinguant "viande offerte" (1ʳᵉ viande) vs "viande en supplément" (2ᵉ…) quand la catégorie autorise plusieurs viandes payantes.
3. Exclure ces mêmes extras de `KioskStepSupplements.supplementList` (via `kioskIsViandeLikeExtra(e, item)`, miroir de `kioskIsBundledFritesMenuUpgradeExtra`).

---

### 1.3. ⚠️ P1 — **Étape Menu : affichée alors qu'il n'y a rien à choisir**

`shouldShowStep('menu')` retourne `true` dès que `item.has_menu && item.addons.length > 0`. Or :

- `addons` contient parfois UNIQUEMENT des accompagnements (frites seule, salade…) sans aucune boisson.
- Dans ce cas, `boissonList = kioskDrinkAddonRowsFromItem(item)` renvoie `[]`.
- L'écran menu s'affiche avec : carte "Menu complet (+frites+boisson)" MAIS la section boisson se réduit à `kiosk-boisson-placeholder` ("En attente…").
- `canAdvance` n'impose pas la boisson car `drinkRows.length === 0`.
- → Le client clique "Menu complet", rien ne se passe côté boisson, il passe au récap qui affiche "Menu complet" sans boisson. Il paie un menu sans savoir ce qu'il contient.

**Fix** :
- `shouldShowStep('menu')` doit vérifier : `has_menu && (drinkAddonsRows.length > 0 || fritesUpgradeRows.length > 0)`.
- Si seuls des accompagnements non-boisson sont présents → masquer "Menu complet" et "Avec boisson", ne laisser que "Avec frites" et "Sans menu".
- Alternativement : étendre le composant pour lister les accompagnements solides (Frites / Nuggets / Wings) comme sous-choix explicite, pas seulement upgrade cheddar.

---

### 1.4. ⚠️ P1 — **Policies catégorie `default_menu_kiosk` et `sauce_included_menu` ignorées**

Le backend (`NormalItemResource`) expose :
```php
"default_menu_kiosk"  => optional($this->category)->default_menu_kiosk ?? false,
"sauce_included_menu" => optional($this->category)->sauce_included_menu ?? false,
```

Grep confirme : **aucun code front borne ne les consomme** (uniquement l'admin pour les éditer).

**Gain attendu** :
- `default_menu_kiosk = true` → pré-sélectionner `menuChoice = 'full'` à l'entrée de l'étape menu (push-pricing).
- `sauce_included_menu = true` → quand un menu est sélectionné, la sauce du sandwich/tacos compte AUSSI comme sauce frites → **masquer la section sauce-frites** pour éviter que le client en choisisse deux distinctes.

**Fix** : brancher ces deux drapeaux dans `KioskStepMenu.mounted()` et `showFritesSauce` computed.

---

### 1.5. ⚠️ P1 — **Détection drink addon par heuristique de nom**

`kioskIsDrinkAddonName(name)` matche sur un regex blacklist/whitelist (`coca`, `fanta`, `sprite`, `eau`, `thé`, `soda`…).

**Cas qui cassent** :
- Boisson exotique non-listée (ex. "Vittel Citron", "Kas Naranja", "Lipton Ice Tea Peach") → exclue.
- Produit nommé sans mot-clé générique (ex. "1664", "Heineken 33cl") → exclu.
- Boisson renommée manuellement par le restaurant ("Notre soda signature").

**Fix** :
- Ajouter à terme un flag `addon_kind='drink'` côté backend (migration `item_addons` + admin).
- Temporaire (V1) : autoriser un `group_label='boisson'` sur l'addon (déjà présent sur les extras) et l'utiliser en priorité avant le regex.

---

### 1.6. ⚠️ P1 — **Upgrade frites : détection par regex fragile**

`kioskIsBundledFritesMenuUpgradeExtra` reconnaît :
```
/frite.*cheddar|cheddar.*frite|frite.*crisp|crisp.*frite|frite.*fromage|suppl.*frite|upgrade.*frite/i
```

**Cas qui cassent** :
- Restaurant nomme "Cheese Fries" / "Frites gratinées" / "Loaded fries" → exclus → ces extras apparaissent dans **Suppléments** (doublon avec l'étape Menu).
- Restaurant nomme "+1€ Cheddar" dans `group_label='menu_frites'` mais nom = "Cheddar" seul → raté.

**Fix** : privilégier `group_label` (`menu_frites`, `menu_upgrade`, `frites_upgrade`) et ne tomber sur le regex que si vide.

---

## 2. BUGS MINEURS — Logique d'état

| # | Bug | Fichier | Impact |
|---|---|---|---|
| 2.1 | `sauceOrder: ['_skip']` émis par `KioskStepSauce` quand la liste est vide | `KioskStepSauceComponent.vue:18` | Le panier enregistre `_skip` comme pseudo-ID sauce, le récap l'affiche comme label brut |
| 2.2 | `_boissonMeta` pas réinitialisé quand `menuChoice='frites'` | `KioskWizardComponent.vue:527` | Instruction finale peut contenir un nom de boisson alors que le menu est "frites seules" |
| 2.3 | `garnitureList` exclut `name.includes('sauce suppl')` mais pas `group_label='sauce'` | `KioskStepGarnituresComponent.vue:98` | Une sauce gratuite (`group_label=sauce`, price=0) apparaît en Garniture |
| 2.4 | `initGarnitures` coche toutes les garnitures prix=0 → pas de choix opt-in, que opt-out | `KioskWizardComponent.vue:745` | Design choice discutable, mais contraire à "maximum d'intelligence" demandé (certains clients aimeraient cocher explicitement) |
| 2.5 | `buildCartItem.allVariations[sauceAttr.id]` ne prend que la 1ʳᵉ sauce, les suivantes passent en surcharge calculée mais pas en ID variation | `KioskWizardComponent.vue:843` | Analytics backend ne voit qu'une sauce par ligne, impossible de tracker les sauces préférées |
| 2.6 | `kioskShowBoissonOnlyMenuCard` false pour `sandwich/burger/tacos` — dur | `KioskWizardComponent.vue:311` | Si un sandwich-burger fusion veut quand même proposer "+ boisson seule", impossible sans modifier le code |
| 2.7 | `detectTemplateFromName` bascule sur heuristique de nom si `wizard_template='simple'` | `KioskWizardComponent.vue:366` | Une catégorie "Snacking" avec template `simple` rate la route snacking, passe par default → étape Supplements seule |
| 2.8 | `shouldAskTacosTaille()` regarde `item.name + item.description`, pas la catégorie | `KioskWizardComponent.vue:465` | "Tacos Bœuf L" échappe à la taille step (contient `L`), "Tacos Spécial" sans taille dans le nom déclenche un 4-choix générique |
| 2.9 | `KioskStepGarnitures` `selectedCount=0 → summary "without any"` mais pas de blocage pour avancer | OK (design choice) | Acceptable |
| 2.10 | Aucun `role=button` / `tabindex` / `keydown` sur les cartes `KioskStepTaille`, `KioskStepPain`, `KioskStepViande`, `KioskStepGarnitures` | Multiple `.vue` | A11y incomplète — seulement Sauce, Menu, Categories ont été traités |
| 2.11 | `buildSimpleCartItem` (ajout direct sans wizard) renvoie `item_variations: { variations: {}, names: {} }` format legacy, alors que `buildCartItem` renvoie un **tableau**. Deux shapes coexistent | `KioskCategoriesComponent.vue:387` vs `KioskWizardComponent.vue:922` | Toute fonction qui itère sur `cartItem.item_variations` comme array plantera sur le simple-add |
| 2.12 | `fritesSauceOrder=['sans']` signifie "sans sauce" mais le récap affiche une ligne "sans" intitulée traduit → OK si i18n fourni, fragile | `KioskOrderSummaryComponent.vue:170` | Minor |
| 2.13 | `$route.query.cat` non nettoyé lors d'un `abandonOrder()` → idle screen conserve l'historique | `KioskCategoriesComponent.vue:408` | Minor |

---

## 3. ÉTATS LOGIQUES — Validation par étape

Tableau de vérité du bouton `Suivant` (`canAdvance`) :

| Étape | Condition actuelle | Manque |
|---|---|---|
| `pain` | `selections.pain !== null` | ✅ OK |
| `taille` | `selections.taille !== null` | ✅ OK (après [AUDIT-P2]) |
| `viande` | `totalViandes >= detectViandeCount()` | ⚠️ Aucun **max-1 par type** si viande est "exclusive" (burger à 1 seul choix) — traité par `maxViandes=1` mais pas de bascule UX radio |
| `sauce` | `sauceOrder.length > 0` | ⚠️ Ne distingue pas un `_skip` d'un vrai choix |
| `garnitures` | *(non contrôlé)* | ✅ OK par design (toutes optionnelles) |
| `supplements` | *(non contrôlé)* | ✅ OK |
| `menu` | `menuChoice ∈ {full,frites,boisson,none}` + boisson obligatoire si `full|boisson` et `drinkRows>0` + ≥1 sauce frites si `full|frites` et `catalog>0` | ⚠️ Manque : "si `full` et pas de boisson ET pas d'accompagnement → alors cacher `full`" (cf. 1.3) |
| `recap` | `true` (dernière étape) | ✅ OK |

---

## 4. PRIX — Cohérence runningTotal vs buildCartItem

Les deux fonctions de pricing vivent dans `kioskPricing.js` :

- `calculateKioskRunningTotal(item, selections)` : pour l'affichage en pied de wizard.
- `buildCartItem()` dans le wizard : pour la persistence panier.

**Écarts détectés** :

| Composant | `calculateKioskRunningTotal` | `buildCartItem` |
|---|---|---|
| Base `item.convert_price` | ✅ | ✅ |
| Sauces extra (2ᵉ+) | `(sauceOrder.length-1) * unit` | `(sauceOrder.length-1) * unit` ✅ |
| Sauces frites extra (2ᵉ+) | `(frySauces.length-1) * unit` après filtre `!== 'sans'` | idem ✅ |
| Suppléments payants | `extras.filter(selected && price>0 && !isSauce)` | idem, **mais inclut aussi les upgrades frites** (sélectionnés via menu step) → pris en compte **deux fois** ? |
| → Vérification | `selections.supplements[id]` géré par `applyBundledUpgradeSelection` ✓ | idem, unifié |
| Menu addon price | `getKioskMenuAddonPrice(item, menuChoice)` | ✅ |
| Quantité | `× quantity` | ✅ |

**Résultat** : les deux fonctions sont cohérentes ✓. Mais risque **si un même extra apparaît à la fois dans `supplementList` et dans `menuFritesUpgradeRows`** → double-comptage. Aujourd'hui `kioskIsBundledFritesMenuUpgradeExtra` est utilisé par les deux filtrages donc mutuellement exclusifs. **OK tant que l'heuristique est identique**, risque si désynchronisée plus tard.

**Fix de sécurité** : centraliser le partitionnement des extras dans un seul helper `partitionKioskExtras(item)` retournant `{ garnitures, supplements, fritesUpgrades, viandesPaid }` — un seul endroit à maintenir.

---

## 5. ACCESSIBILITÉ — États incomplets

L'audit P2/C6-C7 précédent a couvert :
- ✅ `KioskStepSauce` → `role=checkbox` + `keydown.enter/space`
- ✅ `KioskStepMenu` (cartes menu, boisson, upgrade frites, sauce frites) → `role=radio/checkbox`
- ✅ `KioskCategories` (cartes produit) → `role=button`

**Restant à traiter** :
- ❌ `KioskStepTaille` → cartes `<div @click>` sans rôle ni clavier
- ❌ `KioskStepPain` → idem
- ❌ `KioskStepViande` → cartes + boutons `+/-` (les boutons sont natifs `<button>` OK, mais la carte elle-même n'a pas de rôle)
- ❌ `KioskStepGarnitures` → idem
- ❌ `KioskStepSupplements` → idem
- ❌ `KioskOrderSummary` → boutons `±` quantité : OK natif

Contraste : les badges rouges `#e8001c` sur fond `rgba(232,0,28,0.06)` ont un ratio < 4.5:1 → revoir lors du nouveau design.

---

## 6. PLAN DE CORRECTION (ordre d'exécution)

### Phase 1 — Nettoyage "dynamique-only" (bloque le design redesign)

**C1. Supprimer tous les fallback hardcodés**
- [ ] `KioskStepSauceComponent.vue` : supprimer `getDefaultSauceList()`. Si `sauceList.length === 0`, n'afficher que le bouton "Skip" et ne PAS bloquer l'étape. Mieux : remonter au wizard pour masquer la step.
- [ ] `KioskStepPainComponent.vue` : supprimer `getDefaultPainList()`. `shouldShowStep('pain')` vérifie `variations.length > 0`.
- [ ] `KioskStepViandeComponent.vue` : supprimer `getDefaultViandeList()`. Enrichir avec les extras `group_label='viande'` (voir C3).
- [ ] `KioskStepMenuComponent.vue` : supprimer la branche legacy de `fritesSauceRows`. Si catalogue vide → ne pas afficher la section.
- [ ] `KioskStepTailleComponent.vue` : conserver le fallback S/M/L/XL **uniquement pour `template=tacos` sans attribut Taille** (c'est le cas légitime documenté).

**C2. Aligner `shouldShowStep` sur la présence réelle de données**
Dans `KioskWizardComponent.vue:422` :
- `sauce` → vérifier `variations[sauceAttr.id]?.length > 0`.
- `pain` → vérifier `variations[painAttr.id]?.length > 0` (actuellement non-conditionnel dans le template sandwich).
- `menu` → vérifier `drinkAddonsRows.length > 0 || fritesUpgradeRows.length > 0` (pas seulement `has_menu && addons.length > 0`).
- `viande` → vérifier l'un OU l'autre : variations OU extras qualifiés viande.

**C3. Viande en supplément — fusion variations + extras payants**
- [ ] Créer `resources/js/helpers/kioskViandeCatalog.js` → `kioskViandeRowsForItem(item)` retournant `[{ id, name, price, source: 'variation'|'extra', thumb }]`.
- [ ] Refacto `KioskStepViandeComponent.vue` pour afficher un badge `+{price}` quand `source='extra'`.
- [ ] Étendre `calculateKioskRunningTotal` pour inclure le prix des viandes-extras sélectionnées (qui passeront par `_viandeMeta` avec `price`).
- [ ] Exclure ces extras de `KioskStepSupplements.supplementList` via `kioskIsViandeLikeExtra(e, item)`.

**C4. Consolider le partitionnement extras**
- [ ] Créer `resources/js/helpers/kioskExtrasPartition.js` → `partitionKioskExtras(item)` renvoyant `{ garnitures, supplements, fritesUpgrades, viandesPaid }`.
- [ ] Remplacer tous les `item.extras.filter(...)` disséminés par l'appel au helper.

### Phase 2 — Policies catégorie + robustesse addons

**C5. Activer `default_menu_kiosk` et `sauce_included_menu`**
- [ ] `KioskStepMenuComponent.vue:mounted()` : si `item.default_menu_kiosk === true && !this.localChoice` → `selectChoice('full')`.
- [ ] `KioskStepMenuComponent.vue:showFritesSauce` : retourner `false` si `item.sauce_included_menu && selections.sauceOrder.length > 0`.

**C6. Détection drink/menu via `group_label` prioritaire**
- [ ] `kioskDrinkAddons.js` → prioriser `addon.group_label === 'boisson' || 'drink'` avant le regex.
- [ ] `kioskMenuBundledExtras.js` → prioriser `group_label.includes('menu_frites' | 'frites_upgrade')` avant le regex.
- [ ] Ajouter `group_label='viande'` au détecteur viande.

### Phase 3 — Accessibilité uniforme

**C7. Rôles/keyboard sur toutes les cartes**
- [ ] Appliquer le pattern `role=radio|checkbox + tabindex=0 + keydown.enter/space` sur `KioskStepTaille`, `KioskStepPain`, `KioskStepViande`, `KioskStepGarnitures`, `KioskStepSupplements`.
- [ ] Ajouter `:focus-visible` avec `outline: 3px solid rgba(232, 0, 28, 0.55)`.

### Phase 4 — Nettoyage d'état et traçabilité

**C8. Normaliser `item_variations` format**
- [ ] `buildSimpleCartItem` (Categories) doit produire `item_variations: []` et `item_extras: []` (arrays, pas objets).
- [ ] Unifier via un helper `kioskBuildCartItemShape({ item, variations, extras, ... })`.

**C9. Nettoyer `_skip` et `_boissonMeta`**
- [ ] Remplacer `sauceOrder: ['_skip']` par un flag `sauceSkipped: true` distinct.
- [ ] Dans `updateSelection`, purger `_boissonMeta` quand `menuChoice` change pour `'frites'|'none'`.

### Phase 5 — Tests unitaires de non-régression

**C10. Suite Vitest**
- [ ] `tests/js/kioskWizardFlow.spec.js` : pour chaque template (tacos, sandwich, burger, assiette, snacking, omelette, salade, simple), vérifier que `activeSteps` est correct selon différentes configurations de payload.
- [ ] `tests/js/kioskExtrasPartition.spec.js` : couvrir les 4 partitions sur un item complet.
- [ ] `tests/js/kioskViandeCatalog.spec.js` : variations seules / extras seuls / mix.
- [ ] `tests/js/kioskMenuCanAdvance.spec.js` : tous les cas de blocage `canAdvance`.

---

## 7. IMPACT DESIGN REDESIGN (ce que le designer doit savoir)

Pour le brief designer (`docs/DESIGN_BRIEF_KIOSK_2026.md`), **ajouter ces contraintes dérivées de l'audit** :

1. **Aucune liste n'est « fixe »** : toutes les listes de choix (taille, pain, viande, sauce, garniture, supplément, menu, boisson, sauce-frites) proviennent du catalogue admin. Le designer doit prévoir :
   - Des grilles **fluides** : 2, 3, 4 colonnes adaptatives selon nombre d'options (1 à 30).
   - Un état **« vide / liste non disponible »** avec un bouton `Passer cette étape` (plutôt qu'un fallback placeholder).
   - Un état **« beaucoup d'options »** : pagination ou scroll vertical avec indicateurs.

2. **Étape Menu doit gérer 4 sous-sections empilées** :
   1. Choix formule (2 à 4 cartes selon contexte : full / frites / boisson / sans)
   2. Upgrade frites (0 à N cartes)
   3. Choix boisson (0 à N cartes, visible si formule=full|boisson)
   4. Sauce frites (0 à N cartes + "Sans sauce", visible si formule=full|frites)
   
   → Le designer doit prévoir des **séparateurs visuels clairs** et une animation douce de révélation quand une sous-section devient pertinente.

3. **Pricing dynamique visible** : chaque étape doit afficher à tout moment le **total courant** + le **delta** lié à la dernière interaction (ex. "+1,50€" sur la carte "Double Cheese" en surbrillance).

4. **Récap final multi-lignes** : variable selon le template. Le designer doit prévoir 3 densités :
   - Compact (tacos simple 1 viande, pas de menu) → ~5 lignes
   - Standard (burger + menu complet + 2 sauces + 3 supp.) → ~12 lignes
   - Dense (menu complet XL + 4 viandes + 6 sauces + 5 supp. + sauce frites) → ~20 lignes → scroll

5. **Modes d'erreur** :
   - Catalogue partiellement chargé (offline, snapshot IndexedDB).
   - Item retiré du catalogue pendant la commande.
   - Boisson choisie puis désactivée par le staff (si synchro temps réel ajoutée V2).

6. **États d'attente longs** :
   - Chargement détails item après clic catégorie (loader dans `openProduct`).
   - Soumission commande après paiement.

---

## 8. MÉTRIQUES — État post-correction attendu

| KPI | Avant | Après (cible) |
|---|---|---|
| Listes codées en dur | 5 | **0** |
| Étapes affichées sans données réelles | 5 cas | 0 |
| Viandes payantes perdues dans Suppléments | 100% | 0% |
| Policies catégorie consommées (`default_menu_kiosk`, `sauce_included_menu`) | 0/2 | 2/2 |
| Détection drink par heuristique nom seul | 100% | <20% (cas restants après `group_label`) |
| A11y ARIA sur cartes wizard | 3/7 étapes | 7/7 étapes |
| Shapes `item_variations` incohérents | 2 | 1 |
| Double-comptage extras (upgrade vs supplément) possible | Oui | Non (via helper unique) |

---

## 9. DÉCISION RECOMMANDÉE

**Ordre d'exécution recommandé** pour ne pas bloquer le designer :

1. **Immédiat (avant design)** : Phase 1 (C1 + C2 + C3 + C4) → le designer reçoit un wizard « propre » en dynamique pur, sans écrans fantômes.
2. **En parallèle du design** : Phase 2 (C5 + C6) → renforce la logique métier sans changer la surface visuelle.
3. **Pendant l'intégration design** : Phase 3 (C7) → l'a11y est intégrée au nouveau design directement.
4. **Après intégration** : Phase 4 (C8 + C9) et Phase 5 (C10 tests) → consolidation.

**Gate humain recommandé avant Phase 1** : confirmer avec le métier que la liste "6 sauces fallback" peut disparaître sans transition (sinon prévoir une période de supervision).

---

*Fin du rapport d'audit. Prêt à exécuter la Phase 1 sur demande.*
