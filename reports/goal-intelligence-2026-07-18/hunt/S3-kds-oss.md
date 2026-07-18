# S3 — Chasse LOGIQUE KDS + OSS (read-only) — 2026-07-18

Périmètre : écran cuisine (KDS) + mur client (OSS). Serveur :8000 UP, DB `foodking_e2e` (probes tinker read-only). Aucune modification de code.

Fichiers lus : `KitchenReleaseRule.php`, `KdsSyncService.php` (back), `KitchenDisplaySystemOrderService.php`, `OrderStateMachine.php`, `OrderStatusScreenOrderService.php`, contrôleurs KDS/KdsSync/OSS, events (`OrderStatusChanged`, `KdsOrderRecalled`), listeners (`DispatchKdsTicket`, `PersistKdsOrderRecalledToOutbox`, `PersistOrderStatusChangedToOutbox`, `PrintKioskKitchenTicketOnOrderCreated`), routes api.php:1231-1356, front : `KitchenDisplaySystemComponent.vue` (3199 l.), `KdsV2Grid.vue`, `KdsOrderCard.vue`, `KdsHistoryDrawer.vue`, `PreparingAndReadyComponent.vue`, stores `kds.js`/`kitchenDisplaySystemOrder.js`/`orderStatusScreenOrder.js`, services `KdsSyncService.js`/`OssSyncService.js`, `kitchenLocalPrinter.js`, `kdsAutoTransition.js`, FormRequests KDS.

---

## FINDINGS CONFIRMÉS

### [P2] resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue:303 — Raccourcis clavier [D]–[H] bumpent des commandes INVISIBLES (hors « 3 cartes max »)
- **Fait** : depuis KDS-3CARDS (2026-07-05), la grille ne rend que `visibleActiveOrders = activeOrders.slice(0, 3)` (L250-252) et seules ces 3 cartes portent un badge raccourci `SHORTCUTS[idx]` (L68). Mais `onKey` (L302-313) indexe **`this.activeOrders`** (la file complète, 8 lettres A–H L127) : `if (idx >= 0 && idx < this.activeOrders.length) { ... this.onCtaTap(o.id, ...) }`.
- **Effet** : avec 4+ commandes actives, presser D/E/F/G/H fait avancer (ACCEPT→PREPARING ou PREPARING→PREPARED) une commande **non affichée**, sans badge à l'écran, avec notifications client (SendOrderMail/Sms/Push dispatchées par `changeStatus`, KitchenDisplaySystemOrderService.php:516-518) pour un ticket que le chef n'a jamais vu. Seule trace : l'aria-live.
- **Repro** : KDS V2 avec ≥4 commandes actives → touche « D » → la 4e (cachée derrière « +N en attente ») transitionne côté serveur (202) ; le client peut être annoncé « prêt » sans préparation.
- **Preuve** : le commentaire L299-301 (« Index against activeOrders (the rendered list) ») date de Wave U où activeOrders ÉTAIT la liste rendue ; le slice(0,3) de 2026-07-05 a cassé l'invariant sans réaligner onKey.
- **Piste (non appliquée)** : borner à `visibleActiveOrders.length` (et/ou masquer les touches D-H).

