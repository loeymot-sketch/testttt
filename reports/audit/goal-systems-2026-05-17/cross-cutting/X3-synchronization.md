# X3 — SYNCHRONIZATION (cross-cutting)

Auditor: SYNC CROSS-CUTTING (Goal Systems 2026-05-17)
Scope: ACROSS-SYSTEMS sync impact — race conditions, lost events, payload drift,
       retry/replay logic, channel security, listener invariants, dead-letter
       handling. L2 audits the mechanism; X3 audits the consequences.
Surfaces touched: POS, KDS, OSS, Kiosk, Admin, Mobile (mostly standalone).
Files inventoried: 10 Persist*ToOutbox listeners, 1 dispatch job, 3 console
       commands (rescue/retry-failed/monitor), 3 JS sync services
       (Kds/Pos/Oss), 1 event contract (PHP+JS), 1 broadcasting channels
       file, 2 webhook handlers (Stripe, Senangpay).

---

## §0 — Stale findings (historically flagged, currently patched)

The codebase already absorbed and patched a long list of sync issues from
prior audits. Each note below is the patch evidence — included so reviewers
do not re-litigate.

| Tag | What was patched | Evidence anchor |
|---|---|---|
| iter13 SYNC-EVENTS | Listener double-insert race | `domain_events.idempotency_key` UNIQUE + `firstOrCreate` (migration `2026_05_09_180000`, listener `PersistOrderCreatedToOutbox.php:22-48`) |
| iter14 SPECIALIST-2 | Discriminator strategy for one-shot vs transition events | comments in every `Persist*ToOutbox.php` |
| NEW-01 Phase 1 atomic claim | Concurrent worker double broadcast | `DispatchDomainEventsJob.php:60-86` row lock + dispatched_at guard |
| NEW-01 Phase 1bis | Dedupe TTL + sessionStorage persistence | `eventContract.js:99-313` |
| NEW-02 G10 | Reconnect-storm thundering herd | `KdsSyncService.js:247-265` (0–500ms jitter on `reconnect_storm`) |
| NEW-03 G2 / Audit T G2 | Retry curve unreachable trailing entry | `DispatchDomainEventsJob.php:40-42` (tries=6, backoff [1,5,15,60,300]) |
| Audit T G3 | last_error label drift between log + DB | `DispatchDomainEventsJob.php:165-186` |
| F-002 round-3 | Listener-order doctrine for OrderCreated / OrderStatusChanged | `EventServiceProvider.php:121-148` |
| F-VERIFY-09 / P13 | OrderPaymentStatusChanged outbox parity | new listener + EventContract `REQUIRED_PAYLOAD_KEYS` |
| Sprint 3B P1-SYNC-02 | Failed events (>=5 attempts) never retried | new schedule `outbox:retry-failed --since=24h` hourly (`Kernel.php:63`) |
| Sprint 3B P1-SYNC-03 + Sprint 5C Z8-P1-01 | Listener replay enqueued duplicate job | every `Persist*ToOutbox` now early-returns when `wasRecentlyCreated === false` |
| test-e2e E-001 round-3 cluster-8 | Pusher dispatch error bubbling as HTTP 500 | `DB::afterCommit` block wrapped in try/catch in every Persist*ToOutbox |
| ultra-goal A3 heal | Branch fan-out lost N-1 broadcasts on status=1 vs 5 | `PersistCatalogChangedToOutbox.php:39` + sibling listeners |
| AUDIT-F-015 | Queue worker down, no alert | `MonitorOutboxStaleness.php` (Log::error + non-zero exit) |
| P0-FIX-1 NF525 | Webhook replay storms | `webhook_events` UNIQUE(provider, webhook_id) |
| Audit G1 / G4 | Contract violation prefix lost on terminal failure | preserved in `failed()` callback |
| F-12 | Echo subscription auth failure cascade | `WebSocketService.handleSubscriptionError` sliding window |
| KI-001 (gate C9) | Event observable before transaction commits | `DispatchableAfterCommit` trait |

Stale findings count: 17 distinct patches verifiable in code. The pipeline is
defended in depth; the live findings below are the residual holes.

