# Cartographie — SYSTÈME BORNE (kiosk libre-service)

> Vague 1 (compréhension, read-only). Lecteur : borne-kiosk. Date : 2026-07-02.
> Tout file:line cité a été lu via Read/Grep/ls dans cette session (CLAUDE.md §3ter).

## 1. Rôle du système

La borne est une SPA Vue 2 (routes `/kiosk/*`) montée sur `KioskAppComponent`, authentifiée
en tant que **machine** (Sanctum token ability `kiosk:order`), qui laisse le client composer
un panier (wizard multi-étapes), obtient un devis signé serveur (SSOT), poste la commande
`POST /api/frontend/order`, et — en V1 Plan B (`kiosk.payment_route_all_to_counter=true`,
config/kiosk.php:55) — route TOUT paiement vers la caisse : la commande naît
`PENDING_COUNTER` + `pos_payment_method=COUNTER_DEFERRED`, la cuisine démarre, le client
paie au comptoir. Impression ticket via pont ESC/POS local `127.0.0.1:9100`.

## 2. Architecture frontend

### 2.1 Router — `resources/js/router/modules/kioskRoutes.js` (297 l.)
- Shell `/kiosk` → `KioskAppComponent` avec guard `requireKioskAuth` (l.42-74) :
  **non-bloquant** depuis le fix écran-blanc 2026-06-27 — hydrate `kioskFilter/init` et
  pré-chauffe `kioskCart/kioskLogin` en fire-and-forget ; l'auth réelle est rattrapée par
  l'intercepteur 401 d'app.js au 1er fetch. Sans creds ni token → redirect `kiosk.login`.
- Routes enfants : `idle`, `categories` (surface catalogue unique, `?cat=` ; `kiosk.products/:categoryId`
  = redirect legacy avec analytics l.119-135), `wizard/:itemId` (feature-flag
  `kioskUsePosWizard` → wrapper POS, l.181-184), `cart`, `loyalty`/`upsell`/`payment`
  (guard `requireCart` l.88-92), `waiting/:orderId` (guard `requireOrderRef` l.94-105,
  accepte `offline_\d+`), `confirmation` (guard `requireConfirmationContext`),
  `cash-instruction` (props number/total/timeout=45s l.248-262), 4 écrans d'erreur
  (`error/network|menu-unavailable|product-removed|payment-refused`).
- Imports synchrones du trio shell/idle/categories (l.3-8, anti écran-noir) ; le reste en
  chunks lazy `kiosk-shell` / `kiosk-wizard` / `kiosk-errors`.

### 2.2 Store — `resources/js/store/modules/kioskCart.js` (841 l.)
- Constantes : `SOURCE_KIOSK=5`, `PAYMENT_METHOD_MAP {cash:1, card:4, tr:5}` (l.22),
  `KIOSK_ORDER_TYPES {KIOSK:25, TAKEAWAY:10}` (l.25), `MAX_ITEM_QTY` depuis
  `window.foodkingConfig.maxItemQty ?? 20` (l.24).
- `kioskLogin` (l.462-484) : coalescing module-scope `_inFlightKioskLogin` — 1 seule rotation
  serveur par burst de 401.
- `quoteOrder` (l.607-666) : gate token (throw `KIOSK_QUOTE_NO_TOKEN` l.617-624), exige
  order_type explicite (25/10), POST `frontend/order/quote`, exige
  `total_ttc + quote_token + signature` (l.659), toast dédié 429.
- `submitOrder` (l.695-812) : idempotency key UUID générée **une fois par session panier**
  (l.710-715), POST `frontend/order` avec header `X-Idempotency-Key` ; payload =
  `buildKioskOrderPayload` (l.160-180) qui embarque le quote signé (quote_token/signature/
  subtotal/discount/total). Le client n'envoie JAMAIS de prix par ligne
  (`sanitizeKioskOrderItem` l.98-112 : item_id/quantity/variations{id}/extras{id}).
  Échec réseau/5xx → file offline **cash uniquement** (électronique rejetée
  `KIOSK_OFFLINE_ELECTRONIC_PAYMENT_REFUSED` l.770-775) ; le quote est STRIPPÉ du payload
  offline (l.782-788, régénéré au replay) ; réponse synthétique `offline_<key>` (l.796-806).
