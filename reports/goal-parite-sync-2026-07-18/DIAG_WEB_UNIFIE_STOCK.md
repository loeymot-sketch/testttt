# DIAG — Web unifié + Stock caisse/KDS (cartographie READ-ONLY)

> Date 2026-07-18. Repo `testttt` (backend V1 LOCAL Le Cayenne). Aucune ligne
> modifiée. DB `foodking_e2e` interrogée en lecture seule (tinker). Objectif :
> ancrer l'état ACTUEL des 2 chantiers pour planification archi. EXISTE / PARTIEL /
> MANQUE + `file:line` par maillon. **Aucune proposition de code ici.**

Légende enums (référence) : OrderStatus PENDING=1 ACCEPT=4 PREPARING=7 PREPARED=8
DELIVERED=13 CANCELED=16 REJECTED=19 RETURNED=22 · PaymentStatus PAID=5 UNPAID=10
PENDING_COUNTER=15 REFUNDED=20 · OrderType DELIVERY=5 TAKEAWAY=10 POS=15
DINING_TABLE=20 KIOSK=25 · PosPaymentMethod COUNTER_DEFERRED=6.

---

## CHANTIER 1 — Commandes web pleinement intégrées

Flux cible : **web → caisse → cuisine (KDS) → OSS (mur client) → compte client / notif**

### Maillon 0 — Création commande web (fondation)  ✅ EXISTE
- Le site utilise le **même endpoint que la borne** : `POST /api/frontend/order`
  → `FrontendOrderController::store` (`routes/api.php:1395`) → `FrontendOrderService::myOrderStore`.
- Distinction web vs borne : `FrontendOrderService.php:589-590`
  (`source_surface = $isKioskMachineOrder ? 'kiosk' : 'web'`).
- Web envoie `order_type=TAKEAWAY(10)` (confirmé `FrontendOrderService.php:585`,
  allowlist `209-216` KIOSK|TAKEAWAY sinon forcé KIOSK).