---

## §1 — Sync robustness score: **62 / 100**

| Dimension | Score | Anchor |
|---|---|---|
| Idempotency at write (outbox) | 85 | UNIQUE `idempotency_key` + `firstOrCreate` everywhere |
| Idempotency at dispatch (broadcast) | 75 | Atomic claim under row lock; **orphan-dispatched window** drops it (§3-L1) |
| Retry / dead-letter (outbox) | 70 | rescue (attempts<5) + retry-failed (attempts>=5) + monitor; **payload contract violation never re-tries** (§3-L2) |
| Retry / dead-letter (webhook) | 35 | `WebhookEvent` has attempts column + status enum + idx_pending_received index, but **no console command queries failed**; only provider-side retries exist (§3-L3) |
| Listener-order invariant | 50 | Documented for `OrderCreated/OrderStatusChanged`; **violated for `ItemAvailabilityChanged`** (§2-R3) |
| Payload contract enforcement | 75 | `EventContract::assertEnvelopeValid` runs before broadcast; **3 entries in BROADCAST_MAP have no producer** (§4-D1) |
| Cross-branch fan-out integrity | 75 | Heal applied for status=1/5 drift; still no monitoring for late-added branches that miss in-flight events |
| Channel security | 70 | Branch isolation enforced; **kiosk tokens see ALL orders in the branch, not just their own machine** (§2-R5) |
| Replay safety (UI dedupe) | 80 | `correlation_id` dedupe with TTL + persisted across reloads; per-tab only (accepted) |
| Time skew tolerance | 65 | `lastSyncAt` reads server clock; client `Date.now()` only drives jitter; not load-bearing |
| Observability (lag, latency) | 65 | `SyncMetricsRecorder::recordEventDispatched` wired; **no event-received metric on the client to detect Pusher silent-drop** |

Aggregate: **62/100** — production-class pipeline with four concrete
across-systems holes (§§2-3) and two known contract drifts (§4).

---

## §2 — Race conditions identified

### R1 — Orphan-dispatched window (worker crash between claim and broadcast)

- **Scenario**: `DispatchDomainEventsJob::handle` Phase 1 (rows 65-86) commits
  `dispatched_at = now()` under row lock. Phase 2 (rows 96-117) builds the
  envelope and calls `BroadcastManager::connection()->broadcast()`. If the
  PHP worker is SIGKILL'd, OOM-killed, or `php-fpm` segfaults BETWEEN the
  Phase 1 commit and the Phase 2 broadcast, the row is permanently marked
  `dispatched_at != null` but the broadcast never fires.
- **Reproducer (read-only thought experiment)**: in a debugger, suspend the
  process at `DispatchDomainEventsJob.php:96` (after `try {`), commit the
  transaction, then SIGKILL.
- **Impact**: KDS / Kiosk / POS never observe the order. The compensating
  cron `OutboxRescueCommand` filters on `whereNull('dispatched_at')`
  (`DomainEvent.php:35-37, 39-43`) — the orphan row is invisible.
  `MonitorOutboxStaleness.php:46-47` uses the same `whereNull`.
- **Likelihood**: low in steady state, non-zero during OOM, SIGTERM during
  rollout, or `queue:restart` with unfortunate timing. Production-grade
  systems usually accept this trade-off; it's worth documenting because no
  query exists to surface it post-mortem.
- **Files**: `app/Jobs/DispatchDomainEventsJob.php:80-86, 116`,
  `app/Models/DomainEvent.php:39-43`, `app/Console/Commands/MonitorOutboxStaleness.php:44-47`.

### R2 — Inline `sync` queue propagates listener throw to HTTP

- **Scenario**: `config/queue.php:16` defaults to `QUEUE_CONNECTION=sync`.
  Under sync, `DispatchDomainEventsJob` runs inline inside the HTTP request.
  Every Persist*ToOutbox listener wraps the dispatch in try/catch (defense
  applied in cluster-8 / round-3) but listener handles outside the try
  (e.g. `firstOrCreate` failure on transient DB hiccup, or the
  `PayloadMismatchException` thrown by `EventContract::assertEnvelopeValid`
  at job time) bubble up to the controller.