- Totaux locaux = affichage seulement ; commentaire SSOT explicite l.247-249.
- `ADD_ITEM`/`REPLACE_ITEM_AT`/`UPDATE_QUANTITY` clampent qty à MAX_ITEM_QTY et
  **recalculent toujours** le total de ligne (heal F1 l.281-292) ; toute mutation panier
  invalide `orderQuote`.
- `pruneUnavailableLines` (l.670-685) : purge des lignes 86/rupture via kioskMenu.

### 2.3 Composants (resources/js/components/frontend/kiosk/)
| Fichier | Lignes | Rôle |
|---|---|---|
| KioskAppComponent.vue (FROZEN) | 1576 | Shell : timers idle (routes sans timer l.881 : idle/waiting/payment/confirmation ; warnAt = idleMs−confirmMs l.887-903), overlay inactivité, souscription Echo branche `ItemAvailabilityChanged` (l.540-578, fallback TTL silencieux), listeners `kiosk-auth-failed`/`kiosk-auth-retried` (l.368-382), healthcheck périodique (l.999), sync offline toutes 15 s (l.347) |
| KioskIdleScreenComponent.vue | 840 | Attract 1080×1920 (carrousel 8 produits, refonte 2026-06-28), choix emporter/sur place |
| KioskCategoriesComponent.vue | 1588 | Catalogue single-surface (chips catégories + grille items) |
| KioskWizardComponent.vue (FROZEN) | 3134 | Wizard compo : steps lazy (steps/ : Taille, Viande, Pain, Sauce, Garnitures, Supplements, FritesStyle, Menu, GenericChoices), templates par alias config (`wizard_template_aliases` config/kiosk.php:119-146), `buildCartItem()` l.1757 — fix P0 multi-viandes : distribution sur tous attrs « Viande N » + aplatissement `item.variations` objet→`Object.values().flat()` (l.1770-1813) |
| KioskCartComponent.vue | 1365 | Panier, édition ligne (P-MEGA-05 snapshot), bloc promo/loyalty gated par `promo_enabled` |
| KioskUpsellComponent.vue (FROZEN) | 543 | Upsell avant paiement (endpoint `frontend/item/kiosk-upsell`) |
| KioskPaymentComponent.vue | 1443 | Paiement. Plan B : template `v-if="paymentRouteAllToCounter"` (l.6, lu de `window.foodkingConfig.kiosk.paymentRouteAllToCounter` l.333-335, injecté par master.blade.php:179) masque card/cash/tr ; `confirmCounterRoute()` (l.421-428) force `method='cash'` → `confirmPayment()` → `refreshQuote()` (l.560-584, gate token + quote signé) → `submitOrder` → nav `kiosk.cash-instruction` (l.492-494). Card/TR : `processCardPayment` TPE via kioskHardware (stub navigateur), compteur échecs → `kiosk.error.payment-refused` (l.540-556) |
| KioskCashInstructionComponent.vue | 340 | « Payez à la caisse » + n° commande ; auto-print ticket comptoir (`autoPrintCounterTicket` l.118, filet `printFailed` + bouton réimpression l.47-52) ; auto-return 45 s |
| KioskWaitingComponent.vue | 982 | Attente TPE/statut (poll `frontend/order/show/:id`) |
| KioskConfirmationComponent.vue | 793 | Confirmation + auto-retour idle (`confirmation_auto_return_seconds`=30, config/kiosk.php) |
| KioskLoginComponent.vue | 323 | Écran retry/diagnostic (pas de formulaire client) — auto-login machine |
| KioskLoyaltyComponent.vue | 1088 | Fidélité (scan QR/NFC, redeem) |
| ds/ (17 fichiers) | — | Design system Ks* (KsButton, KsModal, KsVirtualKeyboard…) |
| 4 KioskError*Component + Layout | ~455 | Écrans erreurs UX |

