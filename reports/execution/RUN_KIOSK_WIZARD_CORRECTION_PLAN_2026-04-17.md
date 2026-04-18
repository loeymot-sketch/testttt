# RUN — Plan de correction wizard borne (max intelligence) — 2026-04-17

> Réf. plan : `RUN_KIOSK_MENU_WIZARD_UX_2026-04-17.md` (audit maximal du wizard borne).
> Demande utilisateur : « lance le plan de correction avec un max d'intelligence ».
> Périmètre : wizard borne (kiosk) — refonte logique « tout dynamique catalogue », a11y, helpers SSOT, tests.
> SSOT : aucune liste hardcodée résiduelle (pain / viande / sauce / frites‑sauce / supplements / garnitures).

---

## 0. Synthèse exécutive

| Indicateur | Avant | Après |
| --- | --- | --- |
| Helpers SSOT pour `item.extras` | filtrages dispersés (5 endroits, divergents) | **1 helper** `partitionKioskExtras` |
| Helpers SSOT pour viandes (variations + extras payants) | viandes‑extras silencieusement perdues | **1 helper** `kioskViandeCatalogForItem` |
| Listes hardcodées dans étapes (pain/viande/sauce/frites‑sauce) | 4 fallbacks « inventés » | **0** (catalogue uniquement, empty_hint i18n) |
| Détection drinks / frites‑upgrades | regex sur `name` uniquement | `group_label` prioritaire + regex en fallback |
| Surplus viandes payantes dans `runningTotal` | **non comptabilisé** (régression prix) | comptabilisé via `kioskSumPaidViandesSurcharge` |
| `shouldShowStep` (wizard) | conditions ad‑hoc divergentes des étapes | aligné sur les helpers (1 source) |
| A11y étapes (`role` / `aria-checked` / `:focus-visible`) | partiel | étendu à pain / viande / taille / garnitures / supplements |
| Tests Vitest | 132 passing | **162 passing** (+30, dont 27 nouveaux + 3 retravaillés contre nouveau contrat) |
| Lints (zone touchée) | 0 | **0** |

Aucun fallback hardcodé n'a survécu. Toutes les étapes lisent désormais le catalogue via un helper unique. Le récap commande affiche correctement les viandes payantes. Le total panier inclut leurs surplus.

---

## 1. Helpers SSOT (nouveaux)

### 1.1 `resources/js/helpers/kioskExtrasPartition.js` (NEW)
Source unique du partitionnement de `item.extras` en **4 buckets mutuellement exclusifs** :
- `garnitures` : extras gratuits non‑sauce
- `supplements` : extras payants non‑sauce, non‑frites‑upgrade, non‑viande
- `fritesUpgrades` : extras payants détectés « cheddar / crispy / menu frites » (via `group_label` puis regex)
- `viandesPaid` : extras payants qualifiés viande (via `group_label` puis nom)

