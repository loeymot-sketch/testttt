# VERIFY-14 — Sync cross-surface (Pusher / Outbox / EventContract)

| Méta | Valeur |
|---|---|
| Date | 2026-04-20 |
| Origine | `tasks/verify-2026-04-20/14_VERIFY_SYNC_CROSS_SURFACE.md` |
| Mode | AUDIT-ONLY (lecture seule, aucun code applicatif modifié) |
| Sources audit antérieures | `reports/review/AUDIT_POS_110_SYNC_CROSS_SURFACE_2026-04-19.md`, `reports/review/AUDIT_SYNC_BROADCAST_ARCHITECTURE_2026-03-31.md` |
| Périmètre | Backend events + outbox + jobs + `EventContract` + `routes/channels.php` ; Front `bootstrap.js` + `services/eventContract.js` + `WebSocketService.js` + composants KDS/OSS/POS |
| **Verdict GLOBAL** | **WARN** |

---

## 1. Plan exécuté (5 lignes)

1. Pass A back : EventContract / DispatchDomainEventsJob / Persist*ToOutbox / EventServiceProvider / channels.php / migration `domain_events`.
2. Pass B front : init Echo + Pusher (`bootstrap.js`), wrapper `services/eventContract.js`, runtime `WebSocketService.js`, abonnements/désabonnements KDS / OSS / POS.
3. Trace lifecycle complet POS `posOrderStore` → commit DB → `OrderCreated` → listener outbox → `DispatchDomainEventsJob` → Pusher trigger → Echo KDS.
4. Matrice failure modes (worker crash, rollback post-dispatch, bypass channel-auth, drift envelope version).
5. Vérification V1–V7 + verdict.

---

## 2. Pass A — Backend (events / outbox / jobs / channels)

### 2.1 EventContract V1 (envelope canonique)

`app/Domain/Events/EventContract.php`

- Constante `ENVELOPE_VERSION = 1` (l. 28).
- `BROADCAST_MAP` — Map nom-broadcast → type canonique pointé (l. 34-41) ; reflète miroir front `resources/js/services/eventContract.js:10-14`.
- `REQUIRED_PAYLOAD_KEYS` — clés minimales par type (l. 47-54).
- `assertEnvelopeValid()` valide version + type ∈ `EventType::all()` + `aggregate_id` + `branch_id` typage strict + `occurred_at` non vide + `correlation_id` non vide + `payload` array (l. 77-126). Émet `PayloadMismatchException`.
- `assertPayloadValid()` rejette payloads tronqués (`order.status_changed` sans `new_status`, etc.) (l. 133-155).

→ V5 (envelope versionnée) **OK**.

### 2.2 Outbox table & modèle

`database/migrations/2026_04_15_200000_create_domain_events_table.php`

- Colonnes : `event_type`, `aggregate_type`, `aggregate_id`, `branch_id`, `payload (json)`, `channel`, `broadcast_as`, `correlation_id`, `occurred_at`, `dispatched_at`, `attempts`, `last_error`, `timestamps`.
- Indices :
  - `idx_pending` sur `(dispatched_at, occurred_at)` — l. 27.
  - `idx_aggregate` sur `(aggregate_type, aggregate_id)` — l. 28.
  - `idx_branch` sur `(branch_id, occurred_at)` — l. 29.

`app/Models/DomainEvent.php` — scopes `pending`, `stale($minutes=2)`, `failed($maxAttempts=4)` (l. 33-48).

→ V2 (`(processed_at, created_at)` demandé) — **PASS sémantique** : la colonne s'appelle `dispatched_at` (et non `processed_at`) mais joue le rôle ; l'index couvre la file pendante. Recommandation cosmétique : aligner le vocabulaire (`processed_at` dans la doc audit ↔ `dispatched_at` dans le schéma).

### 2.3 Listeners outbox (post-commit)

