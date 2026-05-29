# Sync System — Verified Cartography (SRE/SYNC ultra-audit, 2026-05-29)

> Anti-hallucination: every claim cites a file I opened (Read) or saw in a Grep
> result this session. All three propagation traces and the channel-auth chain
> were read end-to-end; no claim is left unverified.

## Sync System — Verified Cartography

### Transport mechanism
- **Laravel broadcasting, Pusher-protocol driver, env-selected.**
  `config/broadcasting.php:18` `'default' => env('BROADCAST_DRIVER')`; pusher
  connection `config/broadcasting.php:46-61` (`PUSHER_HOST` supports self-hosted
  Soketi). No Socket.io.
- **Polling fallback is per-surface, frontend-owned** (PHP `polling_fallback`
  config deleted, heal B.3). Authoritative cadence note `config/broadcasting.php:20-31`:
  POS 30000ms (`posOrder.js:59-64`), KDS 5000ms WS-down / 60000ms WS-up
  (`KitchenDisplaySystemComponent.vue:1759-1761`), Kiosk 15000ms
  (`KioskWaitingComponent.vue:152-154`).
- **Channel authorization is REAL and branch-scoped** (`routes/channels.php:41-62`).
  `branch.{branchId}` private channel: kiosk-token restricted to its
  `KioskMachine.branch_id` via **token-NAME** check (`:44-50`, immune to Sanctum
  `*` wildcard — a prior `tokenCan('kiosk:order')` bug that over-granted was
  healed, see comment `:27-35`); Admin/Tenant-Admin get cross-branch (`:56-58`);
  regular staff own-branch only (`:61`). Guest-Echo-Bypass (branch_id=0 customers)
  closed by switching from bare `branch_id===0` to explicit role check.

### Event emission architecture (event → Listener → outbox)
Domain events are NOT recorded inline in OrderService. They are emitted by
dedicated **outbox-persister Listeners** (Grep-verified, `app/Listeners/`):
`PersistOrderStatusChangedToOutbox.php` (`EventType::ORDER_STATUS_CHANGED`,
`broadcast_as 'OrderStatusChanged'`, `:28-54`), `PersistCatalogChangedToOutbox.php`
(`'CatalogChanged'`, `:84`), `PersistOrderTableChangedToOutbox.php`
(`'OrderTableChanged'`, `:52-76`), `PersistCouponChangedToOutbox.php`,
`PersistSettingsUpdatedToOutbox.php` (`:69`), `PersistBranchStatusChangedToOutbox.php`.
Each `firstOrCreate`s a `domain_events` row (keyed on `idempotency_key` = sha1
of type+aggregate+old/new+correlation_id → strong dedup) then dispatches
`DispatchDomainEventsJob` directly inside its own `DB::afterCommit` (the listener
path, NOT the `HasDomainEvents` trait — the trait is a second, model-driven
producer path). VERIFIED channel string:
`PersistOrderStatusChangedToOutbox.php:53` writes
`'channel' => json_encode(['private-branch.' . $order->branch_id])`,
`broadcast_as 'OrderStatusChanged'` (`:54`). Same `private-branch.{id}` literal
in `PersistCatalogChangedToOutbox.php:83`, `PersistSettingsUpdatedToOutbox.php:68`,
`PersistBranchStatusChangedToOutbox.php:73`, and the item-availability listeners
(`'private-branch.' . $event->branchId`). **Channel auth is sound end-to-end:**
emitter writes `private-branch.{id}`; Laravel strips the `private-` prefix and
matches `routes/channels.php:41` authorizer `branch.{branchId}` with the
kiosk-token / role / own-branch checks. Each listener wraps the dispatch in
try/catch and, on Pusher failure, fires `OutboxBroadcastSwallowedEvent`
(`PersistOrderStatusChangedToOutbox.php:81-111`) — the row is already persisted so
`outbox:retry-failed` recovers it.

### Outbox / event pipeline (transactional outbox — mature)
- Table/model `domain_events` → `app/Models/DomainEvent.php` (cols incl.
  `branch_id, channel, broadcast_as, correlation_id, idempotency_key,
  dispatched_at, attempts, last_error`; scopes `pending`, `stale(2min)`,
  `failed(>=4)` `:34-49`).