Sauces JAMAIS bucketisées (gérées exclusivement par variations d'attribut). Ce helper élimine la possibilité de double‑comptage (un même extra pris une fois dans `fritesUpgrades` ET une fois dans `supplements`).

### 1.2 `resources/js/helpers/kioskViandeCatalog.js` (NEW)
Fusionne les variations d'attribut « Viande » (offertes) **et** les extras payants viande (avec prix) en une liste uniforme :
```
[{ id, key, name, price, source: 'variation'|'extra', thumb, emoji, attrId? }]
```
Plus la fonction `kioskSumPaidViandesSurcharge(viandeMeta)` pour le calcul du surplus dans `runningTotal`. Régression critique corrigée : les viandes‑extras étaient auparavant silencieusement absentes de l'étape Viande **et** absentes du total.

---

## 2. Helpers existants — robustesse `group_label`

- `kioskMenuBundledExtras.js` : `kioskIsBundledFritesMenuUpgradeExtra` priorise désormais `group_label` (Menu/Frites upgrade) avant les regex sur le nom. Plus de faux positif sur des noms ambigus.
- `kioskDrinkAddons.js` : `kioskIsDrinkAddon` priorise `group_label='boisson'/'drink'` avant le fallback regex.
- `kioskPricing.js` : `calculateKioskRunningTotal` intègre désormais `kioskSumPaidViandesSurcharge(selections.viandeMeta)` — invariant prix : ce qui est facturé est exactement ce que le récap montre.

---

## 3. Étapes wizard — refonte par étape

Toutes les étapes appliquent le même contrat : **catalogue ou rien**. Quand le catalogue est vide, on rend un état vide explicite (clé i18n `*.empty_hint`) au lieu de fabriquer une liste fantôme.

### 3.1 `KioskStepSauceComponent.vue`
- Suppression `getDefaultSauceList()` et tous les `fallback_*`.
- Si `sauceList` vide → empty_hint + bouton « Continuer sans sauce ».
- Plus aucune entrée `_skip` qui fuit dans les emits.

### 3.2 `KioskStepPainComponent.vue`
- Suppression `getDefaultPainList()`.
- Liste = variations de l'attribut `Pain`/`Galette` du catalogue.
- Empty_hint i18n quand vide.
- A11y : `role="radio"`, `aria-checked`, `aria-label`, `tabindex`, `:focus-visible`.

### 3.3 `KioskStepViandeComponent.vue`
- Branché sur `kioskViandeCatalogForItem(item)` → fusion variations + extras payants.
- Méta `viandeMeta` étendu avec `price`, `source`, pour propager le surplus jusqu'au panier.
- Indicateur visuel « payant » (`.is-paid`) pour différencier les viandes en supplément.
- A11y compteur ± avec libellés `viande.add_one` / `viande.remove_one`.

### 3.4 `KioskStepGarnituresComponent.vue`
- Filtrage interne remplacé par `partitionKioskExtras(item).garnitures`.
- Exclut les sauces via `group_label='sauce'` (plus de garniture-sauce dupliquée).
- A11y : `role="checkbox"`, `aria-checked`, `aria-label`.

### 3.5 `KioskStepSupplementsComponent.vue`
- Filtrage interne remplacé par `partitionKioskExtras(item).supplements`.
- Garantit zéro double‑comptage avec `KioskStepMenu` (frites upgrades) et `KioskStepViande` (viandes payantes).
- A11y idem garnitures.

### 3.6 `KioskStepMenuComponent.vue`
- Suppression du fallback hardcodé pour les sauces frites — passe par `kioskSauceVariationRowsForItem`.
- Politique `sauce_included_menu` (catégorie) : si activée, masque la section « sauce frites » pour éviter le doublon visuel.
- Auto‑sélection « full » via `default_menu_kiosk` au mount si la politique catégorie l'exige.

### 3.7 `KioskStepTailleComponent.vue`
- A11y `role="radio"`, `aria-checked`, focus styles.

---

## 4. `KioskWizardComponent.vue` (orchestrateur)

`shouldShowStep(type)` strictement aligné sur ce que chaque étape affichera réellement :

| Étape | Condition d'affichage |
| --- | --- |
| `pain` | attribut Pain/Galette présent ET ≥ 1 variation active |
| `viande` | `detectViandeCount() > 0` ET `kioskViandeCatalogForItem(item).length > 0` |
| `sauce` | `kioskSauceVariationRowsForItem(item).length > 0` |
| `garnitures` | `partitionKioskExtras(item).garnitures.length > 0` |
| `supplements` | `partitionKioskExtras(item).supplements.length > 0` |
| `menu` | `item.has_menu === true` *(la question Menu vs Seul reste posée même sans drink/upgrade — choix utilisateur)* |
| `taille` | `shouldAskTacosTaille()` |

Autres correctifs :
- `updateSelection` purge `_boissonMeta` quand `menuChoice` change (plus de boisson fantôme dans le récap).
- `buildCartItem` mappe correctement les viandes payantes : variations → `item_variations`, extras → `item_extras` (zéro régression de structure serveur).

---

## 5. `KioskOrderSummaryComponent.vue`
- Affiche prix individuel pour chaque viande payante via `_viandeMeta.unitPrice`.
- Filtre défensif `visibleSauceOrder` — plus aucun marqueur interne `_skip` ne fuit dans le récap visible.

## 6. `KioskCategoriesComponent.vue`
- `buildSimpleCartItem` retourne désormais `item_variations: []` et `item_extras: []` (forme tableau attendue par le serveur). Suppression du legacy `{ variations: {}, names: {} }`.

---

## 7. i18n — clés ajoutées

`fr.json`, `en.json`, `ar.json` :
- `kiosk.wizard.step.pain.empty_hint`
- `kiosk.wizard.step.viande.empty_hint`
- `kiosk.wizard.step.viande.add_one` / `remove_one` (a11y compteur viande)
- `kiosk.wizard.step.garnitures.empty_hint`

(`kiosk.wizard.step.sauce.empty_hint` et `skip_btn` existaient déjà.)

---

## 8. Tests

### 8.1 Nouveaux fichiers
- `tests/js/kioskExtrasPartition.spec.js` — **15 tests** couvrant :
  - détection sauce via `group_label` puis nom,
  - détection viande payante (group_label / nom / prix > 0),
  - 4 buckets mutuellement exclusifs (zéro double‑comptage),
  - sauce jamais bucketisée,
  - skip null entries.
- `tests/js/kioskViandeCatalog.spec.js` — **12 tests** couvrant :
  - variations marquées `source='variation'`, `price=0`,
  - extras payants viande marqués `source='extra'`, `price>0`,
  - rejet des extras gratuits même labellisés viande,
  - skip variations supprimées (`status=10`),
  - dédup même `id`,
  - viandes payantes surfacées même sans attribut Viande,
  - somme `kioskSumPaidViandesSurcharge` sur `source='extra'` uniquement.

### 8.2 `tests/js/KioskWizard.spec.js` (mise à jour)
3 tests pré‑existants asseyaient l'ancien comportement de fallback hardcodé. Réécrits pour valider le **nouveau contrat** :
- `KioskStepPain` retourne `[]` + affiche `empty_hint` quand catalogue vide ;
- `KioskStepViande` idem + nouveau test surfaçant les viandes‑extras dynamiques ;
- `sauceList` retourne `[]` + `empty_hint` si toutes les variations sont inactives ;
- `KioskStepMenu` multi‑select frites‑sauce : fixture enrichie d'un attribut Sauce + variations, le test vérifie l'ordre via les noms (plus de clés magiques).
- 2 tests P5 burger heuristique : fixtures enrichies d'un attribut Sauce minimal pour respecter `shouldShowStep('sauce')`.

### 8.3 Résultat global
```
Test Files  20 passed (20)
Tests       162 passed (162)
Duration    2.81s
```

### 8.4 Lints
```
ReadLints sur 21 fichiers (helpers + composants + i18n + tests) → 0 erreur
```

---

## 9. Invariants vérifiés

- **SSOT catalogue** : aucune liste hardcodée n'apparaît dans le panier client. ✅
- **Mutual exclusion** : un extra ne peut pas être à la fois garniture, supplément, frites‑upgrade et viande. ✅ (test dédié)
- **Pricing** : surplus viande payante = `Σ price × count` pour `source='extra'`, intégré au runningTotal. ✅
- **A11y** : `role` + `aria-checked` + `:focus-visible` étendus aux 5 étapes principales. ✅
- **Empty‑state** : chaque étape gère le catalogue vide sans crash et sans liste fantôme. ✅
- **Cart shape** : `buildSimpleCartItem` aligné sur la forme serveur. ✅
- **i18n** : 3 langues (fr/en/ar) en parité sur les nouvelles clés. ✅

---

## 10. Hors‑périmètre / à suivre

Reportés explicitement (audit V2) :
- Persistance `default_menu_kiosk` côté API admin (la lecture est branchée, l'écriture reste à scaffolder).
- Visualisation du `group_label` dans le CRUD des extras (utile à l'opérateur pour profiter des heuristiques renforcées).
- Tests E2E Playwright spécifiques aux 4 nouveaux scénarios (multi‑select frites‑sauce, viande‑extra payante visible et chiffrée, masquage menu sans drink, bumping de stock catégorie hors v2).

---

## 11. Fichiers touchés

Helpers (5) :
- `resources/js/helpers/kioskExtrasPartition.js` *(NEW)*
- `resources/js/helpers/kioskViandeCatalog.js` *(NEW)*
- `resources/js/helpers/kioskDrinkAddons.js`
- `resources/js/helpers/kioskMenuBundledExtras.js`
- `resources/js/helpers/kioskPricing.js`

Composants Vue (10) :
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue`
- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepPainComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepGarnituresComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepTailleComponent.vue`

i18n (3) :
- `resources/js/languages/fr.json`
- `resources/js/languages/en.json`
- `resources/js/languages/ar.json`

Tests (3) :
- `tests/js/kioskExtrasPartition.spec.js` *(NEW)*
- `tests/js/kioskViandeCatalog.spec.js` *(NEW)*
- `tests/js/KioskWizard.spec.js`

---

## 12. Décision intégration design

Le wizard est désormais **prêt à recevoir le nouveau design** : la couche logique est purgée des fallbacks, les helpers SSOT garantissent un comportement déterministe quel que soit le catalogue, l'A11y est en place. Le designer peut habiller chaque étape sans risque de tomber sur des listes inventées ; les composants exposent partout un `empty_hint` i18n quand le catalogue est vide, ce qui simplifiera aussi le travail visuel des états « vide ».