- **Reproducer**: ship a producer that violates a `REQUIRED_PAYLOAD_KEYS`
  entry (e.g. omit `fiscal_sequence_no` on `OrderPaymentConfirmed`). With
  `QUEUE_CONNECTION=sync` (dev/CI), the broadcaster throws
  `PayloadMismatchException`, which propagates out of the job to the HTTP
  layer — returns 500 even though the outbox row is persisted.
- **Impact**: surface user sees 500; outbox row recoverable via cron, but
  the kiosk/POS UX is broken in the moment.
- **Files**: `app/Jobs/DispatchDomainEventsJob.php:110, 140-162`,
  `config/queue.php:16`.

### R3 — Listener order invariant violated for `ItemAvailabilityChanged`

- **Scenario**: `EventServiceProvider.php:169-176` registers
  `BumpMenuSnapshotOnItemAvailabilityChanged` and
  `InvalidateKioskMenuCacheOnItemAvailabilityChanged` BEFORE
  `PersistCatalogChangedToOutbox` and
  `PersistItemAvailabilityChangedToOutbox`. The same file at lines 121-148
  documents the F-002 doctrine: "Persist*ToOutbox MUST run BEFORE
  side-effect listeners".
- **Reproducer**: cache write failure in
  `BumpMenuSnapshotOnItemAvailabilityChanged` (e.g. Redis down) throws,
  Laravel halts the listener chain under sync queue, neither Persist*
  listener writes a row, broadcast never fires.
- **Impact**: kiosk + POS observe a stale "available" state silently.
  Frontend has no fallback signal because the row never reaches
  `domain_events`, and `MonitorOutboxStaleness` won't fire (nothing is
  pending).
- **Files**: `app/Providers/EventServiceProvider.php:169-176`
  (compare 121-148 for `OrderCreated` correct ordering).

### R4 — Concurrent same-key listener replays absorb second wave (by design, but worth noting)

- **Scenario**: under retry of the listener (e.g. a queue worker repeatedly
  processing the same event), `firstOrCreate` collapses to the existing
  row. **Phase 1 of the job already absorbs this**, BUT
  `wasRecentlyCreated` early-return (P1-SYNC-03) skips the second
  `afterCommit` dispatch registration too. Net result: if the original
  dispatch fired but never reached Pusher (silent network drop, no
  exception), the second listener fire silently does nothing.
- **Reproducer**: shape one — listener fires twice in the same correlation
  window (which only happens via direct event re-dispatch — the
  request-scoped correlation_id ensures across-request flips get a new
  row).
- **Impact**: low — the row is dispatched-or-rescued; replay would have
  been a duplicate broadcast anyway. Listed for completeness.
- **Files**: `PersistOrderStatusChangedToOutbox.php:64-66` and 10 siblings.

### R5 — Kiosk channel scope is per-branch, not per-machine

- **Scenario**: `routes/channels.php:25-39` authorizes a kiosk token to
  subscribe to `branch.{branchId}` if the machine's `branch_id` matches.
  ALL events on that branch are visible to that kiosk — including admin
  POS orders, OSS orders, KDS table reassignments, fiscal sequence
  numbers, tokens, payment_status, total amounts.
- **Reproducer**: open a kiosk in a branch, open browser devtools, watch
  the Echo channel — every order from every surface in that branch streams
  in with PII (queue_number, payment_method, fiscal_sequence_no, token).
- **Impact**: PII leak surface if a kiosk is in a publicly accessible area
  (which is the entire use case). Severity depends on what consumers do
  with the data, but the architecture sends it regardless. Not a NF525
  violation (no chain manipulation), but a privacy / least-privilege gap.
- **Files**: `routes/channels.php:25-39`.

---

## §3 — Lost-event scenarios

### L1 — Orphan-dispatched (same as R1)

- **Failure point**: worker crash between Phase 1 commit and Phase 2
  broadcast.