| Listener | Fichier:ligne | Pattern |
|---|---|---|
| `PersistOrderCreatedToOutbox::handle()` | `app/Listeners/PersistOrderCreatedToOutbox.php:14-40` | Crée la ligne `domain_events`, `channel = ['private-branch.{branch_id}']`, broadcast_as = `OrderCreated`, **`DB::afterCommit(fn() => DispatchDomainEventsJob::dispatch(...))`** (l. 37). |
| `PersistOrderStatusChangedToOutbox::handle()` | `app/Listeners/PersistOrderStatusChangedToOutbox.php:14-40` | Idem, broadcast_as = `OrderStatusChanged` (l. 36). |
| `PersistItemAvailabilityChangedToOutbox::handle()` | `app/Listeners/PersistItemAvailabilityChangedToOutbox.php:16-56` | Idem ; pour event sans `branchId` → fan-out vers tous les `branch.{id}` actifs (l. 32-39). `DB::afterCommit` (l. 53). |

`app/Providers/EventServiceProvider.php:103-115` câble explicitement `OrderCreated`, `OrderStatusChanged`, `ItemAvailabilityChanged` vers les listeners outbox.

### 2.4 Job de dispatch

`app/Jobs/DispatchDomainEventsJob.php`

- `ShouldQueue` + queue `high` (l. 27).
- `tries = 5`, `backoff = [1, 5, 30, 300]` (l. 21-23).
- Idempotence : early-return si `dispatched_at !== null` (l. 37-39).
- Incrément `attempts` (l. 41).
- **Validation envelope avant trigger Pusher** : `EventContract::assertEnvelopeValid` (l. 57). `PayloadMismatchException` → `last_error = contract_violation: …` puis re-throw (l. 58-67).
- Trigger : `app(BroadcastManager::class)->connection('pusher')->getPusher()->trigger($channels, $broadcast_as, $envelope)` (l. 71-91). Skip propre si clé Pusher absente (CI, l. 81-89).
- Marque `dispatched_at` + reset `last_error` (l. 95-98).
- `failed()` final : persiste `last_error` + log warning (l. 101-118) ; le job tombe dans la `failed_jobs` Laravel par défaut (DLQ).

### 2.5 Re-queue / replay (rescue + retry-failed)

- `app/Console/Commands/OutboxRescueCommand.php` — `foodking:outbox:rescue` (signature `:11`) : sélectionne `pending().stale(2 min).where(attempts < 5)` puis re-dispatch.
- `app/Console/Commands/OutboxRetryFailedCommand.php` — `foodking:outbox:retry-failed --since=1h` : reset `attempts/last_error/dispatched_at` + re-dispatch ciblé.
- Schedule : `app/Console/Kernel.php:31-33` exécute `foodking:outbox:rescue` chaque minute avec `withoutOverlapping()`.

→ V3 (retry + DLQ documentés) **OK**. Doc d'opération : `docs/OUTBOX_PATTERN.md`.

### 2.6 Authorization canaux

`routes/channels.php:25-39`

```
Broadcast::channel('branch.{branchId}', function ($user, $branchId) {
    if ($user->currentAccessToken() && $user->tokenCan('kiosk:order')) {
        $machine = KioskMachine::where('user_id', $user->id)->first();
        return $machine && (int) $machine->branch_id === (int) $branchId;
    }
    if ((int) $user->branch_id === 0) { return true; }     // admin
    return (int) $user->branch_id === (int) $branchId;     // staff
});
```

