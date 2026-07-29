# LOCK_KIOSK_FRITES_SAUCE_BILLING — 2026-07-29

**Fichier frozen touché** : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
(CLAUDE.md §7 — Frontend / zone borne).
**Fichiers non-frozen touchés** : `resources/js/helpers/kioskPricing.js`,
`tests/js/kioskFritesSauceBilling.spec.js` (nouveau).

## Gate
Demande owner directe (2026-07-29) : « les choses supplémentaires ne sont plus calculées …
c'est bien ajouté mais leur prix ne bouge pas … pareil pour le site ».
Le défaut ne peut PAS être corrigé hors zone frozen : le montant scellé envoyé au backend est
construit dans `buildCartItem` (fichier frozen). Corriger seulement le total affiché aurait
recréé un **display ≠ sealed** (affiché 9,00 € / facturé 8,50 €) — pire que le bug d'origine.

## Défaut (reproduit, pas supposé)
La 2ᵉ sauce **frites** du menu était annoncée payante à DEUX endroits de l'UI :
* `KioskStepMenuComponent.vue:590-593` → `fritesSaucePriceLabel` = `getKioskExtraSauceUnitPrice`
* `KioskOrderSummaryComponent.vue:122` → `+{{ formatPrice(extraSaucePrice) }}`

…alors que :
* `kioskPricing.js:138-140` excluait explicitement `fritesSauceOrder` du total ;
* `KioskWizardComponent.buildCartItem` ne poussait l'ItemExtra « Sauce supplémentaire » que
  pour `sauceOrder` (sauce du sandwich), jamais pour `fritesSauceOrder`.

⇒ le client ajoute une sauce, voit « +0,50 € », **le prix ne bouge pas**, et la caisse ne la
facture jamais (manque à gagner + `composition_snapshot` sous-facturé).

**Prémisse fausse corrigée** : l'ancien commentaire affirmait « aucun mécanisme de facturation
backend, pas d'ItemExtra dédié sur les frites ». La ligne facturée est le **produit parent**,
qui porte bien « Sauce supplémentaire » @0,50 — **24 produits du catalogue de production**
(vérifié sur les 55 items, dump `GET /api/frontend/item/details`).

## Étendue mesurée (balayage des 55 produits)
Seul motif divergent du catalogue : `SAUCE-FRITES 2e` sur 24 produits.
Suppléments payants, frites_style, sauce sandwich, viandes payantes : **tous corrects**.
Après correctif : **0 divergence**.

## Parité site (référence)
Le site facture ET scelle déjà ce surcoût — la borne était la seule à diverger :
* `data/menu.js` `priceFor` : `if (fritesSauceIds.length > 1) total += (n-1) × 0,50`
* `api.js:522-537` : pousse l'ItemExtra « Sauce supplémentaire » (cumulé avec les sauces sandwich)

Direction du correctif = **aligner la borne sur le site** (facturer), jamais l'inverse
(rendre gratuit côté web aurait propagé le bug — cf. `goal_s8_parite_web_borne_2026-07-29`).

## Portée du patch (scope-minimal)
1. `kioskPricing.js` — `(N-1) × prix de l'ItemExtra « Sauce supplémentaire »` pour
   `fritesSauceOrder`, miroir exact du bloc sauce sandwich existant.
2. `KioskWizardComponent.vue` — nouveau `@pricing-allowed-block` poussant le même extra
   ×(N-1) dans `normalizedExtras` + `itemExtraTotal`.
Aucune autre ligne du fichier frozen modifiée.

**Garde de sûreté** : items **sans** l'extra → `extraSauceUnitPrice = 0`, aucun push →
sauce frites gratuite et `display == sealed` (testé).

## Preuves
* `tests/js/kioskFritesSauceBilling.spec.js` — 10 tests, **rouge avant (6 échecs) → vert après**
  (total, cumul sandwich+frites, quantité ≥ 2, item sans extra, extras scellés, affiché == scellé).
* Balayage catalogue 55 produits : 24 divergences → **0**.
* Suite vitest complète : **2615 passés**. Les 10 échecs restants sont des sentinelles de
  fraîcheur de bundles (`public/js/*.js`, `public/css/app.css` non versionnés donc absents du
  worktree) — **vérifiées vertes sur le checkout principal**, sans rapport avec ce patch.

## Rollback
`git revert` du commit. Les deux blocs sont additifs et isolés ; aucun state, aucune migration.

## Reste à faire à l'intégration (owner)
`npm run development` **depuis le checkout principal** (jamais depuis un worktree : les chemins
node_modules polluent tous les bundles). Sans ce rebuild, la borne continue de servir l'ancien
`app.js` et le correctif reste invisible. Ce rebuild verdit aussi les sentinelles de fraîcheur.

## Sign-off
- [ ] Owner — rebuild bundles + validation borne réelle (ajouter une 2ᵉ sauce frites → +0,50 €)
