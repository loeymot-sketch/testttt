# AUDIT P-MEGA-17 — Offline queue K-3 v2 baseline (Phase A.1 du cycle W7)

**Date** : 2026-04-20  
**Mode** : READONLY  
**HEAD** : `9c8f9e202`  
**Subagent** : explore very thorough  

## 0. Synthèse exécutive (5 lignes)

La file offline actuelle est **`localStorage` + `JSON`**, sync **toutes les 30 s** si pending, **sans backoff** entre échecs (abandon à 10 échecs), avec **`X-Idempotency-Key`** conservée au replay — alignée avec `FrontendOrderService::myOrderStore`. **Echo/Pusher est déjà câblé** sur le shell kiosk pour `ItemAvailabilityChanged` ; le menu offline utilise déjà **IndexedDB via `idb-keyval`**. Il manque pour une **v2** : persistance queue **hors `localStorage`**, **revalidation / conflit** des entrées queue vs rupture, et tests **IDB**. Tout changement **backend** sur `FrontendOrderService` / **fiscal** / **`payment-confirm`** déclenche la **hard gate** décrite au §11.

## 1. État `kioskOfflineQueue.js` baseline

**Fichier** : `resources/js/helpers/kioskOfflineQueue.js`

| Attendu | Réel |
|--------|------|
| `enqueue` / `dequeue` / `flush` / `getStatus` | **`saveOrder`**, **`syncQueue`** (flush), **`getPendingCount`**, **`getAbandonedCount`**, **`startAutoSync`**, **`stopAutoSync`**, **`clearQueue`** — pas de `dequeue` ni `getStatus` nommés. |
| Stockage | **`localStorage`**, clé `kiosk_offline_queue_v1` (L19, L37–L51). **Pas d'IndexedDB** dans ce module. |
| Sérialisation | **`JSON.stringify`** tableau d'entrées `{ localKey, payload, savedAt, attempts, synced, (+ abandoned*, abandonedAt*) }` (L62–L74, L136–L141). |
| Retry | **Pas d'exponentiel** : à chaque run de **`syncQueue`**, en cas d'échec **`attempts++`** puis prochaine tentative au prochain run (**`startAutoSync`** intervalle **30 000 ms**, L20, L211–L213). Dans un même run, une entrée n'est tentée **qu'une fois** (boucle `for`). |
| Conflict detection | **Aucune** (pas de `version`, pas de merge, pas de revalidation avant POST). |
| Network | **Aucun** `navigator.onLine` / `online` / `offline` dans ce fichier. La mise en file côté client est déclenchée par **échec axios** interprété comme réseau/5xx dans `kioskCart.js` (voir §9). |

Mutex sync : **`_syncInFlight`** évite deux **`syncQueue`** concurrents **dans le même onglet** (L104–L107, L161–L165).

## 2. État `kioskHardware.js` baseline

- **Service** : `resources/js/services/kioskHardware.js` — wrapper **`window.borne`** avec stub navigateur (L31–L92, L40–L43).  
- **APIs** : audio (`play`, `speak`, …), **haptique** (`haptic`), **scan** (`scanQR`, `readNFC`), **TPE** (`tpeCharge`, `tpeRefund`, `cancelPayment`), **tiroir** (`openDrawer`), **impression** (`printReceipt`, `printEscPos`), **diagnostics** (`healthcheck`, `info`), **lifecycle** (`reload`, `quit`), **`onHardwareEvent`**, **`reportHardwareEvent`** (L178–L366).  
- **Statut** : **`healthcheck`** interprète composants (printer/TPE critique, NFC/caméra dégradé) L287–L303 ; pas de mini-state machine dans le config.  
- **Echo/Pusher** : **non** dans ce module — uniquement bridge + POST `/api/frontend/kiosk-event` (L128–L145).  
- **Config** : `resources/js/config/kioskHardware.js` — constantes timings / stages (L12–L29).

## 3. Backend `ItemAvailabilityChanged`

- **Event** : `app/Events/ItemAvailabilityChanged.php` — propriétés scalaires + fabriques `fromItem` / `forBranch` (L21–L87). **Ne broadcast pas lui-même** ; commentaire outbox (L17–L18).  
- **Listeners** (`EventServiceProvider` L108–L114) :  
  - **`PersistItemAvailabilityChangedToOutbox`** : écrit `domain_events`, `broadcast_as` = `ItemAvailabilityChanged`, canaux `private-branch.{id}` JSON (L42–L55 de ce listener).  
  - **`BumpMenuSnapshotOnItemAvailabilityChanged`**, **`InvalidateKioskMenuCacheOnItemAvailabilityChanged`** : projection / cache menu kiosk.  