### [P2] resources/js/store/modules/kitchenDisplaySystemOrder.js:42 — Le refresh post-bump réutilise le PAYLOAD DU BUMP comme FILTRE de liste → board transitoirement faux + carillon « nouvelle commande » fantôme à chaque bump
- **Fait** : `changeStatus` fait `context.dispatch("lists", payload)` avec `payload = {id, status, expected_status}` (kds.js:3-9). `appService.requestHandler` (appService.js:270-290) sérialise TOUT → `GET admin/kds-order?id=X&status=7&expected_status=4`. Le backend applique le filtre `status` (KitchenDisplaySystemOrderService.php:160-161 `where('status', 7)`) → le commit Vuex `lists` ne contient QUE les commandes du nouveau statut. Même défaut chemin 409 (store L46).
- **Effet 1 (board)** : `KdsV2Grid :orders="orders"` lit DIRECTEMENT ce Vuex (KitchenDisplaySystemComponent.vue:56 + computed L1505-1507) → après chaque bump, les cartes des AUTRES statuts (ACCEPT restants, bandeau « Récemment servies » PREPARED) disparaissent jusqu'au refresh correctif (interceptor axios L1650-1668 immédiat + `_debouncedRefresh` 300 ms L2708-2716). Deux GET concurrents partent (filtré + complet) : si le filtré résout en dernier, le board reste faux jusqu'au correctif suivant.
- **Effet 2 (carillon)** : le watcher `orders` (L1546-1560) diffe par ids. Séquence commit-filtré → commit-complet : les cartes restaurées sont vues comme « nouvelles » → `playKdsNewOrderSound()` sonne **sans nouvelle commande** (throttle 2,5 s, kdsDisplay.js:6) + passage dans `autoPrintNewKitchenTickets` (sauvé du double ticket uniquement par la dé-dup localStorage).
- **Repro** : board avec 1 ACCEPT + 1 PREPARING → bump l'ACCEPT → réseau : 2 GET dont `?...status=7...` ; UI : flicker des autres cartes + ding fantôme.
- **Piste** : dispatcher `lists` avec le filtre COURANT (ou `{vuex:false}`), pas le payload du POST.

### [P2] resources/js/helpers/kitchenLocalPrinter.js:100-116 — Dé-dup impression cuisine PAS cross-onglet : 2 onglets /kds sur le PC cuisine = ticket physique EN DOUBLE pour chaque commande
- **Fait** : `_load()` mémoïse `_printed` par onglet (lit localStorage UNE fois puis cache module) ; `markKitchenPrinted` persiste mais l'autre onglet ne relit jamais. Aucun listener `storage` ni BroadcastChannel pour ce set (grep : les seuls listeners `storage` sont app.js:248 / pos-app.js:211, sync du token auth ; BroadcastChannel = kioskOfflineQueue uniquement). Le guard `_kitchenInFlight` (KitchenDisplaySystemComponent.vue:2019-2033) est lui aussi par onglet.
- **Effet** : deux onglets KDS ouverts sur le PC cuisine (pont USB local 127.0.0.1:9101, partagé par tous les onglets du poste) reçoivent la nouvelle commande (WS/poll chacun), chacun voit `hasKitchenPrinted=false` dans SA mémoire → 2 POST /raw → **2 tickets imprimés**. Erreur humaine triviale (double-clic sur le favori, onglet restauré).
- **Périmètre exclusion** : le heal 2026-07-13 (« réimpression doublon 20 s » + échec perdu au reload, `171cf0ae9`) est INTRA-onglet (retry + persistance) ; ce vecteur cross-onglet n'est pas couvert — finding distinct.
- **Piste** : re-lire localStorage avant impression + écouter `storage`/BroadcastChannel, ou verrou d'onglet (Web Locks).

### [P3] app/Services/KdsSyncService.php:51,150-167 — `deleted_ids` ignore RETURNED(22) et OUT_FOR_DELIVERY(10) : le delta sync ne signale pas 2 sorties de board réelles
- **Fait** : `$inactiveStatuses = [DELIVERED, CANCELED, REJECTED]`. Or une commande active peut sortir du board via **RETURNED** (remboursement pré-Z depuis ACCEPT/PREPARING/PREPARED — OrderStateMachine.php:48,59,67, permission pos-refund) ou **PREPARED→OUT_FOR_DELIVERY** (livraison). Probe DB : **10 transitions réelles** actif→{22,10} sur 30 j (`order_status_transitions`), donc chemins vivants.
- **Impact borné (vérifié)** : le front n'applique pas les deltas — le payload sync sert de TRIGGER de full refresh (KitchenDisplaySystemComponent.vue:1677-1686 `hasFreshOrders || hasDeletes → _debouncedRefresh`). La sortie est normalement couverte par le broadcast `OrderStatusChanged` (old ∈ {4,7,8} → refresh, L1731-1741) et par le poll full (5 s WS-down / 60 s WS-up). Fenêtre résiduelle : WS down + seule activité = ce départ → le tick sync ne déclenche rien, carte fantôme ≤5 s (poll). Incohérence de contrat (`include_deleted` incomplet) plus que dégât réel en V1.
- **Piste** : ajouter RETURNED + OUT_FOR_DELIVERY à `$inactiveStatuses` du delta.

