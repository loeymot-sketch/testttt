# AUDIT POS — Section 2 / 4 : Centralisation multi-surfaces (drawer, filtres, actions, temps réel)

**Date.** 2026-04-18
**Auditeur.** Sous-agent POS-A #2 (read-only)
**Scope.** Vue centralisée POS des activités branche (kiosk + POS + web + delivery + table), filtres, actions d’orchestration, temps réel, isolation `branch_id`, conformité EventContract V1.
**Matériel à lecture.** `tasks/phase9-pos/POS_MASTER_BRIEF.md` §3.2 ; `tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md` ; `tasks/audits/AUDIT_POS_STATUS_TRANSITIONS_003.md`, `…_BRANCH_ISOLATION_004.md`, `…_AMEND_ORDER_GAP_010.md` ; `reports/review/AUDIT_KIOSK_GLOBAL_2026-04-18.md` §3.

> Note. Deux fichiers cités par le brief sont absents du repo (`reports/review/AUDIT_INTEGRATION_POS_KIOSK.md`, `AUDIT_FINAL_KIOSK_POS_2026-04-17.md`). Les autres références ont été lues intégralement.

---

## 1. Verdict synthèse

**BLOCKED** pour l’axe « centralisation multi-surfaces ».

Le POS **n’est pas la vue centrale unifiée promise** par le brief produit. La seule interface temps réel côté `PosComponent.vue` est un **« Kiosk Cash FAB »** (drawer des commandes borne en cash) et rien d’autre. Les commandes web, delivery partners, table orders, POS « sur place » (OrderType 15) ne sont **pas remontées** dans le POS. Aucun filtre par source / statut / table / staff / heure dans le drawer, aucune action autre qu’« Encaisser ». `BranchIsolationTest` est un **placeholder vide** (test d’intrusion absent).

Forces à préserver :
- Chaîne d’événements `OrderCreated` / `OrderStatusChanged` persistée en outbox (`domain_events`) + `DispatchDomainEventsJob` sur queue `high` + `DB::afterCommit` côté listeners + `EventContract::assertEnvelopeValid()` avant broadcast Pusher (conforme V1).
- Authorization `Broadcast::channel('branch.{branchId}', …)` avec ability kiosk isolée (`routes/channels.php:25-39`).
- `BranchScope` global sur `Order` (`app/Models/Order.php:82`) avec règle admin `branch_id=0` clarifiée (`BranchScope.php:33-39`).

Faiblesses structurelles :
- Un seul consommateur côté POS (kiosk cash), aucune centralisation multi-sources.
- Aucune action « accept / préparer / annuler / refund / amend » depuis le POS — tout passe par KDS / Online / Table lists séparées. Pas d’endpoint amend.
- Aucun test d’intrusion réel branch_id ni test de centralisation POS.

---

## 2. Tableau source × visibilité POS × actions possibles

| Source (`Order.source` + `order_type`) | Apparaît dans drawer POS (`PosComponent.vue`) ? | Apparaît dans KDS ? | Apparaît dans OSS ? | Actions possibles **depuis le POS** | Preuve |
|---|---|---|---|---|---|
| Kiosk **cash** (`order_type=25` OU `=10`, `payment_method=1`, status ∈ {4,7,8}) | **Oui** — liste filtrée client side | Oui | Oui | « Encaisser » → POST `admin/kds-order/change-status/{id}` → status=13 DELIVERED | `PosComponent.vue:1018-1040`, `:1042-1054` |
| Kiosk **card/TR** deferred (PENDING) | **Non** | Non (PENDING < ACCEPT) | Non | — | `KitchenDisplaySystemOrderService.php:52` |
| POS comptoir (`order_type=15`, source=POS) | **Non** | **Non** (filtre 4 colonnes : DINING_TABLE, DELIVERY, TAKEAWAY, KIOSK uniquement) | Non (cf. `OrderStatusScreenOrderService.php:43-50`) | — (le caissier quitte POS et va dans `PosOrderListComponent`) | `KitchenDisplaySystemComponent.vue:602-655`, enum absente |
| Web / App client (`source=5|10`, `order_type ∈ {DELIVERY, TAKEAWAY}`) | **Non** | Oui (colonnes DELIVERY / TAKEAWAY) | Oui | — (il faut aller dans `OnlineOrderListComponent`) | `OnlineOrderListComponent.vue:277` (`exceptSource: SourceEnum.POS`) |
| Delivery partners (Uber Eats, Deliveroo…) | **Pas d’intégration** — `Source` enum limité à WEB/APP/POS | — | — | — | `app/Enums/Source.php:1-12` (3 constantes, pas de `UBER_EATS`, `DELIVEROO`, `JUST_EAT`) |
| Table orders (`order_type=20`) | **Non** | Oui (colonne DINING_TABLE) | Oui (via token) | — (UI séparée `TableOrderListComponent`) | `TableOrderListComponent.vue:275` |
| Delivery boy (assigné) | **Non** | Oui (DELIVERY col) | — | Seul le delivery boy peut changer status via `OrderService::deliveryBoyOrderChangeStatus` | `OrderService.php:1312-1358` |

