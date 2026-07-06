# FoodKing — Audit de synchronisation globale (systèmes + site + app + fidélité)

> Comment tout reste cohérent entre POS, borne, KDS, OSS, **site web**, **app mobile** et **fidélité**. 11 axes visés → 9 audités (2 axes, *fidélité* et *FCM*, ont échoué sur la génération structurée de l'outil : la fidélité est reprise en §F ci-dessous par l'orchestrateur, le FCM est couvert par l'axe Client). 63 failles, **22 confirmées adversarialement**.
> Audit statique, ancré `fichier:ligne`. **Aucune modification de code.**

---

## 0. Verdict de synchronisation

Socle correct en principe (backend seule verite ; STI Order/FrontendOrder meme table -> commandes borne remontent au KDS/OSS ; statut broadcaste en afterCommit). Mais la couche de propagation est best-effort et diverge des le premier incident.

TEMPS-REEL/COHERENT (chemin heureux seulement) : staff POS<->KDS<->OSS via branch.{id}, convergence sub-seconde quand ws up et event livre, sur surface deja ouverte + item en memoire. Seule zone reellement temps-reel.

EVENTUEL/BORNE : menu et prix (TTL client 5min kioskMenu.js:19, forget serveur best-effort) ; catalogue <=60s, create/delete/categorie jamais pousse (>=5min).

INCOHERENT/DIVERGENT (le coeur du probleme) :
- CLIENT web : pas de temps-reel, suivi = fetch unique sans Echo ni polling (OrderDetailsComponent:304), obsolescence illimitee.
- CLIENT mobile : FCM sur endpoint legacy decommissionne (FcmNotificationService:76), topic jamais souscrit (SendFcmOnOrderStatusChange:97) -> push mort.
- Canal App.Models.User.{id} : autorise (channels.php:16) mais jamais publie ni souscrit -> fausse impression de temps-reel.
- Borne : user_id=machine, client anonyme abonnable a rien -> ne recoit JAMAIS son statut (OrderPushNotificationBuilder:30).
- OUTBOX non-atomique : INSERT domain_events hors tx (OrderService:948), echec avale en 200 (:949) -> perte permanente ; dispatched_at pose sans diffuser si Pusher absent (DispatchDomainEventsJob:95) ; broadcast-avant-marquage + rescue = doublon (:91). At-least-once sans dedup.
- DISPO/86 : borne n'affiche jamais le 86 par branche (kioskMenu:253), chemin commande ne lit jamais la dispo (FrontendOrderService:835), decrement hors tx (AvailabilityService:140) -> oversell garanti ; deux drapeaux concurrents (global vs branche).
- AUTZ broadcast : token branch_id=0 recoit toutes les commandes de toutes branches (channels.php:33) + fuite token/queue_number a tous les abonnes, filtre cote client seulement.
- FIDELITE : users.loyalty_points ecrit par 5 chemins concurrents SANS verrou (web increment/decrement/reset, borne inline, gain async, remboursement) -> lost-update sur solde monetisable.
- KIOSK degrade : verite locale optimiste jamais reconciliee, fausse confirmation sans preuve serveur (kioskCart:374), commande payee abandonnee apres 10 essais (kioskOfflineQueue:115), 5xx classe offline (kioskCart:366).

Ou ca diverge : partout ou le client est concerne (web/mobile/borne), a chaque event perdu (pas de replay), et sur toute donnee ecrite par plusieurs chemins sans verrou (fidelite, stock).


> **Verdict :** NON FIABLE — BLOCK. Temps-reel seulement staff (POS/KDS/OSS) sur chemin heureux ; ailleurs eventuel ou divergent-permanent. Trois classes critiques : (1) propagation CLIENT morte (FCM legacy, canal User.{id} jamais publie/souscrit, borne anonyme, web sans Echo) ; (2) outbox non-atomique = ni livraison ni dedup ; (3) invariants violes sans verrou = oversell stock + corruption fidelite. Plus fuite autz cross-branche. Correction avant prod ; escalade humaine sur fidelite et securite.


## 1. Topologie de synchronisation

```
CARTE SYNCHRO FOODKING (publieur -> transport -> consommateur)

BACKEND = SEULE VERITE : orders/frontend_orders (STI), items/item_branch_availability, users.loyalty_points
   | mutation sous tx
   v
OUTBOX domain_events -> DispatchDomainEventsJob
   NON-ATOMIQUE : INSERT hors tx (OrderService:948), echec avale 200 (:949),
   skip Pusher = perte (:95), broadcast-avant-marquage = doublon (:91)
   | soketi/Pusher (afterCommit, file high)
   +--> branch.{branchId} [VIVANT] autz permissive branch_id=0 = fuite cross-branche (:33)
   |        -> POS / KDS / OSS (staff, refetch, Echo + polling 10-60s ; frame perdu = fige <=60s)
   +--> App.Models.User.{id} [MORT : autorise :16, jamais publie ni souscrit]
   +--> FCM SendFcmNotificationJob [MORT : endpoint legacy :76, topic non souscrit :97]

SITE WEB CLIENT -> fetch UNIQUE, ni Echo ni polling (:304) -> obsolescence ILLIMITEE
APP MOBILE -> device token (/mobile) mais FCM mort -> RIEN
BORNE (anonyme) -> user_id=machine -> abonnable a rien -> RIEN ; degrade = queue localStorage optimiste jamais reconciliee

MENU/PRIX : DB -> Cache::remember 60s/branche -> payload -> TTL client 5min
   bump snapshot dispo only (pas create/delete/categorie) ; dispo par branche absente du payload
PANIER : localStorage par surface/appareil, AUCUNE verite ni synchro cross-device
   seule verite = orders, dedup idempotency_key (branch_id+key)
```


## 2. Source de vérité par donnée partagée

| Donnée | Source de vérité | Publieurs | Transport | Consommateurs | Risque de synchro |
|---|---|---|---|---|---|
| **Statut commande** | orders.status / frontend_orders.status (DB, tx) | OrderService, FrontendOrderService, KDS -> outbox | Pusher branch.{id} afterCommit ; User.{id} MORT ; FCM MORT | POS/KDS/OSS temps-reel ; web/mobile/borne RIEN | Client jamais notifie ; outbox non-atomique -> perte/doublon ; frame perdu = fige <=60s staff |
| **Menu / catalogue** | items, item_categories, variations, extras (DB) | MenuProjectionService + MenuSnapshot (bump dispo only) | Cache::remember 60s -> payload ; TTL client 5min ; Echo si edit | POS, borne, site, app (affichage=hint) | create/delete/categorie jamais pousse (>=5min) ; snapshot_version et cache HTTP declencheurs divergents |
| **Prix** | items prix DB ; recalcul /pricing/preview checkout | backend pricing (autoritaire) ; edit -> forget/Echo | payload menu + TTL client 5min ; broadcast si Echo actif | toutes surfaces (affichage) ; facturation backend | Prix affiche diverge du prix DB jusqu'a 5min sans Echo (kioskMenu.js:19) ; hint peut tromper le client |
| **Disponibilite / 86** | item_branch_availability + Item.is_available global | AvailabilityService (decrement hors tx :140) | cache forget DANS la tx (avant commit) ; Echo si item en memoire | borne n'affiche jamais le 86 ; commande ne lit jamais dispo | Oversell garanti (FrontendOrderService:835) ; deux sources concurrentes ; auto-86 fige apres minuit ; stale <=60s |
| **Solde fidelite** | users.loyalty_points (entier DB, non cache) | 5 chemins sans verrou : LoyaltyController, FOS:478, Award async, LoyaltyService:58 | aucun temps-reel ; lecture DB directe | web, borne, jobs | Lost-update / race sur solde monetisable ; increment+decrement+reset concurrents = corruption silencieuse |
| **Panier / session** | AUCUNE verite backend ; localStorage par surface | posCart, kioskCart, frontendCart, app mobile (local) | localStorage local ; aucun temps-reel ; aucune session serveur | la surface elle-meme uniquement | Aucune synchro cross-device ; reload POS = fuite inter-caissier avant setScope ; fausse confirmation offline sans preuve |


---

## 3. Axes de synchronisation (détail)

### 🔄 Propagation d'etat commande — POS ↔ kiosk ↔ KDS ↔ OSS (staff)
**Source de vérité :** Table `orders` (statut order-level) = seule verite. Order et FrontendOrder (kiosk) partagent la MEME table (STI : `$table="orders"`, Order.php:19 / FrontendOrder.php:19) donc les commandes kiosk apparaissent bien au KDS/OSS. Les surfaces ne detiennent aucun etat : miroir reconstruit par refetch.

**Modèle de cohérence :** Coherence EVENTUELLE bornee par le polling — pas temps-reel garanti. Chemin heureux (ws up, event livre) : convergence sub-seconde. Mais tout event perdu (gap atomicite, exception avalee, echec job, skip pusher) laisse la surface divergente jusqu'au poll suivant = fenetre jusqu'a 60 s quand ws connecte. Admin branch_id=0 : jamais abonne → 60 s systematiques. Transitions purement order-level.

**Flux de synchro réel :**

Modele reel = **outbox + "poke to refetch"** (payload temps-reel ignore par les consommateurs).

1. **Mutation** : `order->save()` dans `DB::transaction` (KDS.php:129 ; OrderService.php:1383,1445).
2. **Dispatch HORS transaction** : apres commit, `OrderStatusChanged/OrderCreated::dispatch()` (KDS:149 ; OrderService:534,948,1247,1404,1459,1556 ; FrontendOrderService:681,835,841), en try/catch→Log::warning.
3. **Listener outbox synchrone** : `PersistOrderStatusChangedToOutbox:20` fait `DomainEvent::create([channel='private-branch.'+branch_id])` puis `DB::afterCommit→DispatchDomainEventsJob` (queue 'high').
4. **Transport** : `DispatchDomainEventsJob:39` → `EventContract::assertEnvelopeValid` (V1) → `pusher->trigger(channels, broadcast_as, envelope)` → `dispatched_at=now()`.
5. **Auth** : channels.php `branch.{branchId}` (staff=sa branche ; admin branch_id=0=toutes).
6. **Consommateur** : `onEvents(branchId)` (eventContract.js:64) → `private-branch.{id}` → **refetch complet** : KDS `_debouncedRefresh` (vue:577), OSS `list()` (vue:132), POS `loadKioskCashOrders()` (vue:1073). Aucun patch incremental.
7. **Filet** : polling `_pollingInterval()` = **60000 ms si ws connecte**, 10000 ms sinon (KDS.vue:553).
8. **Rescue** : cron `foodking:outbox:rescue` re-queue les domain_events `stale(2)` non dispatches.


**Failles de synchro :**

| Sév | Faille | Mécanisme | Emplacement |
|:--:|---|---|---|
| 🔴 | Outbox non-atomique : dispatch place HORS de la transaction d'etat | order->save() commit dans la transaction, PUIS dispatch apres (KDS:149, OrderService:1404/1459/1556). Le listener synchrone insere domain_events dans une transaction SEPAREE. Crash entre com | `app/Services/KitchenDisplaySystemOrderService.php:149` |
| 🟠 | Exception de publication silencieusement avalee | Chaque dispatch est en try/catch → Log::warning (OrderService:1404, KDS:148, FrontendOrderService:840). Si DomainEvent::create() echoue (contention, deadlock), l'exception est absorbee : la  | `app/Services/OrderService.php:1404` |
| 🟠 | Un frame websocket perdu = surface figee jusqu'a 60 s | Poke+refetch sans reconciliation : payload ignore, pas de patch incremental. Si l'unique frame OrderCreated/OrderStatusChanged est perdu (reseau, reconnexion Echo, backlog queue), la command | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:553` |
| 🟡 | Operateur admin (branch_id=0) : aucun temps-reel | subscribeEcho fait 'if (branchId <= 0) return' (KDS.vue:572, OSS.vue:125, POS.vue:1069) alors que channels.php autorise l'admin sur toute branche. Un admin qui pilote un KDS/OSS ne recoit AU | `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:125` |
| 🟡 | Transition de statut sans verrou : double transition / double livraison | $oldStatus lu AVANT la transaction (KDS:122), transaction limitee au save() sans lockForUpdate. Deux postes cliquant 'Livrer' en concurrence lisent PREPARED, passent la validation, committen | `app/Services/KitchenDisplaySystemOrderService.php:129` |
| ⚪ | Carillon OSS 'commande prete' perdu si l'event est droppe | _markNewReady (son+flash client) n'est arme que par le handler Echo lisant payload.new_status===PREPARED (OSS.vue:137). Le secours list() saute l'ID via _echoMarkedReady/prevPreparedIds. Si  | `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:137` |
| ⚪ | Job marque 'dispatched' meme quand le broadcast est saute | Si PUSHER_APP_KEY vide + manager reel, le job log 'skipping' mais tombe quand meme sur dispatched_at=now(). En prod mal configuree chaque event est marque livre sans partir, et le rescue (sc | `app/Jobs/DispatchDomainEventsJob.php:92` |

**🎯 Redesign :**

1. **Atomicite reelle** : inserer domain_events DANS le DB::transaction de la mutation et ne planifier le job que via DB::afterCommit. Retirer les try/catch qui avalent : un echec d'insertion outbox doit rollback la mutation. Le legacy 'ShouldBroadcastNow hors transaction' n'a plus lieu d'etre, le broadcast etant asynchrone via le job.
2. **Reconciliation, pas juste poke** : payload auto-suffisant (statut+version+updated_at) applique en patch idempotent, OU numero de sequence par branche pour detecter un trou et forcer un refetch cible. Ramener le polling ws-connecte a 10-15 s au lieu de 60 s.
3. **Admin branch_id=0** : s'abonner a la branche affichee au lieu de couper l'Echo (l'auth le permet deja).
4. **Concurrence** : lockForUpdate sur l'order dans changeStatus, relire le statut sous verrou avant validation ; rendre AwardLoyaltyPointsOnDelivery idempotent via orders.loyalty_points_awarded verifie sous verrou.
5. **Transport** : ne jamais poser dispatched_at si l'emission n'a pas eu lieu (skip/echec).
6. **Observabilite** : alerter sur domain_events avec last_error non nul ou attempts>=5.


---

### 🔄 Propagation vers le CLIENT — site web + app mobile (Echo temps reel + FCM push)
**Source de vérité :** Backend seul autoritatif : orders.status / frontend_orders.status muté sous transaction (OrderService, FrontendOrderService). Toutes les surfaces client (web, app mobile, OSS) sont de purs consommateurs ; le statut qu'elles affichent n'est qu'une copie, jamais une source.

**Modèle de cohérence :** INCOHÉRENT côté client. Web : aucune convergence temps réel, fenêtre d'obsolescence = jusqu'au rechargement manuel (illimitée) ; App.Models.User.{id} donne une fausse impression de temps réel (ni publié ni souscrit). Mobile : « éventuel » seulement si commande non-borne ET endpoint FCM vivant (deux conditions fausses). Commande borne : client anonyme ne reçoit RIEN. Echo(web)↔FCM(app) divergents.

**Flux de synchro réel :**

**A. Temps réel Echo (web) — MORT des deux côtés**
1. Statut muté → OrderStatusChanged.
2. PersistOrderStatusChangedToOutbox.php:31 écrit channel=['private-branch.{id}'] — jamais App.Models.User.{id}.
3. DispatchDomainEventsJob.php:91 trigger() diffuse UNIQUEMENT sur private-branch.
4. channels.php:16 autorise App.Models.User.{id} mais rien ne publie dessus et aucun JS ne s'y abonne (grep resources/js = 0).
5. Page suivi client OrderDetailsComponent.vue:304 : dispatch('frontendOrder/show') UNE fois au montage, 0 Echo, 0 setInterval → statut vu qu'au rechargement manuel.

**B. Push FCM**
- Nouveau système (SendFcmOnOrderStatusChange.php:97) → topic customer_order_{orderId} que personne ne souscrit : no-op.
- Legacy (OrderPushNotificationBuilder.php:34) = seul chemin réel, lit user->web_token/device_token de order->user_id. Mais :30 `if source==10 return` → 0 push borne ; et user_id=machine pour la borne.
- Transport : SendFcmNotificationJob (ShouldQueue) mais QUEUE_CONNECTION=sync (queue.php:73) → inline dans la requête HTTP.


**Failles de synchro :**

| Sév | Faille | Mécanisme | Emplacement |
|:--:|---|---|---|
| 🔴 | Stack FCM branchée sur l'endpoint legacy décommissionné | FcmNotificationService poste sur .../fcm/send avec 'key='+serverKey (FCM legacy HTTP, arrêté par Google 2024) alors que la doc annonce HTTP v1. sendToToken renvoie false silencieusement → to | `app/Services/FcmNotificationService.php:76` |
| 🟠 | Canal client App.Models.User.{id} mort : autorisé mais jamais publié ni souscrit | channels.php:16 autorise le canal privé, mais l'outbox ne diffuse que sur private-branch (PersistOrderStatusChangedToOutbox.php:31) et aucun JS ne s'abonne (grep App.Models.User dans resourc | `routes/channels.php:16` |
| 🟠 | Page de suivi client = fetch unique, ni Echo ni polling | OrderDetailsComponent charge le statut une fois au montage (dispatch show, l.304), sans setInterval ni Echo. PENDING→PREPARED→DELIVERED jamais reflété tant que le client ne recharge pas la p | `resources/js/components/frontend/account/myOrder/OrderDetailsComponent.vue:304` |
| 🟠 | Notif FCM client envoyée à un topic que personne ne souscrit | SendFcmOnOrderStatusChange dispatche sur customer_order_{orderId} (aucun client web/mobile abonné — 'if we had device token in future'). Tout le chemin client du nouveau système FCM est un n | `app/Listeners/SendFcmOnOrderStatusChange.php:97` |
| 🟠 | Commande borne : client anonyme ne reçoit jamais son statut | OrderPushNotificationBuilder::send() 'if order->source==10 return' → 0 push kiosk ; et user_id=machine donc les tokens seraient ceux de la tablette. Ni Echo (user_id≠son id) ni FCM. loyalty_ | `app/Services/OrderPushNotificationBuilder.php:30` |
| 🟡 | QUEUE=sync : push FCM inline (bloque la réponse) et perdu si échec | SendFcmNotificationJob est ShouldQueue mais QUEUE_CONNECTION=sync → exécuté dans la requête HTTP, timeout 10s par envoi (plusieurs par transition). Une exception après 3 tries est définitive | `config/queue.php:73` |
| 🟡 | Un seul device_token par utilisateur — pas de multi-appareil | TokenStoreService::deviceToken/webToken écrasent une colonne unique users.device_token/web_token. Enregistrer un 2e appareil évince le 1er ; téléphone+tablette ne reçoivent le push que sur l | `app/Services/TokenStoreService.php:39` |
| 🟡 | Deux systèmes FCM parallèles et divergents | EventServiceProvider câble SendFcmOnOrderStatusChange (topics) ET SendOrderPush→OrderPushNotificationBuilder (tokens users). Cibles différentes, duplication côté cuisine, trous côté client : | `app/Providers/EventServiceProvider.php:96` |

**🎯 Redesign :**

1) Un seul canal client temps réel réellement câblé : publier OrderStatusChanged aussi sur App.Models.User.{order.user_id} depuis l'outbox, et faire souscrire OrderDetailsComponent via Echo.private(...).listen('OrderStatusChanged'). À défaut, un polling 10-15s comme filet — mais choisir UN modèle, pas zéro.
2) Résoudre la borne : canal signé par commande, ex. Echo.channel('order.'+token) autorisé par le token opaque déjà présent (payload.token), pour que le client anonyme suive SA commande sans identité ; alimenter FCM par ce token, pas par user_id=machine.
3) Supprimer le double système FCM : un seul chemin, migrer vers FCM HTTP v1 (OAuth2 service account) — l'endpoint legacy est mort — et remplacer les topics customer_order_{id} fantômes par un envoi au device_token réel.
4) Table device_tokens (1..N par user, plateforme + last_seen) au lieu d'une colonne unique, avec purge des tokens invalidés par FCM.
5) Transport durable : QUEUE_CONNECTION=redis/database + worker 'fcm' dédié, push non bloquant et rejouable ; échec FCM = événement à retenter, jamais silencieux.
6) Une seule fonction mapping statut→message consommée par Echo et FCM pour éliminer la divergence.


---

### 🔄 Propagation menu / prix vers toutes les surfaces (POS, borne kiosk, site, app)
**Source de vérité :** DB : `items`/`item_categories`/`item_variations`/`item_extras` (prix+statut GLOBAL) et `item_branch_availability` (dispo PAR branche, MENU_86). Prix facturé = recalculé backend à /pricing/preview au checkout ; l'affichage n'est qu'un hint.

**Modèle de cohérence :** Éventuel et incohérent selon la mutation. Édition prix/dispo d'item existant : ~temps réel SI Echo actif (<1s), sinon éventuel borné par TTL CLIENT 5 min (kioskMenu.js:19). Create/delete/catégorie : AUCUNE poussée → 5 min au mieux. Dispo par branche au chargement : JAMAIS cohérente (absente du payload A).

**Flux de synchro réel :**

TROIS projections parallèles du même catalogue, mal réconciliées :

**A. Grille réelle borne (vivant)** — kioskMenu.js:249-254 fetch `/frontend/item?surface=kiosk` + `/frontend/item-category` → ItemController::index → ItemService::simpleList (ItemService.php:79-120) → SimpleItemResource (SimpleItemResource.php:19-60). Filtre `channels` (ItemService.php:132-145) + status. AUCUNE jointure item_branch_availability, branch_id ignoré. Pas de cache serveur.

**B. /frontend/menu (KioskMenuService, branch-aware)** — MenuController::kiosk (MenuController.php:66-72) Cache::remember 60s ; build() joint bien MENU_86 (KioskMenuService.php:73-99). Mais côté client appelé QUE pour promos+flags (kioskMenu.js:276-279), jamais pour items/prix.

**C. MenuProjectionService+MenuSnapshot** — mort : docblock « Consumers POS/Kiosk NOT yet plugged » (MenuProjectionService.php:27-29), aucun JS ne lit snapshot_version (grep=0).

**Invalidation** : édition item → ItemService::update émet ItemAvailabilityChanged type='full' (ItemService.php:275-279) → outbox private-branch.{id} (PersistItemAvailabilityChangedToOutbox.php:31-38) → Echo → KioskAppComponent:385-391 UPDATE_ITEM+refetch. Create/delete/catégorie → seulement InvalidateKioskMenuCacheOnCatalogChange (vide cache B, jamais poussé au client) ; ni bump snapshot ni broadcast.


**Failles de synchro :**

| Sév | Faille | Mécanisme | Emplacement |
|:--:|---|---|---|
| 🔴 | Dispo par branche absente du payload consommé par la borne | SimpleItemResource (SimpleItemResource.php:19-60) n'expose ni is_available ni item_branch_availability ; simpleList (ItemService.php:79-120) ne joint jamais MENU_86 et ignore branch_id. Un i | `app/Http/Resources/SimpleItemResource.php:22` |
| 🟠 | Create/Delete/Catégorie ne poussent aucun refetch à la borne | ItemCreated/ItemDeleted/Category* (EventServiceProvider.php:116-130) câblés QUE sur InvalidateKioskMenuCacheOnCatalogChange (vide le cache serveur B non consommé) — ni bump snapshot ni event | `app/Listeners/InvalidateKioskMenuCacheOnCatalogChange.php:14` |
| 🟠 | Prix affiché diverge du prix DB jusqu'à 5 min sans Echo | Changer le prix émet type='full' (ItemService.php:275-279) → refetch borne SEULEMENT si Echo actif (KioskAppComponent:389-390). Fallback sans soketi explicite (KioskAppComponent:382) : retom | `resources/js/store/modules/kioskMenu.js:19` |
| 🟡 | Trois représentations menu parallèles = SSOT éclatée | MenuProjectionService (branch-aware) mort (MenuProjectionService.php:27-29) ; KioskMenuService n'alimente que promos+flags (kioskMenu.js:276-279) ; grille réelle via /frontend/item (non bran | `app/Services/Menu/MenuProjectionService.php:27` |
| 🟡 | snapshot_version : non consommé + régression possible | MenuSnapshot.current() auto-init à 1, TTL 7 jours (MenuSnapshot.php:24,41,58,70). Après expiration la version repart à 1 (recul) ; un client mémorisant une version supérieure ne se rafraîchi | `app/Services/Menu/MenuSnapshot.php:41` |
| ⚪ | Broadcast prix hors DB::afterCommit, pattern incohérent | update() émet l'event hors DB::afterCommit (ItemService.php:279) alors que store() (:181) et destroy() (:305) utilisent afterCommit (doctrine outbox). Ici post-commit donc atomicité tient, m | `app/Services/ItemService.php:279` |

**🎯 Redesign :**

1) **Projection branch-aware unique.** Router la grille borne (et POS/web) vers MenuProjectionService::forChannel(channel,branchId) qui joint déjà MENU_86 ; ou ajouter la jointure item_branch_availability + is_available dans simpleList/SimpleItemResource scopés branch_id. Dispo par branche présente dès le chargement, pas seulement via broadcast.

2) **Contrat unique de mutation catalogue.** Émettre MenuCatalogChanged (ShouldBroadcast via outbox afterCommit) sur create/update/delete item ET catégorie, portant le snapshot_version bumpé. Câbler bump+invalidation+broadcast dessus, pas seulement sur ItemAvailabilityChanged : suppression/création/renommage poussent un refetch temps réel.

3) **Client consomme snapshot_version.** kioskMenu.js stocke la version ; à la reconnexion Echo compare et refetch si divergence, au lieu du TTL 5 min aveugle. Rendre MenuSnapshot monotone (pas de TTL 7j qui reset à 1).

4) **Bannière « prix mis à jour »** si /pricing/preview ≠ somme affichée, avant convergence.

5) Uniformiser les dispatch d'events sur DB::afterCommit (aligner update() sur store()/destroy()).


---

### 🔄 Disponibilité / 86 / rupture stock — synchro temps réel multi-surface
**Source de vérité :** Vérité 86/rupture par branche = table `ItemBranchAvailability` (is_available, reason, daily_consumed_qty, max_daily_qty), écrite via `AvailabilityService`. Mais elle coexiste avec un 2e drapeau GLOBAL `Item.is_available` (non scopé branche) exposé par NormalItemResource : deux sources concurrentes.

**Modèle de cohérence :** INCOHÉRENT. Temps réel effectif seulement sur surface déjà ouverte ET item déjà en mémoire (kioskMenu.js:194). Fenêtres : (1) au chargement/reconnexion la dispo par branche n'est jamais lue ; (2) entre commit commande et décrément hors transaction, commandes concurrentes voient l'ancien stock ; (3) après minuit un auto-86 reste 86. Le chemin commande ne consulte jamais la dispo.

**Flux de synchro réel :**

**Écriture** : `AvailabilityService::toggle` (:38, lockForUpdate:42) / `decrementForOrder` (:118) muta ItemBranchAvailability puis émet `ItemAvailabilityChanged::forBranch` (ItemAvailabilityChanged.php:71). Event = domain event PUR (pas de ShouldBroadcast, :21).

**Outbox** : `PersistItemAvailabilityChangedToOutbox` crée un DomainEvent canal `private-branch.{id}` (:30,41) et publie en `DB::afterCommit` via DispatchDomainEventsJob (:53). Atomicité OK ici. En parallèle : bump snapshot + forget cache `kiosk.menu.branch.{id}` (best-effort).

**Transport** : Echo.private('branch.'+id).listen('.ItemAvailabilityChanged') (eventContract.js:71-99).

**Consommateur borne** : rendu initial via `frontend/item?surface=kiosk` (kioskMenu.js:253) → NormalItemResource, flag GLOBAL seulement (:54-75). `/frontend/menu` (KioskMenuService, seul à projeter la dispo par branche, :257,280) n'est lu QUE pour promos+branch flags (kioskMenu.js:276-279) — items IGNORÉS. Temps réel : `_subscribeEchoChannel` (KioskAppComponent.vue:381) → `UPDATE_ITEM` patch mémoire (kioskMenu.js:185).

**POS** : `_onItemAvailabilityChanged` (PosComponent.vue:1098) patch en place.


**Failles de synchro :**

| Sév | Faille | Mécanisme | Emplacement |
|:--:|---|---|---|
| 🔴 | La borne n'affiche jamais le 86 par branche (dispo write-only au rendu) | fetchMenu rend via frontend/item (NormalItemResource, flag GLOBAL Item.is_available seul, :54-75). La projection par branche (KioskMenuService) n'est pas lue : /frontend/menu sert QUE promos | `resources/js/store/modules/kioskMenu.js:253` |
| 🔴 | Le chemin commande ne consulte jamais la disponibilité → oversell cross-surface | FrontendOrderService (borne) et OrderService (POS/web) ne référencent aucune ItemBranchAvailability/isAvailable avant création (grep vide). Un article 86/rupture est accepté et facturé ; le  | `app/Services/FrontendOrderService.php:835` |
| 🟠 | Décrément stock non atomique et hors transaction → race oversell | decrementForOrder fait un read-modify-write sans lockForUpdate (first() puis save(), :124-151). OrderCreated est dispatché APRÈS le commit (OrderService.php:534), donc DecrementItemAvailabil | `app/Services/Menu/AvailabilityService.php:140` |
| 🟠 | Event perdu à la déconnexion — aucun resync | _subscribeEchoChannel branche un listener qui patche l'état mémoire (KioskAppComponent.vue:385) ; UPDATE_ITEM ne refetch pas (kioskMenu.js:185). Socket tombé = tout event émis pendant la cou | `resources/js/components/frontend/kiosk/KioskAppComponent.vue:381` |
| 🟡 | Auto-86 rupture jamais réactivé (86 collant après minuit) | decrementForOrder remet daily_consumed_qty=0 au changement de jour (:133-136) mais ne repasse jamais is_available à true ; aucune commande planifiée de reset (app/Console vide). Un article a | `app/Services/Menu/AvailabilityService.php:145` |
| 🟡 | Double source de vérité : global Item.is_available vs branche ItemBranchAvailability | NormalItemResource expose le flag global (:57) ; KioskMenuService combine les deux (is_available && item.is_available, :280). Les surfaces divergent selon l'endpoint : borne (frontend/item)  | `app/Http/Resources/NormalItemResource.php:54` |

**🎯 Redesign :**

1) SOURCE UNIQUE : ItemBranchAvailability = seule autorité commandable par (item,branche). Résoudre côté serveur un unique champ `orderable` exposé identiquement sur TOUS les endpoints. La borne doit lire la projection branch-scoped (KioskMenuService), pas frontend/item.
2) GARDE COMMANDE (fermer l'oversell) : dans FrontendOrderService ET OrderService, valider la dispo DANS la transaction, avec lockForUpdate sur les lignes ItemBranchAvailability, incrémenter daily_consumed_qty et rejeter en 409 si 86/cap atteint. Décrément atomique co-transactionnel, pas un listener post-commit.
3) RESYNC RECONNEXION : au (re)connect Echo, refetch de la projection dispo + version MenuSnapshot ; endpoint de replay outbox `since={correlation_id}` pour rejouer les events manqués. Le temps réel = accélérateur, jamais seule source.
4) RÉACTIVATION : commande planifiée nocturne remettant is_available=true pour les 86 'out_of_stock' réinitialisés, émettant ItemAvailabilityChanged::forBranch(true) via l'outbox.
5) COHÉRENCE : un seul contrat d'event (is_available + reason + version) consommé identiquement borne/POS/KDS/OSS ; supprimer la rétrocompat status-only ambiguë (kioskMenu.js:209-212).


---

### 🔄 Outbox — atomicité, garantie de livraison, ordre, idempotence
**Source de vérité :** L'outbox `domain_events` devrait être le journal durable (backend seule vérité). En réalité « l'événement a-t-il été émis ? » est scindé en deux transactions : la mutation commande et l'INSERT outbox, committé séparément après. Aucune ne fait autorité.

**Modèle de cohérence :** Cohérence **éventuelle et non garantie**, par endroits incohérente. L'outbox ne garantit pas la livraison. Fenêtres : (a) crash entre commit (:939) et INSERT outbox (:948) → perte permanente ; (b) broadcast (:91) puis crash avant dispatched_at (:95) → doublon via rescue ; (c) clé Pusher absente → dispatched_at posé sans diffuser = perte silencieuse. At-least-once sans dédup.

**Flux de synchro réel :**

Chemin réel (POS create), représentatif :

1. `OrderService::store` → `DB::transaction(fn)` mute et commit à la fermeture du closure (`OrderService.php:939`).
2. **Après** commit, hors transaction, `OrderCreated::dispatch($order)` dans un try/catch qui n'log qu'un warning (`OrderService.php:948` ; idem `:1404`, `:1556` ; borne `FrontendOrderService.php:835`, `:841`).
3. Listener **synchrone** `PersistOrderCreatedToOutbox::handle` → `DomainEvent::create` dans une transaction **propre** (`PersistOrderCreatedToOutbox.php:18`).
4. `DB::afterCommit` (`:37`) : aucune transaction active → callback exécuté **immédiatement** → `DispatchDomainEventsJob::dispatch`.
5. `QUEUE=sync` (`config/queue.php:16`) → job **inline** : garde `dispatched_at !== null` (`DispatchDomainEventsJob.php:37`), `increment('attempts')` (`:41`), `assertEnvelopeValid` (`:57`), `trigger()` (`:91`), puis `dispatched_at=now()` (`:95`).
6. Filet : `foodking:outbox:rescue` chaque minute re-queue `stale(2)` attempts<5 (`Kernel.php:31`, `OutboxRescueCommand.php:17`).

Note : le mécanisme atomique correct existe (`HasDomainEvents::recordDomainEvent` insère dans le `saved()`, donc dans la transaction, `HasDomainEvents.php:13,29-59`) mais n'est **jamais appelé**. Le chemin vivant est celui, non atomique, des listeners.


**Failles de synchro :**

| Sév | Faille | Mécanisme | Emplacement |
|:--:|---|---|---|
| 🔴 | Écriture outbox HORS de la transaction de mutation → perte permanente | Events dispatchés après fermeture du DB::transaction (OrderService.php:939 puis :948,:1404,:1556 ; FrontendOrderService.php:835,:841). Le listener synchrone fait DomainEvent::create dans une | `app/Services/OrderService.php:948` |
| 🔴 | try/catch avale l'échec d'écriture outbox et renvoie 200 | Le dispatch est enveloppé dans try/catch qui ne fait que Log::warning (OrderService.php:949-951, :1405, :1557 ; FrontendOrderService.php:842). Si DomainEvent::create échoue, la requête retou | `app/Services/OrderService.php:949` |
| 🟠 | Skip Pusher : dispatched_at posé sans diffuser → perte silencieuse | Clé Pusher vide + manager réel → log 'skipping broadcast' (DispatchDomainEventsJob.php:84-89) puis forceFill(['dispatched_at'=>now()]) quand même (:95). L'événement est marqué livré sans émi | `app/Jobs/DispatchDomainEventsJob.php:95` |
| 🟠 | Broadcast-avant-marquage + rescue concurrent = double livraison | trigger() (:91) est appelé AVANT dispatched_at=now() (:95). Un crash entre les deux laisse la ligne pending → rescue (Kernel.php:31, stale 2 min) rediffuse. De plus rescue et un retry peuven | `app/Jobs/DispatchDomainEventsJob.php:91` |
| 🟠 | Aucune idempotence côté consommateur (pas d'event_id) | buildEnvelope n'expose que correlation_id (EventContract.php:59-70), régénéré par Str::uuid() (PersistOrderCreatedToOutbox.php:33). Aucun id de dé-duplication imposé côté client. Avec l'at-l | `app/Domain/Events/EventContract.php:59` |
| 🟡 | Ordre non préservé : ni séquence ni traitement ordonné | Un job par id + sync/rescue asynchrone → ordre non garanti. L'enveloppe n'a pas de numéro de séquence par agrégat (seul occurred_at, EventContract.php:66) et le consommateur ne l'ordonne pas | `app/Jobs/DispatchDomainEventsJob.php:44` |
| 🟡 | Mécanisme atomique correct mort ; attempts non discriminant | recordDomainEvent (HasDomainEvents.php:13) écrirait la ligne DANS la transaction (saved(), :29-50) mais n'est jamais appelé et omet correlation_id (:41). De plus increment('attempts') (Job:4 | `app/Traits/HasDomainEvents.php:13` |

**🎯 Redesign :**

1. Écriture outbox **atomique** : insérer `domain_events` DANS la transaction de mutation. Activer réellement `HasDomainEvents::recordDomainEvent` (l'appeler avant `save()`), supprimer les `Persist*ToOutbox` hors transaction, renseigner `correlation_id` dans le trait. Le code métier ne diffuse JAMAIS ; seul le relais outbox diffuse.
2. Supprimer les try/catch qui avalent l'échec : l'INSERT étant dans la transaction, son échec doit faire rollback de la commande (tout-ou-rien).
3. Skip Pusher : si la diffusion est sautée, NE PAS poser `dispatched_at` — laisser pending.
4. Idempotence consommateur : diffuser l'`id` outbox dans l'enveloppe ; chaque client garde un cache d'ids vus et ignore les doublons.
5. Concurrence : `SELECT ... FOR UPDATE SKIP LOCKED` sur la ligne dans le worker ; poser un `broadcasting_at` avant `trigger()`.
6. Ordre : séquence monotone par agrégat (`aggregate_seq`) dans l'enveloppe ; les consommateurs rejettent toute séquence <= dernière appliquée.
7. Remplacer dispatch-par-ligne+rescue par un **relais dédié** lisant les pending par `id` croissant, avec `QUEUE_CONNECTION` non-`sync` en prod.


---

### 🔄 Infrastructure broadcast — canaux, abonnements, autorisation, leave
**Source de vérité :** Autz des canaux : routes/channels.php (callbacks Broadcast::channel). Membership : soketi (appManager array, en memoire, soketi.json). L'etat de commande fait autorite dans orders ; le temps reel n'est qu'une projection diffusee.

**Modèle de cohérence :** INCOHERENTE/eventuelle avec fuite. Temps reel best-effort (afterCommit, file high) + repli polling. Fuite permanente : token+queue_number de chaque commande pousses a tous les abonnes de la branche, filtre seulement cote client. Autz permissive permanente : tout client branch_id=0 passe le check admin. Coupure croisee au demontage d'un ecran kiosk.

**Flux de synchro réel :**

Un seul canal effectif, `branch.{id}` :

1. **Publieur** : PersistOrderCreatedToOutbox (Listeners/PersistOrderCreatedToOutbox.php:31) et PersistOrderStatusChangedToOutbox (:30) ecrivent domain_events avec `channel=['private-branch.{branch_id}']`. Aucun listener ne cible App.Models.User.{id}.
2. **Transport** : DispatchDomainEventsJob (Jobs/DispatchDomainEventsJob.php) fait getPusher()->trigger($channels,...) en afterCommit, file `high`.
3. **Autz** : Echo hit /api/broadcasting/auth -> channels.php:25-39.
4. **Consommateur** : chemin JS unique Echo.private('branch.'+id) via onEvents (services/eventContract.js:71-72). Abonnes : KDS (KitchenDisplaySystemComponent.vue:567), OSS, KioskApp (dispo items :385), KioskWaiting (client anonyme :208).
5. **Coherence** : filtrage cote client `order_id===this.orderId` (KioskWaiting.vue:214,223) ; la trame WS brute contient deja toutes les commandes.
6. Le canal App.Models.User.{id} (channels.php:16) est autorise mais aucun code ne s'y abonne (0 hit JS) et aucun event n'y est diffuse : canal mort. Le modele « 2 canaux » est en pratique 1 canal branche-large.


**Failles de synchro :**

| Sév | Faille | Mécanisme | Emplacement |
|:--:|---|---|---|
| 🔴 | Bypass autz broadcast : tout client (branch_id=0) recoit toutes les commandes de n'importe | channels.php:33 autorise si (int)branch_id===0. Or clients crees avec branch_id=0 (SignupController:92, GuestSignupController:121, CustomerService:68) et default colonne=0 (users:28) ; NULL- | `routes/channels.php:33` |
| 🔴 | Fuite cross-client du token de commande et queue_number sur canal branche-large | Payload diffuse contient token (PersistOrderStatusChangedToOutbox:28) et queue_number (PersistOrderCreatedToOutbox:26) de chaque commande, pousse sur private-branch.{id} auquel s'abonnent to | `app/Listeners/PersistOrderStatusChangedToOutbox.php:28` |
| 🟠 | Echo.leave partage coupe les autres abonnes du meme canal branche | unsubscribe() fait window.Echo.leave('branch.'+id) (eventContract.js:112) detruisant le canal entier. laravel-echo partage l'objet canal par nom : KioskWaiting.beforeUnmount (:194) tue aussi | `resources/js/services/eventContract.js:112` |
| 🟠 | Canal client App.Models.User.{id} autorise mais jamais diffuse ni souscrit | channels.php:16 l'autorise, mais aucun listener outbox n'y ecrit (Persist* ne ciblent que private-branch) et aucun JS ne s'y abonne. Le client n'a aucun temps reel prive ; sa seule voie real | `app/Listeners/PersistOrderStatusChangedToOutbox.php:30` |
| 🟡 | Plafond soketi maxConnections=100 pour toutes les surfaces et branches | soketi.json fixe maxConnections:100 sur l'unique app, partage par POS/kiosks/KDS/OSS/site de toutes les branches. Au-dela, soketi refuse les souscriptions -> perte temps reel silencieuse + r | `soketi.json:1` |
| 🟡 | Autz kiosk non-deterministe (->first) et re-auth token incomplet | channels.php:28 KioskMachine::where(user_id)->first() : si un user mappe plusieurs machines, seule la branche de la 1ere est verifiee. window._refreshEchoAuth (bootstrap.js:83) ne mute le he | `routes/channels.php:28` |
| ⚪ | Jeton Echo lu depuis vuex localStorage, priorite kioskToken sur authToken | _getEchoBearerToken (bootstrap.js:46-50) prend kioskCart.kioskToken avant auth.authToken. Sur kiosk partage ou un client se connecte, l'identite WS reste celle de la machine (kiosk:order), b | `resources/js/bootstrap.js:46` |

**🎯 Redesign :**

1. **Autz** : remplacer `branch_id===0 => wildcard admin` (channels.php:33) par une capacite explicite (ability/role ou pivot user_branches). branch_id=0 ne doit jamais signifier « toutes branches » (defaut des clients). Refus par defaut.
2. **Granularite** : diffuser sur `private-order.{orderId}` (proprietaire + staff branche) OU App.Models.User.{id} reellement branche cote publieur ET JS. Le canal branche ne transporte que de l'agrege non nominatif (ni token ni queue_number).
3. **Purger le payload** : jamais de token (secret de retrait) dans un broadcast branche ; le client le recupere via GET authentifie. Filtrage serveur, pas client.
4. **Cycle de vie** : supprimer le Echo.leave partage ; ne retirer que ses propres listeners (stopListening), leave seulement avec comptage de references par canal.
5. **Activer App.Models.User.{id}** (publieur+abonnement) pour un temps reel prive client, sans dependre du canal branche.
6. **Scalabilite** : relever/segmenter maxConnections, appManager en driver persistant (redis) multi-noeud, webhooks+metriques et signal UI a saturation.
7. **Presence** : canaux presence KDS/OSS pour detecter les ecrans hors-ligne.


---

### 🔄 Mode dégradé & resynchronisation à la reconnexion (surface kiosk)
**Source de vérité :** Backend = SSOT existence/statut commande (réconciliable via X-Idempotency-Key) ; Echo/Pusher branch.{id} = SSOT menu/dispo/statut temps réel. En dégradé le kiosk fabrique une vérité locale optimiste (queue localStorage + orderRef synthétique) jamais réconciliée contre le serveur.

**Modèle de cohérence :** Connecté : temps réel Echo + polling 10s de secours. Déconnecté : bascule en vérité locale optimiste SANS réconciliation serveur. Fenêtres : bannière 5s, label hors-ligne 30s (ConnectionStatusBanner:38,43) ; retry offline 30s x10 ≈5min puis abandon ; events Echo manqués = perdus (pas de replay) ; snapshot menu jusqu'à 24h. Net : INCOHÉRENT au retour réseau, état figé sur le dernier event reçu.

**Flux de synchro réel :**

Chemin réel (fichier:ligne) :

**Perte réseau à la commande**
1. `kioskCart.js:356` POST `frontend/order` (X-Idempotency-Key).
2. Échec → `kioskCart.js:366` `isNetworkError = !err.response || status>=500` → **5xx == offline**.
3. `kioskCart.js:369` `saveOrder(payload, idempotencyKey)` → localStorage (`kioskOfflineQueue.js:50`).
4. `kioskCart.js:374-384` résout une réponse **synthétique** `{_offline:true, queue:'—'}` + `SET_ORDER_REF(localKey)`. L'UI avance en confirmation comme si la commande était passée.

**Boucle de sync**
5. `startAutoSync` (`kioskOfflineQueue.js:162`) rejoue toutes les 30s ; `syncQueue:108` `await postFn(...)` — la **réponse est jetée** (id/queue serveur perdus).
6. Après 10 échecs → `kioskOfflineQueue.js:115` `synced=true; abandoned=true` : abandon. `_reportAbandoned:146` best-effort (exige réseau).

**Reconnexion**
7. `KioskAppComponent.js:258` autosync au mount ; `:262-269` sur WS `connected` → relance **seulement** la sync des commandes offline. Aucune relecture menu/dispo/statut.
8. Echo `eventContract.js:99` `channel.listen` — Pusher ne rejoue pas les events manqués pendant la coupure.

**Écran d'attente**
9. `KioskWaitingComponent.vue:184` `startsWith('offline_')` décide s'il faut poller.


**Failles de synchro :**

| Sév | Faille | Mécanisme | Emplacement |
|:--:|---|---|---|
| 🔴 | Commande payée abandonnée silencieusement après 10 essais | syncQueue marque synced=true+abandoned=true et cesse de rejouer. Le client a déjà quitté avec un ticket/queue '—' et a pu payer (écran payment). La commande n'atteint jamais la cuisine ; seu | `resources/js/helpers/kioskOfflineQueue.js:115` |
| 🔴 | Fausse confirmation : succès optimiste sans preuve serveur | submitOrder résout une réponse synthétique _offline et commit SET_ORDER_REF(localKey), faisant avancer l'UI vers confirmation comme si la commande existait côté backend. Aucune preuve (invar | `resources/js/store/modules/kioskCart.js:374` |
| 🟠 | 5xx serveur classé comme 'offline' | isNetworkError inclut status>=500. Un 500 (règle métier, item sold-out, prix changé, promo invalide) n'est PAS une coupure réseau : le payload est mis en file et rejoué 10x à l'identique, éc | `resources/js/store/modules/kioskCart.js:366` |
| 🟠 | Détection de commande offline cassée par FIX-54-3 | saveOrder(payload, idempotencyKey) fixe localKey = UUID d'idempotence (plus le préfixe 'offline_'). KioskWaiting teste startsWith('offline_') désormais toujours faux : l'écran POLLE frontend | `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:184` |
| 🟠 | Aucune réconciliation de orderRef après sync réussie | syncQueue jette la réponse du POST rejoué : l'id serveur réel et le queue_number ne sont jamais réinjectés dans le store. orderRef reste le localKey avec queue '—'. Même une sync tardive réu | `resources/js/helpers/kioskOfflineQueue.js:108` |
| 🟠 | Aucune resynchronisation menu/dispo/statut à la reconnexion | Le handler WS 'connected' ne relance QUE la sync des commandes offline. Aucun refetch du menu, de la disponibilité, ni relecture des OrderStatusChanged/ItemAvailabilityChanged manqués (Pushe | `resources/js/components/frontend/kiosk/KioskAppComponent.vue:262` |
| 🟡 | Heartbeat factice : connexion morte non détectée | _startHeartbeat fixe _lastPongAt=Date.now() toutes les 30s sans envoyer de ping ni comparer à un seuil de staleness ; n'émet aucun timeout et ne force aucune reconnexion. Une connexion demi- | `resources/js/services/WebSocketService.js:131` |
| 🟡 | Race saveOrder/syncQueue + quota localStorage silencieux | syncQueue charge la file au début (_load) puis réécrit _save(pruned) à la fin ; un saveOrder concurrent (nouvelle commande offline) est écrasé et perdu. De plus _save avale silencieusement u | `resources/js/helpers/kioskOfflineQueue.js:127` |

**🎯 Redesign :**

1. **Réconciliation** : syncQueue doit lire la réponse serveur (id+queue réels) et commit qui remplace le localKey dans kioskCart+KioskWaiting ; un orderRef offline reste 'en attente serveur', jamais 'confirmé'.
2. **Jamais d'abandon silencieux d'une commande payée** : après N essais, escalader (blocage écran + alerte staff persistée serveur), pas synced=true.
3. **Distinguer 5xx d'offline** : file offline seulement sur !err.response ; un 5xx applicatif remonte l'erreur métier (item indispo, prix changé) sans rejeu aveugle.
4. **Resync-on-reconnect complet** : sur WS 'connected', refetch menu+dispo (force) ET GET statut de toute commande active ; idéalement curseur/seq Outbox backend pour rejouer les events depuis le dernier reçu.
5. **Heartbeat réel** : ping applicatif + seuil de pong ; sur staleness, forcer reconnect Echo.
6. **File atomique** : sérialiser saveOrder/syncQueue sous verrou ; surfacer l'échec de quota comme erreur bloquante, pas un succès.
7. **Détection offline** par flag explicite, pas par préfixe de clé.


---

### 🔄 Panier & session — synchro cross-surface / cross-device
**Source de vérité :** Panier = AUCUNE verite backend : etat localStorage jetable, distinct par surface (posCart scope b/u, kioskCart, frontendCart par navigateur, app mobile). Jamais synchro cross-device. Seule verite serveur = la commande `orders`, dedupliquee par idempotency_key (unique composite branch_id+key).

**Modèle de cohérence :** INCOHERENT / aucune synchro cross-device : etat local par appareil et surface, aucun temps-reel ni session serveur, aucun partage web<->app<->POS. Fenetres : (a) reload POS -> panier NON scope restaure avant setScope async = fuite inter-caissier ; (b) restauration jusqu'a 2h (POS) / indefinie (kiosk) avec items indisponibles ; (c) dedup = coherence-a-la-soumission, cassee si cle regeneree.

**Flux de synchro réel :**

Chemin reel (source -> persistance -> soumission -> dedup serveur) :

**POS** : `ItemComponent.vue:991` dispatch `posCart/lists` -> `state.lists` + `saveCartToStorage` vers cle SCOPEE `pos_cart_v3:b{branch}:u{user}` (`posCart.js:26-45`). En parallele `vuex-persistedstate` persiste TOUT le module `posCart` vers la cle globale `vuex` NON scopee (`index.js:224`). Checkout : `PosComponent.vue:1496` genere `idempotency_key=${Date.now()}_${rand}_${branch}` -> `posOrder.js:73-79` envoie `X-Idempotency-Key` -> `OrderService::posOrderStore:553` pre-check GLOBAL `Order::where('idempotency_key')` + contrainte DB composite (`migration:35`).

**Kiosk** : `kioskCart.addItem` -> `state.items` ; persiste items/idempotencyKey/loyaltyCustomer (`index.js:231-237`). `submitOrder:344` genere la cle une fois, POST `frontend/order` (`kioskCart.js:357`) -> `FrontendOrderService::myOrderStore:129` : Cache::lock SCOPE branche (`:132`) mais lecture GLOBALE (`:136`). Hors-ligne : `saveOrder` (`kioskOfflineQueue.js:50`) + rejeu meme cle (`:108`).

**Web** : `frontendCart.js` -> AUCUNE cle d'idempotence (aucun X-Idempotency-Key dans le chemin web).

FrontendOrder ET Order = MEME table `orders` (`FrontendOrder.php:19`).


**Failles de synchro :**

| Sév | Faille | Mécanisme | Emplacement |
|:--:|---|---|---|
| 🟠 | Double persistance du panier POS : couche vuex-persistedstate NON scopee contredit le scop | index.js:224 persiste tout `posCart` vers la cle globale `vuex`, en parallele du localStorage scope `pos_cart_v3:b:u` (posCart.js:26). Au reload, persistedstate rehydrate les lignes du caiss | `resources/js/store/index.js:224` |
| 🟠 | Commandes WEB sans aucune idempotence -> double commande sur double-clic | frontendCart n'emet aucun X-Idempotency-Key (zero occurrence dans le chemin web). myOrderStore traite un header absent comme 'pas de dedup' (FrontendOrderService.php:130) -> un double-submit | `resources/js/store/modules/frontend/frontendCart.js:32` |
| 🟠 | Cle d'idempotence POS regeneree a chaque ouverture du modal de paiement | PosComponent.vue:1496 regenere idempotency_key dans submitCheckout (ouverture du modal), pas par tentative de paiement. Si le caissier ferme puis rouvre le modal apres un envoi lent/echoue,  | `resources/js/components/admin/pos/PosComponent.vue:1496` |
| 🟡 | Lecture d'idempotence GLOBALE alors que la contrainte DB est composite (branch_id, key) | Le lock est scope branche (FrontendOrderService.php:132) mais la lecture pre-check est globale (:136, :596 ; idem OrderService:555,961). La contrainte autorise (A,K)+(B,K) (migration:35) ; u | `app/Services/FrontendOrderService.php:136` |
| 🟡 | RESET kiosk annule la cle d'idempotence entre tentatives -> doublon possible | kioskCart.js:209 met idempotencyKey=null dans RESET (idle/logout/nav). Si RESET se declenche entre un submit lent et un retry, submitOrder:345 genere une nouvelle cle -> 2e commande. De plus | `resources/js/store/modules/kioskCart.js:209` |
| 🟡 | Panier restaure (2h POS / refresh kiosk) sans reconciliation disponibilite/prix | loadCartFromStorage ne verifie que le TTL 2h (posCart.js:64), aucun controle de disponibilite vs snapshot menu ; kiosk se contente d'un warn snapshot >4h (kioskCart.js:335) sans retirer les  | `resources/js/store/modules/posCart.js:64` |
| 🟡 | Identite fidelite du client precedent persistee cote borne entre clients | index.js:232 persiste kioskCart.loyaltyCustomer (loyalty_code). RESET le nettoie (kioskCart.js:207) seulement si l'idle-watcher declenche ; un client qui s'eloigne, ou un reload avant idle,  | `resources/js/store/index.js:232` |
| ⚪ | Aucune synchro cross-device : panier web non lie au compte serveur | frontendCart est persiste par-navigateur en localStorage (index.js:221) sans etat serveur ni temps-reel ; l'app mobile a son propre etat. Un client connecte changeant de navigateur/appareil  | `resources/js/store/index.js:221` |

**🎯 Redesign :**

1) Panier serveur (table `carts` scope user+branch) comme SoT pour client authentifie, diffuse via `App.Models.User.{id}` -> vraie coherence web<->app ; localStorage = cache offline. 2) Retirer `posCart` des paths persistedstate (index.js:224) : une seule couche, la cle scopee b/u ; hydrater uniquement via setScope -> ferme la fuite inter-caissier. 3) Cle d'idempotence liee au panier (hash contenu+branch+user), generee a la 1ere tentative, NON effacee par un reset de vue, purgee seulement au succes confirme. 4) Aligner lecture et contrainte : pre-check branch-scope `where(branch_id)->where(key)` partout (FrontendOrderService:136/596, OrderService:555/961) -> ferme la fuite inter-branche. 5) Ajouter l'idempotence au chemin WEB. 6) Revalider chaque ligne restauree contre le snapshot menu courant, retirer/marquer les indisponibles avant affichage. 7) Reset borne robuste (purge serveur/heartbeat en plus de l'idle) et ne jamais persister loyaltyCustomer.


---

### 🔄 Cohérence de cache & source de vérité par type de donnée
**Source de vérité :** DB = vérité pour menu/prix/dispo (items, item_categories, item_branch_availability); kiosk.menu.branch.{id} n'est qu'une projection cache 60s. Fidélité: users.loyalty_points (DB, non caché). Statut commande: orders.status (DB, diffusé). Panier: Vuex client non autoritaire, recalculé backend.

**Modèle de cohérence :** Éventuelle, best-effort. 86: cache censé temps réel (<1s) via forget, MAIS invalidation émise DANS la tx (avant commit) → fenêtre stale ≤60s. Catalogue: éventuelle ≤60s (TTL + forget). snapshot_version et cache HTTP suivent des déclencheurs différents → mutuellement incohérents. Statut commande: temps réel (broadcast afterCommit).

**Flux de synchro réel :**

## Menu / dispo

1. 86: AvailabilityService::toggle() ouvre DB::transaction (:38), save ligne (:68), puis event(ItemAvailabilityChanged::forBranch) DANS la tx (:70).
2. 3 listeners synchrones (EventServiceProvider.php:108-115): Persist...ToOutbox (outbox), BumpMenuSnapshot → MenuSnapshot::bump() INCR menu:snapshot_version:branch:{id} (MenuSnapshot.php:52), InvalidateKioskMenuCache → Cache::forget('kiosk.menu.branch.{id}') (Invalidate...AvailabilityChanged.php:72).
3. Lecture borne: MenuController::kiosk() résout branch via KioskMachine (:44), Cache::remember(clé, 60, KioskMenuService::build) (MenuController.php:71). build() relit ItemBranchAvailability scopé branche (:73-79), dispo = item.is_available global AND ligne branche (:280).

## Catalogue
ItemCreated/ItemDeleted/Category* (ItemService.php:182,306; ItemCategoryService.php:119,151,186) → SEULEMENT InvalidateKioskMenuCacheOnCatalogChange (Cache::forget, EventServiceProvider.php:116-130). Pas de bump snapshot.

## 2e chemin (mort)
MenuProjectionService::forChannel() (:60) renvoie snapshot_version (:152) via /api/admin/menu-projection — non consommé par les surfaces (docblock :26-29).


**Failles de synchro :**

| Sév | Faille | Mécanisme | Emplacement |
|:--:|---|---|---|
| 🟠 | Invalidation cache kiosk émise AVANT commit → re-peuplement stale ≤60s | event(ItemAvailabilityChanged) dispatché dans DB::transaction (AvailabilityService.php:38→:70). Cache::forget (Invalidate...:72) s'exécute AVANT COMMIT; une requête borne concurrente (MenuCo | `app/Services/Menu/AvailabilityService.php:70` |
| 🟡 | snapshot_version non bumpé sur create/delete/rename → pas de re-fetch client | BumpMenuSnapshot n'est câblé qu'à ItemAvailabilityChanged (EventServiceProvider.php:110), absent de ItemCreated/ItemDeleted/Category* (:116-130). Un client comparant snapshot_version au reco | `app/Providers/EventServiceProvider.php:116` |
| 🟡 | Deux projections menu divergentes (Kiosk vs MenuProjectionService) | La borne consomme KioskMenuService::build() (is_available = global AND branche, :280) tandis que le contrat SSOT MenuProjectionService::forChannel() (:60) calcule la dispo sans ce AND (:120) | `app/Services/Kiosk/KioskMenuService.php:45` |
| 🟡 | Lecture cross-branche: branch_id du query sans contrôle d'appartenance | MenuProjectionController::show() valide branch_id comme simple entier (:38) et le passe à forChannel() sans vérifier que l'admin appartient à cette branche. Le cache est bien clé par branche | `app/Http/Controllers/Admin/MenuProjectionController.php:38` |
| 🟡 | Pas de Cache-Control no-store sur réponse à état-branche + forget avalé | /api/frontend/menu (MenuController.php:74) est spécifique à la branche mais JsonMiddleware ne pose aucun Cache-Control/no-store (:24-27) → risque de cache proxy/CDN d'une branche servie à un | `app/Http/Middleware/JsonMiddleware.php:24` |
| ⚪ | MenuSnapshot::bump() non atomique sur driver cache par défaut (file) | config/cache.php:18 met CACHE_DRIVER=file par défaut mais MenuSnapshot.php:16-17 suppose un INCR Redis atomique. Sur file, increment() est un read-modify-write non verrouillé: deux toggles s | `app/Services/Menu/MenuSnapshot.php:62` |

**🎯 Redesign :**

1. Invalidation post-commit: déplacer forget + bump en DB::afterCommit (ou listeners ShouldHandleEventsAfterCommit) → cache purgé seulement après COMMIT, repeuplé frais. Ferme la fenêtre stale.
2. Un seul déclencheur: coupler bump snapshot ET forget sur TOUS les events catalogue (create/delete/category), pas que availability. Version et cache bougent ensemble.
3. Une seule projection: retirer KioskMenuService OU MenuProjectionService; la borne consomme la même source que le contrat snapshot, et l'enveloppe HTTP retourne snapshot_version pour un re-fetch conditionnel (ETag/If-None-Match plutôt que TTL aveugle).
4. Isolation lecture: dériver branch_id du contexte authentifié (KioskMachine / branche admin), jamais du query param; autoriser explicitement l'inter-branche.
5. En-têtes: Cache-Control: private, no-store sur toute réponse à état-branche pour bloquer proxy/CDN.
6. Store: forcer Redis pour les compteurs, ou remplacer increment par un lock nommé sur file. Loguer/alerter sur échec d'invalidation au lieu d'avaler.


---

## F. FIDÉLITÉ — synchronisation des points (section orchestrateur)

> L'agent dédié a échoué sur la génération structurée ; section rédigée à partir de la reconnaissance directe du code + des findings confirmés (rapports 10, 12, 14).

**Source de vérité :** un **unique entier mutable** `users.loyalty_points` (+ `users.loyalty_code` unique). Par commande : `orders.loyalty_points_awarded` (idempotence du gain) et `orders.loyalty_customer_code` (pont pour la borne, dont `user_id`=machine ≠ client).

**Flux réel (multi-canal, non sérialisé) :**
- **Gain** : *asynchrone*, à la livraison — `AwardLoyaltyPointsOnDelivery` lit `loyalty_customer_code`, crédite (`increment loyalty_points`).
- **Débit (redeem)** : *synchrone*, à la commande — web `LoyaltyController:304` (`decrement`), borne inline `FrontendOrderService:478` (`decrement`).
- **Remboursement** : `LoyaltyService:58` (`increment`) sur annulation, **cherché par `order_id`** uniquement.
- **Reset** : `LoyaltyController:163` pose `loyalty_points = 0` au (re)register.

**Failles de synchronisation confirmées :**

| Sév | Faille | Mécanisme | Emplacement |
|:--:|---|---|---|
| 🔴 | Lost update / double crédit-débit | Écritures concurrentes (gain async, redeem web, redeem borne, remboursement) sur **un seul entier sans `lockForUpdate` ni transaction ni ledger** → deux flux concurrents s'écrasent ; double transition livraison = double `increment` | `app/Http/Controllers/Frontend/LoyaltyController.php:214` |
| 🔴 | Solde effacé au (re)register | `loyalty_points = 0` sur register d'un téléphone existant → le solde du client est réinitialisé à zéro | `app/Http/Controllers/Frontend/LoyaltyController.php:163` |
| 🟠 | Points débités via `redeem` non remboursables | `redeem` écrit une transaction `order_id=null`; `refundPoints` ne cherche que `where order_id=$order->id` → jamais restitués à l'annulation | `app/Services/LoyaltyService.php:27` |
| 🟠 | Vol de points (ownership absente) | `loyalty_code` accepté du client sans preuve d'appartenance → n'importe qui débite/applique les points d'un autre via la borne | `app/Services/FrontendOrderService.php:463` |
| 🟠 | Gain async vs débit sync = fenêtre d'incohérence | Le gain n'arrive qu'à l'événement livraison (peut être perdu, cf. axe Outbox) ; entre commande et livraison, le solde affiché diverge du réel ; un gain perdu n'est jamais rejoué | `app/Listeners/AwardLoyaltyPointsOnDelivery.php:1` |
| 🟡 | Pas de grand-livre → non auditable/réconciliable | Le solde est un compteur muté en place, sans historique de transactions : impossible de reconstruire, d'auditer, ou de détecter une dérive | `database/migrations/2026_03_08_145926_add_loyalty_fields_to_users_table.php:17` |

**🎯 Redesign fidélité :** remplacer l'entier mutable par un **grand-livre de points append-only** (`loyalty_ledger` : `user_id, order_id, delta, reason, balance_after, created_at`), **solde dérivé** (ou colonne cache reconstruite), toute mutation **sérialisée** (`DB::transaction` + `lockForUpdate` sur l'utilisateur), **gain idempotent** clé `order_id` (via `loyalty_points_awarded`), **remboursement** couvrant redeem+earn (par `user_id`+`order_id`), **ownership obligatoire** du `loyalty_code` (OTP/auth), endpoints `/loyalty/*` privés. Le register ne réinitialise jamais un solde existant.


---

## 4. Ordre de refonte de la synchro (par levier)

1. 1. Outbox atomique : INSERT domain_events DANS la tx de mutation (OrderService:948), supprimer le try/catch qui avale en 200 (:949), marquer dispatched_at APRES diffusion reussie avec dedup, jamais si Pusher absent (:95).
2. 2. Serialiser la fidelite : verrou pessimiste ou increment atomique DB sur users.loyalty_points pour les 5 chemins concurrents, sous tx avec loyalty_points_awarded. Risque monetaire -> escalade humaine.
3. 3. Rebrancher la propagation CLIENT : migrer FCM legacy (FcmNotificationService:76) vers HTTP v1, publier ET souscrire App.Models.User.{id}, aligner le topic FCM sur celui souscrit (SendFcmOnOrderStatusChange:97).
4. 4. Canal de suivi pour la borne : jeton anonyme (via loyalty_customer_code deja pont) permettant abonnement order.{ref} ou push cible, pour que le client borne recoive son statut (OrderPushNotificationBuilder:30).
5. 5. Fermer la fuite securite/isolation : retirer le bypass branch_id=0 (channels.php:33), scoper les tokens admin, retirer token/queue_number du payload et filtrer cote serveur, pas client.
6. 6. Chemin commande lecteur de dispo transactionnel : verifier item_branch_availability et decrementer stock DANS la tx (FrontendOrderService:835, AvailabilityService:140) ; unifier les deux drapeaux is_available.
7. 7. Filet synchro client + reconnexion : Echo/polling sur suivi web (:304), replay events kiosk, reconciliation serveur de la queue offline (retirer fausse confirmation kioskCart:374 et abandon :115), 5xx != offline (kioskCart:366).
8. 8. Pousser le menu sur toutes mutations : bumper snapshot sur create/delete/categorie, inclure la dispo par branche dans le payload de chargement, aligner snapshot_version et cache HTTP sur les memes declencheurs.
9. 9. Reduire les fenetres staff : sur frame perdu, refetch cible plutot qu'attendre le poll 60s (KitchenDisplaySystemComponent:553), abonner l'admin branch_id=0 aux branches observees.


## 5. Failles écartées par la vérification

- Propagation menu / prix vers toutes les surfaces (POS, borne kiosk, site, app) — Create/Delete/Catégorie ne poussent aucun refetch à la borne (`app/Listeners/InvalidateKioskMenuCacheOnCatalogChange.php:14`) : Premisse fausse: le listener catalogue purge kiosk.menu.branch.{id} (CatalogChange.php:45), clef EXACTE lue par la borne (MenuController.php:67). ItemCreated/De
- Outbox — atomicité, garantie de livraison, ordre, idempotence — Aucune idempotence côté consommateur (pas d'event_id) (`app/Domain/Events/EventContract.php:59`) : Premisse fausse, impact non prouve. correlation_id n'est pas regenere par broadcast: fixe une fois (l.33), stocke, relu = id stable unique par event. Consommate

---

*Audit de synchronisation globale par orchestration multi-agents + vérification adversariale. Source de vérité cartographiée par donnée ; le temps réel client (site + app) est le point de rupture majeur. Aucune modification de code.*