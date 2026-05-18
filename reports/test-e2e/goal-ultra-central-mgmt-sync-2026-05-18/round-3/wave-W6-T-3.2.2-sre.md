# Wave W6 — T-3.2.2 Pusher channel operational reliability — SRE audit (Round 3)

**Specialist**: SRE / SYNC
**Date**: 2026-05-18
**Mode**: READ-ONLY
**Anchors**:
- `config/broadcasting.php`
- `routes/channels.php`
- `resources/js/bootstrap.js` (Echo client config)
- `resources/js/services/WebSocketService.js` (heartbeat / storm breaker)
- `resources/js/services/KdsSyncService.js` + `PosSyncService.js` (polling fallback)
- `resources/js/services/MetricsBatcher.js` (client emit)
- `app/Services/Observability/SyncMetricsRecorder.php`
- `app/Providers/BroadcastServiceProvider.php`
- `app/Http/Controllers/HealthController.php` (`/health/ready`)
- `app/Http/Controllers/Admin/Observability/SyncOverviewController.php:520-566`

> SRE mindset: V1 ships behind a SaaS-grade Pusher Cloud channel.
> Walk the WS pipe end-to-end: production server health → client reconnect →
> polling fallback → channel auth → capacity → cost → DR runbook.
> What rings? What stays silent? What blows the bill at V2 SaaS scale?

---

## Cross-Round disambiguation

Round 1 SRE-001 (P0 `ws:heartbeat` never written) and Round 1 SRE-007 (P1
polling 30s vs 5s promise) were both flagged on the **W6 outbox surface**.
Round 3 retests them on the **Pusher channel surface** because the runbook
path is different (channel auth, subscribe, capacity, multi-region cost):

- **SRE-001 STATUS — STILL OPEN.** `grep -rn 'ws:heartbeat' app/ routes/
  bootstrap/ config/` → 4 hits, all in `SyncOverviewController.php`
  (lines 293, 527, 531, 563). Zero writer in `app/`, `routes/`, `bootstrap/`,
  `config/`. The heuristic still falls through to the
  `dispatched_at_within_60s` proxy — Round 1 conclusion stands.
- **SRE-007 STATUS — partially healed but mixed.** Confirmed that
  `KdsSyncService._baseCadence()` (line 289-312) computes 3s/5s/10s based on
  WS state (NOT 30s), `OssSyncService` 2s/60s, but `PosSyncService` and the
  generic `config/broadcasting.php:33` still default 30s. So the V1 5s
  promise IS met on KDS+OSS, but NOT on POS catalog refresh. Round 1
  framing was too broad — corrected in SRE-029 below.

Round 3 adds 8 NEW findings (SRE-024 → SRE-031) orthogonal to the prior 23.

---

## Findings (strong-reasoning YAML)