Conclusion : **1 source sur 5+ visible dans le POS**. Tout le reste est atomisé dans 5 écrans admin distincts (KDS, OSS, posOrders, onlineOrders, tableOrders, deliveryBoys).

---

## 3. Chaîne événements POS → autres surfaces (diagramme texte)

```
                                   ┌──────────────────────────────────────┐
Caissier POS (PosComponent) ──────▶│ POST /api/v1/admin/pos               │
                                   │   → PosController::store             │
                                   │   → OrderService::posOrderStore      │
                                   │     • DB::transaction                │
                                   │     • queue_number via Cache::lock   │
                                   │     • OrderCreated::dispatch(…)      │
                                   └──────────────────┬───────────────────┘
                                                      │
                               Laravel Event subsystem (synchronous listener)
                                                      │
                              ┌───────────────────────▼──────────────────────┐
                              │ PersistOrderCreatedToOutbox::handle          │
                              │   • DomainEvent::create(envelope V1)         │
                              │   • DB::afterCommit → DispatchDomainEventsJob│
                              └───────────────────────┬──────────────────────┘
                                                      │ queue `high`
                              ┌───────────────────────▼──────────────────────┐
                              │ DispatchDomainEventsJob::handle              │
                              │   • EventContract::assertEnvelopeValid()     │
                              │   • Pusher→trigger('private-branch.X',       │
                              │       'OrderCreated', envelope)              │
                              └───────────────────────┬──────────────────────┘
                                                      │
      ┌───────────────────────────┬──────────────────┼──────────────────────┬───────────────────────────┐
      ▼                           ▼                  ▼                      ▼                           ▼
KDS (branch.X)          OSS (branch.X)       POS FAB (branch.X)    PreparingAndReady       ⚠️ **Rien côté
onEvents                onEvents(…           onEvents(…            (OSS)                      client web / app** —
OrderStatusChanged+     OrderStatusChanged+  OrderCreated →        OrderStatusChanged+       le client final
OrderCreated →          OrderCreated)        loadKioskCashOrders() OrderCreated              reçoit push FCM mais
_debouncedRefresh()                          (kiosk cash ONLY)                                pas un broadcast
                                                                                              Pusher ciblé user.
```

Événements **émis** par l’action POS :
- `OrderCreated` — tous chemins `OrderService` (`OrderService.php:531, 904, 1203`).
- `OrderStatusChanged` — changeStatus POS/admin/online/table/KDS/deliveryBoy (`OrderService.php:1348, 1401, 1470`, `KitchenDisplaySystemOrderService.php:142`).
- `SendOrderMail/Sms/Push` — listeners queue (`OrderService.php:1464-1466`).

Événements **absents** (malgré mapping dans le contrat) :
- `OrderItemAdded` — 0 dispatch réel (`EventContract.php:37`, grep vide côté `app/Events/`).
- `OrderCancelled` — aucune class `App\Events\OrderCancelled`, le cancel est transporté par `OrderStatusChanged(oldStatus → 16/19)`.
- `PaymentRecorded` — aucun event dédié malgré la mention §3.8 du MASTER_BRIEF.
- `OrderRefunded` — aucune route, aucun service, aucun event.

---

## 4. Filtres & historique

### 4.1 Ce qui existe

| Interface | Filtres disponibles | Commentaire |
|---|---|---|
| POS drawer (`PosComponent.vue:578-624`) | **Aucun** filtre UI (hardcodé `status ∈ [4,7,8]`, `order_type ∈ {25, 10}`, `payment_method=1`) | L1018-1034 |
| KDS (`KitchenDisplaySystemComponent.vue`) | `status` unique (ACCEPT/PREPARING/PREPARED) + ordre par colonne `order_type` | Pas de filtre source/staff/table/heure |
| PosOrderListComponent | `status`, `user_id`, `from_date`, `to_date`, `order_serial_no`, **source=POS fixe** | `:249-261` |
| OnlineOrderListComponent | idem + `exceptSource: POS` | `:277` |
| TableOrderListComponent | idem + `order_type=DINING_TABLE` fixe | `:275` |