### 2.4 Helpers kiosk (resources/js/helpers/, 30+ fichiers vus via grep -l)
- **kioskPricingPreview.js** (274 l.) : preview SSOT debounce 400 ms vers
  `frontend/pricing/preview`, abort de la requête précédente, **aucun prix envoyé**
  (whitelist l.66-108) ; skip si zéro modificateur (l.198-207) ; **422 = compo incomplète
  attendue, silencieux** (fix signal-jaune l.245-254) ; onError réservé réseau/401/5xx.
- **kioskOfflineQueue.js** (665 l.) : file v2 IndexedDB (`kioskOfflineQueueDb`) + clé legacy
  localStorage `kiosk_offline_queue_v1` ; lock cross-tab (LOCK_TTL 60 s, heartbeat 20 s),
  BroadcastChannel `kiosk-offline-queue-sync`, sync 30 s, MAX_ATTEMPTS=10, backoff ≤30 s,
  event quota-exceeded ; `markStaleItems` invalide les entrées contenant un item 86.
- **kioskPrinter.js** (647 l.) : renderer ESC/POS client 32 col (58 mm, l.42), cascade
  d'impression : kioskHardware Electron → `printViaLocalBridge` POST
  `http://127.0.0.1:9100` (LOCAL_BRIDGE_URL l.471, pont bridge.js/node-usb SK1-31) →
  `window.print()` UNIQUEMENT hors bridge/manuel (l.262-285) ; échec pont → `{method:'none'}`
  → `reportPrinterFailure` (kiosk-event). NB : la caisse, elle, utilise le renderer ESC/POS
  **serveur** (`PosTicketBytesController`) — l'unification borne→serveur est un reste connu
  (memory ticket-widthsafe).
- **kioskAuthInterceptor.js** (169 l.) : debounce capture-phase 1500 ms des events
  `kiosk-auth-retried`/`kiosk-auth-failed` (anti N-toasts), guard double-install.
- Autres : kioskMenuCache (snapshot TTL, stale >4 h), kioskAnalytics (track → kiosk-event),
  kioskViandeCatalog, kioskSauceCatalog, kioskDrinkAddons, kioskExtrasPartition,
  kioskUpsellFlow, kioskReceiptPersistence, kioskTacosSize, kioskFormatPrice, kioskMedia,
  kioskCategoryOrder, kioskItemDisplayOrder, kioskDisplayText, kioskFilters,
  kioskMenuBundledExtras, kioskSandwichSplit (désactivé, config sandwich_split null).

## 3. Architecture backend

### 3.1 Auth machine — `app/Http/Controllers/Auth/KioskMachineLoginController.php`
`POST /api/auth/kiosk-login` (routes/api.php:174, throttle dédié `kiosk-login` 30/min) :
lookup username `withoutGlobalScope(BranchScope)` (pré-auth, l.55-57) ; `Hash::check`
AVANT tout check d'état (anti-énumération, heal F3 l.65-75) ; machine ACTIVE + user lié
ACTIF requis ; transaction : `lockForUpdate`, **revoke des anciens tokens `kiosk-token`**,
`createToken('kiosk-token', ['kiosk:order'], now()+config('sanctum.expiration',480) min)`,
`is_login=YES`. Logout : routes/api.php:212.

Auto-login SPA : `config/kiosk.php` — payload creds injecté dans `window.foodkingConfig`
par master.blade.php SEULEMENT si (path kiosk*) ∧ (creds configurés) ∧ (APP_ENV=local ∨ IP
∈ `KIOSK_AUTO_LOGIN_TRUSTED_IPS` ∨ `?machine_key=` == `KIOSK_AUTO_LOGIN_SECRET`
timing-safe — `app/Support/KioskAutoLoginGate.php:26-56`). Fallback creds locaux
kiosk-lecayenne/kiosk123 **uniquement APP_ENV=local** (config/kiosk.php:198-209).