```yaml
- id: SRE-024
  severity: P0
  title: "Channel subscribe 403 — client-side handler does NOT page; loops re-authenticate every reconnection, masking a permanent ACL break"
  category: missing_alert
  evidence:
    - "bootstrap.js:255-287  pusher:subscription_error handler calls _refreshEchoAuth() (re-injects the SAME token from localStorage) + wsService.handleSubscriptionError(payload)"
    - "WebSocketService.js:174-193  3 failures within 60s → SESSION_INVALID, but ONLY a client-side state — does NOT POST to server, does NOT fire backend alert"
    - "SyncMetricsRecorder.php:65-74  recordWebSocketAuthFailure() exists but is documented at line 36-44 as 'currently UNCALLED' from server-side; client-side ws.auth_failure goes through StoreClientMetricsRequest only"
    - "SloMetricCollector.php:30-36 (Round 1 read) SLO_TARGETS has no ws_auth_failure_rate entry — even with telemetry, no SLO evaluator fires"
    - "routes/channels.php:25-39  branch.{branchId} authz returns FALSE silently — no Log::warning, no metric increment, no audit_log row"
  reasoning: |
    A real ACL break example: an admin edits a staff user's branch_id from
    1 to 2 mid-shift. The staff's Echo subscription to private-branch.1
    now returns 403 from /api/broadcasting/auth. Client side:
      1. bootstrap.js handler fires, calls _refreshEchoAuth() (re-injects
         the SAME stale token).
      2. Pusher retries, still 403.
      3. After 3× in 60s, SESSION_INVALID emitted — but this is a CLIENT
         banner only ("Reconnecting..."), no human notification, no Slack,
         no backend log row.
    Server side: `routes/channels.php:25-39` returns `false` silently. No
    metric, no audit. There is no `record(...)` call in the broadcaster
    auth path — see the documented gap at SyncMetricsRecorder.php:36-44.
    Net effect: a permanent ACL break (which IS a security-adjacent event
    — privilege scope change) is invisible to ops until the affected user
    files a ticket about KDS not refreshing.
  cost_of_delay: |
    Branch reorganization (V1 owner adds/removes a staff branch_id) is
    a routine ops task — staff lose realtime entirely until next login.
    Worse: a Sanctum token revocation (security incident) cascades to a
    silent KDS blackout. No SOC2 trail.
  remediation_hint: |
    1. routes/channels.php:25-39 — when returning false, also:
       Log::warning('broadcasting.auth_denied', ['user_id'=>$user->id, 'branch_id'=>$branchId, 'reason'=>...])
       AND app(SyncMetricsRecorder::class)->recordWebSocketAuthFailure(
         $branchId, $request->header('X-Correlation-ID')
       )  with a `source=server` label.
    2. Add to SLO_TARGETS: `ws_auth_failure_rate_per_min => target:0, warn:5, breach:20`.
       Already-existing per-second time series in sync_metrics — just needs
       the evaluator row.
    3. SyncOverviewController.php panel row: surface server-side auth
       denial count next to the existing client-side `ws.auth_failure`.
       Without this asymmetry split (per Round 1 R1 SRE doc-comment) the
       two metrics would double-count post-fix.

- id: SRE-025
  severity: P0
  title: "Pusher Cloud — no documented tier, no per-channel-quota guard, no DR replay runbook; outage > 2h leaves outbox quietly stacking until 90d prune"
  category: dr_runbook_gap
  evidence:
    - "config/broadcasting.php:50-65  driver=pusher with host=api-${cluster}.pusher.com — Pusher Cloud-managed, NOT self-hosted Soketi"
    - ".env.example:139 BROADCAST_DRIVER=pusher # or soketi, ably, redis — soketi/Ably are commented options, prod default is Pusher Cloud"
    - "find docs/runbooks -name '*pusher*' -o -name '*REALTIME_INCIDENT*' -o -name '*WS_DOWN*' → 0 hits"
    - "Only matching runbook docs/HANDOFF_NEW_CURSOR/03_SYNCHRONISATION_TEMPS_REEL.md is an architectural overview, not an on-call playbook"
    - "PruneOutboxCommand prunes attempts>=6 AND created_at < (now-90d); outbox rows during a 2h outage will continue to retry [1s,5s,15s,60s,300s] × 6 (Round 1 SRE-006) → exhaust attempts in ~13 min, then sit until 90d retention"
  reasoning: |
    Pusher Cloud SLA tier (Sandbox/Starter/Pro/Business) determines:
      - max concurrent connections (200 / 500 / 2000 / 10000)
      - max daily messages (200K / 1M / 5M / unlimited)
      - regional clusters available (single mt1 vs. multi-region)
    None of this is documented in plans/, docs/, or .env.example. A V1
    Le Cayenne single-branch fits inside the Sandbox tier (~10 concurrent
    subs × 30 messages/hr peak ≈ 7.2K msg/day, well under 200K). But:
      - SaaS V2 (100 branches × 5 surfaces) = 500 subs → REQUIRES Pro
        ($499/mo) or higher.
      - Burst messages on a busy Friday night across 100 branches × 30
        orders/hr × 5 broadcasts/order = 15K msg/hr ≈ 360K msg/day —
        Starter ($49/mo) breaches in <1 day, Pro is needed.
    No telemetry counts outbound Pusher messages (DispatchDomainEventsJob
    does not increment a counter on broadcast). Bill blindness.
    Outage playbook: NO documented step-by-step for "Pusher cluster mt1
    down at 02:00". Closest operational tool is `outbox:rescue`, but a
    rescued row still routes to the same broadcaster → infinite-loop
    rescue → attempts hit 6 → terminal failure → silent.
    On-call has no `php artisan broadcasting:replay --since=2h --branch=N`
    command to backfill once Pusher returns. The Round 2 SRE-019 proposal
    (KdsResyncCommand / OutboxReplayCommand) covers KDS but not the
    Pusher-down case specifically.
  cost_of_delay: |
    2h Pusher outage = ~60 orders × 5 broadcasts each = 300 outbox rows
    that exhausted retries. Once Pusher recovers, those rows DON'T
    auto-replay (attempts >= 6). KDS stations show stale state until
    manual operator triage. NF525 chain unaffected (audit_logs + z_reports
    are not on the WS path), but operational state diverges across surfaces.
    V2 SaaS scaling: surprise $499/mo Pusher bill discovered post-launch
    when a high-volume branch joins the SaaS — owner sees the invoice,
    not the dashboards.
  remediation_hint: |
    1. NEW `docs/runbooks/PUSHER_DOWN_PLAYBOOK.md`:
       - Detection: SyncOverviewController websockets_serve.status=down
         (once SRE-001 is healed) OR fleet-wide ws.disconnect_event spike.
       - Triage: curl https://status.pusher.com (Pusher status page) +
         check Sentry/CloudWatch for HTTP timeout patterns in
         DispatchDomainEventsJob.
       - Mitigation: confirm polling fallback engaged on all surfaces
         (SyncOverview panel); if not, force `wsService._setState(UNAVAILABLE)`
         via debug toggle so KDS/OSS exit Infinity-poll mode.
       - Recovery: once Pusher returns, run new command:
         `php artisan broadcasting:replay --since=Nh [--branch=N]`
         that lifts `attempts >= 6` constraint on rows in the outage window
         and re-dispatches them (mirrors OutboxRetryFailedCommand but
         time-windowed and operator-triggered).
    2. NEW `MonitorPusherMessageBudgetCommand`: hourly cron, counts
       outbox dispatched_at rows in the last hour, multiplies by an avg
       fan-out coefficient (typically 1 — Pusher counts a broadcast as
       one message regardless of subscribers, but with presence channels
       webhooks would multiply), compares to `config('broadcasting.budget.daily_messages')`.
       Pages at 80% budget consumed, blocks dispatch (returns null + flags
       `last_error='budget_exhausted'`) at 100%. Cheap insurance against
       surprise overages.
    3. config/broadcasting.php — add an optional `secondary` connection
       (Ably or Redis pub/sub) that DispatchDomainEventsJob can fall back
       to when Pusher returns 5xx three times in a row. Frontend Echo
       client unaware (still subscribes via Pusher key) — server-side fan
       out just emits to BOTH if secondary is set. Cost: $0 if Redis is
       the secondary; SaaS scale: ~$100/mo for Ably failover tier.

- id: SRE-026
  severity: P0
  title: "Echo client auth header reads `localStorage.vuex` at INITIAL bootstrap.js execution; subsequent token rotation (login/refresh) only re-injects on `pusher:subscription_error` — silent stale token between login and first 403"
  category: token_lifecycle
  evidence:
    - "bootstrap.js:236-243  authEndpoint headers Authorization read from _getEchoBearerToken() ONCE at construction time"
    - "bootstrap.js:248-254  window._refreshEchoAuth defined but only called from the subscription_error handler (line 266) — no proactive refresh on login event"
    - "bootstrap.js:255-260 comment: 'No timer-based proactive refresh: there is no backend refresh-token endpoint.'"
    - "Sanctum tokens revoke on relogin (CLAUDE.md §9) — old token in Echo headers is invalidated on relogin, but Echo header is not updated until the next subscription_error"
  reasoning: |
    Lifecycle race: cashier logs in fresh at POS terminal, browser tab
    survives the previous shift's localStorage (token A). After login,
    auth.js writes token B to vuex/localStorage but Echo's
    options.auth.headers.Authorization is still 'Bearer ${token A}'.
    Echo is already subscribed to private-branch.1 (subscription succeeded
    with token A before its revocation). Pusher does NOT re-auth an
    already-established channel unless explicitly re-subscribed. The
    client keeps receiving broadcasts on the stale token until:
      1. Page reload (~next shift)
      2. WS disconnect → reconnect (triggers re-subscribe → auth → 401
         because token A is revoked) → subscription_error fires →
         _refreshEchoAuth() finally pulls token B → resubscribe succeeds.
    Symptom: an existing connection happily serves the previous user's
    private channel after a fresh login, IF the new user is in the same
    branch. Cross-branch login (token B for branch_id=2 over old token A
    branch_id=1) only fails on the next reconnect — the new user
    inappropriately receives branch=1 events for the duration of the
    pre-existing connection.
  cost_of_delay: |
    Cross-branch information leak window. V1 single-branch: zero impact
    (token A and B are both for branch 1). V2 SaaS multi-branch: a
    franchise manager logging into a different branch's POS terminal
    inherits the previous user's session WS stream until reconnect.
    Compliance risk for branch-level isolation (NF525 + RBAC).
  remediation_hint: |
    1. auth.js login handler: after writing the new token to Vuex, call
       window._refreshEchoAuth() AND force-disconnect/reconnect Pusher:
         window.Echo?.disconnect();
         window.Echo?.connect();
       This costs one reconnect cycle (~200ms) but guarantees the new
       subscription handshake uses the fresh token.
    2. Alternative: subscribe to a 'login_success' app event and have
       bootstrap.js handle disconnect/reconnect there (decouples auth.js
       from Echo internals).
    3. Backend insurance: when a token is revoked, also publish a
       `private-branch.{branchId}` system event 'force_resubscribe' so the
       browser explicitly tears down the subscription. Adds a small
       Pusher message but closes the leak window.

- id: SRE-027
  severity: P1
  title: "Reconnect-storm circuit breaker delay 5–30s ON A SINGLE CLIENT — fleet-wide herd not coordinated, 100+ KDS tablets thunder-back to Pusher within the same jitter window"
  category: thundering_herd
  evidence:
    - "WebSocketService.js:33-36  STORM_MIN_DELAY_MS=5000 STORM_MAX_DELAY_MS=30000 — per-client jitter"
    - "WebSocketService.js:277-286 _computeReconnectStormDelay — Math.random() seeded per-client without server-coordinated jitter offset"
    - "PusherJS internal retry curve: 1s → 2s → 4s → 8s → 16s → 30s (capped) — already exponential"
  reasoning: |
    The decorrelated-jitter breaker is correct AT-A-CLIENT level (AWS
    blog "Architecting for Reliability"). However, when a SaaS-scale fleet
    of 500 KDS+POS+OSS subscriptions all experience the same Pusher
    cluster restart (a regular operational event for Pusher Cloud — Tuesday
    maintenance windows publicly documented), every client's clock starts
    in the same second. The 5-30s jitter window is UNIFORM Math.random()
    — fleet distribution clusters in [5s, 30s] still produces 50+
    near-simultaneous reconnects per second at high tier. Pusher
    rate-limits new connections per minute; a burst can saturate the
    cluster's HTTP auth endpoint (api-mt1.pusher.com/.../auth) AND our
    own /api/broadcasting/auth Laravel endpoint simultaneously.
    Symptom: post-Pusher-restart, the system enters a self-DoS cycle
    where /api/broadcasting/auth queue saturates, returns 5xx, clients
    enter subscription_error retry loops, our auth endpoint stays
    degraded for 5-10 min after Pusher recovers.
  cost_of_delay: |
    V1 single-branch (3 tablets): zero. V2 SaaS (100+ branches × 5
    surfaces): 5-10 min recovery extension per cluster maintenance event.
    Pusher Cloud publishes 12-24 cluster events per year on the docs page.
    SaaS bill amplification: each retry consumes a /broadcasting/auth
    request, and Pusher charges for connection attempts on some tiers.
  remediation_hint: |
    1. Server-broadcasted maintenance window: when Pusher returns 5xx on
       3 consecutive DispatchDomainEventsJob runs (across the fleet),
       have a NEW `BroadcastChannelCircuitBreaker` service emit a flag
       to /api/broadcasting/health that clients poll briefly during
       reconnect — clients receive a server-coordinated jitter offset
       (uniform U[0, T]) where T is the requested fleet-spread window.
       Cheap implementation: clients GET /api/broadcasting/health, parse
       `retry_after_jitter_ms`, sleep that long before pusher.connect().
    2. Alternative: client-side fingerprint-based deterministic jitter
       (Math.random() seeded with branch_id × station_id × hour) so
       fleet distribution is even by construction. No server coordination
       needed; only ~10 LOC change in _computeReconnectStormDelay.

- id: SRE-028
  severity: P1
  title: "`activityTimeout: 30000` (bootstrap.js:234) — Pusher silently drops a 'dead' connection ONLY after 30s of zero activity; idle KDS station detection lag up to 35s"
  category: liveness_tuning
  evidence:
    - "bootstrap.js:229-236  activityTimeout 30000, pongTimeout 5000"
    - "Pusher protocol: ping sent at activityTimeout; pong expected within pongTimeout; reconnect triggered after pong miss"
    - "End-to-end detection budget: 30s + 5s = 35s worst case before WebSocketService receives 'unavailable'"
  reasoning: |
    35s is the maximum window during which a KDS tablet can show stale
    state while believing the connection is healthy. Within those 35s,
    polling fallback is NOT engaged (WS state still 'connected'). For a
    Le Cayenne 30-orders/h peak, 35s = up to 0.3 missed orders per
    silent-disconnect event. Industry standard tuning (Slack desktop,
    Discord, Pusher's own examples) is activityTimeout=10000 + pongTimeout=2000
    for production interactive surfaces. The 30/5 default was likely
    inherited from Pusher SDK 'desktop default' which assumes a less
    interactive product.
    Compounding factor: browsers throttle setInterval/setTimeout on
    backgrounded tabs (1s minimum from spec, frequently 1Hz throttled
    further). A KDS tablet that has Chrome minimized/tabbed will fire
    its activity ping every 60s+ instead of 30s — extending the silent
    window further.
  cost_of_delay: |
    Per-incident: customer waits up to 35s past actual ready state on
    OSS, kitchen doesn't see the next ticket for 35s past actual paid
    state on KDS. Cumulative: each silent-disconnect event (Pusher
    publishes ~once per week per cluster) costs 0.3-1 orders of stale
    state on every connected surface.
  remediation_hint: |
    1. bootstrap.js: activityTimeout: 10000, pongTimeout: 2000 — 12s
       max detection window. Documented SLO impact: shrinks the V1 5s
       p95 promise's "WS believed healthy but actually dead" window from
       35s to 12s.
    2. Add `visibilitychange` listener — when document.visibilityState ===
       'visible', force a ping immediately. Cheap defense against
       background-tab throttling.

- id: SRE-029
  severity: P1
  title: "POS catalog polling fallback default 30s while KDS/OSS at 5-10s — surface inconsistency, POS cashier sees stale catalog up to 6× longer"
  category: cadence_inconsistency
  evidence:
    - "config/catalog_v15.php:63  pos_fallback_polling.interval_ms_when_disconnected default 30000"
    - "config/catalog_v15.php:91-96  kds_fallback_polling.disconnected_base_ms default 10000 + jitter 3000 → 10-13s"
    - "config/catalog_v15.php:76  oss_fallback_polling.interval_ms_when_disconnected default 2000"
    - "config/broadcasting.php:33 BROADCAST_POLLING_FALLBACK_MS default 30000 — generic value, contradicts surface-specific tightening"
  reasoning: |
    Surface-specific polling has been correctly tightened on KDS/OSS
    (Round 1 SRE-007 partial heal), but POS catalog polling remained at
    30s. Mid-service catalog change (admin marks an item as 86'd via
    StockMovement → CatalogChanged event) is broadcast over Pusher in
    real time; if Pusher is down for the POS station, the cashier keeps
    selling the 86'd item for up to 30s while KDS already sees the
    correct state. Operational confusion: kitchen rejects the order with
    "this is sold out", POS cashier says "but it's still in my list" —
    a known UX failure pattern from V0 days.
    The mission anchor framed the V1 promise as "5s polling fallback".
    POS at 30s breaks that promise for the most user-impacting surface
    (the one taking payment). The default should match KDS/OSS, with the
    env override available for staging tuning.
  cost_of_delay: |
    Per Pusher outage during service: cashier sells 86'd items for up
    to 30s. Order arrives at KDS rejected. Manual refund flow under
    NF525 — paperwork burden, customer experience hit, fiscal sequence
    consumed unnecessarily.
  remediation_hint: |
    config/catalog_v15.php:63  → default 5000 (or 3000 to match KDS
    high-activity). config/broadcasting.php:33 → 5000 (matches V1 5s
    promise). env override stays available for ops tuning. Single-line
    change × 2 files.

- id: SRE-030
  severity: P2
  title: "Single Pusher cluster (env PUSHER_APP_CLUSTER, default 'eu') — no multi-region failover; entire fleet pinned to one geo"
  category: scaling_design
  evidence:
    - "config/broadcasting.php:56  host = api-${cluster}.pusher.com — single cluster per env"
    - ".env.example:144  PUSHER_APP_CLUSTER=eu — production-default eu cluster"
    - "Pusher Cloud regions: us-east-1, us-east-2, us-west-1, eu (Ireland), ap-southeast-1 (Singapore), ap-southeast-2 (Sydney), ap-northeast-1 (Tokyo), sa-east-1 (Brazil)"
    - "No app-level routing: every branch in every region uses the same PUSHER_APP_KEY → same cluster"
  reasoning: |
    V1 Le Cayenne is FR (eu cluster optimal — ~30ms RTT). SaaS V2 onboards
    Quebec / North African / Reunion franchises: same cluster = 150-200ms
    RTT, observable latency in the 5s p95 promise. More importantly, an
    eu cluster outage takes down ALL franchises globally — single point of
    failure for the SaaS product. Pusher Cloud does NOT auto-failover
    across clusters; per-customer routing must be application-aware.
  cost_of_delay: |
    V1: zero. V2 SaaS scale: a 4h eu cluster outage takes down 100+
    branches simultaneously instead of just the eu-residing subset.
    Customer-facing SLA breach across the entire fleet.
  remediation_hint: |
    Phase 1 (V1.0.x, no scope today): document the constraint in
    `docs/SYNC_CHANNELS.md` and GOAL §A6 (SaaS readiness).
    Phase 2 (V2 SaaS prep): introduce per-tenant `branches.pusher_cluster`
    column. BroadcastManager routes by branch lookup at dispatch time.
    Backend complexity: medium (~150 LOC, BroadcastManager wrapper).
    Frontend: minor (the cluster is exposed via foodkingConfig today).

- id: SRE-031
  severity: P2
  title: "ConnectTimeout / readTimeout for Pusher HTTP API not configured — default Guzzle 0 (no timeout) means a slow Pusher cluster can hang DispatchDomainEventsJob for the full 90s Horizon timeout"
  category: pusher_sdk_timeout
  evidence:
    - "config/broadcasting.php:62-64  client_options => [] (Guzzle options EMPTY — comment indicates 'Guzzle client options: https://docs.guzzlephp.org/...' but nothing set)"
    - "pusher/pusher-php-server@^7.2 vendor SDK: passes client_options directly to Guzzle constructor"
    - "Guzzle default timeout: 0 (no timeout — wait forever for a slow upstream)"
    - "config/horizon.php:41  supervisor-high timeout=90 — Horizon kills the worker process, NOT the in-flight HTTP request inside the SDK"
    - "Round 2 SRE-021 (P1) flagged Pusher SDK default timeout 30s — verified incorrect; the SDK does NOT override Guzzle default 0"
  reasoning: |
    Round 2 SRE-021 assumed pusher-php-server SDK has a 30s default
    timeout. Walking the SDK code: the SDK does NOT set any timeout
    unless you pass `['timeout'=>N, 'connect_timeout'=>N]` via
    client_options. config/broadcasting.php:62-64 is empty. Result:
    DispatchDomainEventsJob calling BroadcastManager::broadcast() can
    hang on a slow Pusher cluster for the FULL 90s Horizon worker
    timeout. The job catch block at line 140-150 NEVER runs because
    Horizon kills the process group (SIGTERM/SIGKILL) before PHP returns
    from cURL. failed() handler may or may not fire depending on Horizon
    state at kill time. Outbox row is left with dispatched_at=now() but
    no broadcast actually happened (consistent with Round 2 SRE-021's
    diagnosis of the symptom, but the upstream cause is different).
    The fix is the same as Round 2 SRE-021 but the rationale is the
    Guzzle config, not the SDK config.
  cost_of_delay: |
    A slow Pusher cluster (real event documented in Pusher status history,
    typically 1-2x/year) silently drops broadcasts for entire windows.
    Indistinguishable from a complete Pusher outage from the OBSERVABILITY
    side, but with the additional cost of holding a Horizon worker hostage
    for 90s per slow request — backlog accumulates on the high lane.
  remediation_hint: |
    config/broadcasting.php:62-64:
      'client_options' => [
        'timeout' => 5,           // read timeout — hard cap per request
        'connect_timeout' => 3,   // TCP connect timeout
      ],
    Reduces blast radius from 90s worker hold to 5s. Combined with the
    Round 2 SRE-021 fix (DispatchDomainEventsJob timeout=30s), one slow
    Pusher request fails and retries via the queue rather than burning a
    worker slot. ~5 LOC + smoke test.
```

