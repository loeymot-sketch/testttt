# AUDIT & PLAN MAÎTRE — PARCOURS BORNE (KIOSK) — Du idle à la commande finalisée

## 0. Métadonnées

Version v1.0 — date 2026-04-25 — cible exécutant : **Codex CLI (GPT-5.5)** sous orchestration Claude. Document écrit pour la Vague 2 (Wave B Kiosk) et au-delà : missions liées **M-11 KIOSK-RUNTIME** (offline CB/TR + identifiants offline_), **M-05** (cohérence pricing/SSOT borne), **M-06** (paiement TPE / payment-confirm idempotent), **M-08** (fiscal Option B — fiscal_sequence_no délégué au POS), **M-17** (synchro WS POS/KDS/OSS pour entrées kiosk). SSOT lus : `docs/ORDER_FLOW.md`, `docs/DEVICE_FLOW.md`, `resources/js/router/modules/kioskRoutes.js`, `app/Http/Controllers/Frontend/OrderController.php`, `app/Services/FrontendOrderService.php` (`myOrderStore`, `finalizePaidKioskOrder`), `routes/api.php` (groupe `frontend.order` + `frontend/menu` + `frontend.kiosk.event`), `resources/js/store/modules/kioskCart.js`, `resources/js/helpers/kioskOfflineQueue.js`, `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md` (FK-030, FK-044, FK-053, FK-095, FK-099). Invariants opposables : pricing **backend = SSOT** (PricingService recalcule total/subtotal/discount, le client n'a pas le droit d'imposer un montant), `OrderStatus` figé V1 (transitions via `OrderStateMachine::allows()/apply()`), `branch_id` lu côté serveur depuis `KioskMachine::user_id` (jamais depuis le client), Sanctum kiosk machine = bearer token + ability `kiosk:order` pour les events, symétrie `OrderService` (POS) ↔ `FrontendOrderService` (kiosk) via la state machine + outbox `OrderCreated`/`OrderStatusChanged`. Inspiration UX QSR self-order (McDonald's, Panera) : une étape = un objectif unique, retour visible à tout moment, dégradation gracieuse hors-ligne.

## 1. Carte du parcours (vue client)

États ordonnés (machine borne nominale + branches d'erreur) :

- **K00 — Bootstrap silencieux** : auto-login machine (`auth/kiosk-login`) si `foodkingConfig.kioskAutoLogin` est servi, sinon écran login.
- **K01 — Idle / Attract loop** (`kiosk.idle`) : carrousel promo, CTA « Commander ici », réveil au tap.
- **K02 — Choix mode de retrait** (sur place / à emporter) — peut être fusionné avec K01 ou K03 selon design final. **À CONFIRMER** si écran dédié ou modale.
- **K03 — Catégories / catalogue** (`kiosk.categories`) : liste catégories + items (filtre `cat=` query), shell stable (pas de re-slide).
- **K04 — Wizard produit** (`kiosk.wizard/:itemId`) : variations, extras, sauces, suggestions sandwich/tacos, formules. Toggle `KIOSK_USE_POS_WIZARD` → `KioskPosWizardComponent`.
- **K05 — Upsell ciblé** (`kiosk.upsell`) : suggestions complémentaires basées sur `item_ids` du panier.
- **K06 — Loyalty / Fidélité** (`kiosk.loyalty`) : scan QR / NFC ou code, application discount serveur (jamais client).
- **K07 — Panier** (`kiosk.cart`) : récap lignes, édition (`startEditingCartItem` → wizard), promo code, totaux d'aperçu (preview, **non SSOT**).
- **K08 — Paiement** (`kiosk.payment`) : choix `cash | card | tr`, déclenche `submitOrder` (POST `/frontend/order` avec `X-Idempotency-Key`).
- **K08.a — Cash instruction** (`kiosk.cash-instruction`) : « passez en caisse régler en espèces », auto-redirect timer.
- **K08.b — TPE flow** (carte / TR) : appel TPE physique côté Electron borne, puis `POST /frontend/order/{id}/payment-confirm`.
- **K09 — Attente** (`kiosk.waiting/:orderId`) : polling status / réception WS (`OrderCreated` → `ACCEPT`), puis route vers confirmation.
- **K10 — Confirmation** (`kiosk.confirmation`) : numéro de file (`queue_number`), total, redirect idle après timer.
- **KE1 — Erreurs globales** : `kiosk.error.network`, `kiosk.error.menu-unavailable`, `kiosk.error.product-removed`, `kiosk.error.payment-refused`.
- **KO1 — Mode offline / dégradé** : queue locale (`kioskOfflineQueueDb`), CB/TR **bloqué** (gate `GATE_OFFLINE_SCOPE_V1`, FK-030/FK-044), cash uniquement si décision humaine — **À CONFIRMER** scope offline.

## 2. Matrice écran → composant → route → API → service

| Étape | Composant | Route name | API | Service | Données | Prix (SSOT) |
|---|---|---|---|---|---|---|
| K00 | (router guard `requireKioskAuth`) | — | `POST /api/auth/kiosk-login` | `KioskMachineLoginController@login` | Sanctum token + machine_id | n/a |
| K01 | `KioskIdleScreenComponent` | `kiosk.idle` | (aucune écriture) `GET /api/frontend/menu` (préfetch) | `MenuController@kiosk` | promos, brand, idle media | n/a |
| K02 | overlay/inline (`KioskIdleScreen` ou `KioskCategories`) **À CONFIRMER** | — | — | — | `order_type` local (`KIOSK=25` ou `TAKEAWAY=10`) | n/a |
| K03 | `KioskCategoriesComponent` | `kiosk.categories` | `GET /api/frontend/menu` | `MenuController@kiosk` (+ `AvailabilityService`) | catégories, items, dispo | preview client |
| K04 | `KioskWizardComponent` / `KioskPosWizardComponent` | `kiosk.wizard` | `GET /api/frontend/menu` (cache) + helpers `kioskPricing`, `kioskMenuBundledExtras` | — | snapshot ligne `cartItem` | preview client (non opposable) |
| K05 | `KioskUpsellComponent` | `kiosk.upsell` | `GET /api/frontend/item/kiosk-upsell?item_ids=…` | `FrontendItemController@kioskUpsell` | items suggérés | n/a |
| K06 | `KioskLoyaltyComponent` | `kiosk.loyalty` | `POST /api/frontend/loyalty/scan`, `POST /api/frontend/promo/validate` | `LoyaltyService`, `CouponService` | meta promo / discount | discount **calculé serveur** |
| K07 | `KioskCartComponent` | `kiosk.cart` | `POST /api/frontend/promo/validate`, (option) `POST /api/frontend/order/quote` | `PosController@quote` (preview SSOT) | totals preview signé | quote **opposable** si quote_token utilisé |
| K08 | `KioskPaymentComponent` | `kiosk.payment` | `POST /api/frontend/order` (header `X-Idempotency-Key`) | `FrontendOrderService::myOrderStore` + `PricingService::calculateOrder(forKiosk)` | `FrontendOrder` créé `PENDING` | **SSOT serveur** (recalcul total/subtotal/discount) |
| K08.a | `KioskCashInstructionComponent` | `kiosk.cash-instruction` | (commande déjà créée, `payment_status=PAID` si CASH) | `myOrderStore` (auto-accept cash) | numéro file | SSOT serveur |
| K08.b | (orchestration `KioskPaymentComponent` + Electron) | `kiosk.payment` puis `kiosk.waiting` | `POST /api/frontend/order/{frontendOrder}/payment-confirm` | `OrderController@paymentConfirm` + `FrontendOrderService::finalizePaidKioskOrder` | `transaction_id`, `card_type` | n/a (pas de re-pricing) |
| K09 | `KioskWaitingComponent` | `kiosk.waiting/:orderId` | `GET /api/frontend/order/show/{id}` + WS Echo (`OrderCreated`, `OrderStatusChanged`) | `FrontendOrderService::show` | statut + queue_number | SSOT serveur |
| K10 | `KioskConfirmationComponent` | `kiosk.confirmation` | (lecture state) | — | `order_serial_no`, `queue_number`, `total` | SSOT serveur (figé) |
| KE | `KioskError*Component` | `kiosk.error.*` | (aucune écriture) + `POST /api/frontend/kiosk/event` (telemetry) | `KioskEventController@store` | code erreur | n/a |
| KO | `KioskOfflineConflictModalComponent` + helpers | (transparent) | queue locale → replay `POST /frontend/order` avec `X-Idempotency-Key` original | `kioskOfflineQueue.syncQueue` | `localKey=offline_<ts>_<rnd>` | SSOT recalculé au replay |

## 3. Détail millimétrique par étape

### K00 — Bootstrap & auth machine
- **But** : la borne est utilisable sans saisie humaine. Token Sanctum machine attaché à `KioskMachine` (qui porte `branch_id`).
- **UI** : aucune. Si échec, redirect `kiosk.login` avec retry/diagnostic.
- **Front (store)** : `kioskCart/kioskLogin` → `SET_KIOSK_TOKEN`. Persistance via `vuex-persistedstate`. Garde `requireKioskAuth` lit `state.kioskCart.kioskToken`.
- **Back** : `KioskMachineLoginController@login` valide credentials machine, retourne `{token, kiosk:{id}}`.
- **Prix** : n/a.
- **Sync (WS)** : channel privé `kiosk-machine.{id}` peut être joint après login (**À CONFIRMER**).
- **Tests** : Vitest store (login/logout, maintenance mode), Feature `KioskMachineLoginControllerTest`.
- **Abus** : credentials hard-codés dans `foodkingConfig` → la config doit venir de `config/kiosk.php` rendu serveur, jamais d'env client. Toute fuite expose le token machine → `branch_id`.
- **Tâche Codex [EASY]** : harmoniser le guard pour éviter double dispatch `kioskLogin` simultané (race au boot).

### K01 — Idle screen
- **But** : capter l'attention, déclencher commande. Aucune écriture.
- **UI** : carrousel promo (`KioskPromoCarouselComponent`), CTA tactile pleine largeur, langue.
- **Front** : préfetch `frontend/menu`, mise en cache via `kioskMenuCache`.
- **Back** : `MenuController@kiosk` (Sanctum + `kiosk.locale` + throttle `kiosk-menu`).
- **Prix** : prix d'affichage promo lisibles uniquement, pas de quote.
- **Sync** : écouter `availability_changed` (rupture) → invalider cache.
- **Tests** : Playwright `kiosk-idle-attract.spec.js` (à créer si absent), Vitest snapshot promo.
- **Abus** : tap fantôme déclenchant ordre vide. Garde panier `requireCart` couvre déjà l'aval.
- **Tâche Codex [EASY]** : ajouter event telemetry `idle_wake` (POST `/frontend/kiosk/event`).

### K02 — Choix mode de retrait (sur place / à emporter)
- **But** : `order_type ∈ {KIOSK=25, TAKEAWAY=10}` capturé tôt pour adapter UX (numéro file vs nom).
- **UI** : 2 grandes cartes accessibles, contraste fort.
- **Front** : `kioskCart/setOrderType` (`SET_ORDER_TYPE`).
- **Back** : `myOrderStore` accepte explicitement les deux types ; toute autre valeur → forçage `KIOSK`.
- **Prix** : pas d'impact direct (sauf taxes spécifiques **À CONFIRMER**).
- **Sync** : WS push côté POS doit afficher le bon badge (sur place / à emporter).
- **Tests** : Vitest store (mutation), Playwright (parcours change-type).
- **Abus** : envoyer `order_type=POS` côté client → bloqué côté `myOrderStore`.
- **Tâche Codex [MEDIUM]** : exiger un choix explicite (pas de défaut silencieux), sinon bloquer la sortie de K02 (alignement M-17).

### K03 — Catégories / catalogue
- **But** : naviguer rapidement vers un produit.
- **UI** : grille catégories + grille items, filtres dispo, badges allergènes.
- **Front** : `kioskFilter`, `kioskMenu` ; query `?cat=` pilote la sélection.
- **Back** : `frontend/menu` (1 round-trip), `AvailabilityService::assertItemsOrderableForBranch` lors du store.
- **Prix** : prix affichés depuis menu cache.
- **Sync** : Echo `branch.{id}.menu` rupture → `kioskMenu/markUnavailable` puis `pruneUnavailableLines`.
- **Tests** : Playwright `kiosk-categories.spec.js`, Vitest helpers.
- **Abus** : deep-link `/kiosk/products/:categoryId` legacy → redirect `categories?cat=`.
- **Tâche Codex [MEDIUM]** : verrouiller `kiosk.products/:categoryId` derrière redirect total et logger un event `legacy_route_hit` (suivi M-11).

### K04 — Wizard produit
- **But** : composer une ligne sans erreur (allergènes, quantités, formule).
- **UI** : steps (variations / extras / sauces / formule). Touch-targets ≥ 56 px.
- **Front** : helpers `kioskPricing`, `kioskMenuBundledExtras`, `kioskSandwichSplit`, `kioskTacosSize`. `replaceEditingCartItem` si édition.
- **Back** : aucun appel write ; les choix sont validés au store final via SSOT.
- **Prix** : preview client (non opposable). Backend validera `variation.item_id == item.item_id` et la dispo.
- **Sync** : si rupture pendant wizard → toast + retour catégories.
- **Tests** : Vitest wizard (variations, multi-quantité), Playwright add-to-cart.
- **Abus** : injecter une `variation.id` d'un autre item → rejet 422 (`Variation ID … n'appartient pas à l'article …`).
- **Tâche Codex [HARD]** : converger `KioskWizardComponent` et `KioskPosWizardComponent` (flag `KIOSK_USE_POS_WIZARD`) pour éviter la dérive double-impl (M-05).

### K05 — Upsell
- **But** : panier moyen + sans forcer.
- **UI** : 4–6 suggestions max, skip clair.
- **Front** : `fetchUpsellItems` → `frontend/item/kiosk-upsell?item_ids=…&limit=6`.
- **Back** : `FrontendItemController@kioskUpsell` (algo complémentaire).
- **Prix** : prix unitaire affiché (preview).
- **Sync** : si item upsell devient indispo → ne pas l'afficher.
- **Tests** : Vitest store (markUpsellShown), Playwright upsell visible une fois.
- **Abus** : ne **pas** ré-afficher upsell après panier modifié si déjà vu (`upsellShown` flag).
- **Tâche Codex [EASY]** : garantir idempotence d'affichage (1 upsell par session panier).

### K06 — Loyalty
- **But** : appliquer un avantage fidélité, sans jamais imposer un discount client.
- **UI** : scan QR / NFC, fallback code, message clair si refus.
- **Front** : `validatePromo` (read-only) ; loyalty = action serveur lors du store final (`loyalty_applied` flag dans la réponse).
- **Back** : `LoyaltyService::scan`, `CouponService::validate`. **AUDIT-P2** : `loyaltyApplied` retourné par `myOrderStore` permet toast si discount silencieusement droppé.
- **Prix** : discount serveur uniquement.
- **Sync** : aucun.
- **Tests** : Feature `loyaltyAppliedFlag`, Playwright (loyalty refusé → toast cohérent).
- **Abus** : envoyer `discount` côté client → unset par `myOrderStore` avant `create()`.
- **Tâche Codex [MEDIUM]** : exposer dans `OrderDetailsResource` la raison de refus loyalty (insufficient_points, expired) pour UX explicite.

### K07 — Panier
- **But** : récapituler, éditer, valider promo.
- **UI** : lignes éditables, prix unit + total ligne, bouton « payer » désactivé si panier vide ou pricing incohérent.
- **Front** : `kioskCart` getters (`subtotal`, `total`, `isEmpty`). Possibilité d'appeler `POST /frontend/order/quote` pour obtenir `quote_token`/`quote_signature` opposables.
- **Back** : `PosController@quote` (preview SSOT signée).
- **Prix** : preview signée par PricingService = source convergente avec POS (M-05).
- **Sync** : `pruneUnavailableLines` au focus.
- **Tests** : Playwright (édition ligne, code promo invalide), Vitest store.
- **Abus** : modifier `total` côté client → ignoré côté store (`unset total/subtotal/discount`).
- **Tâche Codex [MEDIUM]** : ajouter le call `quote` systématique avant K08 et passer `quote_token` à `submitOrder` pour pin du prix (alignement M-05 / GATE_PAYMENT_PROP_MUTATION).

### K08 — Paiement (orchestration)
- **But** : choisir moyen de paiement, créer la commande PENDING (cash auto-PAID, CB/TR différés).
- **UI** : 3 boutons (Espèces / Carte / Ticket Restaurant), informations sur emplacement borne + caisse.
- **Front** : `submitOrder({orderType, paymentMethod, quote})` génère un `X-Idempotency-Key` UUID v4 par session panier (réutilisé au retry/replay).
- **Back** : `myOrderStore` :
  - `Cache::lock('frontend_order_idempotency_…', 10)`, `block(5)` ;
  - lookup `(idempotency_key, branch_id)` → si existant, retour de l'order existant ;
  - `branch_id` injecté serveur depuis `KioskMachine` ;
  - PricingService recalcule **toutes** les valeurs ;
  - `payment_status = PAID` si CASH, sinon `UNPAID` ;
  - dispatch `OrderCreated` outbox uniquement si flux nominal (ou cash kiosk).
- **Prix** : SSOT.
- **Sync** : `OrderCreated` → POS (Echo), KDS lit après ACCEPT.
- **Tests** : `MyOrderStoreIdempotencyTest`, Playwright kiosk-cash, Playwright kiosk-card.
- **Abus** : double-tap → même idempotency → 1 ordre. Retry réseau → idem.
- **Tâche Codex [HARD]** : tracer dans `ActionLog` toute collision idempotency (même clé, payload divergent) pour détecter manipulation client.

### K08.a — Cash instruction
- **But** : informer le client qu'il paye en caisse, déjà PAID côté kiosk parce que cash → POS encaisse.
- **UI** : grand numéro file, total à régler, timer auto-redirect (45 s par défaut).
- **Front** : `kiosk.cash-instruction?number=…&total=…&timeout=…`.
- **Back** : commande déjà `ACCEPT` (auto-accept cash kiosk).
- **Prix** : SSOT figé.
- **Sync** : POS voit l'entrée kiosk dans la file d'encaissement (Echo).
- **Tests** : Playwright (timer, redirect, montant correct).
- **Abus** : montant manipulé côté client → l'écran lit `OrderDetailsResource.total` via state, pas une saisie libre.
- **Tâche Codex [EASY]** : lire `total` depuis la réponse serveur, jamais depuis le query string si possible (sécurise contre URL spoof). **À CONFIRMER** si state déjà privilégié.

### K08.b — TPE (CB / TR)
- **But** : confirmer paiement carte/TR via TPE physique, idempotent, sans double-débit.
- **UI** : écran « Insérez votre carte », spinner, success/refus.
- **Front** : Electron borne dialogue avec TPE local, puis `POST /frontend/order/{id}/payment-confirm` avec `transaction_id`, `card_type`, éventuellement `payment_method`.
- **Back** : `OrderController::paymentConfirm` :
  - exige user authentifié + `KioskMachine` correspondant ;
  - lock row, vérifie `branch_id` cohérent, `payment_method ∈ {CARD, TR}` ;
  - guard duplicate `transaction_id` sur autre order → 409 ;
  - si déjà PAID avec autre tx → 409 ; même tx → 200 idempotent ;
  - si `status != PENDING` → refus 422 + ActionLog `payment_late_after_cleanup` (si REJECTED/CANCELED) ;
  - sinon set PAID + tx + appel `finalizePaidKioskOrder` → PENDING → ACCEPT (DB lock + re-check PAID).
- **Prix** : aucun re-pricing.
- **Sync** : après ACCEPT, dispatch `OrderCreated` + `OrderStatusChanged` (Echo) → KDS+POS+OSS.
- **Tests** : `PaymentConfirmIdempotencyTest`, Playwright kiosk-card, Playwright race-cleanup.
- **Abus** : appeler `payment-confirm` depuis une autre KioskMachine (même restaurant ou autre) → 403. Réutiliser `transaction_id` → 409.
- **Risque** : race avec `CleanupStalePendingKioskOrders` (job rejette PENDING anciennes) ; gate déjà géré par `payment_late_after_cleanup`.
- **Tâche Codex [HARD]** : aligner M-06 — surface d'event WS dédié `kiosk.payment.confirmed` pour éviter au front de re-fetch en boucle ; documenter l'invariant « payment-confirm n'alloue pas de `fiscal_sequence_no` » (Option B M-08).

### K09 — Attente
- **But** : tenir le client informé jusqu'à ACCEPT effectif.
- **UI** : numéro file gros, message « préparation », pas de bouton retour (irréversible).
- **Front** : guard `requireOrderRef` rejette `undefined`/`null`/empty (regex `/^(offline_)?\d+$/`). Polling `frontend/order/show/{id}` toutes 3–5 s **+** Echo `OrderStatusChanged`. Coupe le polling à ACCEPT.
- **Back** : `FrontendOrderService::show`.
- **Prix** : lecture seule.
- **Sync** : WS Echo + fallback poll.
- **Tests** : Playwright kiosk-waiting (transition PAID→ACCEPT), test guard URL invalide.
- **Abus** : naviguer manuellement `/kiosk/waiting/undefined` → guard redirige `kiosk.idle`.
- **Tâche Codex [MEDIUM]** : si offline (`orderId` commence par `offline_`), basculer sur écran « commande en attente de synchronisation » sans polling (FK-053).

### K10 — Confirmation
- **But** : remettre numéro de file, total, dire au revoir.
- **UI** : grand `order_serial_no`/`queue_number`, total, message, retour idle après timer.
- **Front** : `kiosk.confirmation?number=…&total=…`. Garde `requireConfirmationContext` exige `orderRef`.
- **Back** : pas d'écriture. Imprimante locale (kiosk receipt persistence) déclenchée si activée.
- **Prix** : figé.
- **Sync** : OSS et KDS déjà notifiés.
- **Tests** : Playwright happy path total écran.
- **Abus** : afficher total `0` est valide → preserve via `parseFloat` (AUDIT-P47-BUG6).
- **Tâche Codex [EASY]** : ajouter print fallback (réessai 1×) si imprimante OFF, sans bloquer le retour idle.

### KE — Erreurs globales
- **But** : ne jamais laisser l'utilisateur dans un état muet.
- **UI** : 4 écrans dédiés (network, menu unavailable, product removed, payment refused). Boutons `réessayer` / `recommencer`.
- **Front** : routes `kiosk.error.*` paramétrables par query (`code`, `order_id`, `name`, `item_id`).
- **Back** : telemetry `POST /frontend/kiosk/event` (ability `kiosk:order`, throttle 30/min).
- **Sync** : push event vers monitoring restaurant.
- **Tests** : Playwright route directe + flux.
- **Abus** : flooding events → throttle Sanctum.
- **Tâche Codex [MEDIUM]** : centraliser le routeur d'erreurs (helper unique `goToKioskError(code, payload)`) pour homogénéiser front (M-11).

### KO — Mode offline
- **But** : ne **pas** vendre une CB/TR sans backend (FK-030/FK-044). Cash possible **uniquement** si autorisé par GATE_OFFLINE_SCOPE_V1.
- **UI** : bandeau permanent « hors-ligne », options paiement filtrées, items non-cachés grisés.
- **Front** : `kioskOfflineQueue` (`saveOrder` avec `localKey=offline_<ts>_<rnd>`), `syncQueue` au retour réseau, replay avec `X-Idempotency-Key` original.
- **Back** : aucune adaptation (idempotency garantit no-doublon au replay). `branch_id` ré-injecté à partir du token.
- **Prix** : recalcul SSOT au replay → si menu a changé, `OrderDetailsResource` reflète la divergence ; UI doit informer le client (modale conflict).
- **Sync** : aucun WS hors-ligne ; reprise via Echo après replay.
- **Tests** : `KioskOfflineIdPrefixSentinelTest`, `KioskCbTrOfflineRefusedSentinelTest`, Playwright offline-cash-only.
- **Abus** : tenter CB hors-ligne → bouton désactivé + telemetry. ID `offline_…` envoyé à `frontend/order/show/{id}` → guard front bloque (FK-053).
- **Tâche Codex [HARD]** : implémenter `paymentMethodsAvailable(getters)` qui désactive CB/TR quand `navigator.onLine === false` ou ping serveur KO (M-11).

## 4. Paiement TPE / cash / refus / retry

**Cash kiosk (immediat-PAID)** : `myOrderStore` détecte `payment_method=CASH_ON_DELIVERY` + ordertype kiosk → set `payment_status=PAID`, `shouldAutoAcceptAfterCreate=true`. La commande passe à ACCEPT directement (auto-accept), POS l'encaisse physiquement et l'inclut dans son flux fiscal Option B (POS finalize). Aucun retry nécessaire ; idempotency cle protège le double-tap.

**Carte / TR (deferred)** : commande créée `PENDING / UNPAID`, idempotency key conservée côté store (`SET_IDEMPOTENCY_KEY`). Electron borne pilote le TPE local. Trois issues TPE :

1. **Approuvée** → `POST /frontend/order/{id}/payment-confirm` ; serveur lock + check + set PAID + appelle `finalizePaidKioskOrder` (PENDING→ACCEPT idempotent ; re-check PAID dans le lock — défense en profondeur F-21). Retour 200, écran K09.
2. **Refusée** → pas d'appel `payment-confirm` ; front route `kiosk.error.payment-refused?code=…&order_id=…`. La commande reste `PENDING/UNPAID` jusqu'à cleanup ou retry.
3. **Indéterminée (timeout TPE)** → l'Electron doit re-tenter au moins une lecture ; idempotence côté serveur garantit qu'un succès tardif ne double-paye pas. Si `transaction_id` divergent → 409 (réconciliation manuelle nécessaire).

**Race avec `CleanupStalePendingKioskOrders`** : si le job rejette/annule la commande avant `payment-confirm`, le lock détecte `status != PENDING` → `payment_late_after_cleanup` ActionLog + 422 ; **À CONFIRMER** : prévoir un délai de garde (job ne touche que ordres > N min, N ≥ 2× timeout TPE max).

**Alignement M-06** : payment-confirm reste **idempotent**, ne dispose pas de `fiscal_sequence_no` (Option B M-08), n'écrit pas dans le journal Z. La fiscalisation finale est strictement POS-side.

**Risque résiduel** : double-paiement si Electron rejoue contre un autre ID (ex. user reset borne entre tentatives). Mitigation : guard duplicate `transaction_id` global (déjà présent), ActionLog systématique, alerte si > 1 collision/h.

## 5. Offline & résilience

- **Cache menu** : `kioskMenuCache` (LocalForage / IDB) avec TTL ≤ 4 h ; au-delà, l'order continue mais avec note « server SSOT recalculera » (déjà loggé).
- **Queue locale** : `kioskOfflineQueueDb` (IndexedDB), entries `{localKey, payload, idempotencyKey, branchId, savedAt}`. `localKey = offline_<ts>_<rand>` — préfixe **réservé** (regex de garde `requireOrderRef`).
- **Replay** : `syncQueue(postFn)` rejoue séquentiellement avec `X-Idempotency-Key` d'origine ; en cas d'erreur 5xx, retry exponentiel ; 4xx → quarantaine + UI conflict modal (`KioskOfflineConflictModalComponent`).
- **Paiement hors-ligne** : **interdit** pour CB/TR (gate `GATE_OFFLINE_SCOPE_V1`, FK-030/FK-044/FK-095/FK-099). Pour cash : **À CONFIRMER** par décision humaine (FK-095/099).
- **UI dégradée** : bandeau persistent, désactivation upsell réseau-dépendant, désactivation loyalty scan, désactivation promo validate (lecture serveur indispensable).
- **Reprise** : à la reconnexion, déclencher `startAutoSync` puis afficher progression « N commandes en synchronisation » ; ne pas bloquer K01.
- **Cleanup** : `CleanupStalePendingKioskOrders` ne doit jamais purger des commandes dont l'`idempotency_key` figure encore dans la queue locale active — **À CONFIRMER** mécanisme.

## 6. Intersection avec la caisse (POINTS DE CENTRALISATION)

La caisse (POS) doit être **co-présente** sur les commandes kiosk de la même `branch_id`. Points de centralisation explicites :

- **`branch_id` unique** : injecté serveur depuis `KioskMachine::user_id`. POS scope ses listes via `BranchScope`. Toute fuite `branch_id` côté client = défaut sécurité.
- **Visibilité POS** : la caisse doit voir, via Echo `branch.{id}.orders` :
  - les **commandes kiosk PENDING/UNPAID en cours de paiement** (badge « en attente TPE »),
  - les **commandes kiosk ACCEPT** déjà payées (badge type paiement),
  - les **commandes kiosk en file d'encaissement cash** (badge « à encaisser »),
  - le **numéro de file** (`queue_number`), le **total**, le **statut paiement**, l'**heure** de création/ACCEPT.
- **Source de vérité du total** : **backend, point.** Le POS lit `FrontendOrder.total` via API ; il ne recalcule pas le total kiosk côté client. Toute correction (remise manuelle caissier) passe par mutation POS authentifiée + audit (`OrderStateMachine::apply`).
- **Statuts** : seul POS (ou KDS) peut transitionner `ACCEPT → PREPARING/DELIVERED`. Le kiosk **ne peut jamais** déclencher PREPARING (cf. `DEVICE_FLOW.md` §3).
- **Fiscal Option B (M-08)** : `finalizePaidKioskOrder` ne pose **pas** de `fiscal_sequence_no`, ne ferme pas de Z. La fiscalisation se fait quand POS « finalise » (cash encaissé physiquement, ou clôture journée). Invariant à protéger absolument.
- **Cash kiosk** : entrée fiscale comptée côté POS (POS finalize). La commande arrive ACCEPT, POS l'encaisse → fiscal sequence allouée à ce moment-là.
- **Synchronisation temps réel (M-17)** : Echo (Pusher / WS) est le canal documenté. Pas de Firebase pour le flux caisse temps réel. Tout nouvel event kiosk → POS doit transiter par outbox `DomainEvent` + `DispatchDomainEventsJob` pour rester ordonné et rejouable.
- **Ce que la caisse doit voir au minimum** : `queue_number`, `order_serial_no`, `payment_status` (UNPAID/PAID), `payment_method` (cash/card/tr), `status`, `total`, `created_at`, `order_type` (sur place / à emporter), un flag `_origin=kiosk`.
- **Ce que la caisse ne doit pas pouvoir** : modifier le pricing kiosk sans audit ; faire un payment-confirm pour un kiosk (route Sanctum machine-only via `KioskMachine::user_id`).

## 7. Checklist d'audit 360° Kiosk

- [ ] Auto-login machine fonctionne avec config rendue serveur (pas d'exposition credentials côté JS).
- [ ] Token Sanctum jamais persisté hors `vuex-persistedstate` chiffré.
- [ ] `requireKioskAuth` couvre **toutes** les routes enfants de `/kiosk` (sauf login).
- [ ] `requireCart`, `requireOrderRef`, `requireConfirmationContext` actifs sur leurs routes respectives.
- [ ] `kiosk.products/:categoryId` redirige bien vers `kiosk.categories?cat=`.
- [ ] Wizard rejette `variation.id` cross-item (test 422).
- [ ] `myOrderStore` unset systématiquement `total/subtotal/discount` du payload client.
- [ ] PricingService recalcule (`forKiosk`) ; flag `pricing.use_ssot_service` activé.
- [ ] Idempotency : `(branch_id, idempotency_key)` unique en DB ; cache lock 10 s avec `block(5)`.
- [ ] Idempotency clé persistante dans `kioskCart.idempotencyKey` (resync offline).
- [ ] CASH kiosk → auto-accept ACCEPT, fiscal délégué POS.
- [ ] CARD/TR kiosk → PENDING/UNPAID jusqu'à `payment-confirm`.
- [ ] `payment-confirm` : 401 si non authentifié, 403 si KioskMachine ≠ user, 403 si branch ≠ machine branch, 409 si `transaction_id` collision, 422 si statut non PENDING, 200 idempotent si déjà PAID même tx.
- [ ] `finalizePaidKioskOrder` re-check PAID dans le lock (F-21).
- [ ] OrderStateMachine::recordTransition appelé après promotion (audit trail).
- [ ] Echo channels POS reçoivent `OrderCreated` + `OrderStatusChanged` après commit.
- [ ] OSS reçoit `queue_number` en PREPARING / PREPARED.
- [ ] Guard `requireOrderRef` rejette `undefined`/`null` (no infinite poll).
- [ ] Confirmation préserve `total = 0` (parseFloat).
- [ ] CB/TR offline impossibles (UI désactivée + serveur n'aurait pas reçu de toute façon).
- [ ] `localKey=offline_…` jamais envoyé à `/frontend/order/show/{id}`.
- [ ] Cleanup stale orders ne purge pas les ordres dont l'idempotency est encore active en queue locale.
- [ ] Playwright happy paths : cash, card, refus card, network drop, menu unavailable, product removed mi-panier, double-tap, retour idle inactivité.
- [ ] ActionLog systématique : payment confirmé, payment_late_after_cleanup, payment_confirm_invalid_status, idempotency collision payload divergent.
- [ ] Aucune `branch_id` envoyée par le client (write paths).
- [ ] Aucun champ `discount` accepté du client.
- [ ] Throttle `kiosk-orders` actif sur store + quote.
- [ ] Throttle `kiosk-menu`, `kiosk:order` ability sur events.
- [ ] Test sentinel : `MyOrderStoreIdempotencyTest`, `KioskOfflineIdPrefixSentinelTest`, `KioskCbTrOfflineRefusedSentinelTest`.
- [ ] Mode maintenance (`kiosk_maintenance_mode`) suspend l'auto-login (sessionStorage).
- [ ] OSS / POS rebranchés sur Echo (pas Firebase) pour le flux temps réel.

## 8. Plan découpé pour Codex (lots K-01…)

> Chaque lot contient : **Objectif**, **Allowlist indicative**, **Tests à exécuter / créer**, **Dépendances**, **Difficulté**. Allowlist = chemins fichiers que Codex peut toucher pour ce lot, hors gates frozen.

### K-01 — Hygiène routing kiosk + legacy redirect — [EASY]
- **Objectif** : verrouiller le redirect `kiosk.products/:categoryId` → `kiosk.categories?cat=`, ajouter telemetry `legacy_route_hit`.
- **Allowlist** : `resources/js/router/modules/kioskRoutes.js`, `resources/js/helpers/kioskAnalytics.js`.
- **Tests** : Vitest router unit, Playwright `kiosk-legacy-redirect.spec.js`.
- **Dépendances** : aucune.

### K-02 — Choix order_type explicite — [MEDIUM]
- **Objectif** : exiger sélection sur place / à emporter avant K03 ou K07 ; bloquer `submitOrder` si absent.
- **Allowlist** : `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue`, `KioskCategoriesComponent.vue`, `resources/js/store/modules/kioskCart.js`.
- **Tests** : Vitest store (mutation forcée), Playwright (parcours sans choix → bloqué).
- **Dépendances** : K-01.

### K-03 — Pin pricing par quote opposable — [MEDIUM]
- **Objectif** : appeler `POST /frontend/order/quote` à la sortie de K07, transmettre `quote_token`/`quote_signature` à `submitOrder`. Aligne M-05 + GATE_PAYMENT_PROP_MUTATION.
- **Allowlist** : `resources/js/store/modules/kioskCart.js`, `resources/js/components/frontend/kiosk/KioskCartComponent.vue`, `app/Services/Order/OrderQuoteService.php` (lecture only).
- **Tests** : Feature `KioskQuoteIntegrityTest`, Playwright kiosk-quote-pin.
- **Dépendances** : K-02.

### K-04 — UX paiement clair (cash / card / tr) + désactivation offline — [HARD]
- **Objectif** : refondre `KioskPaymentComponent` ; getter `paymentMethodsAvailable` ; désactivation CB/TR offline (FK-030/044).
- **Allowlist** : `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`, `resources/js/store/modules/kioskCart.js`, `resources/js/helpers/kioskOfflineQueue.js`.
- **Tests** : Playwright `kiosk-offline-cb-refused.spec.js` (sentinel FK-030), Vitest store.
- **Dépendances** : K-03.

### K-05 — payment-confirm robustesse + event WS — [HARD]
- **Objectif** : émettre WS `kiosk.payment.confirmed` après `finalizePaidKioskOrder` ; documenter invariant fiscal Option B (M-08) ; logger collisions payload divergent.
- **Allowlist** : `app/Http/Controllers/Frontend/OrderController.php`, `app/Services/FrontendOrderService.php` (chemin `finalizePaidKioskOrder` est gated F-21 — coordination requise), `app/Events/*`.
- **Tests** : `PaymentConfirmIdempotencyTest` étendu, Echo broadcasting test.
- **Dépendances** : K-04. **Gate** : GATE_FROZEN_F21_FINALIZE_PAID_KIOSK_2026-04-23 — lecture obligatoire avant toute modif.

### K-06 — Guard waiting offline_ + UX synchro — [MEDIUM]
- **Objectif** : si `orderId` matches `^offline_`, basculer écran « commande en attente de synchro » sans polling. Aligne FK-053.
- **Allowlist** : `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`, `resources/js/router/modules/kioskRoutes.js`, `resources/js/helpers/kioskOfflineQueue.js`.
- **Tests** : Playwright kiosk-offline-waiting, Vitest router guard.
- **Dépendances** : K-04.

### K-07 — Unification wizard (POS↔Kiosk) — [HARD]
- **Objectif** : converger `KioskWizardComponent` et `KioskPosWizardComponent`. Élimine la dérive double-impl. Aligne M-05.
- **Allowlist** : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`, `KioskPosWizardComponent.vue`, `resources/js/components/frontend/kiosk/steps/*`, `resources/js/helpers/kioskPricing*.js`.
- **Tests** : Vitest wizard (matrice variations + extras + multi-quantity), Playwright sandwich/tacos.
- **Dépendances** : K-03.

### K-08 — Erreurs globales centralisées — [MEDIUM]
- **Objectif** : helper unique `goToKioskError(code, payload)` ; harmoniser CTAs.
- **Allowlist** : `resources/js/components/frontend/kiosk/KioskError*Component.vue`, `resources/js/helpers/kioskAnalytics.js`, `resources/js/store/modules/kioskCart.js`.
- **Tests** : Vitest helper, Playwright erreurs (4 écrans).
- **Dépendances** : K-04.

### K-09 — POS visibilité kiosk en temps réel (M-17) — [HARD]
- **Objectif** : garantir Echo broadcast vers `branch.{id}.orders` pour chaque transition kiosk (création PENDING, ACCEPT, paiement). Inclure flag `_origin=kiosk`, `payment_method`, `queue_number`.
- **Allowlist** : `app/Events/OrderCreated.php`, `app/Events/OrderStatusChanged.php` (lecture uniquement si frozen — sinon proposer wrapper), `app/Http/Resources/OrderResource.php`, `resources/js/store/modules/posOrders.js` (**À CONFIRMER** path exact).
- **Tests** : Feature broadcasting test, Playwright POS reçoit kiosk en temps réel.
- **Dépendances** : K-05.

### K-10 — Cleanup stale + idempotency safety — [HARD]
- **Objectif** : `CleanupStalePendingKioskOrders` doit ignorer les ordres dont l'idempotency_key est encore active en queue offline. Délai minimum ≥ 2× timeout TPE max.
- **Allowlist** : `app/Jobs/CleanupStalePendingKioskOrders.php`, `app/Models/FrontendOrder.php` (lecture), `config/kiosk.php`.
- **Tests** : Feature `CleanupStalePendingKioskOrdersTest`, scénario race TPE/cleanup.
- **Dépendances** : K-05.

### K-11 — Confirmation print fallback + retour idle — [EASY]
- **Objectif** : retry imprimante 1× si OFF, ne jamais bloquer le retour à `kiosk.idle`.
- **Allowlist** : `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue`, `resources/js/helpers/kioskPrinter.js`, `resources/js/helpers/kioskReceiptPersistence.js`.
- **Tests** : Vitest printer mock, Playwright printer-off.
- **Dépendances** : K-04.

### K-12 — Loyalty refus explicite (raison) — [MEDIUM]
- **Objectif** : exposer `loyalty_refusal_reason` dans `OrderDetailsResource` ; UI toast clair.
- **Allowlist** : `app/Http/Resources/OrderDetailsResource.php`, `app/Services/FrontendOrderService.php` (lecture / extension non-frozen path), `resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue`, `resources/js/components/frontend/kiosk/KioskCartComponent.vue`.
- **Tests** : Feature `LoyaltyRefusalReasonTest`, Playwright loyalty-refused.
- **Dépendances** : K-03.

### K-13 — Sentinel idempotency collision — [MEDIUM]
- **Objectif** : ActionLog `idempotency_collision_divergent_payload` lorsqu'une même clé arrive avec un items hash différent.
- **Allowlist** : `app/Services/FrontendOrderService.php` (extension du chemin idempotency), `app/Models/ActionLog.php` (lecture).
- **Tests** : Feature `IdempotencyCollisionDivergentPayloadTest`.
- **Dépendances** : K-05.

### K-14 — Telemetry kiosk_event homogène — [EASY]
- **Objectif** : événements UX (idle_wake, category_view, wizard_open, cart_view, payment_select, payment_success, payment_refused) avec schéma stable.
- **Allowlist** : `resources/js/helpers/kioskAnalytics.js`, `app/Http/Controllers/Frontend/KioskEventController.php` (lecture / validation schéma).
- **Tests** : Vitest analytics + Feature `KioskEventStoreSchemaTest`.
- **Dépendances** : K-08.

## 9. Exigences d'audit en cascade pour Codex

Après **chaque** lot, Codex doit produire :

1. **Diff propre** : un seul commit ou patch série borné à l'allowlist du lot. Toute extension d'allowlist requiert validation Claude (pas d'« opportunisme »).
2. **Preuves automatisées** :
   - `php artisan test --filter=<suite_du_lot>` vert ;
   - `npm run test:vitest -- <ciblé>` vert ;
   - `npx playwright test <spec_du_lot>` vert ;
   - lint PHP (`pint`) + ESLint sans nouvel avertissement sur fichiers touchés.
3. **Preuves visuelles** (UI) : screenshots Playwright golden path + écrans d'erreur. Sortie dans `reports/antigravity/kiosk_K-XX_<date>/`.
4. **Preuves backend** :
   - log `OrderCreated` / `OrderStatusChanged` outbox observé ;
   - vérification `order_status_transitions` row produite si transition (table dédiée) ;
   - ActionLog si lot touche payment-confirm.
5. **Vérifications d'invariants** :
   - aucun `branch_id`/`total`/`discount` accepté côté write client (grep + diff) ;
   - `OrderStateMachine::allows()` non bypassé ;
   - aucune route nouvelle hors Sanctum + ability appropriée ;
   - aucun call Firebase ajouté sur le chemin caisse temps réel.
6. **Rapport JSON** dans `reports/execution/kiosk/K-XX.json` : `{lot, status, evidence_paths[], invariants_verified[], risks[], next_step}`.
7. **Décision Claude** après preuves : `continue` / `heal` / `block` / `escalate` / `human` (cf. CLAUDE.md §8). Pas plus de **3 healings** sur le même lot sans escalade.
8. **Anti-régression** : Codex ne doit jamais :
   - modifier un fichier marqué `GATE_FROZEN_*` sans gate humain ;
   - introduire un nouveau path d'écriture order qui ne passe pas par PricingService ;
   - shipper une UI sans preuve Playwright ;
   - utiliser `--no-verify` ou bypasser un hook.
9. **Cohérence inter-lots** : à chaque lot, relire le tableau §2 et §6 pour vérifier qu'aucune route/composant/API n'a dérivé sans mise à jour de ce document.
10. **Surface d'attaque** : à chaque PR, refaire mentalement le tableau « Abus » de la section §3 sur les fichiers touchés.

## 10. Prochaines 5 actions immédiates

1. **Référencer ce document** (`reports/audit/CLAUDE_KIOSK_ORDER_FLOW_MASTER_PLAN_2026-04-26.md`) depuis `missions/*/execute_brief.md` / `MASTERPLAY` et, si le produit l’exige, un pointeur court dans `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` (SSOT parcours borne Wave B).
2. **Décision humaine GATE_OFFLINE_SCOPE_V1** (FK-095, FK-099) : valider explicitement le scope cash-only offline (oui/non), conditionnant K-04 et K-06.
3. **Lancer K-01** (legacy redirect + telemetry) immédiatement — risque nul, débroussaille routing avant les lots lourds.
4. **Préparer K-03** (quote opposable) en parallèle : Codex lit `OrderQuoteService` et `PricingRequest::forKiosk` pour proposer une signature contract claire avant implémentation côté store.
5. **Pré-rédiger les Playwright sentinels** Wave B (`kiosk-offline-cb-refused`, `kiosk-offline-id-prefix`, `payment-confirm-idempotent`, `payment-late-after-cleanup`) — Codex les exécute et y connecte ses lots K-04 / K-05 / K-06 / K-10. Sans ces sentinels verts, la Vague 2 ne peut pas être déclarée terminée.

**À CONFIRMER** (questions ouvertes, à trancher avant d'engager les lots concernés) :
- K02 : écran dédié sur place / à emporter, ou modale dans idle / catégories ?
- K06 : `LoyaltyService::scan` expose-t-il déjà un code de raison de refus normalisé ?
- KO : décision finale cash-only vs all-offline-blocked (gate humain).
- K-09 : path exact du store Vuex POS pour les listes orders kiosk.
- K-10 : valeur réelle du timeout TPE max retenu côté borne Electron (impact délai cleanup).