### 4.2 Ce qui manque

- **Aucun écran unique** qui agrège les 5 sources avec filtres multi-critères (source, statut, table, staff, heure, payment_method).
- Aucun filtre « par staff » (qui a pris la commande ? qui l’a préparée ?).
- Aucun filtre horaire temps-courant (« dernières 15 min ») ; la requête KDS cherche `whereDate('order_datetime', today)` → impossible de cibler un créneau.
- Aucun **re-print** ni **duplicate** depuis le drawer POS. `reorderItems` existe pour POS seulement (`PosOrderController.php:116-153`) et nécessite l’écran `PosOrderListComponent`.
- Aucune recherche plein-texte cross-source (par téléphone client, par montant, par table…).

---

## 5. Audit temps réel

### 5.1 Pusher channels

- Auth : `branch.{branchId}` avec contrôle ability kiosk (`routes/channels.php:25-39`). Admin `branch_id=0` → accès à tous canaux (`:33-35`). Correct.
- Broadcast : tous les events POS ciblent `private-branch.{branch_id}` via outbox (`PersistOrderCreatedToOutbox.php:31`, `PersistOrderStatusChangedToOutbox.php:30`).
- Envelope V1 validée strictement avant broadcast (`DispatchDomainEventsJob.php:56-68` + `EventContract::assertEnvelopeValid`).

### 5.2 Subscribe côté POS

- `_subscribeEcho()` : `onEvents(branchId, [{OrderCreated}, {OrderStatusChanged}])` (`PosComponent.vue:999-1010`).
- **Aucun listener** pour `ItemAvailabilityChanged` côté POS → le toggle 86 admin ne grise pas les tuiles POS sans refresh (cf. kiosk qui, lui, écoute → `resources/js/store/modules/kioskMenu.js:143-210`).
- Handlers POS déclenchent uniquement `loadKioskCashOrders()` → le catalogue POS et l’écran caisse ne sont **pas** rafraîchis sur events (on rate ACCEPT d’une commande web entrante, par ex.).

### 5.3 Fallback polling

- `_startKioskPolling()` : 60 s quand WS connecté, 10 s quand déconnecté (`PosComponent.vue:988-997`). Polling limité au seul endpoint kiosk cash — pas de polling des autres sources.
- `window._wsService` expose `isConnected()` + events `connected|disconnected` → reconnection backoff géré en amont (non audité ici).
- **Problème** : si `window.Echo` est null au mount (Echo non chargé, Soketi down), `_subscribeEcho` abandonne silencieusement (`:999-1010`) ; polling 10 s seul. Acceptable mais pas logué au niveau INFO/WARN.

### 5.4 Isolation branch

- Émission : `branch_id` du payload outbox pris de `$order->branch_id` (`PersistOrderCreatedToOutbox.php:22, 31`) → non manipulable client.
- Auth abonnement : staff branche X ne peut s’abonner qu’à `branch.X` (channels.php:38). Kiosk token limité à sa propre borne (`:27-30`).
- Côté UI POS : `branchId = parseInt($store.getters['auth/authBranchId'] || 0)` (`PosComponent.vue:1001`). Si 0 → abonnement sauté (admin). Correct.
- `OrderService::list` (endpoint `/api/v1/admin/pos-order`) **n’applique pas explicitement** le filtre `branch_id = auth()->user()->branch_id` : il dépend exclusivement de `BranchScope` (`OrderService.php:102-170`). Si jamais `BranchScope` est désactivé par un `withoutGlobalScope` futur, la fuite cross-branch deviendrait invisible. Defense-in-depth manquante.

---

## 6. Audit événements émis depuis le POS