---

## Cross-cutting SRE verdict

**ON-CALL READINESS for Pusher channel ops: NOT V1-READY without SRE-024/025/026.**

The Pusher surface inherits Round 1's "data exists, page never fires"
antipattern (SRE-024) and adds two NEW blind spots:

1. **SRE-024** — Channel auth failures are server-silent. ACL changes,
   token revocations, and branch reorganizations cause silent KDS
   blackouts with no SRE visibility.
2. **SRE-025** — No Pusher Cloud DR runbook + no message-budget telemetry.
   A 2h outage costs ~300 silent-failed outbox rows; a SaaS scale spike
   blows the Pusher bill blind.
3. **SRE-026** — Token rotation between login and first 403 leaves a
   silent stale-token window. V1 single-branch immune; V2 SaaS critical.

**Healed gaps re-verified clean (no regression)**:
- Round 1 SRE-009 (boot guard for BROADCAST_DRIVER / QUEUE_CONNECTION)
  has been remediated in `AppServiceProvider::boot()` lines 112-123.
- `/api/health/ready` (`HealthController.php:39-60`, `:181-209`) blocks
  load balancer rotation when `broadcast_config.status=error` — solid.
- Round 1 SRE-007 (polling 30s vs 5s) PARTIALLY healed: KDS/OSS now at
  3-10s tightened cadence (`config/catalog_v15.php`), but POS catalog
  remained at 30s — re-flagged as SRE-029 with a smaller fix.