- **Transport temps réel** : job **`DispatchDomainEventsJob`** (après commit) — stack **Laravel broadcasting** (Redis/Soketi/Pusher selon `.env`), pas "Database broadcast" seul.  
- **Consumer JS kiosk** : **`KioskAppComponent.vue`** L395–L407 : `onEvent(branchId, 'ItemAvailabilityChanged', …)` → `kioskMenu/UPDATE_ITEM` + **`kioskCart/pruneUnavailableLines`**.

## 4. Front Echo/Pusher câblage

- **`package.json`** : **`laravel-echo`**, **`pusher-js`**, **`idb-keyval`** (L41–L44).  
- **`resources/js/bootstrap.js`** (L25–L55+) : instancie **`window.Echo`**, **`window.Pusher`**, auth Bearer, `_refreshEchoAuth`.  
- **`resources/js/app.js`** importe bootstrap (L6–L8).  
- **Polling fallback** : si pas d'Echo, le menu dépend du **TTL cache** / fetch ; **`eventContract.onEvent`** retourne unsubscribe vide si pas `window.Echo` (L65–L68). Pas de polling dédié "ItemAvailabilityChanged" sur le kiosk shell au-delà du menu normal.

## 5. Endpoints kiosk + idempotence

- **`routes/api.php`** : préfixe **`/api/frontend/order`** — **POST `/`** = création (alias `FrontendOrderController` = `OrderController`), **POST `/{frontendOrder}/payment-confirm`** (L871–L877).  
- **Idempotence création** : **`X-Idempotency-Key`** lu dans **`FrontendOrderService::myOrderStore`** (L128–L144, L185–187) ; lock cache + colonne `idempotency_key`. Le client kiosk envoie la clé (`kioskCart.js` L450–L452) et **`syncQueue`** rejoue **`X-Idempotency-Key: entry.localKey`** (`kioskOfflineQueue.js` L119–L124).  
- **`payment-confirm`** : **pas** de header `Idempotency-Key` documenté dans le contrôleur ; idempotence **métier** via **`payment_status === PAID`** + `lockForUpdate` (`OrderController.php` L101–L118). Ne pas réintroduire un autre récit sans gate (W5 / GATE 13).

## 6. UI conflict resolution actuelle

- **`KioskAppComponent.vue`** L44–L69 : indicateur **pending** (`offlinePending`) + bandeau **abandonnés** (`offlineAbandoned`). Compteur mis à jour **toutes les 15 s** + callback `startAutoSync` (L268–L277).  
- **`ConnectionStatusBanner`** : basé sur **`WebSocketService`** / état Pusher, **pas** `navigator.onLine` (`ConnectionStatusBanner.vue` L20–L71).  
- **`KioskToastComponent.vue`** : types **`success` | `error` | `info` | `warning`** (L30, L44, L89–L92).  
- **Modale conflit utilisateur** (queue vs serveur / prix / rupture) : **aucune** dans ces fichiers ; la queue **ne réagit pas** aux events de dispo pour les **payloads déjà sérialisés**.

## 7. IndexedDB usage actuel

- **grep** : usage concret **`idb-keyval`** dans **`resources/js/helpers/kioskMenuCache.js`** (L17, L26–L37) ; commentaire IndexedDB dans **`KioskCategoriesComponent.vue`** (L75).  
- **Pas** de Dexie / `window.indexedDB` brut hors ce wrapper.  
- **`kioskOfflineQueue`** : **0 IndexedDB**.  
- **`fake-indexeddb`** : **absent** de `package.json` (devDependencies L16–L34).

## 8. Tests existants offline

- **`tests/js/kioskOfflineQueue.spec.js`** : pending count, mutex **`syncQueue`**, analytics **`offline.*`**, replay clé, abandon après échecs, prune 24 h — avec **`localStorage`** via **`clearQueue`**.  
- **Mocks** : `kioskWizardEditRestore.spec.js` / `kioskWizardEditRoundtrip.spec.js` mockent **`saveOrder`**.  
- **Pas** de tests ciblant **IDB** pour la queue.

## 9. Sérialisation orders + quotas IDB