### 3.2 Modèle — `app/Models/KioskMachine.php` (49 l.)
Table `kiosk_machines` (user_id, branch_id, machine_id, username, password, is_login,
status) ; BranchScope global (boot l.38, skip pré-auth) ; relations user (withTrashed) /
branch.

### 3.3 Services — `app/Services/Kiosk/`
- **KioskMenuService.php** (540 l.) : payload menu unifié `GET /api/frontend/menu`
  (branch, categories parent_id ≤2 niveaux, items+variations+extras+allergens,
  upsell_rules, snapshot_version) ; channel `kiosk` ; PAS de calcul de prix (prix DB
  affichage seulement). Consommé par `MenuController::kiosk` (Frontend/MenuController.php:34-67 :
  tokenCan kiosk:order + KioskMachine requise + cache `kiosk.menu.branch.{id}` TTL 60 s).
- **PricingPreviewService.php** (203 l.) : recalcul SSOT sans persistance via
  `PricingService::calculateOrder(PricingRequest::forKiosk(...))` ; priorité
  `kiosk_promo_code` (KioskPromo::findValid) puis coupon global ; branch_id imposé par le
  controller (`PricingPreviewController` → route `POST /frontend/pricing/preview`
  routes/api.php:1479). Aucune écriture DB, garde cross-item active.
- **KioskPromoService.php** (122 l.), **UpsellRuleService.php** (108 l.).

### 3.4 Commande — `app/Services/FrontendOrderService.php` (Plan B, l.190-330)
- Résolution branche : `KioskMachine::where('user_id', Auth::id())` (l.198) → force
  `branch_id = kiosk.branch_id` (l.205) ; order_type forcé KIOSK(25) si ni 25 ni 10 (l.209-211).
- `isCounterDeferredKioskCash` = (machine ∧ type 25/10 ∧ pm=CASH_ON_DELIVERY) (l.219-220)
  → auto-accept + `payment_status=PENDING_COUNTER`, `pos_payment_method=COUNTER_DEFERRED`
  (l.290-291) ; dispatch KDS immédiat (table de vérité l.228-250) ; kiosk card/TR restent
  PENDING+UNPAID, signaux différés à `finalizePaidKioskOrder` (TPE callback).
- Champs financiers client UNSET avant create (l.271), delivery_charge=0 hors DELIVERY
  (l.280-282) ; prix 100 % recalculés `PricingService::calculateOrder(PricingRequest::forKiosk)`
  (l.301-317) ; `source_surface='kiosk'` si machine (l.574).
- **OrderRequest.php** : `authorize()` = `tokenCan('kiosk:order')` (l.83) ; merge branch_id
  depuis la machine (l.92-94) ; order_type=KIOSK **rejeté sans machine enregistrée**
  (guard WEB-WIREUP l.205-206) ; machine inactive → 422 (l.216-217).

### 3.5 Encaissement caisse (côté POS, hors périmètre mais frontière)
`GET /api/admin/pos/counter-collect/pending` (routes/api.php:807-853 : `source_surface='kiosk'`
+ rattrapage NULL par type kiosk/emporter, exclut CANCELED),
`POST counter-collect/{order}/confirm|cancel` (l.854-896, idempotency),
`POST collect-kiosk-cash/{order}` (l.916-926). Fiscal alloué à l'encaissement, pas au create.

### 3.6 Observabilité — `app/Http/Controllers/Frontend/KioskEventController.php` (290 l.)
`POST /api/frontend/kiosk-event` + alias `/kiosk/event` (routes/api.php:1461-1463,
1507-1509 : auth:sanctum + `abilities:kiosk:order` + throttle 30/min) → ActionLog.
Whitelist stricte de `type` (l.57-85) + `event_name` analytics (l.91+) ; branch_id TOUJOURS
lu serveur depuis KioskMachine ; hors whitelist → 422.

### 3.7 Admin — `KioskSetupController.php` (40 l., `permission:settings` sur index+update
l.21), `KioskMachineController.php` (90 l., CRUD machines routes/api.php:533-540).

