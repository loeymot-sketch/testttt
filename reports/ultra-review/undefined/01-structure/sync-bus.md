# SYNC-BUS — Cartographie du bus de synchro temps-réel (vague 01-structure)

- **Date** : 2026-07-02 · **HEAD** : `594eb92f5` (branche `pos/category-first-caisse-2026-06-23`)
- **Lecteur** : sync-bus (read-only). Tout file:line ci-dessous a été lu dans cette session.
- **Objet** : re-vérifier SYNC_CONTRACT.md contre le code actuel + carte producteurs/consommateurs.

## 1. Architecture (vue d'ensemble)

```
Producteur (OrderService / PaymentService / FrontendOrderService / KDS service)
  └─ Event PLAIN (pas ShouldBroadcast) via trait DispatchableAfterCommit
       └─ Listener Persist*ToOutbox (sync, PREMIER de la liste — ordre garanti)
            └─ row `domain_events` (idempotency_key UNIQUE, channel JSON, broadcast_as)
                 └─ DB::afterCommit → DispatchDomainEventsJob::dispatch(id) (queue 'high')
                      └─ Phase 1 claim (lockForUpdate + dispatched_at guard)
                      └─ Phase 2 EventContract::buildEnvelope + assertEnvelopeValid → broadcaster->broadcast()
                           └─ soketi :6001 (protocole Pusher) → Echo client `private-branch.{id}`
                                └─ eventContract.js onEvents() → KDS / OSS staff / POS / tracker
Dégradé : poll REST par surface (KDS 5s WS-down / OSS wall 5s / POS 30s / kiosk 15s)
Filets : cron rescue (1 min) + monitor (1 min) + retry-failed (1 h) + prune (90 j)
```

## 2. Événements du périmètre

| Event | Fichier | Nature | Payload constructeur |
|---|---|---|---|
| OrderCreated | `app/Events/OrderCreated.php:19-26` | plain, trait `DispatchableAfterCommit` (:21) — commentaire :12 « replacing direct ShouldBroadcastNow » | `BroadcastableOrder $order` |
| OrderStatusChanged | `app/Events/OrderStatusChanged.php:15-25` | plain + after-commit | `$order, int $oldStatus, int $newStatus` |
| KdsOrderRecalled | `app/Events/KdsOrderRecalled.php:27-40` | plain + after-commit ; action COMPENSATOIRE (orders.status NON muté, append-only) ; volontairement ≠ OrderStatusChanged pour ne pas re-notifier le client (docblock :19-21) | `orderId, branchId, queueNumber, actorId, recalledAt, correlationId` |
| OutboxBroadcastSwallowedEvent | `app/Events/OutboxBroadcastSwallowedEvent.php:33-45` | observabilité interne, jamais broadcast client | `domainEventId, eventType, aggregateId, branchId, listener, errorMessage, failedAt` |

`DispatchableAfterCommit` (`app/Events/Concerns/DispatchableAfterCommit.php:30-44`) : si `transactionLevel()>0` → `afterCommit`, sinon dispatch immédiat ; rollback = event abandonné (gate C9 / KI-001).

## 3. Listeners outbox (producteur → row domain_events)

Wiring `app/Providers/EventServiceProvider.php` : Persist*ToOutbox est **toujours en tête de liste** (F-002 : outbox SSOT avant FCM/loyalty/print) —
- `OrderStatusChanged` → `PersistOrderStatusChangedToOutbox` (ESP :153-158)
- `KdsOrderRecalled` → `PersistKdsOrderRecalledToOutbox` (ESP :168-170)
- `OrderCreated` → `PersistOrderCreatedToOutbox` + Decrement*/Fcm/PrintKiosk* (ESP :172-183)
- `OrderPaidAtCounter` → `PersistOrderPaidAtCounterToOutbox` + impression fiscale (ESP :184-187)
- `OrderPaymentStatusChanged` → `PersistOrderPaymentStatusChangedToOutbox` (ESP :189-191)
- `OutboxBroadcastSwallowedEvent` → `EscalateOutboxBroadcastSwallowed` (ESP :327-329, HEAL B.2)

