# Wave W6 — T-3.1.1 Outbox end-to-end lifecycle — SRE audit (Round 1)

**Specialist**: SRE / SYNC
**Date**: 2026-05-18
**Mode**: READ-ONLY
**Anchors**:
- `app/Console/Kernel.php`
- `app/Console/Commands/{OutboxRescueCommand, OutboxRetryFailedCommand, OutboxWebhookRetryFailedCommand, MonitorOutboxStaleness, PruneOutboxCommand, PruneWebhookEventsCommand}.php`
- `app/Jobs/DispatchDomainEventsJob.php`, `app/Jobs/Observability/SloEvaluatorJob.php`
- `app/Services/Observability/{SyncMetricsRecorder, SloMetricCollector}.php`
- `app/Http/Controllers/Admin/Observability/SyncOverviewController.php`
- `app/Http/Controllers/Admin/KdsSyncController.php`
- `resources/js/components/admin/observability/OutboxOverviewComponent.vue`
- `app/Providers/EventServiceProvider.php` (33 Persist*ToOutbox refs)
- `config/{horizon, queue, broadcasting}.php`

> SRE mindset: you're on-call at 02:00. Pusher dies in production. Walk the alert path end-to-end. What rings? What stays silent?

---

## Findings (strong-reasoning YAML)