| Event class | Source dispatch | Après commit ? | Envelope V1 ? | Required keys respectés ? |
|---|---|---|---|---|
| `OrderCreated` | `OrderService.php:531, 904, 1203`; `FrontendOrderService.php:770`; dispatch **hors** `DB::transaction` (après `->save()` commit implicite) | ✅ listener `PersistOrderCreatedToOutbox` utilise `DB::afterCommit` | ✅ `buildEnvelope` + `assertEnvelopeValid` | ✅ `order_id` présent (`PersistOrderCreatedToOutbox.php:24`) |
| `OrderStatusChanged` | `OrderService.php:1348, 1401, 1470`; `KitchenDisplaySystemOrderService.php:142`; dispatch **après** la transaction (`OrderService.php:1462` fin de bloc) | ✅ listener idem | ✅ | ✅ `order_id`, `old_status`, `new_status` (`PersistOrderStatusChangedToOutbox.php:24-27`) |
| `OrderItemAdded` | 🚫 **jamais dispatché** (grep vide) | — | — | — |
| `OrderCancelled` | 🚫 **aucune classe event**. Le cancel passe par `OrderStatusChanged(→16/19)` | — | — | Contrat obsolète (cf. kiosk O-7) |
| `PaymentRecorded` | 🚫 **inexistant** | — | — | — |
| `OrderRefunded` | 🚫 **inexistant** | — | — | — |
| `ItemAvailabilityChanged` | OK côté admin 86, listener outbox dédié (`PersistItemAvailabilityChangedToOutbox.php:53`) mais **POS ne s’abonne pas** | — | ✅ | ✅ |

Violation contractuelle détectée : `EventContract::BROADCAST_MAP` mentionne `OrderItemAdded` et `OrderCancelled` mais aucun dispatch ni aucun listener ne les matérialisent — **drift silencieux** entre contrat et code.

---

## 7. Check-list invariants (résumé ciblé Section 2)

| Invariant | État | Preuve |
|---|---|---|
| `DB::afterCommit` avant broadcast | ✅ | `PersistOrderCreatedToOutbox.php:37`, `PersistOrderStatusChangedToOutbox.php:36` |
| EventContract V1 strict (assertEnvelopeValid avant broadcast) | ✅ | `DispatchDomainEventsJob.php:56-68` |
| `branch_id` canal **jamais** du payload client | ✅ | `PersistOrderCreatedToOutbox.php:22,31` ; `PersistOrderStatusChangedToOutbox.php:22,30` |
| POS subscribe branch channel + ability | ✅ | `PosComponent.vue:1001-1007` ; `routes/channels.php:25-39` |
| POS fallback polling reconnection backoff | ⚠️ | Polling binaire 10/60 s seulement ; pas de backoff exponentiel explicite (`PosComponent.vue:988-997`) |
| POS filtré `auth()->user()->branch_id` (defense-in-depth) | ⚠️ | Exclusivement via `BranchScope`, pas de check explicite in-service (cf. `OrderService.php:102-170`) |
| Test d’intrusion branch | ❌ | `tests/Feature/BranchIsolationTest.php:9-13` = placeholder `assertTrue(true)` |
| Centralisation multi-surfaces (kiosk+POS+web+delivery+table) | ❌ | Drawer kiosk cash seul (`PosComponent.vue:575-625`) |
| OrderType 15 (POS sur place) visible KDS | ❌ | `KitchenDisplaySystemComponent.vue:602-613` — 4 colonnes, POS absent |
| Dispatch `OrderItemAdded` sur amend | ❌ | 0 route, 0 event, 0 service |
| Dispatch `OrderCancelled` dédié | ❌ | absent (remplacé par `OrderStatusChanged`) |
| ItemAvailabilityChanged consommé par POS | ❌ | `PosComponent.vue` ne souscrit qu’à `OrderCreated|OrderStatusChanged` |

---

## 8. Findings priorisés (≥ 12)

### POS-P2-F-01 — Drawer POS ne centralise qu’une seule source (kiosk cash) — **P0**
- file:line : `resources/js/components/admin/pos/PosComponent.vue:1018-1040`
- description : `loadKioskCashOrders()` n’interroge `admin/kds-order` qu’avec `order_type=25|10` et `payment_method=1`. Les commandes web, delivery partners, table, POS sur place, kiosk card ne sont jamais listées dans le drawer.
- impact : violation du cœur produit (« POS = vue centrale unifiée »). Le caissier doit jongler entre 5 écrans (KDS, OSS, posOrders, onlineOrders, tableOrders) — friction critique ; retards d’acceptation ; impossible de superviser la branche en temps réel.
- fix_proposal : remplacer le FAB par un drawer « Activité branche » basé sur un endpoint `admin/branch-activity` agrégeant `Order::whereIn('status', [1,4,7,8,10])->with(['orderItems','user','diningTable','deliveryBoy'])`, paginé et filtrable.
- invariants touchés : SSOT order view ; branch_id isolation (à renforcer côté endpoint).
- resurface_from : `AUDIT_KIOSK_GLOBAL_2026-04-18.md §3.3 O-3` (variations absentes → ici on élargit).

