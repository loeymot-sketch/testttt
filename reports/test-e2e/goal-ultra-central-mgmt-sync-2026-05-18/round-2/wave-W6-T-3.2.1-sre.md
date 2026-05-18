# Wave W6 — T-3.2.1 OrderStateMachine fan-out coherence — SRE audit (Round 2)

**Specialist**: SRE / SYNC
**Date**: 2026-05-18
**Mode**: READ-ONLY
**Anchors**:
- `app/Domain/Order/OrderStateMachine.php` (frozen — read-only)
- `app/Events/OrderStatusChanged.php`
- `app/Listeners/{PersistOrderStatusChangedToOutbox, DispatchKdsTicket}.php`
- `app/Jobs/DispatchDomainEventsJob.php`
- `app/Services/KdsSyncService.php`
- `app/Services/Observability/{SyncMetricsRecorder, SloMetricCollector}.php`
- `app/Jobs/Observability/SloEvaluatorJob.php`
- `app/Http/Controllers/Admin/{KdsSyncController, Observability/SyncOverviewController}.php`
- `app/Console/Kernel.php`
- `config/horizon.php`
- `resources/js/components/admin/kitchenDisplaySystem/{KitchenDisplaySystemComponent, KdsV2Grid}.vue`
- `resources/js/helpers/kdsDisplay.js`

> SRE mindset: V1 ships the 5s p95 cross-surface promise. Walk the state-transition fan-out end-to-end. Per-state? Per-station? Stuck-order pager? Reconciliation runbook? Which page-able alert is missing.

---

## Cross-Round-1 disambiguation

Round 1 SRE-002 framed the gap on `outbox.dispatch_latency_ms` (Outbox internal lag: `occurred_at → broadcast` measured in `DispatchDomainEventsJob.php:124-130`). That metric is the WORKER-SIDE pipeline view. Round 2 covers an orthogonal, V1-promise-critical surface: the END-TO-END state-transition latency `client A apply() → client B render()` that the 5s promise actually covers. The two metrics ALMOST never break together — Outbox can be healthy (200 ms p95) while the cross-surface user-observable window is 8 s because the receiving KDS browser is on the 30 s polling fallback (cf. Round 1 SRE-007). Treat as additive.

---

## Findings (strong-reasoning YAML)