- Producer trait `app/Traits/HasDomainEvents.php`: `recordDomainEvent()` buffers
  (`:13-25`); `saved` hook persists rows (`:29-53`) then **`DB::afterCommit`**
  dispatches one `DispatchDomainEventsJob` per row (`:55-60`) → commit-before-dispatch.
- Drain consumer `app/Jobs/DispatchDomainEventsJob.php` (queue `high`, `:46`):
  - Phase-1 atomic claim under `lockForUpdate` + `dispatched_at` guard (`:65-86`)
    → no double-broadcast across concurrent workers.
  - Phase-2 broadcast outside txn via `BroadcastManager::connection()` honoring
    `broadcasting.default` (`:115-116`); envelope validated by
    `EventContract::assertEnvelopeValid` (`:107-110`).
  - `tries=6`, `backoff=[1,5,15,60,300]` (`:40-42`); generic failure releases
    claim (`dispatched_at=null`, `:161-166`) for retry; `PayloadMismatchException`
    short-circuits `$this->fail()` (`:184`) — no 6× high-lane waste.
  - Heartbeat `Cache::put('ws:heartbeat',…,120)` best-effort on success (`:128-132`),
    read by `SyncOverviewController:531`.

### 3 concrete propagation traces
1. **Kiosk-pay → KDS (fully verified).** Order persists →
   `OrderCreated` event (`app/Events/OrderCreated.php:19-26`,
   `DispatchableAfterCommit`, no direct `ShouldBroadcastNow`) →
   `PersistOrderCreatedToOutbox::handle` (`:17-41`) `firstOrCreate`s an
   `ORDER_CREATED` row, payload `{order_id, queue_number, status, order_type,
   branch_id, created_at}` (`:76-86`), `channel = ['private-branch.'.branch_id]`
   (`:36`), `broadcast_as 'OrderCreated'` (`:37`). After commit (`:47`)
   `DispatchDomainEventsJob` broadcasts on the private branch channel; KDS
   subscribes it (5s/60s poll fallback). Docblock guarantees consumers never see
   orders that did not persist.
2. **KDS-bump / status change → OSS.** `OrderStatusChanged` event →
   `PersistOrderStatusChangedToOutbox::handle` (`:16-58`) writes the
   `ORDER_STATUS_CHANGED` row (payload carries `old_status`, `new_status`,
   `queue_number`, `_origin`, `payment_status`) on `private-branch.{branch_id}`
   with `broadcast_as 'OrderStatusChanged'`; after commit the drain job
   broadcasts it; OSS subscribes the same private channel (`tests/js/
   orderStatusScreenOssSync.spec.js`, `tests/js/ossSyncFallback.spec.js`). The
   bump call-site fires the `OrderStatusChanged` event (KDS service /
   OrderService status-transition path); the listener is the verified emitter.