`PersistOrderCreatedToOutbox.php` (lu intégralement) :
- idempotency_key = `sha1(ORDER_CREATED|order_id)` one-shot (:23) ; firstOrCreate (:25) ; skip dispatch si `!wasRecentlyCreated` (:58-60).
- payload :32-43 : `order_id, queue_number, _origin, payment_method, payment_status, payment_pending_counter, status, order_type, total, created_at` ; `_origin` résolu source_surface→pos→kiosk→web (:126-139).
- channel = `json_encode(['private-branch.'.$order->branch_id])` (:44), broadcast_as = `'OrderCreated'` (:45).
- `DB::afterCommit` → `DispatchDomainEventsJob::dispatch($domainEvent->id)` best-effort (:62-75) ; échec → `Log::error` + `OutboxBroadcastSwallowedEvent` (:76-104), double try/catch (l'observabilité ne casse jamais la cascade).

`PersistOrderStatusChangedToOutbox.php:27-33` : idempotency_key inclut `old|new|correlation_id` (transitions non one-shot, reverts admin possibles). `PersistKdsOrderRecalledToOutbox.php:58-59` : channel `private-branch.{branchId}`, broadcast_as `KdsOrderRecalled` (confirme SYNC_CONTRACT §3).

## 4. Job & contrat d'enveloppe

`app/Jobs/DispatchDomainEventsJob.php` :
- Queue `high` (:46), `$tries=6`, backoff `[1,5,15,60,300]` ≈ 6,4 min (:40-42).
- Phase 1 claim atomique : `lockForUpdate` + guard `dispatched_at !== null` + `attempts++` dans une transaction (:65-86) — jamais de double broadcast concurrent.
- Phase 2 hors transaction : `EventContract::buildEnvelope` (:107) + `assertEnvelopeValid` (:110) → `BroadcastManager::connection()` (respecte `broadcasting.default`, pas de hard-code pusher, :115-116) → heartbeat `Cache::put('ws:heartbeat', ts, 120)` best-effort (:129).
- Phase 3b échec : `dispatched_at=null` + `last_error` (:161-166) ; `PayloadMismatchException` → `$this->fail()` immédiat (pas de retry inutile, :184) ; `failed()` préfixe `contract_violation:` conservé (:199-213).

`app/Domain/Events/EventContract.php` : `ENVELOPE_VERSION=1` (:28) ; `BROADCAST_MAP` (:34-50, 11 broadcast_as ↔ EventType dotted, miroir de `resources/js/services/eventContract.js:18`) ; `REQUIRED_PAYLOAD_KEYS` par type (:56-79, ex. ORDER_CREATED exige `order_id, queue_number, _origin, payment_method`) ; envelope = `{version, type, aggregate_id, branch_id, occurred_at, correlation_id, payload}` (:84-95).

## 5. Table `domain_events` + modèle

- Migration `database/migrations/2026_04_15_200000_create_domain_events_table.php:11-30` : event_type, aggregate_type/id, branch_id nullable, payload JSON, channel, broadcast_as, correlation_id char(36), occurred_at(3), dispatched_at(3) nullable, attempts, last_error ; index `idx_pending(dispatched_at,occurred_at)`, `idx_aggregate`, `idx_branch`. Clé idempotence ajoutée par `2026_05_09_180000_add_idempotency_key_to_domain_events.php` (UNIQUE, vue via ls).
- `app/Models/DomainEvent.php:34-49` : scopes `pending` (dispatched_at NULL), `stale` (pending + >2 min), `failed` (pending + attempts>=4). **Pas de BranchScope** (exemption documentée CLAUDE.md §9 baseline V1.0.2).

## 6. Canal & auth — `routes/channels.php`

- `App.Models.User.{id}` (:16-18) : user propre id.
- `branch.{branchId}` (:41-62) — **CONFIRMÉ ligne 41** :
  - Token kiosk discriminé par **NOM de token** `'kiosk-token'` (:44-45), PAS `tokenCan` (contourné par abilities `['*']` des staff — F-SEC-W6-01) ; machine résolue `withoutGlobalScope(BranchScope)` par user_id, autorisé ssi `machine.branch_id === branchId` (:46-49).
  - Cross-branch : rôle `Admin`/`Tenant Admin` explicite (:56-58) — plus de sentinel `branch_id===0` nu (Guest-Echo-Bypass fermé, F-SEC-W6-02).
  - Staff : `user->branch_id === branchId` (:61).
- Wire name = `private-branch.{id}` (convention Pusher) ; client s'abonne `Echo.private('branch.'+branchId)` (`eventContract.js:353-354`).

## 7. Transport

- `soketi.json` : host 127.0.0.1:6001, app array `app-id/app-key/app-secret`, maxConnections 500, `enableClientMessages:false`, webhooks [].
- `config/broadcasting.php` : default `env('BROADCAST_DRIVER')` (:18) ; connexion pusher → `PUSHER_HOST/PORT/SCHEME` (:52-56) ; **timeouts Guzzle** `PUSHER_TIMEOUT=5s` / `PUSHER_CONNECT_TIMEOUT=3s` (:71-72) anti « clean orphan » (black-hole réseau → throw → last_error → récupérable).
- Cadences poll par surface documentées au même fichier :22-31 (bloc PHP `polling_fallback` supprimé — SoT par surface).

## 8. Client JS

`resources/js/services/WebSocketService.js` (singleton `wsService`) :
- États :38-46 ; bind `state_change` Pusher (:81-102) ; démarré par `bootstrap.js:449` après `new Echo` (:332) ; `pusher:subscription_error` → `wsService.handleSubscriptionError` (`bootstrap.js:400`).
- F-12 auth : fenêtre glissante 60s / seuil 3 (:25-26) → `SESSION_INVALID` une seule fois (:174-193).
- NEW-02 circuit-breaker tempête : 4 déconnexions/30s (:33-36) → breaker ouvert, `pusher.disconnect()`, délai decorrelated-jitter 5-30s (:277-352), pas de reconnect si SESSION_INVALID (:326), fenêtre purgée au tir du timer (:338).
- `on()` retourne un unsubscribe (fuite listeners sinon, :134-138).

Consommateurs :
- **KDS** : `KitchenDisplaySystemComponent.vue:1899` — poll `wsConnected ? 60000 : 5000` ms ; `KdsSyncService.js:31` `degradedBaseMs:5000`, WS connecté → `interval: Infinity` (:302) ; floor anti-DoS (:20). Bannière fallback : **opt-out** `env==='local' && window.FK_KDS_SHOW_FALLBACK_BANNER===false` (:1314-1320) — fail-safe-to-visible (PR-02 2026-06-04).
- **OSS** : `OssSyncService.js` DEFAULTS `intervalMsWhenConnected:60_000`, `intervalMsWhenDisconnected:2_000`, backoff 5s→30s, ceiling 60s/floor 250ms (:36-37). Mur public (`PreparingAndReadyComponent.vue`) : `branchId<=0` → `subscribeEcho()` early-return (:282-283) = poll-only ; **TRAP-4 (:255-271)** : override `intervalMsWhenConnected: 5_000` pour le mur public seul (budget SYNC-2 8s).
- **POS** : `store/modules/posOrder.js:59-68` — cadence via `MIX_BROADCAST_POLLING_FALLBACK_MS` (build-time, défaut 30000).
- **Kiosk waiting** : `KioskWaitingComponent.vue:183` `POLL_INTERVAL_MS=15000`, toujours actif (:318-320).
- Abonnement typé : `eventContract.js onEvents(branchId, bindings)` (:346) — vérifie `BROADCAST_MAP` type attendu (:365), `channel.listen('.'+broadcastAs)` (:388).

## 9. Filets de sécurité / cron (`app/Console/Kernel.php`)

- `foodking:outbox:rescue` everyMinute + withoutOverlapping + onOneServer (:40-43).
- `foodking:outbox:monitor --threshold=10` everyMinute (:50-53) — `MonitorOutboxStaleness.php` : stale = pending > 30s (:42-47) **+ dimension « crash-claimed orphans »** (dispatched_at set + last_error set + attempts>=5 + >10 min, :70-77) inatteignables par retry/rescue → `Log::error` + exit FAILURE (:129-132). Lit + log seulement, n'enqueue jamais (docblock :16-23).
- `foodking:outbox:retry-failed --since=24h` hourly (:64-69) — rows attempts>=5.
- `foodking:outbox:prune --older-than-days=90` daily 04:00 (:176-178).
- Commandes vues (ls) : OutboxRescueCommand, OutboxRetryFailedCommand, OutboxWebhookRetryFailedCommand, PruneOutboxCommand.

## 10. Producteurs (grep dispatch, sites réels)

- `OrderService.php:628, 1277, 1624` → OrderCreated ; `:1997, 2080, 2265` → OrderStatusChanged.
- `PaymentService.php:437, 700` → OrderStatusChanged (dont CANCELED).
- `FrontendOrderService.php:635` → OrderCreated (borne/web).
- `KitchenDisplaySystemOrderService.php:377` → KdsOrderRecalled.
- `CleanupStalePendingKioskOrders.php:140`, `DispatchKdsTicket.php:17` → OrderStatusChanged.

## 11. SYNC_CONTRACT.md — verdict affirmation par affirmation

| Affirmation contrat | Verdict |
|---|---|
| §2 canal privé `branch.{branchId}` auth channels.php:41, kiosk restreint à sa branche | **CONFIRMÉ** (channels.php:41-49) |
| §3 events plain non-ShouldBroadcast, outbox → private-branch.{id} | **CONFIRMÉ** (OrderCreated.php:19-21 ; Persist*:44-45/53-54/58-59) |
| §3 broadcast via DispatchDomainEventsJob->broadcast() | **CONFIRMÉ** (Job :115-116) |
| §4 payload KDSOrderDetailsResource (id, order_serial_no, token, order_type, source_surface, created_at_iso, updated_at…) | **CONFIRMÉ** (KDSOrderDetailsResource.php:22-50) |
| §5 mur OSS public ne s'abonne pas (branchId<=0 early-return), poll | **CONFIRMÉ** (PreparingAndReadyComponent.vue:282-283) |
| §5/§6 mur OSS poll 60s (« up to ~60s stale ») | **DRIFT (améliroé)** : override TRAP-4 → **5s** pour le mur public (PreparingAndReadyComponent.vue:265-270, 2026-06-04, postérieur au contrat d6487f716) |
| §7 fallback KDS « ~30s » | **DRIFT** : réel = 5s WS-down / 60s WS-up (KDS component :1899 ; KdsSyncService :31) |
| §7 bannière KDS supprimée quand APP_ENV=local | **DRIFT (corrigé)** : désormais opt-out visible-par-défaut (KDS component :1314-1320, PR-02) |
| §7 MonitorOutboxStaleness « only Log::error — alerting gap » | **PARTIEL** : toujours Log::error+exit≠0, mais couvre aussi les crash-claimed orphans (:70-77) ; et le swallow inline escalade en `critical` canal fiscal via EscalateOutboxBroadcastSwallowed (ESP:327) |
| Docblock OutboxBroadcastSwallowedEvent « intentionally unwired » (:25-29) | **DRIFT INTERNE** : il EST câblé (ESP:327-329, HEAL B.2 2026-05-19) — le docblock de l'event est périmé, le listener le documente |

## 12. Risques préliminaires (observations, à vérifier vagues suivantes)

1. SYNC_CONTRACT.md ancré HEAD `d6487f716` : 3 cadences/comportements ont dérivé (OSS 5s, KDS 5/60s, bannière opt-out) — doc partagée multi-agents à rafraîchir sinon deux lanes dérivent des contrats différents.
2. Docblock `OutboxBroadcastSwallowedEvent.php:25-29` contredit le wiring réel (ESP:327) — piège pour un futur agent.
3. Bannière KDS : le câblage config `kds.show_fallback_banner` → `window.FK_KDS_SHOW_FALLBACK_BANNER` via master.blade.php est **différé** (commentaire :1304-1308) — le défaut est sûr (visible) mais l'opt-out .env est inopérant.
4. Alerting = Log::error / fiscal critical + exit code cron ; pas de pager réel V1 LOCAL (assumé, cf. EscalateOutboxBroadcastSwallowed docblock) — dépend d'un grep nocturne opérateur.
5. `soketi.json` : credentials statiques `app-key/app-secret` en clair dans le repo (localhost only, maxConnections 500) — à confronter au .env VPS en vague sécurité.
6. Mur public OSS branchId<=0 : poll non authentifié à 5s — vérifier charge/authz endpoint en vague W4.

## 13. Couverture de tests (fichiers réels, vus par ls/grep)

PHP : `tests/Feature/Outbox/` (OutboxDeliveryTest, OutboxConcurrentRetryLockTest, OutboxConcurrentWorkerDedupeTest, ListenerReplayDedupeTest, OutboxRescueStaleClaimedRowsTest, OutboxMonitorCrashClaimedSentinelTest, OutboxBroadcastSwallowedListenerTest, OutboxReplayAuditTest, OutboxRetryFailedAttemptsPreservedTest, OutboxProductionLikeSimulationTest, CatalogEventDispatchAfterCommitTest, PersistBranchStatusChangedTest) + `OutboxTest.php`, `OutboxRescueTest.php`, `EventContractTest.php`, `AfterCommitDispatchTest.php`, `KioskRealtimeBroadcastTest.php`, `Order/ChangePaymentStatusOutboxTest.php`.
JS : `tests/js/wsReconnectStormDetection.spec.js`, `wsReconnectStormCircuitBreaker.spec.js`, `wsAuthExpired.spec.js`, `wsAuthRefreshLoop.spec.js`, `wsAuthAndStormCohabitation.spec.js`, `kdsSyncCadence.spec.js`, `kdsReactsToReconnectStorm.spec.js`, `kdsDedupeByIdVersion.spec.js`, `metricsBatcher*.spec.js`.

## 14. Questions ouvertes

- Le VPS de prod a-t-il `BROADCAST_DRIVER=pusher` + `PUSHER_*` + `MIX_PUSHER_APP_KEY` au build ? (mémoire projet : cause racine des « bugs » terrain ; non vérifiable ici — .env non lu).
- `outbox:rescue` lane-B : seuil exact attempts<5 et interaction avec retry-failed (fichier commande non lu en détail).
- Qui consomme `ws:heartbeat` aujourd'hui (SyncOverviewController:531 cité en commentaire Job:120 — non lu) ?
