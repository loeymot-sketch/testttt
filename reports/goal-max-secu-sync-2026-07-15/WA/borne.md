# Audit BORNE (kiosk) — sécurité + pricing + Plan B

Date : 2026-07-15 · Périmètre : V1 LOCAL Le Cayenne (mono-poste, FR, branch_id=1)
Ancres auditées : `app/Services/Kiosk/*`, `Auth/KioskMachineLoginController.php`,
`Frontend/OrderController.php`, `KioskEventController.php`, `OrderRequest.php`,
`OrderQuoteService.php`, `routes/api.php`, `config/kiosk.php`.

## Ce qui est SOLIDE (vérifié, RAS)
- **Token Sanctum** : ability `['kiosk:order']` (PAS `*`), TTL 480 min, anciens jetons
  révoqués au relogin (`KioskMachineLoginController.php:102-108`). Pas d'ability large.
- **Pricing SSOT** : `total/subtotal/discount` client sont `unset` avant create
  (`FrontendOrderService.php:271`) ; recalcul serveur via `PricingService`
  (`:301-317`) ; `PricingPreviewRequest` whitelist stricte (aucun champ prix accepté).
- **Quote binding** : `intent_hash` HMAC sur les items ; `resolveReplay` rejette
  tout swap d'items (401 intent mismatch) ; `sealForCommit` exige quote.total ==
  order.total (409 sinon) — pas de sous-facturation possible via jeton de quote.
- **Rate-limits** : `kiosk-quote` (120/min) et `kiosk-orders` (5/min) sont des
  buckets DISJOINTS — pas de vidage du budget commande par les quotes.
- **IDOR** : `escpos` + `paymentConfirm` gardent `user_id == token` ET branche
  (`OrderController.php:94-101, 158-166`) ; `changeStatus` garde la propriété
  (`FrontendOrderService.php:754`, sinon 403).
- **branch_id** toujours résolu serveur depuis `KioskMachine` (jamais le payload).

## Findings (3, tous P2, non-frozen — aucune édition de zone gelée requise)

### [P2] #1 — Plan B « tout au comptoir » NON appliqué serveur → carte différée auto-confirmable (commande PAYÉE sans encaissement)
`config('kiosk.payment_route_all_to_counter')` (défaut true) n'est **appliqué que
côté frontend** (KioskPaymentComponent auto-submit cash). Serveur : **0 enforcement**
— la seule occurrence est un commentaire (`app/Domain/Kds/KitchenReleaseRule.php:88`).
Conséquence : un porteur de jeton `kiosk:order` peut, en contournant le wizard
(gelé), créer une commande `payment_method=CARD` (accepté `FrontendOrderService.php:199-203`)
puis **auto-confirmer** le paiement (`OrderController.php:250` passe `payment_status=PAID`
sur `transaction_id`+`amount_cents` FOURNIS PAR LE CLIENT — `PaymentConfirmRequest`
n'exige aucune preuve côté fournisseur ; `BypassAuditLogger::paymentBypassed` l.134
acte le bypass). La commande apparaît **PAYÉE** au comptoir/KDS → plat remis sans
piste de caisse, alors que Plan B existe précisément pour forcer le cash-comptoir.
- Repro : jeton kiosk → `POST /frontend/order/quote` → `POST /frontend/order`
  (order_type=10, payment_method=2, quote_token+signature) → `POST
  /frontend/order/{id}/payment-confirm` `{transaction_id:"FAKE-1",
  amount_cents:<total*100>, card_type:"visa"}` → 200, PAID, dispatché.
- Fix : quand `payment_route_all_to_counter=true`, rejeter tout `payment_method`
  non-cash à la création borne (OrderRequest/FrontendOrderService) ET refuser
  `paymentConfirm` pour les commandes borne — rendre l'invariant Plan B autoritatif.