- Token kiosk (`ability=kiosk:order`) restreint à la branche de la `KioskMachine` rattachée — corrige `[GAP-21-5]` (anciennement, kiosk avec `branch_id=0` héritait d'un wildcard cross-branch).
- Admin (`branch_id=0`, hors token kiosk) → wildcard intentionnel.
- Staff réguliers → `user.branch_id === branchId`.
- Endpoint d'auth : `/api/broadcasting/auth` (Sanctum-protected, voir `BroadcastServiceProvider` Laravel par défaut + `bootstrap.js:71`).

→ V4 (channel auth valide branch + role) **OK**.

### 2.7 Audit transaction-discipline (H1)

Recherche exhaustive de `event(` / `broadcast(` / `*::dispatch(` dans `app/Services/**`. Résultats :

| Fichier:ligne | Type | Statut |
|---|---|---|
| `app/Services/OrderService.php:541` | `OrderCreated::dispatch($this->order)` (Web/App `myOrderStore`) | **APRÈS** `});` ligne 530 → ✓ |
| `app/Services/OrderService.php:961` | `OrderCreated::dispatch($order)` (`posOrderStore`) | **APRÈS** `});` ligne 952, dans le bloc `if ($order)` post-commit → ✓ |
| `app/Services/OrderService.php:1266` | `OrderCreated::dispatch($this->order)` (`tableOrderStore`) | **APRÈS** `});` ligne 1258 → ✓ |
| `app/Services/OrderService.php:1423` | `OrderStatusChanged::dispatch($order, …)` (`changeStatus` Delivery Boy) | **APRÈS** `});` ligne 1413 → ✓ |
| `app/Services/OrderService.php:1478` | `OrderStatusChanged::dispatch` self-cancel customer | Pas wrapper `DB::transaction` (un seul `save()` au-dessus) → ✓ pas de risque |
| `app/Services/OrderService.php:1573` | `OrderStatusChanged::dispatch` cycle changeStatus | Commentaire `[PHASE-E] After commit; ShouldBroadcastNow — must not run inside DB::transaction` ; après `});` → ✓ |
| `app/Services/FrontendOrderService.php:842` | `OrderCreated::dispatch($frontendOrder)` (`dispatchNewOrderSignals`) | Appelé l. 828 après `frontendOrder->refresh()` qui suit `DB::transaction` l. 799-811 (et la transaction principale l. 151+ a déjà clôturé) → ✓ |
| `app/Services/FrontendOrderService.php:848` | `OrderStatusChanged::dispatch` | Idem post-commit → ✓ |
| `app/Services/FrontendOrderService.php:688` | `event(new OrderStatusChanged(...))` self-cancel kiosk | `changeStatus` (l. 642+) **n'utilise pas** `DB::transaction` (uniquement un `save()` ligne 677) → pas de risque rollback → ✓ |
| `app/Services/KitchenDisplaySystemOrderService.php:167` | `OrderStatusChanged::dispatch($snapshot, $oldStatus, $newStatus)` | **APRÈS** `});` l. 157 → ✓ |
| `app/Services/ItemService.php:182` | `event(new ItemCreated(...))` | Wrappé `DB::afterCommit` (l. 181) → ✓ |
| `app/Services/ItemService.php:279` | `event(ItemAvailabilityChanged::fromItem(...))` | **APRÈS** `});` l. 268 → ✓ |
| `app/Services/ItemService.php:306` | `event(new ItemDeleted(...))` | Wrappé `DB::afterCommit` (l. 305) → ✓ |
| `app/Services/ItemCategoryService.php:119/151/186` | `event(new Category*)` | Wrappés `DB::afterCommit` → ✓ |
| `app/Services/Menu/AvailabilityService.php:207` | `event(ItemAvailabilityChanged::forBranch(…))` | Appelé via `dispatchEvent()` depuis `decrementForOrder()` (l. 158-203) ; ce listener (`DecrementItemAvailabilityOnOrder`) écoute `OrderCreated`, lui-même dispatché POST-commit → exécution **hors transaction** → ✓ ; voir finding informationnel ci-dessous. |
| `app/Jobs/CleanupStalePendingKioskOrders.php:44` | `OrderStatusChanged::dispatch` (rejet auto kiosk) | Job hors transaction métier → ✓ |

Aucun `event(...)` / `dispatch(...)` détecté à l'intérieur d'une `DB::transaction` non terminée pour les flux Order/Menu critiques.

→ V1 (dispatch après commit) **OK** sur les surfaces P0 (POS, Kiosk, KDS, OSS, item availability).

---

## 3. Pass B — Frontend (Echo, abonnements, reconnect)

### 3.1 Init Echo + Pusher

`resources/js/bootstrap.js`

- Import `laravel-echo` + `pusher-js` (l. 25-26).
- Conditionnel sur `process.env.MIX_PUSHER_APP_KEY` — si absent, `wsService._setState(UNAVAILABLE)` et bascule polling-only (l. 92-96). **Correctif clé `[V5-BUGFIX]` (l. 30-37)** : usage de `process.env.MIX_*` (Mix/webpack) au lieu de `import.meta.env.VITE_*` (jamais défini en prod) — sans ce fix, Echo n'aurait jamais été initialisé.
- Token Sanctum injecté dynamiquement depuis `localStorage.vuex` (kioskToken | authToken) (l. 44-51, 71-77).
- Tuning liveness : `activityTimeout: 30000`, `pongTimeout: 5000` (l. 64-70). Détection ≤ 35 s + backoff exponentiel natif Pusher (1 → 30 s).
- `window._refreshEchoAuth()` exposé pour ré-injecter le Bearer après login (l. 83-88).
- `wsService.start()` (l. 90).

### 3.2 Wrapper de contrat front

`resources/js/services/eventContract.js`

- `EVENT_TYPES` + `BROADCAST_MAP` miroir du back (l. 1-14).
- `validateEnvelope()` rejette `version !== 1`, `type` non string, `payload` non objet (l. 20-42).
- `parseEvent()` lève si envelope invalide (l. 44-58).
- `onEvents(branchId, bindings)` :
  - Garde `window.Echo && branchId && bindings.length` (l. 65) → noop sûr en mode dégradé.
  - `Echo.private('branch.' + branchId)` (l. 71-72) — Echo préfixe automatiquement `private-` côté serveur (correspond au `private-branch.{id}` du back).
  - Vérifie `parsed.type === BROADCAST_MAP[broadcastAs]` et avertit en cas de drift (l. 85-91).
  - Retourne `{ unsubscribe() }` qui appelle `channel.stopListening('.${broadcastAs}')` puis `Echo.leave(channelName)` (l. 103-115).

### 3.3 Reconnexion / heartbeat

`resources/js/services/WebSocketService.js`

- Bind sur `pusher.connection.state_change` (l. 55-78) → exposes `connected | connecting | disconnected | unavailable | failed`.
- Émet `connected` / `disconnected` (l. 122-127) consommables par les bandeaux UI.
- Heartbeat 30 s (`HEARTBEAT_INTERVAL_MS`, l. 17 + l. 129-138).
- Backoff/reconnect : délégué à Pusher (cf. `bootstrap.js` `activityTimeout/pongTimeout`).

### 3.4 Abonnements + unsubscribe par surface

| Surface | Fichier:ligne | Subscribe | Unsubscribe |
|---|---|---|---|
| **KDS** | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:569-596` | `onEvents(branchId, [OrderStatusChanged, OrderCreated])` (l. 576-579), `_eventSub` stocké sur l'instance | `unsubscribeEcho()` (l. 586-596) appelée dans `beforeUnmount` (l. 823-829) **ET** systématiquement avant chaque `subscribeEcho` (`[AUDIT-P51-BUG2]`, l. 573-574) |
| **OSS** | `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:127-165` | `onEvents(branchId, [OrderStatusChanged, OrderCreated])` (l. 130-… ) | `unsubscribeEcho()` (l. 157-165) — re-mount safe |
| **POS** | `resources/js/components/admin/pos/PosComponent.vue:1066-1126` | `onEvents(branchId, [OrderCreated, OrderStatusChanged, ItemAvailabilityChanged])` (l. 1071-1087) | `_unsubscribeEcho()` (l. 1123-1126) appelé l. 915 |

→ V6 (unsubscribe + reconnect) **OK** — pattern systématique, branche admin (`branch_id=0`) reste en polling (commenté explicitement).

---

## 4. Trace lifecycle — POS `posOrderStore` → KDS

| Étape | Fichier : ligne | Détail |
|---|---|---|
| 1 — Front POS soumet | `resources/js/components/admin/pos/PosComponent.vue` (axios `POST /api/admin/pos`) | Payload validé client-side, totaux ignorés serveur. |
| 2 — Controller HTTP | `app/Http/Controllers/Admin/PosController.php::store()` | Délègue à `OrderService::posOrderStore`. |
| 3 — Service POS | `app/Services/OrderService.php:556-1276` | Idempotency check `X-Idempotency-Key` (l. 558-566) ; ouverture `DB::transaction` l. 570 ; recalcul SSOT prix (`PricingService`) ; persistance `Order` + items + transaction + ActionLog ; close `});` l. 952. |
| 4 — Dispatch événement | `app/Services/OrderService.php:961` | `\App\Events\OrderCreated::dispatch($order)` **après** la fermeture de la transaction. |
| 5 — Listener outbox | `app/Listeners/PersistOrderCreatedToOutbox.php:14-40` | Crée la ligne `domain_events` (`event_type=order.created`, `payload={order_id, queue_number, status, order_type, total, created_at}`, `channel=['private-branch.{branch_id}']`, `broadcast_as='OrderCreated'`, `correlation_id`, `occurred_at`). |
| 6 — Re-garde `afterCommit` | `app/Listeners/PersistOrderCreatedToOutbox.php:37-39` | `DB::afterCommit(fn() => DispatchDomainEventsJob::dispatch($id)->onQueue('high'))` — sécurité `defense in depth` : si jamais l'appel s'effectuait dans une transaction parente non vue, le job ne serait enqueué qu'au commit. |
| 7 — Job dispatch | `app/Jobs/DispatchDomainEventsJob.php:31-99` | `find($id)` ; idempotence (skip si `dispatched_at` non null) ; incrément `attempts` ; build envelope `EventContract::buildEnvelope` ; `assertEnvelopeValid` ; `Pusher::trigger(['private-branch.X'], 'OrderCreated', envelope)` ; set `dispatched_at = now()`. |
| 8 — Pusher / Soketi | (infra) | Push WebSocket vers les sockets abonnés à `private-branch.X`. |
| 9 — Echo client KDS | `resources/js/bootstrap.js:55-78` | Connexion authentifiée via `/api/broadcasting/auth`. |
| 10 — Wrapper front | `resources/js/services/eventContract.js:60-101` | `Echo.private('branch.X').listen('.OrderCreated', handler)` ; `parseEvent` valide version + type. |
| 11 — Composant KDS | `KitchenDisplaySystemComponent.vue:577-578` | Handler appelle `_debouncedRefresh()` → re-fetch `kitchenDisplaySystemOrder/lists` → la nouvelle commande apparaît. |

**Correlation_id** propagé bout-en-bout (UUID set à l'étape 5, conservé en envelope, exposé en log côté job).

---

## 5. Matrice failure modes

| Scénario | Impact attendu | Garde-fou code | Verdict |
|---|---|---|---|
| **Worker queue crash** entre `OrderCreated::dispatch` et `Pusher::trigger` | Ligne `domain_events` créée, jamais broadcastée | Job auto-retry `tries=5` `backoff=[1,5,30,300]` (`DispatchDomainEventsJob.php:21-23`). Au-delà → `failed_jobs` (DLQ Laravel) + `last_error` persisté (`failed()` l. 101-118). Schedule `foodking:outbox:rescue` chaque minute re-queue les `stale(2 min)` ayant `attempts<5` (`Kernel.php:31` + `OutboxRescueCommand.php:17-26`). Replay manuel via `foodking:outbox:retry-failed` (`OutboxRetryFailedCommand.php`). | **Mitigé** |
| **DB rollback après dispatch** (transaction interne échoue tardivement) | Risque de notification fantôme (commande inexistante côté lecteurs) | Toutes les `event/dispatch` Order* sont placées **hors** des `DB::transaction()` ; les listeners outbox utilisent en plus `DB::afterCommit()` pour enqueuer le job. Test `OutboxTest::test_domain_event_not_persisted_on_rollback` (l. 43-60) prouve qu'un `OrderCreated::dispatch` dans une transaction rollbackée ne laisse aucune ligne `domain_events`. | **Protégé** |
| **Channel auth bypass** (utilisateur branche A écoute branche B) | Fuite cross-branch des events `OrderCreated`/`OrderStatusChanged` | `routes/channels.php:25-39` filtre : kiosk-token → KioskMachine.branch_id strict ; staff régulier → `user.branch_id === branchId` ; admin (`branch_id=0`) wildcard intentionnel. Endpoint `/api/broadcasting/auth` derrière Sanctum. | **Protégé** (admin wildcard documenté & assumé) |
| **EventContract version drift** (un nouveau listener émet v=2) | Clients front décoderaient mal | Back : `EventContract::assertEnvelopeValid` l. 81-83 lève `PayloadMismatchException` si `version !== 1` → job marque `last_error=contract_violation:…` + remonte (`DispatchDomainEventsJob.php:57-67`). Test `EventContractTest::test_dispatch_job_rejects_envelope_that_violates_contract` (l. 143-186). Front : `eventContract.js:26-29` `validateEnvelope` rejette `version !== 1` ; `parseEvent` lève. | **Protégé** |
| **Token Bearer expiré côté SPA** (post-login délai, post-refresh) | Auth `/api/broadcasting/auth` 401 → channel non souscrit | `window._refreshEchoAuth()` exposé pour réinjecter le Bearer (`bootstrap.js:83-88`) ; pattern `subscribeEcho` resilient (try/catch + log warn + polling fallback). | **Mitigé** (à confirmer côté `auth.js` — hors scope) |
| **Drift `decrementForOrder` réinvoqué dans une transaction parente** (non-régression) | `event(ItemAvailabilityChanged)` lèverait dans une transaction → potentiel rollback post-event | Aujourd'hui sûr : seul caller = listener `DecrementItemAvailabilityOnOrder` sur `OrderCreated` (post-commit). Aucun caller transactionnel détecté. À surveiller si nouvel appel direct depuis un service. | **Surveillance** |

---

## 6. Vérifications V1–V7

| ID | Critère | Verdict | Preuve |
|---|---|---|---|
| **V1** | Tous les `event/dispatch` après commit | **PASS** | Cf. tableau §2.7 + `OutboxTest::test_domain_event_not_persisted_on_rollback`. |
| **V2** | Outbox a un index `(processed_at, created_at)` | **PASS (sémantique)** | `idx_pending` sur `(dispatched_at, occurred_at)` — `domain_events` migration l. 27. Recommandation : aligner vocabulaire `processed_at` ↔ `dispatched_at` dans la doc audit. |
| **V3** | Retry policy + DLQ documentés | **PASS** | `tries=5`, `backoff=[1,5,30,300]`, `failed()` persiste `last_error`, `failed_jobs` Laravel, `foodking:outbox:rescue` (every minute), `foodking:outbox:retry-failed`, `docs/OUTBOX_PATTERN.md`. |
| **V4** | `routes/channels.php` valide branch + role | **PASS** | l. 25-39 ; cas kiosk-token (ability `kiosk:order` → KioskMachine.branch_id) couvert. |
| **V5** | EventContract versionné (champ `version`) | **PASS** | `EventContract::ENVELOPE_VERSION = 1`, `assertEnvelopeValid` rejette mismatch ; miroir front `eventContract.js:26-29`. |
| **V6** | Front Echo unsubscribe au unmount + reconnect | **PASS** | KDS / OSS / POS appellent `unsubscribe()` dans `beforeUnmount` ; `WebSocketService` bind state_change ; backoff délégué Pusher (`activityTimeout/pongTimeout`). |
| **V7** | Test E2E "POS commande → KDS apparaît < 2s" présent | **FAIL/WARN** | `tests/e2e/04-kds-status.spec.js` se limite à login + non-crash. Aucun spec mesurant explicitement la latence POS → KDS. `tests/Feature/SyncComprehensiveTest.php` existe mais n'a pas été inspecté pour mesure de latence — à valider dans cycle dédié. |

---

## 7. Findings (consolidés)

| ID | Sévérité | Pilier | Description | Référence |
|---|---|---|---|---|
| F-SYNC-V7-WARN | **P2** | Tests | Pas de test E2E mesurant explicitement « POS commande → KDS apparaît < 2 s ». `04-kds-status.spec.js` n'évalue ni la réception de l'event ni sa latence. | `tests/e2e/04-kds-status.spec.js` |
| F-SYNC-OUTBOX-NAMING | **P3** | Doc | Vocabulaire « `processed_at` » dans audits/doc ↔ « `dispatched_at` » réel en schéma. Aligner pour éviter confusion lors d'audits ultérieurs. | `database/migrations/2026_04_15_200000_create_domain_events_table.php:22-27` |
| F-SYNC-AVAIL-EVENT-FRAGILE | **P3** | Robustesse | `AvailabilityService::decrementForOrder` appelle `event(ItemAvailabilityChanged…)` sans `DB::afterCommit`. Aujourd'hui sûr (chaîne post-commit), mais une future invocation directe depuis un service transactionnel introduirait un risque d'event fantôme. | `app/Services/Menu/AvailabilityService.php:205-213` |
| F-SYNC-DLQ-OBSERVABILITY | **P3** | Ops | Aucun mécanisme de remontée alerte sur `domain_events.last_error != NULL` ou `failed_jobs.payload` orienté outbox. La file DLQ est silencieuse hors inspection manuelle. | `app/Jobs/DispatchDomainEventsJob.php:101-118` + `failed_jobs` |

Aucun finding **P0/P1**. Aucun **FAIL** sur V1 ni V4.

---

## 8. Verdict

> **GLOBAL : WARN**

Justification : V1, V2, V3, V4, V5, V6 vérifiés conformes (preuves code + tests). V7 incomplet — la couverture E2E « POS → KDS < 2 s » n'est pas matérialisée par un spec explicite. Les invariants critiques (dispatch-after-commit, isolation branche, contrat envelope, retries/DLQ) sont solides ; la vulnérabilité résiduelle est de type **observabilité / preuve par test**, pas une faille runtime.

---

## 9. Cycles P proposés

| Cycle | Priorité | Objectif |
|---|---|---|
| **P14_SYNC_E2E_LATENCY_<2S** | **P2** | Créer un spec Playwright (login chef KDS + login caissier POS dans 2 contexts) qui : (1) déclenche `POST /api/admin/pos`, (2) attend l'apparition de la commande sur la surface KDS, (3) assert latence wallclock < 2 000 ms. |
| **P15_OUTBOX_DLQ_OBSERVABILITY** | **P3** | Ajouter une route admin (ou commande Artisan) listant `domain_events` avec `last_error != NULL` ou `attempts >= 5` ; + alerte (log channel `slack`/email) si seuil dépassé sur la dernière heure. |
| **P11_DISPATCH_AFTER_COMMIT_AUDIT (confirmation)** | **P3** | Ajouter un test statique (rule PHPStan ou test PHPUnit qui scanne via Reflection les services Order*/Item* et vérifie qu'aucun `event(`/`dispatch(` n'apparaît à l'intérieur d'une closure passée à `DB::transaction`). Garde-fou anti-régression. |

---

*Audit AUDIT-ONLY effectué sans modification de code applicatif. Seul fichier écrit : ce rapport.*