- **Recovery gap**: no cron query covers `dispatched_at != null AND
  broadcast_acknowledged_at IS NULL` because the column does not exist —
  there is no broadcast-receipt feedback. Recovery requires manual
  intervention.

### L2 — Contract violations re-tried infinitely with no quarantine path

- **Failure point**: `EventContract::assertEnvelopeValid` throws
  `PayloadMismatchException` in `DispatchDomainEventsJob.php:110`. The
  `catch` at 140-161 releases the claim and **rethrows**; the row stays
  pending. After `tries=6`, `failed()` callback persists `last_error =
  contract_violation:...` (line 183-186).
- **Recovery gap**: `OutboxRescueCommand` only re-queues rows where
  `attempts < 5` (line 19). `OutboxRetryFailedCommand` re-queues rows with
  `attempts >= 5` — and **resets attempts to 0** (line 27-31) — which means
  a contract-violating row will be re-tried hourly forever with the same
  payload, racking up queue cost and pager noise. No quarantine flag, no
  manual dead-letter table, no operator UX to acknowledge "this row will
  never broadcast — drop it".
- **Files**: `app/Console/Commands/OutboxRescueCommand.php:18-20`,
  `app/Console/Commands/OutboxRetryFailedCommand.php:21-31`,
  `app/Jobs/DispatchDomainEventsJob.php:140-162`.

### L3 — Webhook events failed status is a true dead-letter

- **Failure point**: Stripe/Senangpay webhook handler's
  `DB::transaction(...)` throws, `markFailed()` increments attempts and
  sets status='failed' (`WebhookEvent.php:108-115`,
  `Stripe.php:298-312`, `Senangpay.php:169-183`).
- **Recovery gap**: zero. The migration `2026_05_09_120000` builds
  `idx_pending_received (status, received_at)` and the `attempts` counter,
  visible intent of a retry poller. `grep -RnE "WebhookEvent|webhook_events"
  app/Console` returns no command. Stripe's external retries cover ~3
  days; SenangPay's retries are documented in their docs but bounded.
  Once external retries are exhausted the row sits status='failed' until
  manually re-driven. Severity: payment capture can be lost if the local
  capture-notification race wins.
- **Files**: `app/Models/WebhookEvent.php:108-115`,
  `database/migrations/2026_05_09_120000_create_webhook_events_table.php:66-90`,
  `app/Http/PaymentGateways/Gateways/Stripe.php:234-312`,
  `app/Http/PaymentGateways/Gateways/Senangpay.php:125-186`.

### L4 — Pusher silent drop (no client-side ACK)

- **Failure point**: broadcast reaches Pusher (server `recordEventDispatched`
  fires with latency), but Pusher fails to deliver to a subscriber (network,
  client tab GC, browser throttling).
- **Recovery gap**: KDS has `KdsSyncService` polling fallback. OSS has
  `OssSyncService` polling fallback. POS has `PosSyncService` polling
  fallback (gated by `FK_CATALOG_POS_FALLBACK_POLLING_ENABLED=false` by
  default). **Kiosk has none**. A kiosk that briefly loses Pusher misses
  the `ItemAvailabilityChanged` 86 events; the cached menu becomes stale
  until the cache TTL (60s in
  `InvalidateKioskMenuCacheOnItemAvailabilityChanged`).
- **Files**: `resources/js/services/KdsSyncService.js:79-218`,
  `resources/js/services/OssSyncService.js:49-80`,
  `resources/js/services/PosSyncService.js:75-112`. No kiosk equivalent.

---

## §4 — Payload contract drift risks

### D1 — Three entries in `BROADCAST_MAP` have no producer

- `OrderItemAdded` (`EventContract.php:38`, `eventContract.js:5`,
  `EventType.php:10`): no Persist*ToOutbox listener, no `OrderItemAdded`
  event class, no controller emits it.
- `OrderCancelled` (`EventContract.php:39`, `eventContract.js:6`): the
  actual event class is **`OrderCanceled`** (single-l) and is only
  registered to `ReleaseStock` + `ReleaseAvailability` listeners — no
  Persist*ToOutbox, no broadcast. Frontend code that registers a handler
  for `OrderCancelled` waits forever.
