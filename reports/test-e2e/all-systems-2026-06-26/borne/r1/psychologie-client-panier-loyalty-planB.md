# BORNE r1 — Lentille PSYCHOLOGIE CLIENT — Panier + loyalty + paiement Plan-B

Auteur: agent psychologie-client (dominante). Sous-système 2.c (`kioskCart.js`,
`KioskPromoService`, `KioskPaymentComponent`, `KioskCashInstructionComponent`).
DB: `foodking_e2e` (READ-ONLY). Serveur :8766. Tinker preview SANS effet de bord.

Parcours joué: idle → catégorie → wizard → panier (code promo + loyalty) →
Plan-B « payer à la caisse » → cash-instruction → retour idle.

---

## [P1] app/Services/Kiosk/KioskPromoService.php:76 + resources/js/store/modules/kioskCart.js:562 — Code promo affiché « −X € » au panier mais JAMAIS appliqué au total payé (le client paie plus que ce que le panier promet)

**repro (prouvé via tinker, foodking_e2e, branch 1, panier 3× Glace + 3× Tarte = 21 € sous-total) :**
```
1) (new KioskPromoService)->validate(1,'WVALFIX5',21.0)
   => {valid:true, source:'coupon', type:'amount', discount_amount:5}   // le panier affiche "−5,00 €", total local 21−5 = 16 €
2) OrderQuoteService::quote(payload kiosk RÉEL = {kiosk_promo_code:'WVALFIX5', items, source:5}, 'kiosk')
   => totals.discount = 0 ; total_ttc = 21   // l'écran paiement cartTotal = _lastQuote.total_ttc = 21 €
3) Même quote AVEC coupon_id=9 (que la borne n'envoie JAMAIS)
   => totals.discount = 5 ; total_ttc = 16   // prouve que le rabais EXISTE mais n'est pas câblé côté borne
```
**evidence :**
- `kioskCart.js:142-158` `buildKioskQuotePayload` envoie `kiosk_promo_code` mais
  **jamais** `coupon_id` ni `coupon_code`. (`grep coupon_id kioskCart.js` = vide.)
- `OrderQuoteService.php:288-301` `calculatePricing` (surface kiosk) lit
  UNIQUEMENT `(int)$request->input('coupon_id',0)` → le code promo tapé est ignoré.
- `OrderQuoteService.php:496` `kiosk_promo_code` n'entre que dans le
  `canonical_payload.discounts.promo_code` (métadonnée de signature), pas dans le calcul.
- `grep kiosk_promo_code app/Services/Pricing app/Services/Order app/Services/FrontendOrderService.php`
  → AUCUNE application monétaire hors `PricingPreviewService` (preview décoratif).
- `kiosk_promos` table = **0 ligne** (`SELECT COUNT(*) FROM kiosk_promos` = 0) →
  donc 100 % des codes « valides » au panier viennent du fallback `coupons` global,
  qui n'est jamais appliqué à la commande borne.
- Promo UI réellement affichée : `config('pos.manual_discount_enabled')` = **true**
  au runtime (`master.blade.php:174` → `foodkingConfig.discountsEnabled` → `KioskCartComponent.vue:444`).
- Test `kioskCartPromo.spec.js` ne valide QUE la math d'état locale (getter
  `total = subtotal − loyalty − promo`), jamais le round-trip vs quote autoritatif → trou non couvert.

**lentille : client.** Le client tape son code, voit « Promo WVALFIX5 −5,00 € » et
un total panier réduit, se croit gagnant, puis à la caisse on lui réclame le plein
tarif. Frustration / sentiment d'arnaque / litige au comptoir. Pire que pas de promo
du tout : on a créé une attente fausse. NF525 non touché (le Z reste cohérent — c'est
une SOUS-promesse vs devis, pas une sur/sous-facturation fiscale), donc P1 (argent/confiance), pas P0.

