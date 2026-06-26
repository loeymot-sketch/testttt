# BORNE r1 — Lentille SÉCURITÉ / LOGIQUE / SSOT (Sub 2.c : Panier + loyalty + paiement Plan-B)

Cible : `kioskCart.js`, `KioskPromoService::validate`, `PricingPreviewService`, `OrderQuoteService`,
`KioskPaymentComponent.vue`, `KioskCashInstructionComponent.vue`. DB live `foodking_e2e`.

Verdict global : le **cœur argent/fiscal Plan-B est SOLIDE** (quote signé HMAC, token+signature requis au commit,
amount-echo TPE ±1c, counter-route encaissement — 11/11 tests verts). La faille est **le widget promo/coupon de la borne** :
décoratif (affiché puis silencieusement abandonné au devis/commande) ET mal calculé. Le client voit un prix remisé
puis est **facturé plein tarif à la caisse**.

---

[P1] app/Http/Requests/OrderRequest.php:173 + app/Services/Order/OrderQuoteService.php:286-302 — Le promo/coupon borne est affiché remisé au panier mais JAMAIS appliqué au devis signé ni à la commande → client surfacturé vs prix annoncé
  repro:
    DB_DATABASE=foodking_e2e php artisan tinker --execute='
      $i=App\Models\Item::withoutGlobalScopes()->where("status",5)->whereNull("deleted_at")->first();   // Menu 2,50€
      $p=new App\Models\KioskPromo(["branch_id"=>1,"code"=>"BORNEAUDIT","type"=>"amount","value"=>2.00,"min_cart"=>0,"active"=>1]);
      echo "cart shows ".max(0,$i->price-$p->computeDiscount($i->price));   // => 0.5
      $q=app(App\Services\Pricing\PricingService::class)->calculateOrder(App\Services\Pricing\PricingRequest::forKiosk(0,1,[(object)["item_id"=>$i->id,"quantity"=>1,"item_variations"=>[],"item_extras"=>[],"item_addons"=>[]]],0,0,0.0),app(App\Services\CouponService::class));
      echo " ; quote/order total=".$q->total;';   // => 2.5
    Reproductible AUSSI sans seed via le coupon global EXISTANT `ADVKIOSKB1` (-5€) :
      $svc=app(App\Services\Kiosk\KioskPromoService::class); $svc->validate(1,"ADVKIOSKB1",6.0);  // valid=true discount=5 → cart 1€
      …mais quote forKiosk(coupon_id=0) total=6€.
  evidence:
    - tinker (ci-dessus) : cart=0,50€ / quote+order=2,50€ ; et cart=1€ / order=6€ avec ADVKIOSKB1.
    - kioskCart.js:147-158 `buildKioskQuotePayload` envoie `kiosk_promo_code` + `loyalty_code` mais JAMAIS `coupon_id`.
    - OrderRequest.php:173 ne valide QUE `coupon_id` ; `grep -c kiosk_promo_code OrderRequest.php` = 0 → champ stripé de validated() → jamais persisté.
    - OrderQuoteService.php:294 `PricingRequest::forKiosk(..., coupon_id=$request->input("coupon_id",0), ...)` → toujours 0 côté borne ; le promo branch-scoped (kiosk_promos) n'est calculé NULLE PART dans le devis (uniquement PricingPreviewService, affichage wizard).
    - KioskCartComponent.vue:260-262 affiche la ligne `-{{ promoDiscount }}` ; KioskPaymentComponent.vue:328 `cartTotal = _lastQuote.total_ttc ?? total` = total devis NON-remisé.
    - Contre-écran : KioskCashInstructionComponent reçoit `total` = total serveur (2,50€) → contradiction visible dans le MÊME parcours (panier 0,50€ → "Payez à la caisse 2,50€").
  lentille: client (surfacturation vs prix affiché, perte de confiance, litige conso) + SSOT (preview/cart ment vs backend).
  reco (NON-frozen, hors zones gelées) : décider le contrat promo borne.
    Option A (si promo borne doit exister) : porter `kiosk_promo_code` jusqu'au devis — l'appliquer dans `OrderQuoteService::calculatePricing` (surface kiosk) comme un discount serveur recalculé, et le faire honorer par FrontendOrderService. Sceller via le `promo_code` déjà présent dans `canonicalPayload` (OrderQuoteService.php:496).
    Option B (si V1 ne supporte pas le promo borne) : masquer le champ promo borne (gate `discountsEnabled` mais OK pour loyalty) OU forcer `KioskPromoService::validate` à renvoyer invalid, pour ne JAMAIS afficher un discount non-appliqué.
    Test à créer : `tests/Feature/Kiosk/KioskPromoAppliedToOrderTest.php` (promo valide ⇒ order.total = subtotal - discount, et reflète le devis).
  note caveat : kiosk_promos VIDE aujourd'hui dans foodking_e2e, mais `pos.manual_discount_enabled=true` ⇒ champ promo AFFICHÉ, et le coupon global `ADVKIOSKB1` rend le bug atteignable sans rien seeder.