## 4. Flux critiques (chaînes vérifiées)

1. **Cold-start / auth machine** : kioskRoutes.requireKioskAuth:42 → kioskCart.kioskLogin:462
   (coalescé) → POST auth/kiosk-login → KioskMachineLoginController.login:27 (revoke+token
   kiosk:order 480 min) → SET_KIOSK_TOKEN (+ `window._refreshEchoAuth` l.392) ; rattrapage
   401 par app.js interceptor + debounce toasts kioskAuthInterceptor.js.
2. **Idle → catalogue** : KioskIdleScreenComponent (choix 25/10 → `setOrderType`) →
   kiosk.categories → GET frontend/menu (MenuController.kiosk:34 → KioskMenuService).
3. **Wizard → panier** : KioskWizardComponent.buildCartItem:1757 (distribution Viande N +
   flatten variations objet) → kioskCart.ADD_ITEM (clamp+recompute) ; total live =
   kioskPricingPreview.request (debounce 400, 422 silencieux) sinon fallback local.
4. **Paiement Plan B** : KioskPaymentComponent.confirmCounterRoute:421 (method=cash) →
   refreshQuote:560 (POST frontend/order/quote, quote signé) → kioskCart.submitOrder:695
   (X-Idempotency-Key, quote_token+signature) → FrontendOrderService:190-330
   (branch forcé machine, PENDING_COUNTER + COUNTER_DEFERRED, PricingService SSOT,
   dispatch KDS) → nav kiosk.cash-instruction:492 → autoPrintCounterTicket
   (KioskCashInstructionComponent:118 → kioskPrinter cascade bridge 9100) → encaissement
   POS counter-collect (routes/api.php:854) → fiscal_sequence à l'encaissement.
5. **Paiement legacy card/TR** (flag=false) : processCardPayment:586 (kioskHardware TPE,
   timeout) → waiting (poll order/show) → finalizePaidKioskOrder (signaux différés,
   FrontendOrderService:241-249) ; échecs répétés → kiosk.error.payment-refused:548.
6. **Offline (cash only)** : submitOrder catch réseau/5xx:768 → saveOrder (quote strippé:782)
   → kioskOfflineQueue (IndexedDB, lock cross-tab, MAX 10 tentatives, re-quote au replay) →
   orderRef `offline_*` accepté par requireOrderRef:102.
7. **Temps réel dispo** : Echo `ItemAvailabilityChanged` (KioskAppComponent:540-578) →
   `_handleItemAvailabilityChanged:662` → pruneUnavailableLines (kioskCart:670) +
   markStaleItems file offline ; fallback TTL cache si Echo absent.

## 5. Invariants observés (file:line)

- Pricing SSOT : aucun prix client persisté — unset l.271 + recalcul l.301-317
  FrontendOrderService ; preview whitelist kioskPricingPreview.js:66-108 ; totaux store =
  affichage (kioskCart.js:247-253).
- Quote signé obligatoire au commit (quote_token+signature exigés kioskCart.js:659,
  KioskPaymentComponent:579 ; test KioskQuoteTokenRequiredOnCommitTest.php).
- branch_id résolu serveur via KioskMachine, jamais client (OrderRequest:92-94,
  FrontendOrderService:205, KioskEventController doc, MenuController:44-56).
- order_type=KIOSK réservé machine enregistrée (OrderRequest:205-206).
- Token machine ability unique `kiosk:order`, TTL 480 min, revoke au relogin
  (KioskMachineLoginController:~96-110) ; `block_kiosk_token_admin` sur routes admin
  (routes/api.php:281,302).
- Plan B : PENDING_COUNTER+COUNTER_DEFERRED, fiscal à l'encaissement POS seulement
  (FrontendOrderService:290-291, config/kiosk.php:34-55) ; TTL stale 180 min auto-cancel
  non-fiscal (config/kiosk.php:73-84).