### POS-P2-F-02 — `OrderType::POS` (15) n’apparaît dans **aucune** colonne KDS — **P1**
- file:line : `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:602-613, 644-655`
- description : les 4 filtres client side n’incluent pas `orderTypeEnum.POS` (valeur 15). Une commande POS « sur place » (dine-in sans table) disparaît de l’écran cuisine.
- impact : trou fonctionnel ; cuisine ignore la commande ; caissier doit crier l’ordre → incident sécurité alimentaire.
- fix_proposal : ajouter une colonne « Sur place » pour `order_type=15` ou basculer ces commandes dans `dineinOrders` (col DINING_TABLE).
- invariants touchés : OrderType enum ; state machine (la commande passe ACCEPT→PREPARING sans avoir été vue).
- resurface_from : `AUDIT_KIOSK_GLOBAL_2026-04-18.md §3.3 O-5`.

### POS-P2-F-03 — `BranchIsolationTest` est un placeholder — **P0**
- file:line : `tests/Feature/BranchIsolationTest.php:9-13`
- description : le test unique retourne `assertTrue(true)` sans créer 2 branches ni comparer leurs orders. L’invariant critique `branch_id data isolation` n’est couvert par aucun test d’intrusion.
- impact : toute régression (ex. `withoutGlobalScope`, join oubliant `branch_id`) passe inaperçue. Fuite cross-tenant possible. Régression P0 silencieuse garantie à moyen terme.
- fix_proposal : créer un scénario Feature « Branch A staff ne voit pas order B » sur les endpoints `/admin/pos-order`, `/admin/kds-order`, `/admin/oss-order`, `/admin/table-order`, `/admin/online-order`, `/admin/delivery-boy-order/{id}/delivered-order` ; asserter 404/403/absence.
- invariants touchés : branch_id data isolation.
- resurface_from : `AUDIT_POS_BRANCH_ISOLATION_004.md` Q10.

### POS-P2-F-04 — Aucun endpoint « amend order » (add/remove/update item post-création) — **P1**
- file:line : `routes/api.php:625-638` (prefix `pos-order` : pas de `patch` ni `put`) ; `app/Services/OrderService.php` (grep `amend|addItem|removeItem` vide).
- description : après création, le caissier ne peut pas ajouter/retirer un plat. Aucune route, aucune méthode service, aucun event `OrderItemAdded` réellement dispatché (contrairement à `EventContract::BROADCAST_MAP:37`).
- impact : gap fonctionnel bloquant pour dine-in (ajout boisson tardive, correction erreur). Force le caissier à annuler + recréer → perte traçabilité + TVA.
- fix_proposal : ajouter `Route::patch('/{order}/items', …)` + `OrderService::addItem/removeItem` recalculant `PricingService::calculateOrder()` + event `OrderItemAdded` conforme V1. Bloquer amend après `PREPARING` (cf. `AUDIT_POS_AMEND_ORDER_GAP_010.md`).
- invariants touchés : SSOT pricing ; OrderStateMachine ; EventContract ; audit log.
- resurface_from : `AUDIT_POS_AMEND_ORDER_GAP_010.md`.

### POS-P2-F-05 — Aucune action POS → « cancel / refund / discount » depuis le drawer — **P1**
- file:line : `resources/js/components/admin/pos/PosComponent.vue:608-617` (seule action `Encaisser`) ; `app/Services/OrderService.php:1312-1480` (changeStatus oui, refund non) ; `routes/api.php:625-638`.
- description : depuis le POS on ne peut que marquer DELIVERED les kiosk cash. Cancel après PAID, refund partiel, annulation delivery boy, reassign table → zéro UI embarquée ; il faut quitter PosComponent pour aller dans posOrders/onlineOrders/tableOrders.
- impact : l’ambition « POS centrale opérationnelle » (MASTER_BRIEF §1) est non tenue. Friction majeure ; risque fiscal (cancel non journalisé côté POS alors que `ActionLog` existe pour les autres chemins `OrderService.php:1451-1461`).
- fix_proposal : exposer dans le drawer « Activité branche » des boutons Accept / Preparing / Ready / Delivered / Cancel avec motif ; router vers `PosOrderController::changeStatus` + futur endpoint `refund` + audit log immuable.
- invariants touchés : permissions Spatie ; audit log ; state machine.
- resurface_from : `AUDIT_POS_STATUS_TRANSITIONS_003.md` Q5/Q6.

