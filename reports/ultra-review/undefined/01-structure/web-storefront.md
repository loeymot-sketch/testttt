# W1 / 01-structure — Cartographie WEB STOREFRONT (vitrine client servie par ce repo)

Date : 2026-07-02 — Lecteur read-only, vague « compréhension max ».
Branche : `pos/category-first-caisse-2026-06-23`. Tout file:line ci-dessous a été lu dans cette session.

## 1. Verdict d'état : vitrine SPA DÉSACTIVÉE, API client VIVANTE

La vitrine client Vue (« marketplace livraison » historique FoodKing) est **désactivée à 3 étages**,
mais **l'API `/api/frontend/*` reste pleinement fonctionnelle** (elle sert la borne kiosk ET le
site web standalone câblé en 2026-06-26 — hors scope, pointeur : `/Users/1millnonstop/Downloads/web/`).

1. **Flag serveur** : `config/features.php:52` — `staff_only_mode` = `env('STAFF_ONLY_MODE', true)`,
   **défaut TRUE fail-secure** (commentaire ST-W2-ENV-1-LEGACY : lu depuis config-file pour survivre
   à `config:cache`). Exposé au JS via `resources/views/master.blade.php:222`
   (`staffOnlyMode: @json((bool) config('features.staff_only_mode'))`).
2. **Garde Vue Router** : `resources/js/router/index.js:241-243` — dans `beforeEach`, si
   `isStaffOnly()` et `to.meta.isFrontend === true` et route hors allowlist → redirect /login ou
   `/admin/dashboard`. Allowlist `STAFF_ONLY_FRONTEND_ALLOWLIST` (index.js:61-69) = auth.login,
   auth.signup, auth.forgetPassword, auth.resetPassword, auth.guest, route.notFound, route.exception.
   Racine `/` (index.js:112-117) : staff-only → landing admin ou login ; sinon `frontend.home`.
   Kiosk exempté (`isKioskRoute`, index.js:241).
3. **Suppression physique des pages vitrine** : `resources/js/router/modules/frontendRoutes.js:1-8`
   ([STOREFRONT-DELETE 2026-06-25]) — HomeComponent/MenuComponent/OffersComponent/OffersItemComponent
   **SUPPRIMÉS du repo** (vérifié : absents de `resources/js/components/frontend/` ; les dossiers
   home/, menu/, offers/ n'existent plus — seuls restent account, auth, checkout, components, kiosk,
   otherPage, page, search). Leurs routes = redirections nommées vers /login
   (frontendRoutes.js:22-25) pour garder valides les ~15 références `frontend.home` etc.
4. **Chrome storefront masqué** : `resources/js/components/DefaultComponent.vue:5-10` —
   FrontendCart/MobileNavBar/MobileAccount/Cookies/Footer rendus seulement `v-if="!staffOnlyMode"`
   (computed lisant `window.foodkingConfig.staffOnlyMode`, DefaultComponent.vue:83-84). 401 → retour
   `auth.login` si staff-only (DefaultComponent.vue:109-110).

**Composants morts-mais-présents** (routés `meta.isFrontend:true`, donc bloqués par la garde, code
conservé) : `frontendRoutes.js:26-107` → /page/:slug, /edit-profile, /my-orders, /my-orders/:id,
/chat, /address, /change-password, **/checkout** (CheckoutComponent, 999 lignes), /search.
`offers_enabled` (features.php:29) = false par défaut (offre affichée ≠ prix facturé, F1).

**NOTE périmètre** : `router/modules/customerRoutes.js` n'est PAS la vitrine client — c'est le CRM
admin `/admin/customers` (customerRoutes.js:11-56, `isFrontend:false`, permission `customers`).

## 2. Arborescence frontend (vue, hors kiosk/**)