**reco (NON-frozen) :** deux options scope-minimal —
(A) câbler la borne : faire que le panier convertisse un code coupon validé en
`coupon_id` envoyé dans le payload quote+order (path déjà correct côté backend, cf. étape 3) ;
OU (B) si la promo borne n'est pas un objectif V1, masquer l'input promo au kiosk
(forcer `discountsEnabled=false` côté kiosk) pour ne RIEN promettre. Décision business
(garder/retirer la promo borne) → à confirmer owner. Aucune frozen-zone.

---

## [P2] app/Services/Kiosk/KioskPromoService.php:82,98 — Mauvaise interprétation `discount_type` (un coupon % affiché 3× trop gros au panier)

**repro (tinker, coupon `WV3PCT1781387121` : discount_type=10=PERCENTAGE, discount=15 ⇒ 15 %, maximum_discount=10, panier 21 €) :**
```
(new KioskPromoService)->validate(1,'WV3PCT1781387121',21.0)
   => discount_amount: 10    // traité comme 15 € flat puis plafonné à 10 €
(new CouponService)->calculateDiscountAmount($coupon, 21.0)   // interprétation canonique
   => 3.15                   // correct = 15 % de 21 € = 3,15 €
```
**evidence :** `KioskPromoService.php:82` `discount_type == 1 ? 'percent' : 'amount'`
et `:98` `((int)$coupon->discount_type == 1) ? percent : amount`. Mais l'enum projet
`app/Enums/DiscountType.php` = `FIXED=5, PERCENTAGE=10` (jamais 1), et le service
canonique `CouponService.php:399` utilise `== DiscountType::PERCENTAGE` (==10). Donc
`KioskPromoService` traite TOUT coupon (type 5, 10, 2) comme un montant fixe.
Dans la DB live, `coupons` contient des type=10 (percent) et type=2.

**lentille : client.** Couplé au P1, l'affichage panier d'un coupon % est non
seulement non-appliqué mais 3× surévalué — illusion encore plus forte. (Si le P1 est
résolu par câblage `coupon_id`, le calcul autoritatif `CouponService` corrige seul ce
P2 ; ce finding reste vrai pour l'aperçu `validate()` lui-même.)

**reco (NON-frozen) :** remplacer `== 1` par `== DiscountType::PERCENTAGE` aux deux
sites (`validate` ligne 82 et `computeCouponDiscount` ligne 98), ou mieux : faire que
`KioskPromoService::computeCouponDiscount` délègue à `CouponService::calculateDiscountAmount`
(SSOT unique). Test TDD : `validate()` d'un coupon type=10 doit rendre un % du panier.

---

## [P2] resources/js/languages/fr.json (kiosk.cash_instruction.help) — « Paiement en espèces uniquement » contredit le Plan-B réel (CB + tickets-resto acceptés au comptoir)

**repro :** clic borne → panier → « Confirmer ma commande » (counter-route) → écran
cash-instruction. Texte d'aide affiché : « Paiement en espèces uniquement à la caisse. »
**evidence :**
- `KioskCashInstructionComponent.vue:36` rend `$t('kiosk.cash_instruction.help')`.
- `fr.json` : `kiosk.cash_instruction.help` = « Paiement en espèces **uniquement** à la caisse. »
- Or le Plan-B route TOUS les modes au comptoir (espèces tiroir **OU** carte ticket +
  terminal manuel **OU** tickets-resto) — cf. `KioskPaymentComponent.vue:418-420`
  commentaire « espèces tiroir OR carte ticket+manual terminal » + note BRAIN/MEMORY
  2026-06-22 (« message paiement 'espèces uniquement' → cash+CB+tickets-resto »).
- L'ordre part en `payment_status=15 PENDING_COUNTER` (vérifié DB : orders source=5
  récents = status 4, payment_status 15, fiscal_sequence_no NULL) — l'encaissement réel
  (et le mode) se décide AU comptoir, pas figé « espèces ».

