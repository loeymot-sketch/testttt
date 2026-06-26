# BORNE r1 — Refutation: promo borne « affiché remisé mais facturé plein »

VERDICT: **REFUTED** (la nuisance affirmée n'existe pas dans le câblage réel V1-LOCAL).

## Finding sous test
[P1] OrderRequest.php:173 (+ OrderQuoteService.php:286-302, kioskCart.js:147-158) —
« Promo/coupon borne affiché remisé au panier mais JAMAIS appliqué au devis signé
ni à la commande → client surfacturé vs prix annoncé. »

## Ce qui est VRAI (vérifié)
- `kiosk_promo_code` n'est PAS validé/persisté comme financier dans OrderRequest
  (`grep -c kiosk_promo_code OrderRequest.php = 0`). Le devis (`OrderQuoteService.php:289-302`)
  et la création (`FrontendOrderService.php:278-283`) calculent avec `coupon_id`
  (toujours 0 côté borne). `kiosk_promo_code` n'entre dans le payload signé que comme
  métadonnée (`OrderQuoteService.php:496` → `discounts.promo_code`), et `total_ttc`
  (l.516) provient de `$pricing->total` calculé SANS la promo. Repro tinker brute :
  item#22 7,40€ → `computeDiscount` 2€ → 5,40€, mais `calculateOrder(forKiosk, coupon_id=0)` = 7,40€.

## Pourquoi la nuisance affirmée N'ARRIVE PAS (réfutation décisive)
Le panier borne n'affiche JAMAIS la remise comme appliquée → aucun écart vs prix annoncé.
Cause = **mismatch de clé front/back** :
- `KioskPromoService::validate()` ne retourne QUE `discount_amount`
  (`KioskPromoService.php:54, 84, 118`) — jamais `discount`.
- `PromoController::check` renvoie `$result` brut sous `data` (aucun remap ; `grep discount` = 0 hit).
- Le front lit `data.discount` (`kioskCart.js:580`: `parseFloat(data.discount || 0) || 0`) →
  `undefined` → **`state.promoDiscount = 0`**.
- Aucun code front kiosk ne lit `discount_amount` (`grep -rn discount_amount resources/js/` → 0 dans le path kiosk).
- Donc : `KioskCartComponent.vue:260` `v-if="promoDiscount > 0"` est FAUX → ligne remise non rendue ;
  getter `total` (`kioskCart.js:252`) soustrait 0 → total panier = plein prix = prix serveur.
  L'ordre = même plein prix → AUCUNE surfacturation vs prix affiché.

## Evidence live (foodking_e2e, READ-ONLY)
- `KioskPromo::count() = 0` → la promo BORNEAUDIT du finding est une fiction non-persistée
  (le repro appelle `computeDiscount` sur un `new KioskPromo` jamais sauvé).
- 5 coupons actifs (status=5), dont **ADVKIOSKB1** (amount 5€) cité par le finding.
- Chemin live complet :
  `KioskPromoService::validate(branch:1, "ADVKIOSKB1", cart:6.0)` →
  `{"valid":true,"source":"coupon","type":"amount","value":5,"discount_amount":5}`.
  MAIS le front lit `data.discount` (absent) → `promoDiscount = 0` →
  `item price=7,40 | client voit cart=7,40 | order quote=7,40`. Cohérent, 0 écart.

## Lentille
Le repro du finding court-circuite 2 couches (service + front) en appelant le modèle
directement ; le « cart » est un nombre calculé à la main, PAS ce que l'UI borne affiche.
verify-before-report : reproduire le PARCOURS (validate → SET_PROMO → getter total), pas
le calcul modèle isolé.

## Vérité résiduelle (≠ le finding, à enregistrer séparément)
Issue réelle mais INVERSE et mineure : un code promo saisi borne renvoie `valid=true`
serveur mais ne produit AUCUNE remise visible ni appliquée (no-op silencieux dû au
mismatch `discount_amount`/`discount`). C'est une **feature promo morte (P2/P3 UX)**,
PAS une surfacturation / fuite / NF525. Elle PROTÈGE même la justesse fiscale (ordre signé
au plein prix SSOT). Ne JAMAIS surfacer comme P1 « client surfacturé ».

## Reco
Aucun heal au titre de ce finding (la nuisance affirmée est réfutée). Si l'owner veut la
feature promo borne fonctionnelle, c'est un chantier séparé (porter la remise serveur jusqu'au
devis + honorer côté FrontendOrderService), pas un correctif d'urgence — et il faut d'abord
décider si V1-LOCAL supporte le promo borne (sinon masquer le champ).