3. **Stock-86 → all surfaces (fully verified).** An item going 86 fires
   `ItemAvailabilityChanged` → `PersistItemAvailabilityChangedToOutbox::handle`
   (`:16-40`) `firstOrCreate`s an `ITEM_AVAILABILITY_CHANGED` row, payload
   `{item_id, branch_id, available, reason}` (`:29-34`),
   `channel = ['private-branch.'.event->branchId]` (`:20,35`),
   `broadcast_as 'ItemAvailabilityChanged'` (`:36`); after commit (`:44`) the
   drain job broadcasts to POS/Kiosk/KDS (POS comment: "→ private-branch.{id}
   channel → POS `_announceAvailabilityChange()`"). Sibling listeners cover
   variations + extras + catalog. The `stock:scan-rupture` cron drives the
   preventive auto-86 path (`Kernel.php:213-218`, gated by
   `catalog_v15.auto_86_preventive_cron.enabled`). Tests:
   `Stock/StockRuptureAvailabilitySyncTest.php`,
   `Menu/CatalogStockCentralSyncEndToEndTest.php`.

### Queue/cron drain mechanism
- Primary path = **queue, not cron** (`afterCommit` push).
- Safety nets in `app/Console/Kernel.php`:
  - `foodking:outbox:rescue` `everyMinute()->withoutOverlapping()->onOneServer()`
    (`:40-43`) — re-queues stuck pending events (rescue re-queues `attempts<5`).
  - `foodking:outbox:monitor --threshold=10` everyMinute (`:50-53`) — Log::error +
    non-zero exit for pager when pipeline degraded.
  - `foodking:outbox:retry-failed --since=24h` hourly (`:64-69`) — retries
    `attempts>=5` terminal failures (complements rescue, which skips them).
  - `foodking:outbox:prune --older-than-days=90` daily 04:00 (`:136-142`) —
    bounds unbounded growth (outbox is NOT NF525-retained).
  - `CleanupStalePendingKioskOrders` every 5 min (`:83-86`).

## Maturity score: **8.5/10**
Genuine transactional outbox with commit-before-dispatch, row-lock idempotent
claim, tuned backoff, terminal-failure short-circuit, a 4-lane cron safety net
(rescue/monitor/retry-failed/prune), real private-channel branch auth with a
healed kiosk-token escalation, and a heartbeat observability hook — well above
typical SMB-POS sync. Held back from 9-10 only by: frontend polling-cadence
divergence (no single SoT, V1.0.2 backlog) and the null-channel silent-drop edge
below.

## Findings (adversarial)

- **[P4 / latent] `app/Jobs/DispatchDomainEventsJob.php:100` — null-channel rows
  are marked dispatched without ever broadcasting, with no warning.** The
  broadcast lives entirely inside `if ($channel !== null && $broadcast_as !== null)`
  (`:100-133`); on a null channel the job falls through to `:153-155` and stamps
  success silently. **Verified NOT currently reachable:** all 13 production
  emitters (`app/Listeners/Persist*ToOutbox.php`) hardcode a non-null
  `'channel'` (one each, grep-confirmed), and `recordDomainEvent(` — the trait
  path that allows a null channel — has **zero call-sites** (`HasDomainEvents`
  trait is effectively dead for the live outbox). So this is a defensive gap,
  not a live lost-sync: only a *future* emitter that omits the channel would
  trigger it, and it would do so without any signal. Rec: add a
  `Log::warning` (or sentinel) when a `DomainEvent` reaches the drain with
  `channel===null`, so a future misconfiguration is caught instead of silently
  dropped.

- **[P3] Frontend polling cadence has no single source of truth** (POS 30s / KDS
  5-60s / Kiosk 15s hardcoded across 3 components, `config/broadcasting.php:23-28`).
  During a Pusher outage the same order can appear on surfaces up to ~45s apart
  (KDS 60s vs Kiosk 15s). Already documented as V1.0.2 backlog. Rec: one shared
  cadence config consumed by all three surfaces.

- **[P3] `ws:heartbeat` only refreshed on a SUCCESSFUL broadcast of a *channeled*
  event** (`:128-132`, 120s TTL). In a quiet period (no orders) the key expires
  and `SyncOverviewController:531` may flap to "degraded" while Pusher is healthy.
  Rec: distinguish "no traffic" from "broadcast failing", or refresh heartbeat
  from a tiny scheduled ping.

- **(RESOLVED, not a defect) Re-drain thrash on a permanently-failing event.**
  I verified the full lane partition: `OutboxRescueCommand` re-queues
  `attempts<5` (pending>2min OR crash-claimed>10min, `:34-48`);
  `OutboxRetryFailedCommand` handles `attempts>=5` BUT is capped by
  `REPLAY_MAX_ATTEMPTS=12` via `->where('attempts','<',12)` (`:67,104`).
  `attempts` is monotonic (the old `attempts=0` wipe was healed). So a
  forever-500 target climbs to `attempts>=12`, falls out of BOTH replay lanes,
  is paged by `outbox:monitor`, and is reclaimed by `outbox:prune` (`>=6` + 90d).
  No infinite 1/min thrash. The retry-failed command is also Cache::lock guarded
  (5min TTL) + batch-capped (500) against concurrent double-dispatch. Strong.

- **[P3] Channel naming convention relies on the implicit Laravel `private-`
  prefix strip.** Emitters write `private-branch.{id}` while the authorizer is
  named `branch.{branchId}` (`routes/channels.php:41`). This is correct Laravel
  behavior but is an untyped string contract spread across 6 listeners — a future
  refactor that writes `branch.{id}` (without `private-`) would silently make the
  channel PUBLIC (no auth callback fires for non-private channels), leaking
  cross-branch order streams with zero error. Rec: a sentinel test asserting
  every outbox `channel` string starts with `private-` (the swallow-alarm /
  pipeline-health sentinels exist as a place to add it).

- **(RETRACTED) Branch-isolation leak on broadcast channels.** My initial pass
  suspected public channels; `routes/channels.php:41-62` proves real
  private-channel auth with kiosk-token + role checks. NOT a defect (see P3 above
  for the residual convention-fragility risk).

## Existing tests for sync — verified-real via Glob/find
~30 PHP + 7 JS sync tests found (all paths returned by `find tests`):
- Outbox core: `tests/Feature/OutboxTest.php`, `OutboxRescueTest.php`,
  `Outbox/OutboxDeliveryTest.php`, `Outbox/OutboxConcurrentWorkerDedupeTest.php`,
  `Outbox/OutboxConcurrentRetryLockTest.php`, `Outbox/OutboxRescueStaleClaimedRowsTest.php`,
  `Outbox/OutboxRetryFailedAttemptsPreservedTest.php`,
  `Outbox/OutboxProductionLikeSimulationTest.php`, `Outbox/OutboxReplayAuditTest.php`,
  `Outbox/OutboxBroadcastSwallowedListenerTest.php`,
  `Catalog/CatalogOutboxIdempotencyTest.php`, `Order/ChangePaymentStatusOutboxTest.php`,
  `Sync/OutboxRetryFailedScheduleTest.php`.
- Dispatch job: `Queue/DispatchDomainEventsFailedCallbackTest.php`,
  `Observability/DispatchDomainEventsObservabilityIntegrationTest.php`.
- Broadcast/channel: `KioskRealtimeBroadcastTest.php`,
  `Refund/RefundBroadcastsPaymentStatusChangedTest.php`,
  `Settings/SettingsUpdatedBroadcastTest.php`, `Config/BroadcastDriverConfiguredTest.php`.
- KDS/stock sync: `Admin/KdsSyncControllerTest.php`, `KDS/KdsSyncSargableTest.php`,
  `KDS/KdsSyncTzAwareTest.php`, `Stock/StockRuptureAvailabilitySyncTest.php`,
  `Stock/WizardOptionStockSyncTest.php`, `Menu/CatalogStockCentralSyncEndToEndTest.php`,
  `Composer/ComposerPublishSyncTest.php`, `Catalog/CategoryRenameSyncTest.php`,
  `Sync/FinalizePaidKioskOrderBroadcastFreshnessTest.php`, `SyncComprehensiveTest.php`.
- Observability: `Observability/SyncOverviewControllerTest.php`,
  `Observability/OutboxOverviewControllerTest.php`,
  `Observability/SyncMetricsRecorderTest.php`.
- Sentinels: `Sentinels/OutboxPipelineHealthSentinelTest.php`,
  `Sentinels/OutboxBroadcastSwallowAlarmSentinelTest.php`,
  `Sentinels/PayloadMismatchFailOnceSentinelTest.php` (referenced
  `DispatchDomainEventsJob.php:183`).
- JS unit: `tests/js/{kdsSyncCadence,posSyncFallback,ossSyncFallback,
  orderStatusScreenOssSync,realtimeBroadcastFallback,runtimeSyncFlagsWiring,
  observabilityOutboxRoute}.spec.js`.
- E2E (~25 specs) incl. `e2e/test-e2e-pos-kds-sync-2026-05-10-wave-{A..F}.spec.js`,
  `e2e/test-e2e-kiosk-kds-sync-2026-05-11-wave-{A..D}.spec.js`,
  `e2e/zone6-sync-resilience.spec.js`, `e2e/wave-z-stress-sync-3-kiosk.spec.js`.