| Dossier / fichier | Rôle | État V1 |
|---|---|---|
| `frontend/auth/` (9 SFC : Login, Signup×3, Guest×2, Forget/Reset, VerifyEmail) | Auth SPA (routes `authRoutes.js:1-9`, allowlistées) | **VIVANT** (porte d'entrée staff) |
| `frontend/account/` (address, changePassword, chat, editProfile, myOrder) | Compte client | Mort (garde staff-only) |
| `frontend/checkout/` (Checkout, Address, Coupon) | Tunnel commande web | Mort côté SPA ; logique API équivalente vivante via borne/web standalone |
| `frontend/components/` (Category, Item, Loading×2, Map, Offer, OrderStatus, TemplateManager) | Briques partagées | TemplateManager ne rend plus que ContactUs (TemplateManagerComponent.vue:1-5) |
| `frontend/page/`, `frontend/search/`, `frontend/otherPage/` | CMS pages, recherche, 404/exception | /page et /search routés ; NotFound/Exception vivants (baseRoutes index.js:138-149) |
| `layouts/frontend/` (6 SFC NavBar/Footer/Cart/Cookies/Mobile×2) | Chrome vitrine | Masqué par `!staffOnlyMode` (DefaultComponent.vue:5-10) |
| stores : `store/modules/frontend/frontendOrder.js`, `frontend/GuestSignup.js`, `auth.js:161` | Couche API Vuex | Vivante (utilisée par composants morts + tests) |

## 3. Routes API customer `prefix('frontend')` — routes/api.php:1267

Groupe middleware `['installed','apiKey','localization']` (api.php:1267). Sous-groupes :
- **Publics** : setting, page, subscriber (throttle 5/1, :1280), branch, oss-order public
  (throttle `oss-public`, :1311-1316), language, offer, item, item-category, time-slot, coupon
  (`/coupon-checking` throttle 10/1 anti brute-force, :1394), slider, country-code, cookies,
  loyalty/register (throttle 5/1, :1434), loyalty/opt-in, csp-report (hors groupe, :1528).
- **auth:sanctum** : address (CRUD, :1284-1291), **order** (:1334-1348 — index, show,
  `show/{id}/escpos` [TICKET-UNIFY 2026-07-01], quote → `PosController::quote`, POST store
  `throttle:kiosk-orders`+`idempotency`, change-status idempotency, payment-confirm idempotency),
  payment/reconcile-pending (:1353-1355), message, device-token, delivery-boy-order (:1418-1426),
  loyalty auth (add-points, redeem idempotency, balance, history, qr signé :1438-1457).
- **Kiosk Design V1** (partagés borne, token kiosk) : /menu, /pricing/preview, /promo/validate,
  /upsell, /loyalty/scan, /kiosk/event + /kiosk-event (`abilities:kiosk:order`, :1461-1509).
- **OTP guest** (hors prefix frontend, groupe auth) : `POST /auth/guest-signup/otp` (5/min) +
  `/verify` (3 par 5 min — 4 digits/10 000 combinaisons documenté), api.php:198-206.

## 4. Flux critiques (chaînes réelles)

### F1 — Commande WEB/guest (chemin de création PARTAGÉ kiosk/web)
`store/modules/frontend/frontendOrder.js:54-88` (ou `kioskCart.js:724` pour la borne — MÊME endpoint)
→ `POST /api/frontend/order` (api.php:1341, throttle kiosk-orders + idempotency middleware)
→ `Frontend\OrderController::store` (OrderController.php:47-71)
→ `FrontendOrderService::myOrderStore` (FrontendOrderService.php:132-702) :
- **Résolution branch du lock idempotency** : KioskMachine.branch_id → user.branch_id → fallback
  WEB-WIREUP `request branch_id` vérifié en DB raw (:145-158) ; sinon HttpException 422 (:160-165).
- **Idempotency dual-layer** : `Cache::lock('frontend_order_idempotency_'.sha1(branch|key))` (:170-174)
  + recovery lecture (branch_id, key, user) (:176-183) + catch SQLSTATE 23000 UNIQUE (:683-688).
- **DB::transaction** : kiosk machine force branch/order_type (:204-212) ; distinction
  `isCounterDeferredKioskCash` → `payment_status=PENDING_COUNTER` + `pos_payment_method=COUNTER_DEFERRED`
  vs UNPAID (:290-291) ; **unset total/subtotal/discount client** (:271) ; **delivery_charge=0 forcé
  si non-DELIVERY** (anti phantom charge, :280-282).
- **Pricing SSOT** : `PricingService::calculateOrder(PricingRequest::forKiosk(...))` (:301-312, flag
  `pricing.use_ssot_service` défaut true ; chemin legacy :321-484 avec gardes cross-item injection
  :397-402/:423-428 et composition_snapshot NF525 :451-466).
- **Gate remise fiscale F1** : `assertDiscretionaryDiscountAllowed` (:526, def :851 — remise>0
  refusée tant que `pos.manual_discount_enabled !== true`).
- **Livraison offerte ≥30€** : Settings `delivery.free_delivery_above` appliqué sur subtotal SSOT
  serveur (:538-543) ; total = subtotal(+tax si non-TTC)+delivery−discount (:544-549).
- **IDOR adresse** : Address WHERE user_id=Auth (:598-608) → OrderAddressOwnershipException 403,
  rollback atomique.
- **source_surface** : `'kiosk'` si KioskMachine liée au token, sinon `'web'` (:573-575).
- **Dispatch gate** (truth table :228-250) : web/mobile → OrderCreated immédiat ; kiosk cash →
  auto-ACCEPT (:629-633) + transition OrderStateMachine (:650-660) ; kiosk carte/TR → différé à
  `finalizePaidKioskOrder` (:1160, alloc fiscal_sequence_no kiosk via flag
  `fiscal.kiosk_auto_allocate_sequence`).

### F2 — Guards KIOSK vs WEB (OrderRequest, MODIFIÉ NON-COMMITÉ)
`app/Http/Requests/OrderRequest.php` :
- `authorize()` (:37-84) : user requis ; token → `tokenCan('kiosk:order')` (:83) ; **fallback sans
  token** restreint à guard-authenticated ET route `frontend.order.*` (:77-80, Sprint H1 K-002).
- `isKioskOrderToken()` (:394-397) = ability kiosk:order **ET KioskMachine enregistrée**
  (`kioskMachineForToken` memoisé, :358-381). Token guest web (kiosk:order sans machine) = commande
  WEB : branch_id du payload, quote_token/signature OPTIONNELS (:175-180) — doc :383-393.
- **Diff non-commité (vérifié `git diff`)** : ajout du guard `order_type=KIOSK(25) && !isKioskToken
  → reject` (:205-208, restaure `KioskSecurityTest::test_kiosk_order_rejects_token_without_registered_machine`).
  Le reste du fichier est identique au HEAD.
- Autres gardes : machine inactive rejetée (:216-219) ; dine-in désactivé V1 kiosk (:232-243, FR) ;
  DELIVERY → phone valide requis (:322-342) + minimum de commande par branche (:288-312 — **le floor
  check lit le subtotal CLIENT**, commentaire l'assume :283-286) ; variations/addon-roles (:271-275).

### F3 — Devis livraison (fee serveur)
`OrderRequest::prepareForValidation` (:97-129) : DELIVERY + address_id + branch_id →
`DeliveryQuoteService::quoteForSavedAddress` (DeliveryQuoteService.php:16-29 : adresse possédée par
user sinon GeocodeUnavailableException) → `quoteForAddress` (:31-72 : geocode_status=OK requis,
coordonnées bornées :74-91, Haversine :93-103) → `DeliveryFeeService::fromDistanceKm(d, $branch)`
(DeliveryQuoteService.php:70) → merge server-side de distance_km + delivery_charge (OrderRequest:113-116).
Fallback legacy sans address_id : fee depuis distance **client** via DeliveryFeeService (:117-129).
`DeliveryFeeService.php:26-56` : si base/per_km/minimum non-NULL → règle owner « 4€ ≤ free_km,
+1€/km ENTAMÉ au-delà » (`ceil(max(0, d−free_km))`, :44-46), `round(max(minimum, fee),2)` (:50) ;
sinon legacy `max(5, ceil(d/5)*5)` (:55). **Diff non-commité = COMMENTAIRE UNIQUEMENT** (note
« base 5€→4€ on 2026-06-27 » ; la valeur vit en DB colonnes branch, pas dans le code).
Livraison offerte ≥30€ appliquée ensuite dans FrontendOrderService:538-543.

### F4 — Auth guest OTP → token scopé
`frontend/auth/GuestVerifyComponent.vue` → store `auth.js:161` (`auth/guest-signup/verify`)
→ `GuestSignupController::verify` (GuestSignupController.php:60-87 : si `site_phone_verification`
DISABLE, OTP supprimé et register direct sans check du code — comportement config) → `register()`
(:89+) : refuse guest_login DISABLE ; lookup phone `withoutGlobalScopes()->withTrashed()` (GAP-32-3) ;
**refuse token guest sur compte staff/admin non-guest** (SECURITY, is_guest != YES) ; restaure les
soft-deleted ; crée Guest User branch_id=0 rôle CUSTOMER ; **token `['kiosk:order']` TTL 30 jours**
(:146, Sprint H1 Z6-02 — pas de wildcard).

### F5 — Suivi / annulation client
`frontend/account/myOrder/MyOrderComponent.vue` → store frontendOrder.js:54-70 →
`GET /frontend/order` → `FrontendOrderService::myOrder` (:86-127 : scope `user_id=auth` :100,
colonnes de tri whitelistées :93-97, exclut POS :99). Annulation : frontendOrder.js:113 →
`POST /frontend/order/change-status/{id}` (idempotency) → `changeStatus` (:744-839) :
`ValidStatusTransition` (:747), **owner-only** (:750, sinon abort 403 :832), cible CANCELED
uniquement (:757), seuil PREPARING (kiosk/takeaway) vs ACCEPT (:764-773), cashBack si transaction
(:775-781), refund points (:782), `OrderStateMachine::recordTransition` + reason persistée
(:788-807), OrderStatusChanged + OrderCanceled (stock release) après commit (:812-828).

### F6 — Ticket ESC/POS borne (ownership)
`GET /frontend/order/show/{id}/escpos` (api.php:1339) → `OrderController::escpos` : 401 sans user,
**403 sans KioskMachine liée au user**, 403 si order.user_id ≠ user OU branch ≠ machine.branch →
`EscPosTicketBytesService::render` b64. Les tokens guest WEB ne peuvent PAS imprimer (machine requise).

## 5. Invariants observés
- Pricing/total 100% serveur : champs financiers client unset (FrontendOrderService.php:271),
  SSOT PricingService (:301-312), subtotal/total `nullable` + backend ignore (OrderRequest.php:148-162).
- composition_snapshot écrit dans la même transaction (FrontendOrderService.php:451,466 — legacy ;
  SSOT via orderItemInsertRows :313).
- Idempotency branch-scopée dual-layer (lock :170-174 + UNIQUE recovery :683-688) ; jamais de clé
  cross-branch (422 si branch non résolue :160-165).
- order_type=KIOSK réservé machine physique (OrderRequest.php:205-208, non-commité).
- Remise discrétionnaire (coupon+loyalty) REFUSÉE tant que F1 TVA non fixée (:526/:851-861).
- delivery_charge non-DELIVERY forcé 0 (:280-282) ; fee DELIVERY recalculée serveur quand adresse
  sauvegardée (OrderRequest.php:108-116) ; livraison offerte sur subtotal serveur (:538-543).
- staff_only_mode fail-secure défaut true (config/features.php:52).
- Token guest scope `kiosk:order` 30j, jamais wildcard (GuestSignupController.php:146) ; OTP ne
  donne jamais accès à un compte staff (check is_guest).
- OrderCreated après commit via DispatchableAfterCommit (:635-647) ; kiosk carte différé au PAID
  (:241-250) pour éviter les commandes fantômes KDS.

## 6. Risques préliminaires (à vérifier W2/W4 — PAS des findings certifiés)
1. **La désactivation vitrine est purement CLIENT-SIDE** (garde Vue Router + v-if chrome). L'API
   `/api/frontend/*` accepte des commandes web guest complètes (par design WEB-WIREUP). Les routes
   SPA mortes (/checkout, /my-orders…) restent compilées dans le bundle — surface morte, et un JS
   modifié contournerait la garde (l'API resterait l'autorité, prix SSOT — risque faible mais à
   trancher W4).
2. `validateDeliveryMinimumOrder` (OrderRequest.php:300-302) lit le **subtotal client** pour le
   plancher livraison → contournable en postant un subtotal gonflé (le total facturé reste SSOT,
   mais le minimum owner n'est pas réellement enforce). Commentaire :283-286 l'assume.
3. Fallback legacy fee (OrderRequest.php:117-129) : `delivery_distance_km` fourni par le CLIENT
   quand pas d'address_id → sous-déclaration de distance = fee minorée (tests DeliveryFeeForge*
   existent, à confronter W2).
4. `GuestSignupController::verify` : si `site_phone_verification=DISABLE`, register sans validation
   du code OTP (GuestSignupController.php:63-70) — dépend d'un setting runtime.
5. Deux fichiers du périmètre **modifiés non-commités** (OrderRequest guard KIOSK + commentaire
   DeliveryFeeService) : le VPS n'a pas le guard → l'invariant « KIOSK=machine seulement » est
   absent en prod tant que non déployé.
6. `changeStatus` : branch admin/staff (user_id ≠ owner) → abort 403 sec, mais le path kiosk
   machine (user_id = machine user) permet au token machine d'annuler ses commandes — cohérent borne.
7. Composants account/chat/checkout référencent des stores encore actifs — dérive possible si
   quelqu'un ré-allowliste une route sans re-audit.

## 7. Couverture de tests observée (ls réel)
- `tests/Feature/StaffOnlyRoutingTest.php` (flags staff_only/kiosk config) ;
  `tests/js/staffOnlyLandingRedirect.spec.js` ; `tests/js/checkoutGeocodeError.spec.js`.
- `tests/Feature/Frontend/` : OrderRequestDeliveryFeeAuthorityTest, OrderRequestKioskAbilityTest,
  OrderRouteAbilityTest, PaymentConfirmFcmFailureTest.
- `tests/Feature/Delivery/` (11 fichiers) : DeliveryFeeConfigurableTest, DeliveryFeeForgePosTest,
  DeliveryFeeForgeWebTest, DeliveryFeeBranchWireupSentinelTest, DeliveryMinimumOrderTest,
  DeliveryOwnerRuleHeninBeaumontSentinelTest, DeliveryValidationTest, GeocodeFailureBlocksOrderTest,
  DeliveryStatusTransitionWhitelistTest, BranchZoneFallbackTest, DeliveryBoyAddressPermissionSplitTest.
- `tests/Unit/Services/DeliveryFeeServiceTest.php` ; `tests/Feature/Auth/GuestSignupAbilityScopeTest.php` ;
  `tests/Feature/FrontendDiscountIntegrityTest.php`, `KioskFrontendComprehensiveTest.php`,
  `PosWalkInAndDeliveryFeeTest.php`, `DeliveryOrderContractTest.php`.

## 8. Questions ouvertes
- Le maintien des composants checkout/account morts est-il voulu (réactivation V2) ou dette à purger ?
- `customerRoutes.js` (CRM admin) était listé dans le périmètre « storefront » de la mission — confirmer
  que le vrai module client est `frontendRoutes.js` + `authRoutes.js`.
- Les guest tokens web (kiosk:order sans machine) doivent-ils pouvoir appeler /menu, /pricing/preview,
  /upsell (routes « kiosk design ») ? Aujourd'hui auth:sanctum suffit (api.php:1474-1491).
- Le guard KIOSK non-commité (OrderRequest:205-208) attend-il un commit dédié ou fait-il partie d'un
  lot en cours sur cette branche ?