- `StockLow` (`EventContract.php:44`, `EventType.php:22`): no
  `PersistStockLowToOutbox`; `NotifyStockLowOnStockLevelChanged` is
  side-effect-only.
- **Risk**: a frontend consumer subscribing to one of these on the
  `branch.{id}` channel silently never receives data. Confusing for a
  maintainer who reads the map expecting a producer.

### D2 — Idempotency key composition is not uniform

| Event | Key composition | Note |
|---|---|---|
| ORDER_CREATED | `sha1(type | aggregate_id)` | one-shot; second fire absorbed silently (good) |
| ORDER_PAYMENT_CONFIRMED | `sha1(type | aggregate_id)` | one-shot (good) |
| ORDER_STATUS_CHANGED | `sha1(type | id | old | new | correlation_id)` | transition; new request → new row (good) |
| ORDER_PAYMENT_STATUS_CHANGED | same shape, includes correlation_id | parity (good) |
| CATALOG_CHANGED | `sha1(type | entity_type | entity_id | branch_id | change_type | correlation_id)` | per-branch fan-out; admin save → many rows |
| COUPON_CHANGED | same shape as CATALOG_CHANGED | parity (good) |
| MENU_ITEM_AVAILABILITY_CHANGED | `sha1(type | item_id | branch_or_global | is_available | type | correlation_id)` | global emission + per-branch emission coexist (good) |
| MENU_EXTRA / MENU_VARIATION | `sha1(type | id | branch_id | is_available | correlation_id)` | no `change_type` discriminator, but no fan-out, so OK |
| ORDER_TABLE_CHANGED | `sha1(type | id | prev | new | correlation_id)` | (good) |

Risk: a bulk-import controller that emits the same `CatalogChanged(entity,
change_type)` twice in the same request (same correlation_id) collapses to
one row — by design, but worth flagging. If a future producer issues two
legitimately distinct CATALOG_CHANGED for the same (entity, branch,
change_type) within one request (e.g. an idempotent "republish" after a
short-circuit return), the second will be silently absorbed.

### D3 — Envelope version is V1 only; no producer/consumer negotiation

- `eventContract.js:37` rejects `version !== 1`. `EventContract::ENVELOPE_VERSION = 1`.
- If a future server publishes V2, every JS consumer drops the event and
  logs `[eventContract] Invalid envelope: ...`. There is no compatibility
  shim, no `version >= 1` acceptance with feature-flag gated extension.
  A V2 migration will require simultaneous deploy of server + every
  installed kiosk/POS browser at the branch, with no rolling-deploy
  tolerance.

### D4 — Cross-tab dedupe is per-tab only (already documented, accepted)

- `eventContract.js:88-94` explicit accepted limitation.
- Risk: same correlation_id reaches two POS tabs in the same browser →
  same toast fires twice. Low impact (POS users rarely run two tabs).

### D5 — Broadcasted payload includes fiscal/PII fields

- `PersistOrderCreatedToOutbox.php:31-43` payload includes `total`,
  `payment_method`, `payment_status`.
- `PersistOrderPaidAtCounterToOutbox.php:32-39` includes
  `fiscal_sequence_no`.
- `PersistOrderStatusChangedToOutbox.php:41-52` includes `token`.
- These are broadcast to every subscriber on `private-branch.{id}` —
  including kiosk machine tokens (cf. R5). Drift risk: when a new field is
  added to the payload (e.g. customer email, phone), it will inherit the
  same audience. No contract review gates new keys.

---

## §5 — Top 5 hardening recommendations

1. **Quarantine path for contract violations** (closes L2). Add a
   `quarantined_at` column to `domain_events`. When
   `DispatchDomainEventsJob::failed()` sees
   `PayloadMismatchException`, set `quarantined_at = now()` and let
   `OutboxRetryFailedCommand` skip rows with `quarantined_at IS NOT NULL`.
   Operator UX: a Horizon page lists quarantined rows. Cost: 1 migration
   + 1 column filter + 1 admin view. Removes the "infinite hourly retry
   of a known-broken row" noise.