### POS-P2-F-06 — Event `OrderCancelled` et `PaymentRecorded` jamais émis (drift contrat) — **P1**
- file:line : `app/Domain/Events/EventContract.php:34-41` (MAP) ; `app/Events/` (ls : pas de `OrderCancelled.php`, pas de `PaymentRecorded.php`) ; grep `OrderCancelled|PaymentRecorded::dispatch` vide.
- description : le contrat annonce ces events, aucun listener outbox ne les matérialise. Les clients qui s’appuieraient sur `order.cancelled` ou `payment.recorded` n’en verront jamais.
- impact : drift silencieux ; futur consumer (intégration comptable, delivery partner webhook) cassé.
- fix_proposal : créer `App\Events\OrderCancelled($order, $reason)` + listener `PersistOrderCancelledToOutbox` + dispatch dans `OrderService::changeStatus` quand `$newStatus ∈ {16,19}`. Idem `PaymentRecorded`. Sinon retirer du contrat.
- invariants touchés : EventContract V1 cohérence.
- resurface_from : `AUDIT_KIOSK_GLOBAL_2026-04-18.md §3.3 O-7`.

### POS-P2-F-07 — Pas de filtre source/statut/table/staff dans le drawer POS — **P1**
- file:line : `resources/js/components/admin/pos/PosComponent.vue:575-625, 1018-1040` (hardcodage `status=[4,7,8]`, `order_type=25|10`, `payment_method=1`).
- description : aucune UI permet de filtrer par source (kiosk/web/delivery), par staff, par table, par créneau horaire, par statut individuel.
- impact : le caissier ne peut pas isoler « table 7 » ou « delivery en retard » — inutilisable en rush.
- fix_proposal : remplacer le drawer par un composant `BranchActivityComponent` avec 5 filtres v-model + debounce 300 ms + query params vers un endpoint agrégé.
- invariants touchés : branch_id (endpoint doit toujours filtrer serveur-side) ; SSOT filtres.
- resurface_from : `POS_MASTER_BRIEF.md §3.2`.

### POS-P2-F-08 — POS ne s’abonne pas à `ItemAvailabilityChanged` (86 admin non reflété) — **P2**
- file:line : `resources/js/components/admin/pos/PosComponent.vue:1004-1007` (2 bindings seulement) ; `resources/js/store/modules/kioskMenu.js:143-210` (kiosk le fait).
- description : le toggle 86 depuis l’admin déclenche `ItemAvailabilityChanged` sur `branch.X`, mais l’écran POS ne réactualise pas ses tuiles avant un refresh manuel.
- impact : un caissier vend un plat rupture → commande envoyée à KDS, puis annulation client → ticket rectificatif obligatoire → friction.
- fix_proposal : ajouter `{ broadcastAs: 'ItemAvailabilityChanged', handler: payload => this.$store.dispatch('posCategory/patchAvailability', payload) }` et créer le mutateur correspondant dans le store POS.
- invariants touchés : parité kiosk/POS.
- resurface_from : `AUDIT_KIOSK_GLOBAL_2026-04-18.md §3.3` (axe élargi).

### POS-P2-F-09 — `OrderService::list` n’applique pas de filtre `branch_id = auth()->user()->branch_id` explicite — **P2**
- file:line : `app/Services/OrderService.php:102-170` (pas de `->where('branch_id', Auth::user()->branch_id)` codé dans la closure) ; dépendance exclusive à `BranchScope` (`app/Models/Scopes/BranchScope.php:27-40`).
- description : la défense en profondeur recommandée par le MASTER_BRIEF §1 (FormRequest + Service + BranchScope) n’est que partielle. Si un jour `withoutGlobalScope(BranchScope::class)` est ajouté sans revue, la fuite cross-branch devient invisible.
- impact : risque P0 différé sans aucun canari.
- fix_proposal : ajouter `->when(Auth::check() && !Auth::user()->hasRole('Admin'), fn($q) => $q->where('branch_id', Auth::user()->branch_id))` **en plus** du scope. Coupler avec `BranchIsolationTest` réel (F-03).
- invariants touchés : branch_id data isolation.
- resurface_from : `AUDIT_POS_BRANCH_ISOLATION_004.md` Q3/Q7.