### [P3] OrderService.php:3357-3383 + fenêtres 8 h — Recyclage des numéros après minuit : deux « N°X » simultanés possibles sur mur OSS / KDS
- **Fait** : le compteur queue est remis à A0032 par **business_date civile** (unique branch+business_date+queue → duplicates CROSS-date autorisés par schéma ; probe : A0032 réutilisé sur 20 jours, branche 1). Les fenêtres KDS/OSS sont GLISSANTES 8 h et straddlent minuit (Le Cayenne 23 h-02 h, choix assumé ULTRA MINUIT-STRADDLE 2026-07-04). Une commande d'hier soir encore PREPARING/PREPARED après minuit peut coexister avec la même N° réattribuée aujourd'hui si le compteur du jour la rattrape → 2 lignes « N°32 » sur le mur (`key=item.id`, PreparingAndReadyComponent.vue:34-37/59-62) et 2 cartes KDS mêmes numéros → ambiguïté d'appel client.
- **État live** : probe fenêtre courante : 0 collision (`dups=[]`). Probabilité faible (départ 32, il faut que le compteur du jour atteigne un numéro d'hier ENCORE actif <8 h) mais réelle en rush post-minuit. P3 assumé/documenté à surveiller.

### [P3] app/Services/KitchenDisplaySystemOrderService.php:349-358 — TTL du recall ancré sur `updated_at` : toute écriture ultérieure ROUVRE la fenêtre « Annuler bump » 60 s
- **Fait** : le guard TTL compare `updated_at` (proxy du bump) à now-60 s. Or `updated_at` avance sur TOUTE écriture de la ligne : encaissement au comptoir d'un PREPARED PENDING_COUNTER (payment_status→PAID), edit admin… → le bouton « ↶ Annuler bump » réapparaît (KdsHistoryDrawer.vue:416-428, même ancre) et le serveur ACCEPTE un recall des minutes après le vrai bump. Le commentaire [#13] (L363-366) reconnaît ce décalage pour la dédup (ancrée fenêtre glissante) mais PAS pour le guard TTL lui-même.
- **Impact** : faible — action compensatoire cosmétique (status jamais muté, cap N=1/fenêtre, notification client intacte) ; le contrat « 60 s après le bump » n'est pas tenu. Effet jumeau : `historyToday` trié `updated_at desc` re-remonte une commande au paiement/refund (tri « bump récent » approximatif — TODO déjà tracé D-03bis pour `status_changed_at`).

### [P3] resources/js/store/modules/kds.js:55-62 — `kds.bumped_items_v1` (bump item-level local) croît sans borne dans localStorage
- **Fait** : `bumpItem` ajoute `{orderId:{itemId:ts}}` à chaque bump d'article (layout legacy) ; seule `recallItem` retire une entrée. Aucune purge par âge/complétion/commande partie du board — contrairement au set d'impression borné à 500 (kitchenLocalPrinter.js:99). Sur un poste cuisine 24/7, le map accumule tous les orders historiques (parse+stringify complet à chaque bump).
- **Impact** : hygiène/perf long-run (localStorage bloat), pas de corruption d'état serveur (bump item = local-only, bannière l'assume).

---

## ÉCARTÉS (vérifiés sains) — raisons