- Idempotence : clé unique par session panier (kioskCart.js:710-715) + header, préservée
  au replay offline (kioskCart.js:777-794).
- Offline = cash uniquement (kioskCart.js:770-775).
- FR-lock ADR-007 (config/kiosk.php:22-31, locale_switch_allowed=false).
- Promo/loyalty UI gated `KIOSK_PROMO_ENABLED=false` par défaut car le chemin coupon
  kiosk n'est pas câblé end-to-end (config/kiosk.php:57-68).
- Frozen zones respectées : KioskWizardComponent / KioskAppComponent / KioskUpsellComponent.

## 6. Couverture de tests (fichiers réels, ls/find)

PHPUnit (tests/Feature) : KioskAuthTest, KioskLoginApiTest, KioskSecurityTest,
KioskScopeIsolationTest, KioskQuoteIntegrityTest, KioskQuoteTokenRequiredOnCommitTest,
KioskQuoteForgesBranchIdSilentlyOverriddenTest, KioskPaymentStateMachineTest,
KioskOfflinePaymentScopeTest, KioskEventTest, KioskLoyalty*(2), KioskRealtimeBroadcastTest,
KioskUpsellCategoryTest, KioskFrontendComprehensiveTest, KioskBundleLockdownTest,
PosCollectKioskCashRouteTest, PosKioskPricingParityTest + dossiers Kiosk/, KioskMultiBranch/,
KioskPhase1/5/7/, KioskSecurity/ ; tests/Unit/KioskAutoLoginGateResolverTest.
Vitest (tests/js) : ~60 specs kiosk* dont kioskWizardMultiViande, kioskPaymentPlanBRoute,
kioskCounterPaymentFlow, kioskCashAutoPrint, kioskLocalBridge, kioskOfflineQueueMigration,
kioskCartClampTotal, kioskCartSendPayload, kioskFrLockImmutable, kioskRouterLockdown,
posKioskVariationParity, kioskA11y*(5).

## 7. Risques préliminaires (à vérifier vagues suivantes — PAS des findings)

1. Creds machine dans `window.foodkingConfig` : gate IP/secret OK sur papier
   (KioskAutoLoginGate) mais dépend du .env prod (TRUSTED_IPS/SECRET) — à auditer W4.
2. Impression borne = renderer ESC/POS **client** 32 col (kioskPrinter.js:42) distinct du
   renderer serveur width-safe de la caisse — divergence de format connue (memory), reste
   d'unification.
3. `refreshQuote` (KioskPaymentComponent:573-577) duplique quoteOrder du store sans le
   toast 429 — chemin parallèle à surveiller.
4. Fallback `varId = (idx===0 ? v.id : null)` dans buildCartItem:1807 : si aucun match par
   nom sous l'attribut, la viande 2 est silencieusement droppée (comportement post-fix ;
   422 backend garde le filet).
5. `getOfflinePendingCount`/replay : re-quote au replay OK, mais fenêtre entre stale-mark
   et sync 30 s ; quota localStorage → event, UX à vérifier W5.
6. Guard non-bloquant requireKioskAuth : dépend entièrement de l'intercepteur 401 app.js ;
   si le bundle change, risque de régression silencieuse (couvert par
   kiosk-spa-black-screen-guard.spec e2e cité en commentaire l.5).
7. Promo kiosk : flag default FALSE documente un vrai gap fonctionnel (discount affiché
   jamais appliqué si activé sans câblage) — ne pas activer sans W2.

## 8. Questions ouvertes

- Le pont d'impression 9100 est-il déployé/actif sur la vraie borne (VPS/Chrome flag
  LocalNetworkAccess) ? Preuve physique = owner-only (memory 2026-06-30).
- `KIOSK_USE_POS_WIZARD` (wrapper POS wizard, kioskRoutes.js:180-184) : statut V4.1 ?
- `.env` prod : KIOSK_AUTO_LOGIN_TRUSTED_IPS / SECRET réellement définis ?
- Unification renderer ticket borne → serveur (`escpos` endpoint) planifiée mais non faite ?