### POS-P2-F-10 — `PosComponent` ne dispose pas de notification sonore / toast sur nouvelle commande — **P2**
- file:line : `resources/js/components/admin/pos/PosComponent.vue:1005` (handler = `loadKioskCashOrders()` seul ; aucun `new Audio`, aucun `alertService.info`).
- description : quand un kiosk vient de passer en ACCEPT, le badge FAB s’incrémente silencieusement. Aucun son, aucun toast en haut de l’écran POS actif.
- impact : en rush, un kiosk cash peut attendre 3-5 min avant d’être repéré par le caissier — perception client dégradée.
- fix_proposal : déclencher `new Audio('/sounds/new-order.mp3').play()` + `alertService.info('N° X à encaisser')` dans le handler `OrderCreated`, en respectant un cooldown 5 s.
- invariants touchés : RGPD (son bienvenu, pas de tracking).
- resurface_from : `POS_MASTER_BRIEF.md §3.2` (notifications in-app).

### POS-P2-F-11 — Historique commandes closes non disponible depuis le POS — **P2**
- file:line : `routes/api.php:625-638` (pas de route `history/search` cross-source) ; `resources/js/components/admin/pos/PosComponent.vue` (pas de lien UI).
- description : aucun accès à l’historique multi-source avec recherche (téléphone, montant, serial_no, date), re-print, duplicate. Les 5 écrans admin séparés permettent chacun `from_date/to_date` sur leur silo seul.
- impact : cas d’usage « client revient se plaindre » → le caissier quitte POS, cherche dans 3 écrans successifs.
- fix_proposal : endpoint unique `admin/order-history` (filtrable source, status, date, customer_phone, amount range, table, staff) + écran `OrderHistoryComponent` intégré dans PosComponent comme panneau secondaire ; actions re-print (reuse `PosOrderReceiptComponent`) et duplicate (reuse `reorderItems`).
- invariants touchés : branch_id ; performance (index `(branch_id, created_at)` à vérifier).
- resurface_from : `POS_MASTER_BRIEF.md §3.2` (historique).

### POS-P2-F-12 — `Source` enum ne couvre pas les delivery partners — **P2**
- file:line : `app/Enums/Source.php:1-12` (3 constantes : WEB=5, APP=10, POS=15).
- description : aucune distinction entre web natif et delivery partners (Uber Eats, Deliveroo, Just Eat, Lyveat…). Toute commande externe serait assignée à `source=WEB`, empêchant la segmentation CA / commissions / reporting.
- impact : blocage de la roadmap « centralisation 4+ sources » ; KPI business faussé ; SalesReport impossible à découper.
- fix_proposal : étendre l’enum (`UBER_EATS=20`, `DELIVEROO=25`, `JUST_EAT=30`, `PHONE=35`, `KIOSK=40`) et migrer `source_surface` existant (`app/Models/Order.php:46`) vers ce type. Mettre à jour `SalesReport`, `PosOrderListComponent`, filtres.
- invariants touchés : schéma events (ajout `source` dans payload `OrderCreated`), rétrocompat.
- resurface_from : `POS_MASTER_BRIEF.md §1` (sources multi-partenaires).

### POS-P2-F-13 — `KitchenDisplaySystemOrderService::list` filtre `branch_id` **après** un `LIKE '%0%'` si client envoie — **P2**
- file:line : `app/Services/KitchenDisplaySystemOrderService.php:75-85`
- description : la boucle itérative applique `$query->where($key, 'like', '%' . request_value . '%')` pour `branch_id` lorsqu’il vient en query-string (`$this->orderFilter` inclut `branch_id` ligne 26). Pour un staff `branch_id=1`, le scope applique `WHERE branch_id=1`, mais si le client passe `?branch_id=2` un `AND branch_id LIKE '%2%'` s’ajoute : jamais satisfait (car `branch_id=1`), donc liste vide → faux négatif UX ; MAIS pour un admin (`branch_id=0`, scope bypass) le client peut filtrer côté admin OK. Risque : forme `LIKE` est trompeuse (ex `branch_id=1` matche `%1%` ≠ exact).
- impact : bug latent si branchIds dépassent 1 chiffre (branch_id=12 matche `%1%` et `%2%`) → fuite potentielle cross-branch pour un admin filtrant mal.
- fix_proposal : utiliser `where('branch_id', (int) $request)` (comparaison stricte) comme pour `status` ligne 79. Idem `OrderStatusScreenOrderService.php`.
- invariants touchés : branch_id isolation ; correction pattern `LIKE` sur integer.
- resurface_from : nouveau finding (scan Section 2).