- **`kioskCart.js`** **`buildKioskOrderPayload`** (L21–L32) : `items` = **`JSON.stringify`** des lignes **`sanitizeKioskOrderItem`** (variations + extras + instruction ; **pas** d'allergènes explicites dans le sanitize L11–L18). Champs : `order_type`, `loyalty_code`, `kiosk_promo_code`, `source`, `payment_method`, etc.  
- **Taille typique** : pour un panier kiosk raisonnable (≤ MAX_ITEM_QTY par ligne), **ordre de grandeur faible dizaine de ko** par commande ; le risque principal est **beaucoup d'entrées** ou **menus** volumineux — **`localStorage` ~5 Mo** est le goulet **actuel** pour la queue (sujet v2 IDB).

## 10. Race conditions identifiées

1. **Double soumission** : mitigée côté serveur par **`X-Idempotency-Key`** identique au replay (`kioskOfflineQueue.js` L119–L124 + `FrontendOrderService` L128–L144).  
2. **Rupture après enqueue** : le **panier actif** est purgé (`pruneUnavailableLines`), mais **les entrées `payload` dans la queue offline ne sont pas mises à jour** → au flush, **422 serveur** possible ; pas de résolution UX dédiée.  
3. **Multi-onglets** : **`localStorage` partagé** ; **`_syncInFlight` par onglet** → deux onglets peuvent lancer des **`syncQueue`** en parallèle ; l'idempotence par clé limite les **doublons de la même** entrée, pas les **entrées distinctes** créées par deux sessions.  
4. **Bannière "offline"** : reflète surtout **WS** / **`WebSocketService`**, pas forcément la **disponibilité HTTP** utilisée par la queue.

## 11. Verdict ROUTING (critique)

| Question | Réponse |
|----------|---------|
| **EXECUTE "queue v2" pur (front + IDB + UX + tests)** touche zone fiscal / `PaymentService` ? | **Non** (si on ne modifie pas le flux paiement fiscal backend). |
| **`FrontendOrderService` / `OrderService` dans le périmètre EXECUTE ?** | **Non** pour une v2 **strictement client** ; **Oui** dès qu'on ouvre idempotence **`payment-confirm`**, fiscal, ou validation serveur nouvelle. |
| **HARD GATE (stop EXECUTE "routine" tel que Composer)** | **Non** si scope figé **sans** ces fichiers backend. **Oui** si le plan **impose** `FrontendOrderService`, fiscal, ou contrat **`payment-confirm`**. |
| **Subagent recommandé** | **`complex-implementer`** (équivalent **EXECUTE — complex / GPT-5.4** dans `.cursor/routing.md` : sync, races, stockage non trivial ; **pas** "polling + toast" seul). **`routine-implementer`** (**Composer**) : **inadapté** — le baseline dépasse déjà ce niveau et la v2 ajoute **non-trivial** (quota, conflits, multi-onglet). |

**Justification `routing.md`** : dès que **`FrontendOrderService`** est **inclus**, la table *FoodKing Routing Triggers* impose **GPT-5.4 + symmetry** (L50–L51). Pour rester **Composer**, le plan doit **exclure explicitement** tout changement à ce service et toute migration.

## 12. Périmètre proposé pour EXECUTE (indicatif)

- **Modifier** : `resources/js/helpers/kioskOfflineQueue.js`, `resources/js/store/modules/kioskCart.js`, `resources/js/components/frontend/kiosk/KioskAppComponent.vue`, éventuellement `KioskWaitingComponent.vue`, `kioskAnalytics` hooks, CSS kiosk.  
- **Créer** : ex. `resources/js/helpers/kioskOfflineQueueDb.js` (wrapper IDB) ou extension **`idb-keyval`** clés dédiées.  
- **LOC** : ordre **~200–450** selon UX conflit et couverture tests (hors backend).

## 13. Tests à créer

- Vitest + **`fake-indexeddb`** (à ajouter en devDependency) ou tests d'intégration **happy-dom** avec mock store.  
- Cas **retry / intervalle / abandon** ; **concurrence** deux **`syncQueue`** (onglets simulés).  
- Cas **payload stale** après **`ItemAvailabilityChanged`**.

## 14. Décisions techniques

- **Library IDB** : réutiliser **`idb-keyval`** (déjà en prod) pour cohérence avec **`kioskMenuCache.js`**.  
- **Echo vs polling** : **Echo déjà présent** pour dispo ; la queue ne doit pas dépendre du WS — garder **sync pilotée par timer + succès HTTP** ; option : déclencher **`syncQueue`** sur **`window._wsService` `connected`** (déjà L278–L284).  
- **Conflit UX** : aujourd'hui **indicateurs + abandon** ; v2 probable **toast `warning`/`error` + action opérateur** ou **file d'attente "en erreur"** ; **modale** si reprise manuelle obligatoire.

## 15. Risques au-delà du plan W7

- **Quota `localStorage`** + **silence** sur `_save` (`kioskOfflineQueue.js` L46–L51) → **perte de commandes** sans feedback.  
- **Carte / TPE** : chemins **`payment-confirm`** et **`finalizePaidKioskOrder`** restent des **zones sensibles** (audits W5/W6) — **ne pas les mélanger** à la queue offline sans **gate** dédiée.  
- **`routing.md`** : **Composer** interdit migrations / décisions d'architecture ; une **v2 IDB** peut être vue comme **architecture** → tracer dans le **plan** pour éviter **violation de routing**.