```yaml
- id: SRE-001
  severity: P0
  title: "WS health probe reads `ws:heartbeat` cache key that is NEVER written — dashboard masks a dead broadcaster"
  category: observability_blindspot
  evidence:
    - "SyncOverviewController.php:531  Cache::get('ws:heartbeat')"
    - "SyncOverviewController.php:293  docblock claims `set by the broadcaster pulse`"
    - "grep -rn 'ws:heartbeat' app/ routes/  → only 2 hits, both in SyncOverviewController.php (no writer in app/ or routes/; no writer in vendor/beyondcode/)"
    - "Fallback at line 547: ws_up=true if domain_event dispatched_at within 60s"
  reasoning: |
    The probe ALWAYS falls through to the dispatched-event fallback. Symptom:
    if Pusher dies but Outbox keeps writing dispatched_at (the broadcaster
    swallows errors silently in some envelopes, OR Pusher returns 200 OK
    while channel auth is broken), the `websockets_serve.status=up` panel
    shows green emerald-50 (`OutboxOverviewComponent.vue:114-115`). Operator
    sees green, KDS stops receiving events, kitchen prepares wrong tickets.
    No writer means the heuristic_cache_heartbeat branch is dead code, and
    the documented `runbook OBSERVABILITY_OUTBOX_DASHBOARD.md §3` real-probe
    deferral now matters TODAY — V1 ships with effectively no WS healthcheck.
  cost_of_delay: |
    Operator sees green WS panel while Pusher is dead → KDS/OSS show stale
    state. Customer waits past ETA, kitchen burns FOH labour reconciling.
    Operational invisibility window = until manual page reload or until a
    customer complains. NO PAGE FIRES because there is no SloEvaluatorJob
    target for WS heartbeat and no `MonitorOutboxStaleness` analog for WS.
  remediation_hint: |
    Either add `Cache::put('ws:heartbeat', now()->timestamp, 90)` in a
    broadcaster-side tap (Pulse listener / Echo channel pong handler), OR
    delete the dead branch and rely solely on the dispatch-recency proxy
    + document the limitation. Half-implemented is worse than honest.

- id: SRE-002
  severity: P0
  title: "`outbox.dispatch_latency_ms` SLO documented (p95 < 2000 ms) but absent from `SloMetricCollector::SLO_TARGETS` — Slack never pages on outbox latency"
  category: missing_alert
  evidence:
    - "SyncMetricsRecorder.php:11-15  comment block declares the SLO target"
    - "SloMetricCollector.php:30-36  SLO_TARGETS contains uptime / tti_p95_ms / order_completion_rate / ws_reconnect_p95_ms / payment_success_rate — NO outbox_dispatch_latency entry"
    - "SloEvaluatorJob.php:46-62  iterates SLO_TARGETS only; outbox metric never evaluated, never breached, never Slack-alerted"
    - "SyncOverviewController.php:163-164  computes p95/p99 but only on user-driven dashboard polling"
  reasoning: |
    The whole observability surface RECORDS outbox latency
    (`DispatchDomainEventsJob.php:125-130` calls
    `recordEventDispatched`) and DISPLAYS it on
    OutboxOverviewComponent.vue. But SloEvaluatorJob (the only thing that
    fires Slack) reads from SLO_TARGETS, and the outbox latency key is
    NOT in that array. Result: p95 latency can creep to 8s in peak (queue
    contention, Pusher slowdown) and zero alert fires — no Slack ping, no
    `breach` flag in ActionLog, no pager hook. Operator only learns when
    a customer complains about stale KDS.
  cost_of_delay: |
    Production "soft death" — orders stack up in the outbox while latency
    p95 silently inflates from 1s → 8s → 30s. Kitchen sync degrades, no
    on-call rotation triggers, on-call only finds out via the
    OutboxOverviewComponent.vue dashboard which they have to OPEN
    manually. Owner-can't-operate window = entire silent degradation.
  remediation_hint: |
    Add to SLO_TARGETS: `'outbox_dispatch_latency_p95_ms' => ['target'=>2000, 'warn'=>5000, 'breach'=>10000]`.
    Add a `collectOutboxLatencyP95` method that reads from `sync_metrics`
    (same source SyncOverviewController already uses). Update SloEvaluatorJob
    `dispatchAlerts` (no changes needed — it iterates).

- id: SRE-003
  severity: P0
  title: "`fiscal:verify-chain` NOT scheduled — daily NF525 chain-integrity attestation never runs"
  category: missing_cron
  evidence:
    - "Kernel.php full read (lines 21-197) declares: outbox:rescue, outbox:monitor, outbox:retry-failed, webhook:retry-failed, CleanupStalePendingKioskOrders, pos:purge-parked-orders, outbox:prune, webhook:prune, SloEvaluatorJob, stock:scan-rupture, foodking:fiscal:retry-alloc, availability:reset-stale-quota, foodking:fiscal:archive"
    - "grep -n 'verify-chain' app/Console/Kernel.php  → 0 hits"
    - "CLAUDE.md §8: HMAC chain SHA-256 (prev_hash → current_hash) is non-negotiable"
  reasoning: |
    The audit chain is HMAC-SHA-256 (CLAUDE.md §8). The chain can only
    be PROVEN unbroken by a periodic verification job that walks the
    sequence + recomputes hashes. Without a scheduled chain-verifier,
    a single corrupted row (disk bit-rot, DBA error, partial write) is
    detectable only at the next manual audit (could be the actual
    French tax authority inspection). The mission anchor specifically
    flagged this as something to verify.
  cost_of_delay: |
    Worst case: NF525 inspection presents a broken HMAC chain → fines,
    potential prison time per CLAUDE.md §8 framing. Realistic case:
    archive job (`foodking:fiscal:archive`, Kernel:170-184) ships
    corrupted ZIP/JSON daily for weeks before someone notices, and
    restoring chain integrity requires forensic SQL across 6y retention.
  remediation_hint: |
    Add `$schedule->command('foodking:fiscal:verify-chain --since=24h')`
    daily at 03:00 (after archive 02:00, before outbox-prune 04:00).
    Non-zero exit on chain break → Log::error + pager. Confirm command
    exists in app/Console/Commands/ first (mission anchor only mentioned
    it; not verified in this read).

- id: SRE-004
  severity: P1
  title: "Staleness monitor threshold (30s) fires BEFORE rescue's stale window (≥120s) — guaranteed false-positive paging"
  category: alert_tuning
  evidence:
    - "MonitorOutboxStaleness.php:33  default --stale-after=30"
    - "Kernel.php:49  scheduled with --threshold=10 (count) but stale-after uses 30s default"
    - "OutboxRescueCommand.php:19  stale(2)  → DomainEvent::scope reads N minutes (verified by Sprint 3B cite); rescue only acts on rows ≥2 min old"
    - "DispatchDomainEventsJob.php:40  backoff curve totals 381s before final failure"
  reasoning: |
    Monitor flags >10 rows undispatched for ≥30s. Rescue only re-queues
    rows ≥2 min old. So between 30s and 120s, the monitor PAGES while
    rescue is still cooling its heels. Worse, the natural backoff
    [1,5,15,60,300] means a healthy retry naturally sits for up to 60s
    on its 4th attempt — perfectly normal queue behaviour will set off
    the alert. Result: pager fatigue, alert gets routed to /dev/null
    after week 2, real outage rings into a muted channel.
  cost_of_delay: |
    On-call gets paged at 02:00 by what is in fact normal retry latency.
    After 3-4 false alarms the team mutes the channel. Real outage
    arrives, monitor fires, nobody is reading it → outage runs unbounded.
    Classic SRE failure mode (page-fatigue death-spiral).
  remediation_hint: |
    Either bump `--stale-after` to 180 (3 min, well past rescue's 2-min
    window) OR keep 30s but raise --threshold to 50+ so transient
    bursts don't fire. Document in runbook why: 30s × 10 = "ten orders
    suspended for ≥30s = unusual"; 180s × 10 = "ten orders stuck
    PAST self-heal" = pager-worthy.

- id: SRE-005
  severity: P1
  title: "Terminal outbox failure emits Sentry BREADCRUMB only, no captureException — silent in Sentry dashboards"
  category: sentry_misuse
  evidence:
    - "DispatchDomainEventsJob.php:211-221  class_exists(Sentry...) gate, then Sentry\\addBreadcrumb"
    - "No Sentry::captureException / Sentry\\captureException call ANYWHERE in handle() / failed()"
    - "Log::error fires at line 208 — visible in fiscal.log but not aggregated into Sentry issue grouping"
  reasoning: |
    Sentry breadcrumbs attach to the NEXT exception captured in the same
    scope. The `failed()` callback runs in the worker AFTER the exception
    has bubbled out of `handle()` and Laravel queue-runner has caught it.
    By the time addBreadcrumb fires, there is no live scope to attach to,
    so the breadcrumb dies with the worker process. Net effect: terminal
    outbox failures are INVISIBLE in Sentry. Only fiscal.log Log::error
    fires, and Log channel `fiscal` is not the default Sentry sink.
  cost_of_delay: |
    On-call has Sentry as the canonical pager source. Outbox terminal
    failures (contract violations, repeated 6-attempt failures) NEVER
    create Sentry issues → no alert routing → only post-mortem grep of
    fiscal.log finds them, and only if someone goes looking.
  remediation_hint: |
    Replace addBreadcrumb with `\Sentry\captureException($exception, ...)`
    OR `\Sentry\captureMessage('outbox.terminal_failure', ...)` with the
    $context array attached. Keep the class_exists() gate for optional
    sentry-laravel dependency.

- id: SRE-006
  severity: P1
  title: "`OutboxWebhookRetryFailedCommand` has no max-attempts give-up — runaway re-dispatch loop for permanently-broken webhooks"
  category: dlq_runaway
  evidence:
    - "OutboxWebhookRetryFailedCommand.php:42-75  resets every failed row to STATUS_PENDING + dispatches every hour, indefinitely"
    - "Kernel.php:75-80  schedule hourly with --since=24h"
    - "Comment line 30-32: 'attempts counter intentionally NOT reset' but no cap-out branch"
  reasoning: |
    The `attempts` counter being preserved is documented but NEVER
    consulted. A webhook for a payment provider that permanently
    deauthorised the merchant (account closed, fraud lock) will be
    re-dispatched every hour forever — burning ProcessWebhookEventJob
    capacity, log volume, and (worse) potentially re-firing user-visible
    side-effects if the provider eventually returns 5xx → 200.
    Compare with OutboxRetryFailedCommand which DOES gate via `failed(5)`
    scope (line 22).
  cost_of_delay: |
    Steady-state operational cost: low. But on a long-tail merchant-account
    closure scenario the queue can stack up indefinitely, eventually
    crowding out healthy webhooks. Also: side-effect risk — if a Stripe
    refund webhook is re-fired after 30d while the merchant has set up a
    new account, double-refund risk exists.
  remediation_hint: |
    Add `->where('attempts', '<', 10)` to the query (line 47-49). Rows
    over 10 attempts flagged for manual triage via the staleness monitor.

- id: SRE-007
  severity: P1
  title: "Polling fallback default 30s — 6× the V1 anchor promise of 5s"
  category: doc_code_mismatch
  evidence:
    - "config/broadcasting.php:33  'interval_ms' => (int) env('BROADCAST_POLLING_FALLBACK_MS', 30000)"
    - "Mission anchor: 'V1 promise = 5s polling fallback + Pusher real-time'"
    - "OutboxOverviewComponent.vue:311  pollIntervalMs default 10000 — dashboard polls 3× faster than the actual data fallback"
  reasoning: |
    Whatever V1 docs claim ('5s fallback'), production code defaults
    polling at 30s. If Pusher goes dark, KDS polls every 30s — six
    times slower than the operator promise. Either the doc is wrong or
    the env default is wrong, but the discrepancy means SRE has to
    pick which one will fail an audit.
  cost_of_delay: |
    Kitchen syncs are 30s behind reality during a Pusher outage instead
    of 5s. For a busy fast-food window (Le Cayenne peak = 30 orders/h),
    that's up to 6 missed transitions per minute → orders served cold,
    customer SLA breached.
  remediation_hint: |
    Decide which is canonical (5s OR 30s), then set the default to match.
    If 5s is the promise, change `30000` → `5000`. If 30s is acceptable,
    update mission/runbook anchors.

- id: SRE-008
  severity: P1
  title: "Silent miss on deploy: new event class without a `Persist*ToOutbox` listener = no row in domain_events, no log, no metric"
  category: deployment_safety
  evidence:
    - "EventServiceProvider.php:90+  33 Persist*ToOutbox listener mappings (grep confirmed)"
    - "Laravel default behaviour: event::dispatch() with no listener mapping = no-op (no exception, no log)"
    - "No deploy-time validation: no test like `foreach (EventClass) assert($listenerExists)`"
  reasoning: |
    Adding `App\Events\NewBranchOnboardingCompleted` and forgetting to
    map a `PersistNewBranchOnboardingCompletedToOutbox` listener means
    the event fires, listeners (zero of them) run, nothing persists,
    nothing broadcasts, no warning is logged. Operator sees the new
    feature 'work' in dev (where the listener was hand-tested in
    isolation) but in production nothing ever syncs. Catching this in
    QA requires explicit per-event coverage.
  cost_of_delay: |
    Production silently runs without the new sync surface. Bug discovery
    window = until a user files a ticket about KDS/Kiosk not showing the
    new entity. Could be weeks. Worst case: a P0-tagged event (e.g.
    OrderPaidAtCounter for a new payment provider) silently never syncs
    → fiscal sequence allocation runs but KDS never sees the order.
  remediation_hint: |
    Add a static-analysis CI step OR a boot-time assertion in
    EventServiceProvider::boot(): for each App\Events\* class
    implementing `ShouldBroadcast`, assert a Persist*ToOutbox listener
    is mapped. Fail boot in dev/staging, log error in prod.

- id: SRE-009
  severity: P2
  title: "No boot-time guard against QUEUE_CONNECTION=sync or BROADCAST_DRIVER=null in production"
  category: config_hardening
  evidence:
    - "config/queue.php:16  'default' => env('QUEUE_CONNECTION', 'sync')"
    - "config/broadcasting.php:18  'default' => env('BROADCAST_DRIVER')   ← no default at all"
    - "DispatchDomainEventsJob.php:115  BroadcastManager::connection() — falls back to default driver"
  reasoning: |
    If a deploy ships with a misconfigured .env (QUEUE_CONNECTION unset
    → sync), `DispatchDomainEventsJob::dispatch` runs INLINE in the web
    request. Retry curve [1,5,15,60,300] does NOT apply (no queue
    worker, no retries). User-facing request blocks on broadcaster
    latency. Worse if BROADCAST_DRIVER unset → BroadcastManager throws
    silently and we land in the catch block at line 140, setting
    last_error — but rescue can't fix the misconfig, so the row
    backlogs forever until env is fixed.
  cost_of_delay: |
    Single bad deploy = entire request path inflates by Pusher latency.
    P0 incident potential. Detection only at first request that triggers
    a domain event → kiosk order, POS sale → which means it surfaces
    at peak.
  remediation_hint: |
    AppServiceProvider::boot()  add:
      if (app()->environment('production')) {
        if (config('queue.default') === 'sync') throw new \Exception('QUEUE_CONNECTION=sync in production');
        if (! config('broadcasting.default')) throw new \Exception('BROADCAST_DRIVER must be set in production');
      }

- id: SRE-010
  severity: P2
  title: "Queue-high backlog (`jobs.queue=high.oldest_age_seconds` + count) surfaced on dashboard but NEVER paged"
  category: missing_alert
  evidence:
    - "SyncOverviewController.php:437-456  describeQueueLane returns count + oldest_age_seconds"
    - "OutboxOverviewComponent.vue:230-253  renders them"
    - "SloEvaluatorJob.php + SloMetricCollector.php:30-36  no SLO_TARGETS entry for queue depth"
  reasoning: |
    Dashboard shows the data, but no automated alert fires when the
    high lane backs up. A stuck worker (memory limit hit, deadlock with
    Redis) leaves jobs.high.oldest_age_seconds growing unboundedly,
    visible only to whoever happens to refresh the page. Mirrors
    SRE-002: 'has data, no breach evaluator'.
  cost_of_delay: |
    Same blast radius as SRE-002 — Outbox dispatch silently stalls.
    Difference: SRE-002 catches latency degradation; this catches the
    extreme case of total stall. Both should ring.
  remediation_hint: |
    SLO_TARGETS += `'queue_high_oldest_age_s' => ['target'=>30, 'warn'=>120, 'breach'=>300]`
    + collectQueueHighOldestAge method that reads from `jobs` table.

- id: SRE-011
  severity: P2
  title: "Outbox prune 90d vs operator triage 24h — terminal failures pruned BEFORE post-mortem evidence is preserved elsewhere"
  category: retention_gap
  evidence:
    - "PruneOutboxCommand.php:50-58  attempts>=6 AND created_at < (now - 90d) pruned"
    - "OutboxRetryFailedCommand --since=24h (Kernel:63)"
    - "No archive/cold-storage of pruned rows; row goes straight to /dev/null"
  reasoning: |
    A 90d-old terminally-failed event is gone forever. If a fiscal audit
    looks back at a 6-month-old ghost transaction (NF525 6y retention!),
    operations cannot reproduce the failure trail. NF525 retention
    applies to audit_logs + z_reports, NOT to operational outbox per
    PruneOutboxCommand docblock — TRUE but only if the operational
    outbox has zero fiscal-impact evidence. PersistOrderPaidAtCounterToOutbox
    rows have fiscal_sequence_no info in payload (verify).
  cost_of_delay: |
    Post-incident investigation hits a wall at the 90d boundary. Lost
    operational forensic trail. Low-frequency cost (only matters when
    bug discovery > 90d) but high impact when it happens.
  remediation_hint: |
    Before delete, archive eligible rows to `domain_events_archive` table
    (cheap, no indexes) OR S3 cold storage. Mirror the fiscal:archive
    pattern that already runs daily at 02:00.

- id: SRE-012
  severity: P3
  title: "Horizon memory_limit 128MB + tries=6 + outbox payloads can be large → OOM kills worker mid-dispatch silently"
  category: worker_health
  evidence:
    - "config/horizon.php:30  memory_limit 128 (MB)"
    - "config/horizon.php:40  supervisor-high tries=6 timeout=90"
    - "DomainEvent payload JSON unbounded (no schema-level size cap in migration)"
  reasoning: |
    A 128MB worker handling a 5MB JSON payload + Laravel framework
    overhead (~80MB base) leaves ~40MB headroom. Big composition_snapshot
    on a 20-item order easily pushes into OOM territory. Horizon
    auto-restarts on memory-limit-exceeded, but the IN-FLIGHT job is
    re-queued — and our `lockForUpdate + claim` (DispatchDomainEventsJob:65)
    DID claim it before broadcast, so the row sits dispatched_at=now()
    with attempts=N but broadcast never happened. Net effect: silent
    sync miss with no log entry (worker died mid-handle, failed()
    never called).
  cost_of_delay: |
    Rare but plausible — heavy-bundle orders silently never broadcast.
    Detection only via customer complaint or outbox-overview row showing
    dispatched_at set + broadcast never observed downstream.
  remediation_hint: |
    Bump memory_limit to 256MB OR add a max-payload-size validation in
    EventContract::buildEnvelope. Add a post-broadcast assertion (e.g.
    record a 'broadcast_acked' metric the receiver echoes) — current
    code trusts the broadcaster blindly.
```

