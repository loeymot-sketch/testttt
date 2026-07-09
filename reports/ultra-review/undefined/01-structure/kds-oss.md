# Cartographie — Système KDS + OSS (écran cuisine + écran statut client)

> Vague 01-structure — lecteur read-only, session 2026-07-02.
> Tout file:line cité a été lu via Read/Grep/ls dans cette session (CLAUDE.md §3ter).

## 1. Rôle du système

- **KDS** (`/admin/kitchen-display-system`, SPA Vue 2) : le chef voit les commandes « libérées cuisine » du jour (branch-scopées), les fait avancer ACCEPT→PREPARING→PREPARED (bump), peut annuler un bump ≤60s (recall, compensating action NF525-safe), consulte l'historique du jour, et voit un board « items à préparer » agrégé.
- **OSS** (`/admin/order-status-screen`) : mur client TV, 2 colonnes PRÉPARATION / PRÊT (n° de file), poll public sans session + Echo si staff authentifié. Aucune mutation.

## 2. Architecture & fichiers clés

### Backend
| Fichier | Rôle |
|---|---|
| `app/Domain/Kds/KitchenReleaseRule.php` | SSOT « visible sur le board == bumpable ». `visibleStatuses()`=ACCEPT/PREPARING/PREPARED (:16-23), `itemBoardStatuses()`=ACCEPT/PREPARING (:28-34), `canTransition()` échelle stricte ACCEPT→PREPARING→PREPARED (:41-49), release paiement board = PAID ∥ PENDING_COUNTER ∥ (POS && CASH) (`isReleasedForBoard` :100-112) + miroir SQL `applyBoardReleaseFilter` (:130-140). **Le filtrage ne repose PAS sur `kds_station`** (colonne non consultée). |
| `app/Services/KitchenDisplaySystemOrderService.php` | Coeur KDS. `list()` :55-182 (statuts visibles + board-release + branche + fenêtre « aujourd'hui Paris » + advance orders overdue + limit 51→overflow flag :172-177). `changeStatus()` :392-500 (lockForUpdate, optimistic-lock `expected_status`→409 :411-424, garde release board :447-449, transition via KitchenReleaseRule **ET** OrderStateMachine :430-434, notifications post-commit non-bloquantes :477-493). `orderItems()` :505-587 (board items, merge par hash item+variations+extras+addons+instruction+**allergens** :554-575). `historyToday()` :221-250 (PREPARED/OUT/DELIVERED du jour, updated_at desc, cap 50). `recall()` :286-387 (fenêtre 60s, cap N=1→409, statut jamais muté, append `order_status_transitions` reason='kitchen_recall' :344-354, assertion défensive :360-363, broadcast after-commit :377-384). |
| `app/Http/Controllers/Admin/KitchenDisplaySystemController.php` | index/changeStatus(202)/orderItems/historyToday/recall ; middleware `permission:kitchen-display-system` (:23). |
| `app/Services/KdsSyncService.php` (182 l.) | Payload delta du poll fallback : orders `updated_at>=since` + `deleted_ids` (sorties de fenêtre), Cache::remember 5s clé md5(since|includeDeleted) (:46-49), sert `KDSOrderDetailsResource` + `version` (:121-129). |
| `app/Http/Controllers/Admin/KdsSyncController.php` | `GET /api/admin/kds-order/sync?since=ISO` ; admin (branch 0) peut override `?branch_id`, staff cross-branch → 403 (:55-66). |
| `app/Services/OrderStatusScreenOrderService.php` | `list()` :37-153 : allowlist fail-closed `order_type IN (KIOSK, TAKEAWAY)` (:59-62), statuts PREPARING/PREPARED (:63), fenêtre jour Paris + prune `oss.stale_window_hours` (déf. 8h) (:132), FIFO queue_number,id (:144), scope branche via `resolveBranchScope` (staff hors branche → 403 :273-291). `listForBranch()` :206-271 = jumeau public byte-identique. `mostPopularItems()` :169-195 top 9 branch-scopé. |
| `app/Http/Controllers/Admin/OrderStatusScreenController.php` | `index` authed (PosShortcutOrderResource avec total :32), `publicIndex`/`publicMostPopularItems` :80-140 pour mur non authentifié (branch = `?branch_id` sinon 1ʳᵉ branche ACTIVE). |
| `app/Http/Resources/KDSOrderDetailsResource.php` | Carte KDS : expose `payment_pending_counter` (:48 — distinction « à encaisser » vs réglé), `source_surface` (:29 — lane borne), adresse livraison whenLoaded (:60-66), **phone client seulement si DELIVERY** (GDPR :72-75). |
| `app/Http/Resources/KDSOrderItemsResource.php` | Board items : variations/extras/addons résolus depuis `composition_snapshot` SSOT avec fallback legacy (:27-29, :52-89), `allergens_snapshot` exposé (:44). |
| `app/Http/Resources/CDSOrderDetailsResource.php` | Mur client : 6 champs sans PII (id, serial, token, queue_number, order_type, status). |
| `app/Http/Requests/Kds/KdsOrderStatusRequest.php` | authorize = rôles Admin/Branch Manager/Chef/POS Operator/Cashier (:19-20) ; status & expected_status ∈ {4,7,8} (:26-27). `KdsOrderRecallRequest` : même gate, body vide. |
| `app/Events/KdsOrderRecalled.php` | Event `DispatchableAfterCommit` (drop sur rollback). |
| `app/Listeners/PersistKdsOrderRecalledToOutbox.php` | Outbox `domain_events` idempotent sha1(type|order|recalled_at|corrélation) (:37-42), canal `private-branch.{branchId}` (:58), dispatch after-commit + fallback cron + événement d'observabilité si swallow (:69-99). Enregistré dans `EventServiceProvider.php:168-169`. |
| `app/Services/Hardware/KitchenTicketSymbolicFormatter.php` | Jumeau PHP des symboles cuisine (MEAT/SAUCE/CRUDITE tables :22-54, `isMenuItem` regex `\bmenu\s*\(|\bformule\b` :230-233) — parité stricte avec `kdsSymbolic.js`. |

### Routes API (vues)
- `routes/api.php:1191-1218` (groupe admin) : `GET /admin/kds-order`, `POST /admin/kds-order/change-status/{order}` [idempotency + throttle:kds-bump], `GET /items`, `GET /sync`, `GET /history-today` [throttle:60,1], `POST /recall/{order}` [idempotency + throttle:kds-bump].
- `routes/api.php:1239-1242` : `GET /admin/oss-order`, `GET /admin/oss-order/popular-items`.
- `routes/api.php:1311-1316` (groupe frontend public `installed+apiKey+localization`) : `GET /frontend/oss-order` + `/popular-items` [throttle:oss-public].

### Frontend
| Fichier | Rôle |
|---|---|
| `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` (2849 l.) | Orchestrateur. Buckets par lane `_applyOrderBuckets` :2017-2042 (**kiosk par `source_surface==='kiosk'` d'abord**, fallback order_type legacy ; POS+TAKEAWAY même lane :2039-2041). Poll adaptatif `_pollingInterval` 60s WS-up / 5s WS-down (:1892-1900). Echo `subscribeEcho` :1914-1979 (branch staff only ; admin branch 0 = polling ; events OrderStatusChanged filtré {4,7,8} :1564-1575, OrderCreated, OrderPaidAtCounter, ItemAvailabilityChanged, OrderTableChanged, KdsOrderRecalled). Layout V2 défaut ON, kill-switch `?v2=0` / localStorage / `FK_KDS_V2_DEFAULT_ENABLED` (:1249-1281). Bannière erreur persistante (pas toast) :2105-2128 ; overflow >50 flag :2052. Recall TTL 60s local `recallActiveIds` :1215-1227. À-encaisser : `isPaymentPendingCounter` :1684-1687. |
| `KdsV2Grid.vue` (574 l.) | Grille FIFO : `activeOrders` = ACCEPT+PREPARING triés created_at (:197 et suiv., **toutes** les commandes, plus de slice(0,8)), strip « récemment servies » = 4 derniers PREPARED `slice(0,4)` (:236), ticker `now`. KdsUndoToast retiré du chemin V2 (commentaire :89-92) — undo = recall serveur via drawer. |
| `KdsOrderCard.vue` (804 l.) | Carte : rendu items via `renderItemSymbolic` (:181, :394) ; note NON-bloquante « Non encaissé » si `payment_pending_counter===true` (:143-162, :382-387 — owner : la cuisine prépare AVANT encaissement, CTA bump reste actif). Chips source via kdsSource. |
| `KdsOrderLine.vue` | Types de lignes : `symbolic-main` (:29-35), `symbolic-menu` MENU/F (:38-39), supplement, allergen. |
| `KdsHistoryDrawer.vue` (789 l.) | Historique jour (GET history-today) + bouton Rappeler (POST recall, 60s, cap N=1, désactivé pendant requête :183-196). |
| `KdsStatusBanner.vue`, `KdsUndoToast.vue` | Bandeau sync/erreur ; toast undo 3s legacy (V2 ne l'utilise plus). |
| `resources/js/helpers/kdsSymbolic.js` (319 l.) | Symboles cuisine JS : tables viande/sauce/crudités (:37-67), `buildSymbolic` :173-245 (crudités gratuites seulement repliées STO :219, tacos→support G défaut :229-231, addons rôle `menu_*`→MENU sinon frites→F :236-240), `isMenuItem` :109-111 (même regex que PHP), `renderItemSymbolic` :266-319. Ligatures Œ→oe :30-31 (parité PHP). |
| `resources/js/helpers/kdsCustomization.js` (387 l.) | `categorize`, `kdsVariationGroupValue`, `kdsVariationLine`, `sanitizeKdsInstruction` (strip duplication compo/prix), `renderItem`. |
| `resources/js/helpers/kdsSource.js` (79 l.) | Map `source_surface`→chip (pos/admin→POS, kiosk, web/online→ONLINE, uber/uber_eats→UBER vert #06C167 :68). Fallback null→POS (:49-53). |
| `resources/js/services/KdsSyncService.js` (503 l.) | Client poll `/api/admin/kds-order/sync?since=…` (:145), gate anti-régression par `version` (:176-186). |
| `resources/js/services/OssSyncService.js` (471 l.) | Poll OSS : 60s connecté / 2s déconnecté, backoff expo 5s→30s sur 4xx/5xx/réseau (:311-339), clamp cadence [250ms,60s] (:34-35, :437-442), burst-poll sur visibilitychange (:214-252), listeners jamais propagés (`_emit` try/catch :448-467). |
| `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue` (517 l.) | Mur : hydrate PREPARING/PREPARED (:397-414), anim + chime 4 tons sur nouveau PREPARED (chime coupé sur mur public authBranchId≤0 :366), dédup Echo/poll `_echoMarkedReady` (:292-299), wakeLock TV (:208-224), **mur public : override cadence connectée 60s→5s** car aucun push ne l'atteint (:255-271). |
| `resources/js/store/modules/orderStatusScreenOrder.js` | Choix endpoint par `authStatus` : authed→`admin/oss-order`, sinon `frontend/oss-order` (:29-52). |
| `resources/js/store/modules/kds.js` | Bump/recall **par item** local (checklist ligne, fenêtre 60s `bumpTimestamp/recallItem` :44-66) — distinct du recall serveur de commande. |
| `resources/js/router/modules/kitchenDisplaySystemRoutes.js` / `orderStatusScreenRoutes.js` | Routes SPA lazy (`admin-kds`/`admin-oss`), meta `permissionUrl`. NB : `resources/js/routes/orderStatusScreenRoutes.js` (chemin du brief) ABSENT (vérifié) — le vrai chemin est `resources/js/router/modules/`. Pas de route `/kds` (vérifié routes/web.php + router) : surface = `/admin/kitchen-display-system`. `resources/js/helpers/kdsSource.js` etc. existent ; `OssSyncService.js` est dans `resources/js/services/` (vérifié). |

## 3. Flux critiques (chaînes réelles)

1. **Affichage board KDS** : `KitchenDisplaySystemComponent._refreshWithCurrentFilter` (:2043) → store `kitchenDisplaySystemOrder/lists` → `GET /api/admin/kds-order` (api.php:1192) → `KitchenDisplaySystemController::index:26` → `KitchenDisplaySystemOrderService::list:55` (statuts {4,7,8} + `KitchenReleaseRule::applyBoardReleaseFilter:78` + branche + jour Paris) → `KDSOrderDetailsResource` → `_applyOrderBuckets:2017` (lanes par source_surface/order_type) → `KdsV2Grid`.
2. **Bump chef** : `KdsV2Grid @change-status` → `onV2ChangeStatus:1610` → store changeStatus → `POST /admin/kds-order/change-status/{order}` [idempotency+throttle] (api.php:1196) → `changeStatus:392` : lockForUpdate + `expected_status`≠réel→409 (:411) + `KitchenReleaseRule::canTransition` + `OrderStateMachine::allows` (:430-434) + garde release (:447) → save + `recordTransition` → post-commit Mail/Sms/Push + `kdsTicketDispatcher` (non-bloquants :477-493). Client : 409/422 → refresh silencieux + bannière (:1626-1643).
3. **Recall 60s** : `KdsHistoryDrawer` bouton → `POST /admin/kds-order/recall/{order}` (api.php:1215) → `recall:286` : PREPARED only, TTL 60s sur updated_at, cap N=1 fenêtre glissante (:331-339), append transition from=to=PREPARED reason='kitchen_recall', statut JAMAIS muté → `KdsOrderRecalled` after-commit → `PersistKdsOrderRecalledToOutbox` → `domain_events` → broadcast `private-branch.{id}` → autres stations `handler KdsOrderRecalled:1955` → badge RAPPELÉ 60s (`recallActiveIds:1215`).
4. **Poll fallback KDS** : WS down → `_pollingInterval()=5000` (:1899) + `KdsSyncService.js` → `GET /admin/kds-order/sync?since` (api.php:1201) → `KdsSyncController::sync:32` → `KdsSyncService::sync:37` (delta updated_at + deleted_ids, cache 5s) ; bannière fallback fail-safe-to-visible (:1282-1320).
5. **OSS mur client (public)** : `PreparingAndReadyComponent.startOssSync:244` → `OssSyncService.start` (cadence publique 5s :266-270) → store `orderStatusScreenOrder/lists` → non-authed → `GET /api/frontend/oss-order` [throttle oss-public] (api.php:1311) → `publicIndex:80` (branche = query ou 1ʳᵉ ACTIVE) → `listForBranch:206` (KIOSK/TAKEAWAY only, PREPARING/PREPARED, prune 8h, FIFO) → `CDSOrderDetailsResource` (0 PII) → `_hydrateFromRows:397` → colonnes + flash/chime.
6. **Sync temps réel OSS staff** : bump KDS → `OrderStatusChanged` (outbox) → Echo `private-branch.{id}` → `PreparingAndReadyComponent handler:289` (new_status==PREPARED → `_markNewReady` + `list()`), dédup avec poll via `_echoMarkedReady:297`.
7. **Board items cuisine** : `items():2134` → `GET /admin/kds-order/items` → `orderItems():505` (ACCEPT+PREPARING + release filter :520) → merge quantités par hash incluant allergens (:566) → `KDSOrderItemsResource` (snapshot-first).
8. **Symboles cuisine (parité écran↔ticket)** : `KdsOrderCard.renderItemLines:394` → `kdsSymbolic.renderItemSymbolic:266` ; jumeau imprimé `KitchenTicketSymbolicFormatter` (PHP) — tables et regex identiques (tests parité `tests/Unit/Hardware/KitchenSymbolPhpJsParityTest.php`).

## 4. Invariants observés

- Visible == bumpable : `list()` et `changeStatus()` partagent `KitchenReleaseRule` (Service :78 et :447 ; règle :93-98).
- Release board = PAID ∥ PENDING_COUNTER ∥ (POS && CASH) — `KitchenReleaseRule.php:100-112` ; PENDING_COUNTER bumpable (Plan B borne→caisse), badge « Non encaissé » non-bloquant `KdsOrderCard.vue:143-162`.
- Transitions : échelle stricte ACCEPT→PREPARING→PREPARED, double garde `KitchenReleaseRule::canTransition` + `OrderStateMachine::allows` (Service :430-434) ; optimistic lock `expected_status` → 409 (:411-423).
- Recall NF525-safe : `orders.status` jamais muté, append-only `order_status_transitions`, assertion post-write (Service :356-363) ; pas de re-notification client (Event :19-21).
- Fenêtre jour = Paris-local (session MySQL tz=SYSTEM) — invariant documenté Service :92-121, dépend de `connections.mysql.timezone` restant NULL (sentinelles KdsTodayWindowTzSentinelTest / SisterServicesTzAwareTest).
- Branch isolation : staff branch>0 scopé partout (list :82-84, changeStatus :404-407, recall :304-306, sync 403 :60-66, OSS resolveBranchScope :273-291) ; admin branch 0 = toutes branches (polling only côté Echo :1917).
- OSS public = 0 PII (CDSOrderDetailsResource, 6 champs), allowlist KIOSK/TAKEAWAY fail-closed (:59-62), throttle `oss-public`.
- GDPR : phone client exposé au KDS uniquement pour DELIVERY (KDSOrderDetailsResource :72-75).
- Compo = `composition_snapshot` SSOT avec fallback legacy (KDSOrderItemsResource :27-29 ; kdsSymbolic readVariations :122-126).
- Cap 50 commandes + flag overflow (Service :172-177, Controller :31-36).
- Idempotency + throttle `kds-bump` sur change-status ET recall (api.php:1196-1197, :1215-1217).

## 5. Risques préliminaires (à vérifier vagues suivantes — PAS des findings)

1. `historyToday()` sans throttle spécifique lit PREPARED/OUT/DELIVERED **toutes branches** pour admin — OK, mais fenêtre `updated_at` (proxy bump) peut manquer un ordre re-touché après minuit (:236).
2. Recall TTL basé `updated_at` : n'importe quel write concurrent (ex. paiement encaissé) ré-ouvre/étend la fenêtre 60s (Service :313-317) — cap N=1 est ancré sur fenêtre glissante, mais la TTL elle-même reste manipulable par writes non liés.
3. OSS `publicIndex` : `?branch_id=N` accepté sans vérifier que N existe/est ACTIVE (Controller :83-89) — payload sans PII donc impact faible, throttle nommé `oss-public` mitige l'énumération.
4. `OrderStatusScreenController::index` (authed) renvoie `PosShortcutOrderResource` avec `total` (:32) — surface différente du public ; vérifier que ce endpoint n'est pas monté sur un mur non prévu.
5. Bucket lanes legacy : ligne 2030, une commande sans `source_surface` ET type KIOSK bucket « borne » ; NULL + POS → lane takeaway — cohérent, mais dépend du backfill `source_surface` (memory : fix 258f74722 côté caisse).
6. `changeStatus` retourne 202 même quand `changed=false` (no-op replay) — voulu (idempotence), à confirmer côté client.
7. Invariant TZ Paris : toute future config `mysql.timezone='+00:00'` casse silencieusement les 3 fenêtres jour (list/orderItems/OSS) — sentinelles existent mais l'invariant est fragile par design (Service :115-120).
8. Mur public poll 5s (PreparingAndReady :266-270) vs brief « poll 60s » : la cadence publique effective est 5s (override), 60s seulement pour staff authentifié WS-up.

## 6. Couverture de tests observée (ls/grep réels)

- PHP Feature : `tests/Feature/KDSFlowTest.php`, `KdsChangeStatusConcurrencyTest.php`, `KdsExpectedStatusConflictTest.php`, `KdsTransitionWhitelistTest.php`, `KDSScopeRestrictionTest.php`, `KdsBranchFilterExactTest.php`, `KdsRecallCapNTest.php`, `KdsPaginationOverflowTest.php`, `KdsListExceptsFilterTest.php`, `KdsNotificationFailureTest.php`, `KDSOrderItemsTest.php`, `KitchenReleaseRuleTest.php`, `KitchenDisplaySystemOrderSortTest.php`, `tests/Feature/KDS/` (11 fichiers : KdsUnreleasedOrderBump{,P1}Test, KdsSyncTzAwareTest, KdsSyncSargableTest, KdsHistoryTodayEndpointTest, KitchenRecallEndpointSentinelTest, KdsAllergenAggregationSplitTest, KdsOrderItemsResourceAllergenExposureTest, KdsSnapshotImmutableTest, KDSDeliveryEnrichmentTest, BackfillAllergensSnapshotTest), `tests/Feature/OSS/OssCustomerScreenFilterTest.php`, `OssPolishClusterTest.php`, `tests/Feature/OSSReadOnlyTest.php`.
- PHP Unit : `tests/Unit/Hardware/KitchenTicketSymbolicFormatterTest.php`, `KitchenSymbolPhpJsParityTest.php`.
- JS (Vitest, `tests/js/`) : kdsSymbolic, kdsSymbolicRender, kdsSource, kdsState, kdsCustomization, kdsAllergens, kdsBumpRecall, kdsSyncCadence, kdsCadenceFloor, kdsBackoffOn5xx, kdsDedupeByIdVersion, kdsVersionGate, kdsV2KillSwitch, kdsStationFilter, kdsTimerEscalation, kdsLegacyDeliveryAllLanes, kdsRemediation*, orderStatusScreenOssSync, ossSyncFallback, ossChimePublicWall, ossWakeLockOnMount, posOssCadenceCap.

## 7. Questions ouvertes

1. Le brief mentionnait `resources/js/routes/orderStatusScreenRoutes.js` — ABSENT (vérifié) ; vrai chemin `resources/js/router/modules/orderStatusScreenRoutes.js`. Idem : pas de surface `/kds` (CLAUDE.md §6 la liste) — la route SPA est `/admin/kitchen-display-system`. Le mapping doc↔code mérite correction.
2. Qui pilote la transition PREPARED→DELIVERED (sortie du board / entrée historique) ? Hors périmètre lu (POS/encaissement) — à croiser avec le lecteur POS.
3. `KdsUndoToast.vue` encore présent mais retiré du chemin V2 (KdsV2Grid :89-92) — dead code legacy ou support rollback `?v2=0` ?
4. `kds_station` : confirmé non utilisé dans le filtrage lu ici ; existe-t-il ailleurs (seeder `KdsStationAssignmentSeederTest.php` vu dans ls) une logique station réelle ?