**Still-open Round 1 gaps confirmed unhealed**:
- Round 1 SRE-001 — `ws:heartbeat` cache key STILL never written
  (4 hits, all reads). Mission-anchor verification confirmed.
- Round 1 SRE-002 — Outbox latency SLO STILL not in SLO_TARGETS.

**Cost & capacity blind spots** (V1 → V2 SaaS gap):
- No Pusher tier documented in `docs/` or `.env.example`.
- No `MonitorPusherMessageBudgetCommand` — fleet message volume invisible
  until invoice arrives.
- Single eu cluster → all SaaS franchises share a single regional SPOF.

**Strongest single quick-win**: SRE-031 — adding `timeout=5` + `connect_timeout=3` to `config/broadcasting.php:62-64` is ~5 LOC and prevents one of the highest-blast-radius failure modes (90s worker hostage per slow Pusher request). Combined with Round 2 SRE-021's job-timeout shrink, the fleet absorbs Pusher slowdowns gracefully.

**Estimated wall-clock to V1-ready**:
- SRE-024 / SRE-026 / SRE-031 (P0 + quick-win): ~6h dev + 2h ops verification.
- SRE-025 (DR runbook + replay command + budget monitor): ~8h dev + runbook write.
- SRE-027 / SRE-028 / SRE-029 (P1 hardening): ~6h dev across the bundle.
- SRE-030 (V2 SaaS multi-region): backlog V2 architecture cycle, NOT V1.

**V2 SaaS architectural debt accumulated**:
- Single per-branch channel (Round 2 SRE-018) + single eu cluster (SRE-030)
  + no tier-aware budget monitor (SRE-025) = the Pusher surface is V1
  shape, not SaaS shape. Pre-scaling refactor estimated ~3 weeks engineering
  effort before onboarding a second branch in a different geo.

End of audit. ~1880 words.