**lentille : client.** Un client qui n'a que sa CB ou des tickets-resto lit « espèces
uniquement » et croit qu'il ne pourra pas payer → il abandonne la borne ou panique. Le
même défaut « espèces uniquement » avait déjà été noté côté owner (2026-06-22) ; il
persiste dans `cash_instruction.help`. (À noter : ce libellé n'est atteint que si le
client choisit Espèces hors counter-route ; en counter-route pur, le sous-titre est
« Présentez votre numéro à un membre de l'équipe » — neutre et correct. Mais
`processCashPayment` route bien vers cet écran cash-instruction avec ce help.)

**reco (NON-frozen) :** reformuler `kiosk.cash_instruction.help` en neutre, p.ex.
« Réglez votre commande à la caisse (espèces, carte ou titres-restaurant). » Aligner
aussi `kiosk.pay_screen.cash_sub` si nécessaire. i18n only, FR.

---

## VERIFIED-HOLDS (vecteurs abusés, défense solide — pas de finding)

- **Double-tap « Confirmer ma commande » (Plan-B) → 1 seule commande.**
  `KioskPaymentComponent.vue:421-431` `confirmCounterRoute`/`confirmPayment`
  early-return si `this.submitting`, passent `submitting=true` synchrone ; le bouton
  est `v-if="!submitting && !submitted"` (retiré pendant l'envoi) ;
  `kioskCart.js:710-714` réutilise un `idempotencyKey` STABLE en state Vuex, envoyé en
  `X-Idempotency-Key` (`:725`), adossé à `IdempotencyKeyMiddleware` + DB UNIQUE.
  Couvert par `kioskCounterPaymentFlow.spec.js`. Robuste.

- **Total local panier ≠ backend AVANT refresh = neutralisé au paiement.**
  L'écran paiement lit `cartTotal = _lastQuote?.total_ttc ?? total`
  (`KioskPaymentComponent.vue:328`) ; `confirmPayment` appelle `refreshQuote()` AVANT
  `submitOrder` (`:443`) → le montant facturé est toujours le `total_ttc` autoritatif
  recalculé serveur (SSOT), jamais le sous-total local. Le total local n'est qu'un
  affichage panier. (Le P1 ci-dessus est l'exception : il vient de ce que le rabais
  promo n'entre pas dans CE total_ttc.)

- **Plan-B = pas de fuite fiscale / pas de faux « payé ».**
  Order kiosk Plan-B → `payment_status=15 PENDING_COUNTER`, `fiscal_sequence_no=NULL`
  (alloc à l'encaissement comptoir, conforme NF525 cash-trail). Le client n'est jamais
  marqué PAID à la borne. TTL anti-orphelin = `KIOSK_STALE_COLLECT_TTL_MINUTES=180`
  (`config/kiosk.php:68`) + job `CleanupStalePendingKioskOrders` PLANIFIÉ
  (`app/Console/Kernel.php:105`) ne purgeant que les PENDING sans séquence fiscale.

- **Promo : aucune écriture DB à la validation.** `KioskPromoService::validate` est
  lecture-seule (pas d'`increment uses_count`) ; la consommation réelle n'arrive qu'à
  `POST /frontend/order`. Pas d'abus de double-validation.

---

## NOTE (à vérifier — sous-claim non-prouvé, autre ancre)

- **Loyalty au quote :** `buildKioskQuotePayload` (`kioskCart.js:142-158`) envoie
  `loyalty_code` mais PAS `discount` ; or `OrderQuoteService::withKioskLoyaltyDiscount`
  (`:318-323`) exige `request->input('discount') > 0` pour appliquer le rabais loyalty.
  Le payload ORDER (`buildKioskOrderPayload:174`) ré-injecte `discount = quote.discount`,
  mais le 1er quote pourrait ne pas refléter le loyalty. Le flux loyalty a toutefois un
  pré-redeem ledger propre (`KioskLoyaltyLedgerAtomicTest`) → NON prouvé comme bug ici,
  ancre T-2.c.2 dédiée. Signalé pour l'agent loyalty, pas chiffré.