### [P2] #2 — Identifiants borne faibles committés (kiosk-lecayenne/kiosk123) + apiKey non-secret → jeton kiosk:order obtenable
`.env` porte `KIOSK_MACHINE_PASSWORD=kiosk123` (défaut aussi dans
`config/kiosk.php:213` et `EnsureKioskMachineCommand.php:25`), username
`kiosk-lecayenne`. Le « garde » `apiKey` n'en est pas un : la clé est injectée dans
CHAQUE page (`resources/views/master.blade.php:149`, `config/app.php:63`), donc
publique. `kiosk-login` (30/min) n'arrête pas un login à mot de passe connu (1 requête).
Un acteur atteignant le serveur peut donc frapper un jeton `kiosk:order` (TTL 480 min,
`KioskMachineLoginController.php:104-108`) et créer des commandes borne
(spam KDS/comptoir, décrément stock → fausses ruptures ; en Plan B cash la cuisine
prépare des plats non payés). C'est le maillon qui rend #1 exploitable.
- Repro : `curl -X POST /api/frontend/auth/kiosk-login -H "x-api-key:<page>"
  -d '{"username":"kiosk-lecayenne","password":"kiosk123"}'` → 201 + token.
- Fix : mot de passe fort/unique par déploiement (rotation hors kiosk123) ;
  garantir `APP_ENV=production` en prod (le bypass auto-login local est actif si
  `APP_ENV=local`) ; ne pas livrer de défaut devinable.
- Sévérité : borné en V1 LOCAL LAN ; **escalade P1 si la box est joignable
  hors-LAN** (wireup web / borne distante mentionnés en mémoire).

### [P2] #3 — Remise kiosk_promo/fidélité AFFICHÉE en aperçu mais JAMAIS facturée (affiché < facturé si KIOSK_PROMO_ENABLED=true)
`PricingPreviewService.php:82-106` applique `KioskPromo::computeDiscount` et renvoie
un `total` remisé. Mais `kiosk_promo_code` n'est **jamais lu** au moment de la
commande : `FrontendOrderService.php:301-317` ne passe que `coupon_id` à
`PricingRequest::forKiosk` (grep : `kiosk_promo_code` n'apparaît QUE dans le preview
et la signature canonique `OrderQuoteService.php:523`, jamais dans le pricing).
Le client voit « -X € », est débité **plein tarif** (intégrité prix affiché/facturé,
enjeu conso/NF525). Le dev le documente lui-même (`KioskCartComponent.vue:462`
« the backend never applies … customer charged full price ») ; le docstring
`PromoController.php:19-20` prétend faussement une consommation à la commande.
Atténué UNIQUEMENT par `kiosk.promo_enabled` défaut false (`config/kiosk.php:70`) —
c'est un masquage UI, pas un correctif : réactiver le flag documenté rouvre le
mensonge de prix. (La voie fidélité, elle, est HARD-REJECT à la commande via
`assertDiscretionaryDiscountAllowed` `:857` alors que le quote l'applique — même
incohérence à 3 étages preview/quote/order.)
- Repro : `KIOSK_PROMO_ENABLED=true` → `POST /frontend/pricing/preview`
  (kiosk_promo_code valide) → `data.total` remisé ; `POST /frontend/order` mêmes
  items+code → `order.total` = sous-total plein.
- Fix : soit câbler `kiosk_promo_code` dans le pricing de commande (bloqué tant que
  le verrou fiscal F1/`manual_discount_enabled` rejette les remises) ; soit RETIRER
  la remise du preview (`PricingPreviewService`/`KioskPromoService`) pour que
  affiché==facturé, et corriger le docstring `PromoController`.

## Hors-scope confirmé (déjà escaladé/exclu)
TPE simulé (choix assumé), fidélité redeem order_id=NULL (escaladé), RBAC
online-orders POS Operator, orphelins fiscaux pré-C33. Wizard borne = FROZEN
(aucun bug propre détecté dedans ; les 3 findings sont hors zone gelée).