```yaml
- id: SRE-013
  severity: P0
  title: "V1 5s p95 cross-surface state-transition promise has NO recorder, NO SLO target, NO evaluator — the headline V1 SLI is unobservable"
  category: missing_metric
  evidence:
    - "grep -rn 'order_state_changed\\|state_transition_latency\\|order_transition_latency' app/  → 0 hits"
    - "SyncMetricsRecorder.php:29-31  METRIC_OUTBOX_DISPATCH_LATENCY_MS / METRIC_WS_AUTH_FAILURE / METRIC_KDS_SYNC_FALLBACK_INTERVAL_MS — no state-transition metric"
    - "SloMetricCollector.php:30-36  SLO_TARGETS = {uptime, tti_p95_ms, order_completion_rate, ws_reconnect_p95_ms, payment_success_rate} — no order_state_changed_latency"
    - "SloEvaluatorJob.php iterates SLO_TARGETS — never evaluates the V1 5s promise"
  reasoning: |
    The mission anchor lists "V1 promise = 5s p95 cross-surface" as the
    headline SRE SLI. Walked the codebase: that metric does not exist.
    There is no producer (no MetricsBatcher emit on Echo/Pusher payload
    receive timestamping `now - envelope.occurred_at`), no schema slot in
    `sync_metrics` for it, no SLO target row, no evaluator. The closest
    proxy is `outbox.dispatch_latency_ms`, which only measures broadcaster
    pickup — it CANNOT detect WS-receive-side stalls (POS→Pusher 200ms
    but Pusher→KDS browser delivery 12s, or KDS browser tab throttled in
    background = real user-facing miss). Operator/Owner reads the dashboard,
    sees green outbox lane, customer waits past ETA, kitchen prepares wrong
    ticket. The whole promise of the V1 sync surface is therefore unverifiable
    in production.
  cost_of_delay: |
    Worst case: V1 ships, owner attests 5s p95 to the franchise customer,
    real-world median is 7-12s because of WS browser-side throttling on
    tabbed KDS displays. Detection only by direct customer complaint or
    kitchen FOH friction. No data exists to defend the V1 SLA in a dispute.
  remediation_hint: |
    1. Add `METRIC_ORDER_STATE_TRANSITION_LATENCY_MS` to SyncMetricsRecorder.
    2. Frontend tap on Echo Listener payload receive (POS / KDS / OSS /
       Kiosk + admin observability tabs all subscribe to `OrderStatusChanged`):
       emit `client_metrics.order_state_transition_latency_ms` =
       `Date.now() - envelope.occurred_at_ms` (server already exposes
       occurred_at; ensure envelope ships a ms-precision stamp).
    3. SLO_TARGETS += `order_state_transition_p95_ms => target:5000, warn:5000, breach:10000`
    4. SloMetricCollector::collectOrderStateTransitionP95(branch, 24h) reads
       from sync_metrics whose metric_type = the new constant.
    5. Surface on OutboxOverviewComponent.vue alongside ws_reconnect_p95_ms.

- id: SRE-014
  severity: P0
  title: "No per-state latency breakdown — `outbox.dispatch_latency_ms` is keyed on event_type='OrderStatusChanged' only, paid→preparing vs preparing→ready vs ready→served are indistinguishable"
  category: missing_metric
  evidence:
    - "DispatchDomainEventsJob.php:124-130  recordEventDispatched($event->event_type, $branch_id, $latency_ms, $correlationId) — no transition tuple label"
    - "PersistOrderStatusChangedToOutbox.php:48-49 payload has old_status/new_status but the LABELS arg on recordEventDispatched (line 60 of SyncMetricsRecorder) only carries event_type"
    - "SyncMetricsRecorder.php:60  $labels = ['event_type' => $eventType] — no from_status/to_status emitted"
  reasoning: |
    Even if the cross-surface receive metric is added (SRE-013), without a
    per-transition label every state move collapses into one bucket. Yet
    the ops profile is heterogeneous:
      - paid → preparing  (kitchen pick-up, ~immediate, kiosk + POS both
        emit; should be sub-second)
      - preparing → ready (kitchen FOH bump, manual; expected 3-15 min
        but the EVENT propagation should still be <5s)
      - ready → served   (OSS bump / passive call-screen; <5s)
    A single bucket masks a per-station degradation. If the kitchen LAN
    WiFi is slow for the cold-prep tablet, preparing→ready propagation
    inflates 5x while paid→preparing stays clean. Aggregate p95 looks OK
    while one station is silently degraded.
  cost_of_delay: |
    Per-station-noisy infrastructure (intermittent kitchen-network packet
    loss, low-RAM tablet) survives every SLO check. Investigation requires
    grep-correlate fiscal.log by correlation_id — labour-intensive,
    reactive only.
  remediation_hint: |
    SyncMetricsRecorder::recordEventDispatched accepts `array $extraLabels`.
    Listener writes: ['event_type' => '...', 'from_status' => $old, 'to_status' => $new].
    SyncOverviewController p95/p99 query groups by labels JSON.
    Per-transition dashboard panel: same shape as the current latency
    block but pivoted by (from,to).

- id: SRE-015
  severity: P1
  title: "OrderStatusChanged Outbox row lands on `high` queue but shares ALL supervisor capacity with bulk events (CatalogChanged, ItemAvailabilityChanged) — no priority lane for user-facing transitions"
  category: queue_design
  evidence:
    - "DispatchDomainEventsJob.php:46  \\$this->onQueue('high')  — ALL DomainEvent dispatches go on 'high' regardless of event_type"
    - "config/horizon.php:33-43  supervisor-high: queue=['high'], minProcesses=1, maxProcesses=8, tries=6, timeout=90"
    - "EventServiceProvider 33 PersistXToOutbox listeners (Round 1 anchor) all feed the same lane"
  reasoning: |
    A single catalog import (Settings::group('menu').publish triggering
    `PersistCatalogChangedToOutbox` for 200 items + 50 categories +
    ingredient flags) can flood the `high` lane with 250+ broadcast jobs.
    Each consumes a worker slot for the Pusher round-trip (variable
    latency, typical 200-800ms). Worst-case backlog: 250 × 500ms / 8
    workers = 16s clearing time. During that 16s, OrderStatusChanged
    rows queued in the same lane wait their turn behind the catalog
    burst — the user-facing 5s p95 promise is silently broken by a
    background admin task.
  cost_of_delay: |
    Mid-service admin re-publishes the menu (allergen swap, price typo
    fix) → 16s state-sync stall hits POS / KDS / OSS at the same time.
    Customers paying at POS see "Confirmation..." spinner stuck because
    KDS hasn't acked the ticket yet (visible on OSS), kitchen
    queues fall behind. Single bad admin click + peak hour = order
    backlog cascade.
  remediation_hint: |
    Split lane: `high.user_facing` for OrderStatusChanged / OrderCreated /
    OrderPaidAtCounter / OrderPaymentStatusChanged ; `high.bulk` for
    CatalogChanged / ItemAvailabilityChanged / SettingsUpdated.
    config/horizon.php: two supervisors with separate scaling.
    DispatchDomainEventsJob constructor picks lane via event_type
    classification (whitelist for user-facing, default = bulk).
    Cheap win: 30 LOC + config + 1 schema migration if storing lane.

- id: SRE-016
  severity: P0
  title: "No `stuck-order` monitor — an order frozen at PREPARING for 30+ minutes silently runs unbounded; no pager, no SLO breach"
  category: missing_alert
  evidence:
    - "grep -rin 'stuck.*order\\|MonitorStuck\\|preparing.*30\\|stale_order' app/Console/ app/Jobs/ app/Services/  → 1 hit, DashboardService.php:299 (dashboard render only, no alerter)"
    - "Kernel.php:21-197  no schedule entry for stuck-order monitor"
    - "DashboardService.php:299-312  ONLY computes 'time_preparing' for dashboard display ; never paged"
  reasoning: |
    OrderStateMachine permits PREPARING → PREPARED only via FOH bump or
    admin override. If the kitchen tablet crashes/is misclicked, an order
    can sit at PREPARING indefinitely. The FOH operator on the POS or OSS
    has no proactive signal — the dashboard column shows time_preparing
    but is only visible if Admin opens dashboard. There is no Slack
    alert, no email, no Sentry event, no SLO breach for >30 min PREPARING.
    Cf. industry standard (Otter / Toast): "ticket-age-alert" fires at
    18 min default. FoodKing V1: 0 min default — nothing fires.
  cost_of_delay: |
    Customer waits 45 min at the OSS screen, FOH has no idea the order
    is stuck unless they happen to scroll the KDS. Reputational damage
    + refund obligation + NF525 refund-flow paperwork.
  remediation_hint: |
    Add `app/Console/Commands/MonitorStuckOrdersCommand.php`:
      - SELECT id, branch_id, status, updated_at FROM orders
        WHERE status = OrderStatus::PREPARING
          AND updated_at < (now() - 30 min)
          AND deleted_at IS NULL.
      - foreach: Log::error + Slack via existing Sentry/Slack hook + flag
        ActionLog category='stuck_order' for ops review.
    Kernel.php: ->everyFiveMinutes()->withoutOverlapping()->onOneServer()
    Add corresponding SLO: `stuck_order_count_24h => target:0, warn:0, breach:1`.
    P0 because Owner explicitly listed this in the mission anchor.

- id: SRE-017
  severity: P1
  title: "OSS auto-promotion (ready → served after N min) does NOT EXIST — BRAIN claim is documentation drift; no Console command, no Job, no Service"
  category: feature_gap_or_doc_drift
  evidence:
    - "grep -rin 'auto.served\\|auto_served\\|auto-promote\\|ready.*served\\|OssAutoTransition' app/Console/ app/Jobs/ app/Services/  → 0 hits"
    - "find app -iname '*OssAuto*'  → 0 results"
    - "BRAIN claim in mission anchor: 'OSS auto-transitions ready → served after N min'"
    - "KdsV2Grid.vue:100 has CLIENT-side `autoTransitionEnabled` prop for kitchen UI bump animation only — not OSS, not server-driven, not durable"
  reasoning: |
    The mission anchor frames OSS auto-promotion as an EXISTING surface
    that needs verification. Walking the code: it does not exist at all.
    KdsV2Grid has client-side auto-bump for the KITCHEN view (renders
    ready→served on click delay), but this is UI animation only — it
    does NOT mutate Order::status server-side, does NOT trigger
    OrderStatusChanged, does NOT cause an OSS update. If a kitchen
    finishes a meal but never gets bumped, the OSS screen will show
    READY indefinitely.
    Possibilities:
    A) Feature intended but never built (most likely given the empty greps).
    B) Feature exists in mobile/web frontend only (unlikely, codebase grep clean).
    C) BRAIN documentation drift inherited from V2 plan (verify with /goal anchor).
    Whichever: V1 cannot claim "OSS auto-transitions" until built.
  cost_of_delay: |
    Customer sees READY but no one physically called their number.
    They wait, FOH realises 10 min later and rushes the meal — meal is
    now lukewarm. UX failure pattern, customer NPS hit, repeat business loss.
  remediation_hint: |
    Decision needed: build or scope-out.
    If BUILD: new `app/Console/Commands/OssAutoPromoteCommand.php` that
    transitions PREPARED → DELIVERED via OrderStateMachine::apply() for
    orders >= N min in PREPARED state (N = settings('oss.auto_promote_minutes'),
    default 5). Cron everyMinute->withoutOverlapping->onOneServer.
    Triggers OrderStatusChanged so KDS/OSS both update.
    If SCOPE-OUT: update BRAIN + GOAL to remove the auto-promote claim;
    document as V1.0.2 backlog.

- id: SRE-018
  severity: P1
  title: "Single `private-branch.{branch_id}` channel for ALL stations — every KDS station receives every event; station filter is client-side only — N-station ops cost is unbounded"
  category: scaling_design
  evidence:
    - "PersistOrderStatusChangedToOutbox.php:52  'channel' => json_encode(['private-branch.' . \\$order->branch_id])  — single per-branch channel"
    - "KitchenDisplaySystemComponent.vue:1108  stationFilter local state, default 'all'"
    - "kdsDisplay.js:51  filterOrdersByStation() — pure client function, every payload arrives at every station tablet"
    - "KDSOrderDetailsResource grep  → no station/department/prep_area routing field"
  reasoning: |
    Architecture is "broadcast to branch, filter on client". For Le
    Cayenne V1 (1 branch, 2-3 stations) this is fine. The framing matters:
    if SaaS scale targets N=50 branches × 3 stations = 150 tablet
    subscribers, each receiving every event, Pusher message volume
    becomes 3x what it would be with per-station channels (each event
    sent once, fanned out 3x by Pusher to 3 subscribers vs once-per-station
    routed). Also: a noisy station event (e.g. station-only ingredient
    flag) traverses the network to every other station unnecessarily.
    Not a V1 blocker for single-branch Le Cayenne. IS a documented
    pre-scaling constraint that should be in GOAL §A6 (SaaS readiness).
  cost_of_delay: |
    V1 single-branch: zero operational cost. V2 SaaS: Pusher tier upgrade
    needed earlier than necessary (4-8x message volume). Refactor cost
    grows as more event types ship — every new Persist*ToOutbox listener
    inherits the design.
  remediation_hint: |
    OPTION A (V1 stay): document the choice in `docs/SYNC_CHANNELS.md`
    as deliberate; add comment in PersistOrderStatusChangedToOutbox.php:52.
    OPTION B (V1.x prep): introduce station-scoped channels for KDS only
    (OSS / POS still per-branch). Listener emits 2 channels:
      ['private-branch.{branch_id}', 'private-branch.{branch_id}.station.{station}']
    KDS subscribes only to its station's channel; OSS/POS unchanged.
    Backward-compatible — admin/KDS-all can still listen to branch-wide.
    Add `station` derivation in OrderItem (already exists per KitchenReleaseRule
    cross-ref — grep `prep_area` confirms).

- id: SRE-019
  severity: P0
  title: "No reconciliation runbook — `POS says paid, KDS still has it queued` divergence has no documented operator playbook and no replay command"
  category: missing_runbook
  evidence:
    - "grep -rln 'RECONCIL\\|reconciliation\\|kds.replay\\|state_drift\\|surface_divergence' docs/  → 1 hit (E2E_TEST_SUITE.md) — payment-reconcile test only, no operator runbook"
    - "find app/Console/Commands -iname '*reconcil*' -o -iname '*replay*'  → 0 hits"
    - "OrderStateMachine.php is frozen — no rollback path for cross-surface drift"
  reasoning: |
    Cross-surface divergence has multiple known causes:
      - WS message lost (Pusher drop, browser tab throttled to deep sleep)
      - Outbox row stuck (rescue cycle still pending)
      - KDS poll fallback last fetch >30s ago
      - Browser cache stale (KDS Vue local store + server divergence)
    When divergence is detected (operator opens KDS, sees order that POS
    has already marked PAID, kitchen confused), the runbook should answer:
      Q1: How to confirm POS state vs KDS state authoritative?
        → No tool exists. Operator can read DB directly. No CLI command.
      Q2: How to re-emit the broadcast?
        → `OutboxRescueCommand` re-queues if `stale(2 min)` AND `attempts < 5`.
          But a non-stale, dispatched_at-set row is invisible — replay
          impossible without manual DB UPDATE.
      Q3: How to force a KDS hard-refresh?
        → No `kds:resync` command. Operator must instruct kitchen to
          manually reload browser.
    No runbook = on-call burnout, divergence resolution time inflates
    from <5min to 30+ min as operators figure out the inspection sequence
    each time.
  cost_of_delay: |
    First production divergence event during dinner peak: 30+ min
    confusion, customers in shop watching the FOH try to figure out
    "did we charge them or not". Brand reputation hit. After the second
    event, ops manual gets drafted reactively — at which point the
    runbook is bad ("just refresh", "wait a bit") because nobody wrote
    it with SRE discipline.
  remediation_hint: |
    1. New `docs/runbooks/SYNC_DIVERGENCE_PLAYBOOK.md`:
       - Step 1: Confirm authoritative state via DB (queries provided).
       - Step 2: Inspect outbox row state (provided SELECT).
       - Step 3: Decision tree (stuck row → manual replay; KDS stale →
         hard-refresh; WS dead → check Pusher dashboard).
    2. New `app/Console/Commands/KdsResyncCommand.php`:
       Signature: `kds:resync --branch=N [--order=N]`.
       Emits a synthetic OrderStatusChanged for every active order in the
       branch (or just the specified order) — forces the KDS Vue store
       to re-fetch.
    3. New `app/Console/Commands/OutboxReplayCommand.php`:
       Signature: `outbox:replay --domain-event-id=N`.
       Resets dispatched_at=null + dispatches DispatchDomainEventsJob
       regardless of attempts (manual operator override of the rescue
       attempts<5 gate). Logs ActionLog category='outbox_manual_replay'
       with actor_id for audit.
    P0 because the mission anchor explicitly listed reconciliation as a
    runbook requirement.

- id: SRE-020
  severity: P2
  title: "Synchronous listener chain on OrderStatusChanged — if PersistOrderStatusChangedToOutbox throws BEFORE downstream listeners run, FCM push / KDS dispatch / loyalty listeners are silently skipped (no rollback risk because DispatchableAfterCommit, but observability fans out unevenly)"
  category: listener_chain_fragility
  evidence:
    - "OrderStatusChanged.php:13-17  uses DispatchableAfterCommit — event is deferred until the surrounding DB::transaction() commits; listener throws CANNOT roll back the apply() tx (no live tx in scope)"
    - "Laravel synchronous listener iteration: any uncaught throw inside listener N aborts listeners N+1..K of the same event"
    - "PersistOrderStatusChangedToOutbox.php:34  firstOrCreate is bare (no try/catch) — a DB-constraint conflict or DomainEvent table lock throws, aborting downstream listeners registered later"
    - "Listeners chain known to react to status changes: DispatchKdsTicket (calls OrderStatusChanged::dispatch which is a re-emit, not a sibling), SendFcmOnOrderStatusChange, AwardLoyaltyPointsOnDelivery"
  reasoning: |
    Reframed after verification: DispatchableAfterCommit means a listener
    throw cannot roll back the originating apply() transaction (no live
    transaction in scope). So the previously-feared silent state corruption
    is NOT a risk class here — that's the framework contract.
    What REMAINS is: synchronous listener iteration order. If
    PersistOrderStatusChangedToOutbox is registered first and throws on
    a UNIQUE conflict (idempotency_key race) or table lock (prune at
    04:00, Kernel.php:100), Laravel aborts the rest of the chain. Net
    effect: Outbox row not written, FCM push not sent, loyalty points
    not awarded — but the state change IS already persisted. Cross-surface
    drift class: DB says PREPARED, but the kitchen tablet never got the
    push and the loyalty engine never observed the DELIVERED transition.
    Bounded but real: requires a triggering DB error inside the listener.
  cost_of_delay: |
    Rare (DB locks on domain_events are non-zero around prune windows;
    UNIQUE conflicts on idempotency_key are protected by listener-side
    dedupe). Realistic frequency: <1/month. Blast radius when it does
    fire: 1 lost broadcast + 1 lost FCM + 1 lost loyalty event per
    incident, never observed by the originating user.
  remediation_hint: |
    Wrap firstOrCreate in try/catch + Log::error so the listener cannot
    abort the chain. Alternative: split into per-listener queued jobs
    (implements ShouldQueue on each listener) so each fails in isolation.
    The current code already has try/catch around the inner DispatchDomainEventsJob::dispatch
    (line 75-85); apply the same pattern at line 34.

- id: SRE-021
  severity: P1
  title: "DispatchDomainEventsJob `timeout=90` config/horizon.php:41 + Pusher SDK default HTTP timeout 30s — broadcaster hangs 30s × 3 retries = 90s; the job times out before logging which retry failed"
  category: worker_timeout
  evidence:
    - "config/horizon.php:41  supervisor-high tries=6 timeout=90"
    - "DispatchDomainEventsJob.php:115  app(BroadcastManager::class)->connection()->broadcast() — uses default Pusher SDK timeout"
    - "pusher-php-server vendor default connect_timeout=30, timeout=30 (not overridden in app code)"
    - "Worst case: 3 Pusher retries × 30s = 90s = timeout boundary — race between job timeout and Pusher retry exhaustion"
  reasoning: |
    Horizon SIGTERM after 90s. Pusher SDK doing its 3rd internal retry at
    second 89 gets cut off, but the job's failed() handler (line 165) may
    or may not fire — Horizon kill mid-broadcast leaves the row state
    `dispatched_at=now()` (claimed) + `last_error=null`. Both the success
    AND failure paths have `dispatched_at=now()` set BEFORE broadcast
    (line 80-83) — so on timeout, the row LOOKS dispatched while the
    broadcast never happened. The rescue command (`stale(2)` AND
    `attempts<5`) won't re-pick it because dispatched_at is set.
    The retry-failed command picks up rows where attempts>=5 in last 24h,
    so eventually it converges. But the SILENT WINDOW between timeout
    and next retry-failed run (next hour) is up to 60 min.
  cost_of_delay: |
    Single Pusher slowdown causes a 60-min silent gap on selected events.
    Customers waiting on a status change observe ~10-50 min lag. Hard to
    distinguish from a stuck-order condition (SRE-016) at observer level.
  remediation_hint: |
    1. Configure broadcaster connect_timeout in config/broadcasting.php
       to 5s and timeout to 5s (override Pusher SDK default).
    2. DispatchDomainEventsJob timeout shrink to 30s (still 3x SDK timeout
       budget) — fail fast, retry sooner.
    3. On failed(): ENSURE `dispatched_at=null` is restored. Currently
       handled in handle()'s catch (line 146-151) BUT a Horizon SIGTERM
       skips the catch. failed() should detect dispatched_at != null +
       last_error == null + attempts < tries and reset dispatched_at=null
       so rescue can re-claim.

- id: SRE-022
  severity: P2
  title: "`KdsSyncService::sync` caches a 5-second TTL on the polling fallback delta — on Pusher outage, KDS polling fallback shows up to 30s + 5s = 35s stale data"
  category: cache_freshness
  evidence:
    - "KdsSyncService.php:49  Cache::remember(\\$cacheKey, 5, ...) — 5 second TTL"
    - "config/broadcasting.php Round 1 SRE-007: polling fallback default 30000ms (30s)"
    - "30s polling + 5s cache = up to 35s end-to-end fallback latency"
  reasoning: |
    Polling fallback already 6x slower than the V1 5s promise (Round 1
    SRE-007 documented). Layering a 5s cache on top compounds the
    staleness window. The cache exists to protect against the read-DB
    cost on a busy /sync endpoint — but at 30s polling interval the
    cost is bounded (1 read/30s × 3 stations = 0.1 rps). The cache is
    saving nothing meaningful but doubling worst-case stale window.
  cost_of_delay: |
    Pusher outage + polling fallback active = 35s worst case for state
    change visibility. Customer at OSS sees stale state for an extra 5s
    over and above the 30s polling.
  remediation_hint: |
    Cache TTL → 1s (still mitigates burst reads) OR remove cache entirely
    (DB read is bounded by polling rate). Trade-off: read cost vs.
    freshness. Recommendation: 1s TTL is the sweet spot.

- id: SRE-023
  severity: P2
  title: "Envelope `version` field is a hardcoded scalar (always 1), not a semver — no compat-path support + no payload_hash → schema migration forces a flag-day deploy, replay during the window broadcasts upgraded-shape clients in old language"
  category: contract_resilience
  evidence:
    - "EventContract.php:84  'version' => self::ENVELOPE_VERSION (single int constant, always 1)"
    - "EventContract.php:103-105  assertEnvelopeValid hard-rejects any envelope where version != ENVELOPE_VERSION — strict equality, not range / >="
    - "EventContract.php:81-92  buildEnvelope output keys = {version, type, aggregate_id, branch_id, occurred_at, correlation_id, payload} — NO payload_hash, NO schema_revision"
    - "PersistOrderStatusChangedToOutbox.php:41-51 payload literal array, no per-payload schema version stamp"
  reasoning: |
    The envelope DOES have a `version` field — but it's a single-value
    bit. A schema migration that changes payload shape must increment
    ENVELOPE_VERSION = 2, at which point assertEnvelopeValid REJECTS
    every domain_events row still pending broadcast (they have version=1).
    The rescue + retry-failed cron will hammer them as PayloadMismatchException
    until the retention prune sweeps them at 90 days. Net effect: schema
    migrations are flag-day (drain outbox to zero, then deploy) — high
    operational cost.
    Also: no payload_hash means a partial DB write (network blip on
    DomainEvent insert that loses a JSON tail) goes undetected at
    broadcast time; the receiver gets corrupt payload and the KDS Vue
    store may render garbage or crash.
  cost_of_delay: |
    Low frequency, high process cost. Each schema migration on a
    high-volume event (OrderStatusChanged, OrderCreated) requires an
    outbox-drain window at deploy time. For Le Cayenne V1 single-branch
    this is hours not days, but for SaaS scale it becomes a release-train
    blocker.
  remediation_hint: |
    1. Replace `int $version` with structured `array $version => {schema:int, contract:int}`.
       schema=per-event-type payload version (bumped per migration),
       contract=envelope-shape version (rare bump). assertEnvelopeValid
       accepts a range, listener client routes by event_type+schema.
    2. Add `payload_hash` field: sha256 of canonical-JSON-encoded payload.
       Listener client verifies on receipt; ActionLog category=
       'envelope_hash_mismatch' on drop.
    Both changes are additive (old version=1 envelopes accepted via
    compat path), so deploys do not require an outbox drain.
```