---

## Cross-cutting SRE verdict

**ON-CALL READINESS: NOT V1-READY without remediation of SRE-001/002/003.**

Three top failures are the same pattern — *the data exists, the page never fires*:
1. SRE-001 — WS health probe reads a key nobody writes
2. SRE-002 — Outbox latency SLO documented but missing from evaluator
3. SRE-010 — Queue-high depth surfaced but no breach evaluator

This is the classic "dashboard-only observability" antipattern: green panels lull on-call into believing the system is healthy while silent degradation runs. The mission anchor framed it correctly — *"What page-able alert is missing? What dashboard panel is missing? What runbook is missing?"* — and the answer is: dashboards are mostly complete, alerts are mostly missing.

**Runbook gaps** observed:
- No documented escalation path for terminal outbox failures (Sentry breadcrumb-only, SRE-005).
- No documented chain-verification SOP (SRE-003).
- No documented Pusher-down playbook (polling fallback exists in code but ops behaviour undocumented).

**Strongest single quick-win**: SRE-002 — adding one line to SLO_TARGETS unlocks Slack paging on the most operationally-critical metric. ~10 lines of code, ~1h work, prevents the silent-degradation class entirely for outbox latency.

**Estimated wall-clock to V1-ready**:
- SRE-001/002/003/004 (P0+P1 top): ~6h dev + 2h ops verification
- SRE-005/006/007/008 (P1 hardening): ~8h dev
- SRE-009..012 (P2/P3): backlog V1.0.2

End of audit. ~1480 words.