2. **Webhook dead-letter retry cron** (closes L3). Add
   `foodking:webhook:retry-failed` that selects
   `webhook_events WHERE status='failed' AND attempts < 10 AND received_at
   >= NOW() - INTERVAL 24h` and re-drives via a queueable
   `ProcessWebhookEventJob`. Mirror the
   `OutboxRescueCommand`/`OutboxRetryFailedCommand` doctrine. Closes the
   gap that the migration's `idx_pending_received` already anticipates.

3. **Fix listener-order for `ItemAvailabilityChanged`** (closes R3). Reorder
   `EventServiceProvider.php:169-176` so `PersistCatalogChangedToOutbox`
   and `PersistItemAvailabilityChangedToOutbox` run BEFORE
   `BumpMenuSnapshot...` and `InvalidateKioskMenuCache...`. Add a
   sentinel test that asserts the order matches the documented F-002
   doctrine. The mirror change should apply to the
   `ItemExtra/ItemVariationAvailabilityChanged` chains too — they emit
   `PersistCatalogChangedToOutbox` LAST when it must run FIRST to be the
   outbox SSOT.

4. **Channel scoping for kiosk: per-machine instead of per-branch**
   (closes R5). Introduce `kiosk-machine.{machineId}` channel for events
   that a kiosk legitimately needs (order created by that machine,
   menu availability for that branch's items). Keep `branch.{id}` for
   admin/POS/KDS/OSS. Update the `Persist*ToOutbox` listeners to emit on
   both channels when the kiosk is the originator. Cost: medium
   (channels.php + listener fan-out + frontend channel binding), but
   addresses the PII broadcast surface.

5. **Sync prune-and-prune monitoring + orphan detection** (closes R1/L1).
   Add a `broadcast_acknowledged_at` column populated by
   `DispatchDomainEventsJob` after the broadcaster `broadcast()` call
   succeeds. Extend `MonitorOutboxStaleness` to alert on
   `dispatched_at IS NOT NULL AND broadcast_acknowledged_at IS NULL AND
   dispatched_at < NOW() - INTERVAL 2 MINUTE` — surfaces the
   worker-killed-mid-broadcast orphan class. Without it, the only signal
   is a downstream complaint ("KDS missed an order"); with it, the
   operator gets a structured alert.

---

## Appendix — files inventoried (anchors)

- Listeners:
  `app/Listeners/Persist{OrderCreated,OrderStatusChanged,OrderPaidAtCounter,OrderPaymentStatusChanged,OrderTableChanged,ItemAvailabilityChanged,ItemExtraAvailabilityChanged,ItemVariationAvailabilityChanged,CatalogChanged,CouponChanged}ToOutbox.php`
- Job: `app/Jobs/DispatchDomainEventsJob.php`
- Contract: `app/Domain/Events/EventContract.php`, `app/Enums/EventType.php`,
  `app/Exceptions/PayloadMismatchException.php`,
  `resources/js/services/eventContract.js`
- Models: `app/Models/DomainEvent.php`, `app/Models/WebhookEvent.php`
- Migrations: `database/migrations/2026_04_15_200000_create_domain_events_table.php`,
  `database/migrations/2026_05_09_180000_add_idempotency_key_to_domain_events.php`,
  `database/migrations/2026_05_09_120000_create_webhook_events_table.php`
- Console: `app/Console/Kernel.php`,
  `app/Console/Commands/OutboxRescueCommand.php`,
  `app/Console/Commands/OutboxRetryFailedCommand.php`,
  `app/Console/Commands/MonitorOutboxStaleness.php`
- JS sync services: `resources/js/services/{Kds,Pos,Oss}SyncService.js`,
  `resources/js/services/WebSocketService.js`
- Provider: `app/Providers/EventServiceProvider.php`
- Channels: `routes/channels.php`
- Webhook handlers: `app/Http/PaymentGateways/Gateways/{Stripe,Senangpay}.php`
- Config: `config/{broadcasting,queue,horizon,catalog_v15}.php`
- Trait: `app/Events/Concerns/DispatchableAfterCommit.php`