---

[P2] app/Services/Kiosk/KioskPromoService.php:82,98 — Mauvaise sémantique discount_type : les coupons PERCENTAGE sont traités comme montant fixe (calcul de remise faux affiché au client)
  repro:
    DB_DATABASE=foodking_e2e php artisan tinker --execute='
      $s=app(App\Services\Kiosk\KioskPromoService::class);
      $r=$s->validate(1,"WV3PCT1781387121",25.0); echo "kiosk: type={$r["type"]} discount={$r["discount_amount"]}";   // amount / 10.00
      $c=App\Models\Coupon::where("code","WV3PCT1781387121")->first();
      echo " ; SSOT=".app(App\Services\CouponService::class)->calculateDiscountAmount($c,25.0);';                       // 3.75
  evidence:
    - Coupon `WV3PCT1781387121` : discount_type=10 (réel `App\Enums\DiscountType::PERCENTAGE=10`), discount=15 (=15%), max=10.
    - KioskPromoService.php:82 `($coupon->discount_type == 1 ? 'percent' : 'amount')` et :98 `((int)$coupon->discount_type == 1) ? %  : fixe` — la constante projet est 10 (percent) / 5 (fixed), PAS 1.
    - Résultat : 15% (devrait = 3,75€ capé) traité comme 15€ fixe capé à 10€ → la borne affiche **-10,00€** au lieu de **-3,75€** (CouponService SSOT). Tous les coupons PERCENTAGE de la borne sont faux.
  lentille: client (remise affichée erronée) + technique (divergence vs CouponService SSOT). Masqué en pratique par le P1 (rien n'est appliqué), mais à corriger ensemble.
  reco: remplacer les littéraux `== 1` par les constantes `DiscountType::PERCENTAGE` / `DiscountType::FIXED`, ou mieux : déléguer à `CouponService::calculateDiscountAmount($coupon, $cartTotal)` (déjà SSOT) au lieu de réimplémenter dans `computeCouponDiscount`. Couvrir par un test percent + fixed.

---

## VERIFIED-HOLDS (abusés, défenses tiennent — pas de finding)
- **Plan-B counter-route / token / amount-echo** : `PosCollectKioskCashRouteTest` + `KioskQuoteTokenRequiredOnCommitTest` + `KioskPaymentConfirmAmountTest` = 11/11 PASS. Commit sans quote_token/signature → 422/401 ; quote expiré → 410 ; amount echo hors ±1c → 422 AMOUNT_ECHO_MISMATCH (OrderController.php:137-152).
- **Double-tap confirmer** : `confirmPayment()` (KioskPaymentComponent.vue:431) garde `if(!this.method||this.submitting)return` + `submitting` reste true à travers cash/TPE ; bouton counter-route `v-if="!submitting && !submitted"` (l.41) ; clé idempotency générée une fois et réutilisée (kioskCart.js:710-715) + header `X-Idempotency-Key` (l.725) + middleware `idempotency` sur la route (api.php:1318). Double POST ⇒ 1 commande.
- **Loyalty** : correctement appliqué au devis (`OrderQuoteService::withKioskLoyaltyDiscount` l.318) et atomique — `KioskLoyaltyLedgerAtomicTest` + `KioskLoyaltyDoubleRedeemRefusedTest` = 8/8 PASS (rollback points+order, 1 seule entrée ledger, code étranger refusé). Le bug est promo/coupon-only, PAS loyalty.
- **Isolation branche / scope token** : quote kiosk vérifie `tokenCan('kiosk:order')` (OrderQuoteService.php:170), KioskMachine résolu par user_id, branch_id serveur (jamais client) ; `paymentConfirm` rejette cross-branch 403 (OrderController.php:129).
- **Écran "Payez à la caisse"** : KioskCashInstructionComponent clair (titre FR, #commande, montant SSOT serveur, aide, auto-redirect 45s). TTL stale counter-collect 180 min (config kiosk.php). Pas d'ambiguïté "j'ai payé" côté écran cash.

## CAVEAT (non-bloquant, signalé)
- `KioskPromoModelTest` couvre `findValid`/`computeDiscount` en isolation mais AUCUN test n'assure promo→order : c'est le trou qui a laissé passer le P1. À combler par le test d'intégration proposé.