- **Un n° de file EST alloué** (`saveFrontendOrderWithQueueNumber`
  `FrontendOrderService.php:544` + `1050-1082`, allocateur A#### FIFO partagé borne).
- Carte en ligne OFF (mandat owner) → commande créée **PENDING / UNPAID** (règlement comptoir).
- Runtime : **72 commandes `source_surface='web'`** en base (dont status=7 PREPARING pay=5,
  status=4 ACCEPT, status=1 PENDING, + résidus test REJECTED) → pipeline réellement exercé.

### Maillon 1 — CAISSE  🟨 PARTIEL (asymétrie confirmée)
- ✅ **Visibilité PENDING** : `GET /api/admin/pos/web-orders/pending`
  (`routes/api.php:882-896`, gate `can('pos')`, branch-scope, FIFO, cap 200) → filtre
  `source_surface='web'` + `status=PENDING`. Affiché dans le panneau « Commandes web »
  `PosComponent.vue:453-498`, chargé par `loadWebOrders` (`PosComponent.vue:3600-3612`)
  dans le tick de polling unifié 15-60s (`PosComponent.vue:3225`, cadence `3201`).
- 🟨 **ASYMÉTRIE (le point noté par l'owner)** : `openWebOrder`
  (`PosComponent.vue:3616-3622`) ne gère PAS inline → `$router.push('admin.order.show')`
  (redirige vers la page **/admin/online-orders**). L'accept/préparation/encaissement
  se fait sur la surface online-orders, **pas inline dans le POS**. À l'inverse, les
  commandes borne cash sont gérées **inline** dans le panneau « Commandes borne — à
  encaisser » (`confirmCounterPayment`, `PosComponent.vue:1443+`).
- ✅ **Encaissement INLINE APRÈS accept** : à l'accept d'une web TAKEAWAY COD,
  `OnlineOrderController::changeStatus` bascule `PENDING_COUNTER` +
  `pos_payment_method=COUNTER_DEFERRED` (`OnlineOrderController.php:162-184`, heals
  SYNC-WEB-KDS-01 + P1-3). Elle rejoint alors la file unifiée
  `GET /api/admin/pos/counter-collect/pending` (clause web `routes/api.php:839-849`) →
  **encaissable inline** dans le panneau « Commandes borne » via `confirmCounterPayment`.
- 🟥 **GAP** : le caissier ne voit PAS *toutes* les commandes web en cours dans **une
  seule vue inline**. PENDING = panneau read-only → redirection online-orders ;
  ACCEPTÉE COD = panneau « borne » (mal nommé pour du web) ; l'**action Accepter**
  n'existe QUE sur `/admin/online-orders` (`OnlineOrderListComponent.vue`), pas dans le POS.
  Il n'y a pas de cycle de vie « commande web » unifié géré depuis la caisse.

### Maillon 2 — KDS (écran cuisine)  ✅ EXISTE (déjà prouvé) · 🟨 note lane
- ✅ Une web acceptée (PENDING_COUNTER) est **board-released**
  (`KitchenReleaseRule::applyBoardReleaseFilter` admet PAID|PENDING_COUNTER|POS-cash,
  appelé partout côté cuisine) → arrive au board comme une borne. Broadcast statut sur
  `private-branch.{branch_id}` (`PersistOrderStatusChangedToOutbox.php:53-54`).
- 🟨 **Note classification** : layout legacy 4 colonnes Dine-in / Online / Takeaway /
  Kiosk (`KitchenDisplaySystemComponent.vue:52`). La web (`order_type=TAKEAWAY`,
  `source_surface='web'`) tombe vraisemblablement dans la colonne **Takeaway**, pas dans
  une lane « Online/Web » dédiée (pas un blocage fonctionnel, une nuance UX). Le grid V2
  est mono-FIFO sans lanes (`KitchenDisplaySystemComponent.vue:55-69`).

### Maillon 3 — OSS (mur de suivi client)  ✅ EXISTE (complet pour web takeaway)
- `OrderStatusScreenOrderService::list` (`:37`) + `listForBranch` (`:213`, corps byte-identique).
- Allowlist `order_type IN [KIOSK, TAKEAWAY]` (`:59-62`) → **web TAKEAWAY qualifie**.
- Exige `status IN [PREPARING, PREPARED]` (`:63`) + board-release (`:71`) + identifiant
  visible `queue_number` OU `token` (`:133-139`) → web a un n° de file → **s'affiche**.
- Tri **FIFO déterministe** `queue_number, id` (`:151`) → « en ordre avec les autres ».
- Web DELIVERY exclue par design (allowlist KIOSK/TAKEAWAY) → correct (pas de retrait comptoir).
- **Conclusion : maillon OSS COMPLET** pour une web à emporter (une fois acceptée + PREPARING).

### Maillon 4 — Compte client / notification  🟨 PARTIEL → 🟥 notif « prête »
- ✅ **Suivi par le compte (polling)** : `GET /api/frontend/order` (liste mes commandes,
  `FrontendOrderController::index:38` → `myOrder`) + `GET /api/frontend/order/show/{id}`
  (`show:72`), tous deux sous `auth:sanctum` (`routes/api.php:1385`). Renvoient
  `OrderDetailsResource` avec `status` → le compte client **peut** voir « en cours → prête »
  **en pollant**.
- 🟨 **Notif au passage PRÊTE (dispatch existe)** : sur changement de statut (caisse/KDS →
  PREPARED), `OrderService::changeStatus` émet `SendOrderMail/Sms/Push`
  (`OrderService.php:2144-2146` et `2233-2234`) + `OrderStatusChanged` →
  `SendFcmOnOrderStatusChange` construit un push client topic `customer_order_{orderId}`
  « Votre commande est prête ! » (`SendFcmOnOrderStatusChange.php:103-120`).
- 🟥 **GAP — la notif n'atteint PAS le client web** :
  1. **SMS** gaté sur gateway SMS configuré (`OrderSmsNotificationBuilder.php:78`
     `$smsService->gateway() && ...->status()`) + `notification_alert.sms == ON` (`:89+`).
     Clés SMS non configurées en V1 LOCAL (cf. mémoire) → **no-op**.
  2. **Push FCM** vers topic `customer_order_{orderId}` : le site web standalone n'est
     **pas** câblé au SDK FCM web / abonnement topic (repo séparé, no API wireup) → le
     navigateur client n'est pas abonné → **n'arrive pas**. Commentaire assumé
     `SendFcmOnOrderStatusChange.php:112-113` (« if we had device token / in future »).
  3. **Broadcast temps-réel** : `OrderStatusChanged` diffuse UNIQUEMENT sur
     `private-branch.{branch_id}` (canal **staff**, `PersistOrderStatusChangedToOutbox.php:53`)
     — **aucun canal client** (pas de `private-customer.{id}` ni `private-order.{id}`) → le
     compte client ne reçoit **pas** de push temps-réel « prête » ; dépend du polling.
- 🟥 **MANQUE** : une notif « prête » fiable côté client web (ni SMS provider, ni web-push
  abonné, ni canal broadcast client). Seul mécanisme réellement fonctionnel aujourd'hui =
  **polling** de `/api/frontend/order(/show)`. Le Mail peut fonctionner si SMTP configuré.

### Tableau récap Chantier 1
| Maillon | État | Ancrage |
|---|---|---|
| Création web (source_surface, order_type, queue_number) | ✅ EXISTE | `FrontendOrderService.php:544,589-590,1050-1082` |
| Caisse — visibilité PENDING (panneau read-only) | ✅ EXISTE | `routes/api.php:882-896` ; `PosComponent.vue:453-498,3600` |
| Caisse — gestion INLINE PENDING (accept) | 🟥 MANQUE (redirige online-orders) | `PosComponent.vue:3616-3622` |
| Caisse — encaissement INLINE après accept (COD) | ✅ EXISTE | `OnlineOrderController.php:162-184` ; `routes/api.php:839-849` |
| Caisse — vue unifiée « toutes web en cours » | 🟥 MANQUE | (aucune ; accept sur `/admin/online-orders`) |
| KDS — board-release web acceptée | ✅ EXISTE | `KitchenReleaseRule::applyBoardReleaseFilter` ; `OnlineOrderController.php:139-166` |
| KDS — lane dédiée « web/online » | 🟨 PARTIEL (tombe en Takeaway) | `KitchenDisplaySystemComponent.vue:52` |
| OSS — web takeaway FIFO avec les autres | ✅ EXISTE | `OrderStatusScreenOrderService.php:59-63,133-151` |
| Client — suivi statut par compte (polling) | ✅ EXISTE | `FrontendOrderController.php:38,72` ; `routes/api.php:1385` |
| Client — notif « prête » (dispatch) | 🟨 PARTIEL | `OrderService.php:2144-2146,2233-2234` ; `SendFcmOnOrderStatusChange.php:103-120` |
| Client — notif « prête » qui ARRIVE (web) | 🟥 MANQUE (SMS off, pas de web-push, pas de canal client) | `OrderSmsNotificationBuilder.php:78` ; `PersistOrderStatusChangedToOutbox.php:53` |

### Gaps précis Chantier 1 (à combler — non résolus ici)
1. **Gestion inline caisse des web PENDING** : accepter/rejeter une commande web sans
   quitter le POS (aujourd'hui redirection `/admin/online-orders`).
2. **Vue caisse unifiée du cycle de vie web** (PENDING → acceptée → à encaisser → prête).
3. **Notif client « prête » fonctionnelle sur le web** : au choix (a) canal broadcast
   client (`private-order.{id}` / `private-customer.{id}`) que le site standalone écoute,
   (b) web-push abonné, ou (c) fallback documenté sur polling + Mail SMTP. Actuellement
   AUCUN chemin push client ne fonctionne pour le web.
4. (Mineur UX) lane KDS « Online/Web » distincte du Takeaway borne si souhaité.

---

## CHANTIER 2 — Gestion stock/rupture depuis CAISSE + KDS

### Permission  ✅ EXISTE (runtime confirmé)
- Permission dédiée `availability_toggle` seedée sur guards **sanctum + web**, accordée à
  Admin, Branch Manager, **POS Operator**, **Chef** (`AvailabilityTogglePermissionSeeder.php:39`,
  `updateOrCreate` convergent heal W6). Enregistrée `DatabaseSeeder.php:55`.
- **Runtime** (`foodking_e2e`) : `POS Operator[sanctum] availability_toggle=YES, pos=YES,
  online-orders=YES` · `Chef[sanctum] availability_toggle=YES`.
- 🟨 **CAVEAT runtime** : dans `foodking_e2e`, les rôles n'existent **que sur le guard
  sanctum** (lookup guard `web` = null pour POS Operator/Chef). Les routes `/api/admin/*`
  sont en `auth:sanctum` → OK pour l'API. Mais la résolution de permission via cookie de
  session SPA peut passer par le guard `web` → **à vérifier sur la DB prod** que les rôles
  guard-web portent bien la permission (le seeder le fait SI le rôle guard-web existe).

### Endpoints backend  ✅ EXISTE
- `POST /api/admin/menu/availability/toggle` → `AvailabilityController::toggle`
  (`routes/api.php:280-281`), gate `permission:items_edit|availability_toggle`
  (`AvailabilityController.php:24`), throttle dédié `menu-availability` 60/min.
- Délègue à `AvailabilityService::toggle` → événement `ItemAvailabilityChanged` → outbox +
  invalidation cache kiosk-menu → **borne / caisse / web** en temps réel.
- Également : `toggleExtra` / `toggleVariation` (`routes/api.php:287-290`),
  `showBranchAvailability` (`:315-317`), `setMaxDailyQty` (items_edit only, `:310`).

### Caisse (POS) — UI  ✅ EXISTE (complet)
- Panneau partagé `AvailabilityTogglePanel.vue` monté dans `PosComponent.vue:1407`
  (import `:1719`, register `:1807`).
- Bouton « Rupture » 🚫 `PosComponent.vue:253-263`, gaté `canToggleAvailability`
  (`:2119-2126` : perm `availability_toggle` OU `items_edit`, matcher sur `p.name`).
- Le panneau liste les items, marque rupture / réactive → action store
  `itemAvailability/toggle` → endpoint toggle. MAJ tuiles POS temps-réel via le listener
  Stock86 existant (`ItemAvailabilityChanged`).

### KDS — UI  ✅ EXISTE (complet)
- Même panneau partagé monté `KitchenDisplaySystemComponent.vue:44` (import `:1153`,
  register `:1169`).
- Bouton « Rupture » `KitchenDisplaySystemComponent.vue:15-20`, gaté `canToggleAvailability`
  (`:1316-1322`, même logique perm que POS).

### Propagation temps-réel  ✅ EXISTE
- `AvailabilityService::toggle` → `ItemAvailabilityChanged` →
  `PersistItemAvailabilityChangedToOutbox` + `InvalidateKioskMenuCacheOnItemAvailabilityChanged`
  → borne / caisse / web se mettent à jour. Tuiles POS/KDS via listener Stock86.

### Tableau récap Chantier 2
| Maillon | État | Ancrage |
|---|---|---|
| Permission `availability_toggle` (seed + rôles) | ✅ EXISTE | `AvailabilityTogglePermissionSeeder.php:31-53` ; `DatabaseSeeder.php:55` |
| Permission accordée POS Operator + Chef (runtime) | ✅ EXISTE (sanctum) | tinker : POS Operator/Chef = YES |
| Parité guard `web` (session SPA) | 🟨 À VÉRIFIER prod | rôles guard-web absents en e2e |
| Endpoint toggle (gate items_edit\|availability_toggle) | ✅ EXISTE | `routes/api.php:280-281` ; `AvailabilityController.php:24-30` |
| Bouton + panneau CAISSE | ✅ EXISTE | `PosComponent.vue:253-263,1407,2119-2126` |
| Bouton + panneau KDS | ✅ EXISTE | `KitchenDisplaySystemComponent.vue:15-20,44,1316-1322` |
| Rupture ON/OFF item temps-réel borne/caisse/web | ✅ EXISTE | `AvailabilityService::toggle` → `ItemAvailabilityChanged` → outbox/cache |
| Rupture 86 sur EXTRA / VARIATION depuis panneau | 🟥 MANQUE UI (backend OK) | endpoints `:287-290` ; panneau ne toggle que l'item (`AvailabilityTogglePanel.vue:138-159`) |

### Gaps précis Chantier 2 (à combler — non résolus ici)
1. **Vérifier la parité permission sur le guard `web` en prod** (le e2e n'a les rôles que
   sur sanctum ; risque de 403 sur résolution session SPA si le rôle guard-web n'a pas
   `availability_toggle`).
2. (Optionnel owner) **86 au niveau extra/variation depuis le panneau caisse/KDS** :
   backend prêt (`toggleExtra`/`toggleVariation`) mais le panneau partagé n'expose que le
   toggle **item** (`AvailabilityTogglePanel.vue`). MANQUE = surface UI uniquement.
3. Sinon, l'exigence owner « accès vraiment caissier » + « depuis l'écran cuisine » est
   **essentiellement COMPLÈTE** : permission accordée, boutons visibles+gatés, endpoint
   partagé, propagation temps-réel câblée.

---

## Synthèse orientée planification
- **Chantier 2** (stock caisse/KDS) : quasi terminé. Reste = vérif guard-web prod (P2) +
  éventuel 86 extra/variation dans le panneau (feature, backend déjà là).
- **Chantier 1** (web unifié) : fondation + OSS + KDS + suivi-par-polling **solides**. Les
  3 vrais manques sont **(a)** gestion/accept **inline** des web en caisse (asymétrie
  actuelle → redirection online-orders), **(b)** une vue caisse unifiée du cycle web, et
  **(c)** une **notif client « prête » qui arrive réellement** sur le web (aujourd'hui SMS
  off + pas de web-push + pas de canal broadcast client → polling seulement).