1. **Transitions illégales serveur (lens 1)** : chemin KDS triple-gardé — FormRequest `Rule::in(4,7,8)` sur status ET expected_status (KdsOrderStatusRequest), `KitchenReleaseRule::canTransition` (ACCEPT→PREPARING→PREPARED uniquement), `OrderStateMachine::allows`, verrou optimiste `expected_status` → 409 + lockForUpdate. PAID→PENDING impossible ; « bump après recall » = PREPARED→PREPARED no-op (`changed=false`). Chemin admin/POS (OrderService::changeStatus:2141+) revalide sous lock (race guard F3) + sealed-Z guard. L'état est gardé serveur, pas juste UI.
2. **Fuite impayée en cuisine (lens 2)** : SSOT `applyBoardReleaseFilter` (PAID | PENDING_COUNTER | POS-cash) partagé par list/orderItems/sync/OSS list/listForBranch + guard bump (orderIsReleasedForBoard). Probe DB : 0 UNPAID non-cash en PREPARING/PREPARED (3 j). PENDING_COUNTER sur board = Plan B voulu (note « non encaissé » sur carte, CTA actif — owner reversal D1).
3. **OSS filtres (lens 4)** : allowlist fail-closed KIOSK/TAKEAWAY, statuts PREPARING/PREPARED, board-release, fenêtre 8 h + advance sans plancher, identifiant visible exigé (queue OU token), tri FIFO déterministe. `listForBranch` = jumeau conforme ; publicIndex → fallback 1re branche active, aucune branche → mur vide (jamais toutes-branches). Probe live `frontend/oss-order` : 200 `{"data":[]}` cohérent avec DB (0 en fenêtre). Un « prêt » sort du mur au DELIVERED (poll 5 s mur public) ; lingering max 8 h si personne ne livre = opérationnel, pas logique.
4. **Reconnexion WS (lens 6)** : KDS `_onWsConnected` → full refresh + restart polling ; OSS idem + burst-poll visibilité ; `reconnect_storm` → forceSync jitté 0-500 ms ; erreurs 4xx/réseau → backoff (OssSyncService [#6]) ; 401/403 sync → bannière bruyante. Architecture poll-full = tolérante à la perte d'events, pas de replay nécessaire. `since` initial = horloge client mais sync n'est qu'un trigger (full list au mount).
5. **Doublon impression intra-onglet (lens 3)** : in-flight guard sur les 3 chemins (auto/retry/réimpression manuelle), dé-dup persistée 500, seed backlog excluant les échecs, timeout client 20 s > timeout pont 15 s (anti faux-échec→retry→double). Couvert par heals 2026-07-13 (exclu du scope).
6. **`server_now` du cache 5 s (KdsSyncService back)** : cache hit renvoie un server_now vieux ≤5 s → le `since` suivant RECOUVRE (overlap-safe, version-gate côté client) ; jamais de trou.
7. **Recall : idempotence/diffusion** : clé idempotency stable par minute + cap serveur N=1 (409) + fenêtre RAPPELÉ ancrée sur `recalled_at` broadcast ([#9]) ; outbox `PersistKdsOrderRecalledToOutbox` idempotent (sha1 type|id|at|corr). 422 fenêtre expirée ne fake plus le badge (F6 healé) + refetch canonique ([#12]).
8. **`orderFilter.kitchen_status` (OSS service)** : colonne inexistante en DB MAIS propriété morte — `list()` n'applique jamais `$this->orderFilter`. Aucun chemin d'erreur.
9. **Auto-promote ACCEPT→PREPARING** : `v2AutoTransitionEnabled` épinglé `false` (KitchenDisplaySystemComponent.vue:1306) + prop default false → chemin inactif en prod.
10. **Multi-station (lens 5)** : filtre station purement client (`filterOrdersByStation`, préférence par user) ; `kds_station` porté par item ; pas de routing serveur par station en V1 — pas d'état divergent serveur.

## Probes DB (read-only, foodking_e2e)
- `RETURNED depuis statuts cuisine (30 j)` = 1 ; `actif→{RETURNED,OUT} (30 j)` = 10 → chemins F4 vivants.
- `unpaid_noncash_preparing (3 j)` = 0 → pas de fuite board.
- `pending_counter_active (fenêtre 8 h)` = 0 ; `fenêtre mur` = 0 ligne, 0 dup queue.
- Recyclage cross-date : A0001/A0002/A0003/A0004/A0032 réutilisés 20-22 business_dates (branche 1).
- `kitchen_recall` = 13 lignes (feature utilisée).