### POS-P2-F-14 — Polling drawer POS est **borné à l’endpoint kiosk cash** et n’actualise pas le catalogue/panier — **P3**
- file:line : `resources/js/components/admin/pos/PosComponent.vue:988-997`
- description : `_startKioskPolling` ne rafraîchit que `loadKioskCashOrders`. Les items/catégories/availability ne sont pas polés. Si Echo tombe, l’écran caisse devient stale jusqu’à reload manuel.
- impact : risque vente rupture en mode dégradé.
- fix_proposal : polling secondaire 30 s `itemCategories()` + `itemList()` quand `_wsService.isConnected()===false`.
- invariants touchés : résilience mode dégradé.
- resurface_from : nouveau finding.

### POS-P2-F-15 — `OrderService::changeStatus` écrit `->status = X` puis `->save()` à l’intérieur de `DB::transaction` sans passer par `OrderStateMachine::apply()` — **P2**
- file:line : `app/Services/OrderService.php:1439-1449` ; idem `KitchenDisplaySystemOrderService.php:121-133`, `deliveryBoyOrderChangeStatus` `OrderService.php:1334-1344`.
- description : la transition est validée par `ValidStatusTransition` en amont mais l’écriture se fait toujours via `$order->status = $request->status; $order->save();`. Puis `OrderStateMachine::recordTransition(...)` est appelé en POST-hoc pour tracer. L’invariant §1.1 `OrderStateMachine::apply() obligatoire` n’est pas respecté littéralement : il y a écriture directe.
- impact : absence d’un single-entry point ; un futur refactor pourrait oublier `recordTransition` et casser l’audit log `order_status_transitions`.
- fix_proposal : encapsuler dans `OrderStateMachine::apply(Order $order, int $newStatus, ?User $actor, ?string $reason): Order` qui valide + écrit + recordTransition en un seul `DB::transaction`. Refactor des 3 sites.
- invariants touchés : OrderStateMachine SPOT ; audit trail.
- resurface_from : `AUDIT_POS_STATUS_TRANSITIONS_003.md` Q1/Q7.

### POS-P2-F-16 — `PusherBeams` / FCM push vers staff branche non visibles dans ce scan (suspicion absence) — **P3**
- file:line : `app/Services/FcmNotificationService.php` + `app/Events/SendOrderPush.php` ; mais aucun routage ciblé « staff POS branche X » par device token (grep `PusherBeams` vide).
- description : les push sont envoyés au client (`SendOrderPush::dispatch(['order_id'=>…,'status'=>…])`) mais pas au personnel POS en mode écran éteint (tablette fond cuisine). Le seul vecteur staff = Echo Pusher sur canal actif.
- impact : en cas de déconnexion WS + onglet fermé, le staff rate les nouvelles commandes. Perception client dégradée.
- fix_proposal : intégrer `PusherBeams` interest `branch.X.staff` + push natif sur ACCEPT.
- invariants touchés : RGPD (opt-in device), branch_id.
- resurface_from : `POS_MASTER_BRIEF.md §3.2` (notifications).

---

## 9. Section recouvrement (synchronisation inter-tracks Kiosk ↔ POS)

Les findings suivants **se chevauchent** avec la roadmap kiosk Phase 9 et doivent être traités ensemble côté backend shared :

| POS finding | Kiosk finding (`AUDIT_KIOSK_GLOBAL_2026-04-18.md`) | Zone shared |
|---|---|---|
| F-02 (OrderType 15 absent KDS) | O-5 | `KitchenDisplaySystemComponent.vue` colonnes |
| F-06 (events drift) | O-7 (`OrderItemUpdated` absent) | `EventContract::BROADCAST_MAP` + listeners outbox |
| F-08 (POS ne consomme pas availability) | §2.2 (snapshot allergens) | `eventContract.js` BROADCAST_MAP |
| F-09/F-13 (branch_id defense) | §3.2 (invariant respecté côté kiosk) | `BranchScope` + service layer |
| F-12 (Source enum partenaires) | §5.4 (funnel attribution incomplet) | `app/Enums/Source.php` + payload events |

---

## 10. Next step recommandé

- **POS-9.1 stop-the-bleed Section 2** : F-01, F-02, F-03, F-09/13 (critiques opérationnels + test d’intrusion).
- **POS-9.2** : F-04, F-05 (amend + actions drawer) → dépend d’un lock backend partagé avec Track A (EventContract extension).
- **POS-9.3** : F-06, F-08, F-12 → refonte enum + events ; coordonner avec Track A via `SYNC_PROTOCOL_KIOSK_POS.md`.
- **POS-9.4** : F-07, F-10, F-11 (UX drawer unifié + notif sonore + historique).

---

**Fin rapport — Section 2/4**