---

## Cross-cutting SRE verdict

**ON-CALL READINESS for OrderStateMachine fan-out: NOT V1-READY without SRE-013/016/019.**

The pattern is the same as Round 1 — *the data exists or could exist trivially, the page never fires*. Three additive concerns to Round 1:

1. **SRE-013** — The headline V1 5s promise has no producer and no SLO. We literally cannot prove we met our own SLA in a customer dispute.
2. **SRE-016** — A common ops scenario (order stuck at PREPARING) has no automated detector. Pure dashboard observability.
3. **SRE-019** — When divergence happens (and it WILL), the operator has no runbook and no replay tools. Resolution time will inflate to 30+ min on first incident.

**Documentation drift exposed**:
- SRE-017: "OSS auto-promote" exists in BRAIN claim but not in code. Either build or scope-out — cannot ship a documented feature that doesn't exist.

**Quick wins** (highest ROI, ~1d total):
- SRE-013 + SRE-014: ~150 LOC across SyncMetricsRecorder + SloMetricCollector + SloEvaluatorJob + frontend taps. Unlocks the V1 SLA proof.
- SRE-016: ~80 LOC for MonitorStuckOrdersCommand + Kernel.php schedule + SLO target. Closes a high-blast-radius silent failure mode.
- SRE-019: ~200 LOC for two replay commands + a runbook MD. Halves the cross-surface divergence MTTR.

**Architectural deferrals to V1.0.x**:
- SRE-015 queue-lane split — needs design review.
- SRE-018 station-channel routing — V1 single-branch is fine; pre-document for SaaS.
- SRE-023 envelope schema_version — design now, ship when first schema migration lands.

**Estimated wall-clock to V1-ready**:
- SRE-013/016/019 (P0 top): ~8h dev + 2h ops verification.
- SRE-017 (decide build vs scope-out): owner decision then either 2h or 0h.
- SRE-014/015/018/021 (P1 hardening): ~10h dev across the bundle.
- SRE-020/022/023 (P2): backlog V1.0.2.

End of audit. ~1860 words.
