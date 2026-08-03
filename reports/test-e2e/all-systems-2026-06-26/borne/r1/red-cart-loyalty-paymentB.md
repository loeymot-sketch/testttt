# BORNE r1 — Lentille ADVERSAIRE-RED — Panier + loyalty + paiement Plan-B

DB: foodking_e2e (live). Méthode: Read fichiers ancrés + SELECT + tinker preview SANS effet de bord (0 ordre réel créé). READ-ONLY respecté.

---

## [P2] app/Services/Order/OrderQuoteService.php:286-302 (+ kioskCart.js:152, KioskPromoService.php:59-87) — Promo code « faux-succès » : validé + déduit du panier, JAMAIS appliqué à la commande

**repro** (tinker live, foodking_e2e, branch 1) :
1. Config live : `pos.manual_discount_enabled=true` ⇒ `discountsEnabled=true` ⇒ le champ code-promo EST visible dans `KioskCartComponent.vue:275` (contredit le commentaire ":441 Defaults to FALSE"). `kiosk_promos` table = **0 ligne**.
2. `KioskPromoService::validate(1, 'ADVKIOSKB1', 30.0)` ⇒ `valid:true, source:'coupon', discount_amount:5.0` (fallback coupon global `coupons.ADVKIOSKB1` type=2 montant=5€). Le cart appelle ce path via `kioskCart.js:571 axios.post('frontend/promo/validate')` ⇒ `SET_PROMO discount=5` ⇒ getter `total` (kioskCart.js:250-253) soustrait 5€ ⇒ la ligne `KioskCartComponent.vue:260 v-if=promoDiscount>0` affiche « -5,00 € » et le total panier baisse.
3. MAIS le quote SSOT (utilisé par `/frontend/order`) ignore le promo :
   `OrderQuoteService::calculatePricing` (kiosk, :288-301) ne lit QUE `coupon_id` (jamais envoyé par la borne — `buildKioskQuotePayload` n'émet que `kiosk_promo_code`) + loyalty. Repro tinker (4× item#1 @2,50, `kiosk_promo_code='ADVKIOSKB1'`) :
   `subtotal=10.00  discount=0.00  TOTAL_TTC=10.00` ; `canonical.promo_code=ADVKIOSKB1` enregistré en metadata mais **discount appliqué = NO**.

**evidence** : sortie tinker ci-dessus (quote discount=0, total=10€ alors que validate annonçait -5€) ; `grep kiosk_promo_code app/Services/FrontendOrderService.php` ⇒ 0 hit (le path order-create ne consomme PAS le promo) ; seul `OrderQuoteService.php:496` le pose en metadata. Aucun test n'assert l'application au moment de l'ordre (`tests/Feature/KioskPhase1/KioskEndpointsTest.php` couvre preview+validate mais pas `/frontend/order`). Le commentaire PromoController.php:18-20 (« consommation réelle à la création via FrontendOrderService ») est aspirationnel — non implémenté.

**lentille** : client. Le client voit « -5,00 € » dans son panier, croit payer 5€ de moins ; à la caisse Plan-B l'écran affiche le total SERVEUR (plein tarif) → litige au comptoir, sentiment d'arnaque. (NB : PAS une fuite fiscale — le Z reste cohérent au plein tarif ; le mal est l'UX trompeuse + dispute comptoir.) Note : `PricingPreviewService::preview` (wizard) NE fait PAS le fallback coupon global (param `kiosk_promo_code` → seulement `KioskPromo::findValid`, table vide) → divergence asymétrique entre les 2 paths promo : `/promo/validate` (cart) accepte les coupons globaux, `preview` (wizard) et `quote`/order ne les honorent pas.

**reco** (NON-frozen — ne touche aucun fichier gelé) : option A (recommandée V1) — neutraliser le promo trompeur : dans `KioskPromoService::validate`, ne PAS faire le fallback `coupons` global tant que l'order-path ne le consomme pas (retourner invalide pour les codes non-`kiosk_promos`), OU masquer le champ promo (`discountsEnabled` doit refléter un flag dédié `kiosk.promo_enabled`, pas `pos.manual_discount_enabled`). Option B — câbler réellement : faire lire `kiosk_promo_code` par `OrderQuoteService::calculatePricing` (kiosk) via `KioskPromoService` et l'appliquer au discount (puis incrémenter `uses_count` à l'ordre). Test à écrire AVANT : `tests/Feature/Kiosk/KioskPromoAppliedAtOrderTest.php` (place un ordre avec `kiosk_promo_code` valide ⇒ assert order.total reflète le discount), + `tests/js` parité cart-total vs quote-total.

---

## [P3] resources/js/languages/fr.json → kiosk.cash_instruction.help — Copy « espèces uniquement » résiduel (Plan-B accepte aussi CB + tickets-resto au comptoir)

**repro** : `kiosk.cash_instruction.help = "Paiement en espèces uniquement à la caisse."` (lu via tinker nested key). Or le Plan-B route TOUS les paiements au comptoir où le caissier encaisse espèces OU carte OU tickets-resto (cf. KioskPaymentComponent.vue:419-420 commentaire « espèces tiroir OR carte ticket+manual terminal »). L'owner avait déjà corrigé ce même message sur l'écran paiement ([project_borne_owner_ux_quality_2026-06-22]) mais le résiduel persiste sur l'écran cash-instruction.

**evidence** : valeur i18n ci-dessus ; KioskPaymentComponent.vue:420 confirme multi-moyens au comptoir. Lentille : client — un client qui n'a pas d'espèces lit « espèces uniquement » et croit ne pas pouvoir payer → abandon inutile alors que la caisse prend la CB.

**lentille** : client (cosmétique copy).

**reco** (NON-frozen) : remplacer par « Réglez votre commande à la caisse (espèces, carte ou tickets-restaurant). » dans `fr.json` (+ parité ar/en via sentinel `studioFrontendI18nParity`). Pas de logique touchée.

---

## REFUTÉ — fausses certitudes vérifiées (VERIFIED-HOLD, non surfacées comme findings)

- **Double-tap « Confirmer » Plan-B** : TIENT. `confirmPayment` (KioskPaymentComponent.vue:431) garde `if (!this.method || this.submitting) return` et `submitting` reste `true` à travers le path cash (processCashPayment ne le remet à false qu'après navigation). `POST /frontend/order` porte le middleware `idempotency` (routes/api.php:1317) et la borne réutilise une clé `X-Idempotency-Key` stable par session-cart (kioskCart.js:710-715, stockée en state, rejouée). ⇒ pas de double commande.
- **total panier LOCAL ≠ backend avant refresh** : NON exploitable pour sous-facturer. `confirmPayment` appelle `refreshQuote()` (re-POST `/frontend/order/quote`) AVANT submit (:443) et passe `quote` à `submitOrder` ; `cartTotal` affiché bascule alors sur `_lastQuote.total_ttc` (serveur, :328) ; l'écran cash-instruction reçoit `total = quote.total_ttc` (:468,498). Le backend reste SSOT (sealForCommit lie le total au quote signé). Le seul écart possible est le promo ci-dessus (P2), pas une forge de total local.
- **forge total_price / omission requise** : hors-scope direct ici, mais le quote passe par PricingService (prix relus DB) + `assertVariationPresenceConstraints` ; les `id` payload sont des hints. Pas de finding neuf de mon angle.

---

## Notes scope
- Vecteurs « abandon / TTL PENDING_COUNTER 180min / offline race / retour-bundle-périmé » relèvent de la lane Résilience (2.d) — non audités ici par discipline de lane.
- Aucun fichier frozen touché ; toutes recos visent NON-frozen (services backend `OrderQuoteService`/`KioskPromoService`, config, `fr.json`, `KioskCartComponent.vue` non-frozen).
