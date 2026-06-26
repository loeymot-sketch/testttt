# HEAL — Catalogue money FR (brut « 6.00 » → « 6,00 € »)

Round 3 · 2026-06-26 · NON-frozen · scope-minimal display swaps · NON committé.

## Pattern
Composants catalogue affichaient `*_flat_price` (brut, point décimal, sans €,
destiné aux INPUTS) au lieu de `*_currency_price` (FR via
`AppLibrary::currencyAmountFormat`). 10 occurrences d'AFFICHAGE corrigées (7
fichiers). Champs `:value`/`v-model`/`price:` de formulaire d'édition INTOUCHÉS.

## Champs currency vérifiés EXISTANTS (grep app/Http/Resources + modèle)
| Composant | Resource serveur (ctrl) | Champ avant → après | Vérif |
|---|---|---|---|
| OfferItemListComponent:21 | OfferItemResource (OfferItemController:41) | `offer_item_flat_price`→`offer_item_currency_price` | OfferItemResource:30 ✅ |
| CatalogStudioComponent:134 | SimpleItemResource (ItemController:102 index/`item/lists`) | `item.flat_price`→`item.currency_price` | SimpleItemResource:37 ✅ |
| ItemVariationListComponent:24 | ItemVariationResource (via ItemVariationGroupByAttributeResource:21 children) | `child.flat_price`→`child.currency_price` | ItemVariationResource:27 ✅ |
| ItemShowComponent:60 | ItemResource (ItemController:119 `item/show`) | `item.flat_price`→`item.currency_price` | ItemResource:76 ✅ |
| ProductComposerSummary:37 | ItemResource (item prop = item/show) | invert `flat_price\|\|currency_price`→`currency_price\|\|flat_price` | ItemResource:76 ✅ |
| ProductComposerSummary:73 | ItemResource:103 `variations` = modèles ItemVariation bruts | invert choice | Modèle ItemVariation `$appends`:17 + `getCurrencyPriceAttribute`:127 ✅ |
| ProductComposerSummary:98 | ItemResource:105 `extras` = ItemExtraResource::collection | invert extra | ItemExtraResource:24 ✅ |
| ProductComposerSummary:122 | ItemResource:106 `addons` = ItemAddonResource::collection | `total_flat_price\|\|addon_item_flat_price`→`total_currency_price\|\|addon_item_currency_price` | ItemAddonResource:68 + :56 ✅ |
| ItemExtraListComponent:28 | ItemExtraResource (`itemExtra/lists`) | `extra.flat_price`→`extra.currency_price` | ItemExtraResource:24 ✅ |
| ItemAddonListComponent:30 | ItemAddonResource (ItemAddonController:28 `itemAddon/lists`) | `addon.total_flat_price`→`addon.total_currency_price` | ItemAddonResource:68 ✅ |

Note l.73 : les variations dans `ItemResource` sont des modèles bruts
(`groupBy`), MAIS le modèle `ItemVariation` append `currency_price`/`flat_price`
(accesseurs `getCurrencyPriceAttribute`/`getFlatPriceAttribute`, tous deux
`currencyAmountFormat`). Donc `choice.currency_price` existe → swap valide.

## Swaps NON faits (champ absent)
AUCUN. Les 10 champs `*_currency_price` ciblés existent tous dans le resource/
modèle servant le composant. Aucun finding « champ absent » à signaler.

## Discipline INPUTS préservée
NON touchés (peuplent des formulaires d'édition, l'input veut le brut) :
ItemVariationListComponent `edit()` l.132 `price: itemVariation.flat_price` ;
ItemExtraListComponent `edit()` l.137 `price: itemExtra.flat_price` ;
CatalogStudio `productQuickForm.price` (input v-model libre).

## Gates
- **Frozen-diff = 0** : `git diff --stat` sur pos-wizard.js/.css,
  admin-pos-v4.blade.php, PaymentComponent, PosV5TrancheRow, 3 kiosk → VIDE.
- **Diff total** : 7 fichiers, 10 insertions / 10 suppressions (exactement les 10 swaps).
- **Vitest catalogue/composer** : 53/53 ✅ (productComposerSummary, productComposerEditor,
  catalogStudio×5, itemListWizardButton, studioFrontendI18nParity). Aucune spec
  n'assertait le brut affiché (le fixture `flat_price:'9.90'` de
  catalogStudioCategoryWizardEntry n'est jamais asserté en sortie) → 0 spec à modifier.
- **Sentinels** : 401/404. Les 3 échecs (appBundleFreshnessSentinel,
  posAppBundleFreshnessSentinel, KeyboardNavigationSentinel `:focus-visible`)
  sont **PRÉ-EXISTANTS** : prouvé par `git stash` de mes 7 fichiers → les 3
  échouent À L'IDENTIQUE sans mes changements (build-state : en.json/CSS d'un
  agent concurrent non encore rebuildé dans les bundles ; fix = `npm run
  production` côté superviseur, hors-scope par consigne « économe disque »).

NON committé. Pas de `npm run` (superviseur rebuild).
