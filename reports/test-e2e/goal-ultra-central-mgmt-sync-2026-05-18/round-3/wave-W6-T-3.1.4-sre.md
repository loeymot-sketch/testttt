# Wave W6 — T-3.1.4 Outbox 10k-events production simulation — SRE audit (Round 3)

**Specialist**: SRE / Ops
**Date**: 2026-05-18
**Mode**: READ-ONLY
**Anchors**:
- `tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php` (5 tests, all unit-scale: 1-4 events each — file is misnamed, not a 10k simulation)
- `tests/load/RushMidiSimulationTest.php` (S7.5 — 12 outbox events, sync queue, broadcast skip path)
- `app/Jobs/DispatchDomainEventsJob.php` (claim → broadcast → finalize, $tries=6 / $backoff=[1,5,15,60,300])
- `app/Console/Commands/E2EStressCommand.php` (Guzzle Pool, owner-driven, --orders default 50, no Outbox export)
- `app/Console/Commands/{OutboxRescueCommand, OutboxRetryFailedCommand, MonitorOutboxStaleness}.php`
- `app/Services/Observability/{SyncMetricsRecorder, SloMetricCollector}.php`
- `app/Jobs/Observability/SloEvaluatorJob.php` + `app/Console/Kernel.php`
- `phpunit.xml` (sqlite-memory + sync queue + log broadcaster + empty PUSHER_*)

> SRE mindset: at 12:00 sharp the rush hits. POS + Kiosk fire orders, every
> commit emits domain events, the queue worker chews them through Pusher, the
> KDS receives them, the cashier punches another order. If ANY link in that
> chain stalls 5 minutes, the kitchen prints duplicate tickets or skips
> orders entirely. What does the test prove? What does it NOT prove?

---

## Findings (strong-reasoning YAML)

```yaml
- id: SRE-T314-001
  severity: P0
  title: "Test file misnamed: 'ProductionLikeSimulation' covers 1-4 events, NOT a 10k-events production simulation — the task name in the GOAL has no implementation"
  category: false_advertising_test_coverage
  evidence:
    - "tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php — 5 test methods, each handles ≤4 domain events (rescue + retry-failed + broadcast-failure + global-catalog-fanout + contract-violation)"
    - "tests/load/RushMidiSimulationTest.php::test_S75 (the closest cousin) handles 12 events with sync queue + null channel (skips broadcaster entirely)"
    - "Repo-wide grep `10000|10_000|stress.*10k` returns ZERO hits in any outbox test"
    - "E2EStressCommand defaults --orders=50, max documented example is --orders=200 — none touch the outbox volume axis directly (orders, not events; outbox checked post-run only via outbox_stale_30s count)"
  reasoning: |
    The GOAL ledger names this task "Outbox 10k-events production simulation"
    and the audit anchors point to `OutboxProductionLikeSimulationTest.php`
    as if it implements that scope. Reading the file: it is a unit-level
    behaviour spec for the rescue/retry-failed/broadcast-failure paths. The
    five tests collectively create 11 DomainEvent rows and only one of them
    actually invokes `DispatchDomainEventsJob::handle()` more than once
    (the fanout test, twice). This is correctness coverage, not load
    simulation. The 10k events promise is therefore aspirational, not
    implemented. Any reviewer skimming the GOAL who sees "production-like"
    in the filename will assume soak coverage exists. It does not.
  cost_of_delay: |
    The OutboxPipelineHealthSentinel + RushMidiSimulation already give us
    "events get delivered". What we have NEVER measured: dispatch latency
    p95 under 200 concurrent inflight events, claim contention rate when
    two workers race the same row, attempts distribution under
    Pusher-flapping conditions, table growth versus prune cadence. The
    first time we discover one of these is wrong will be in prod at noon.
  remediation_hint: |
    Either (a) rename the file to OutboxRescueAndContractDispatchTest.php
    to match its actual scope, OR (b) add a `@group outbox-soak` test that
    seeds 1000-10000 DomainEvent rows and drives DispatchDomainEventsJob
    through them synchronously, asserting: zero `last_error`, zero
    `attempts > 1`, p95 of (now() - occurred_at) at handle return < a
    declared budget (e.g. 500ms in sqlite-memory ≠ prod but catches
    regressions). Keep the soak under the @group flag so CI skips it by
    default; tag it pre-merge.

- id: SRE-T314-002
  severity: P0
  title: "CI simulation diverges from production on 4 axes simultaneously (SQLite vs MySQL, sync vs Redis queue, log vs Pusher broadcaster, array vs Redis cache) — `lockForUpdate` is a no-op, rendering the dedupe invariant unprovable in CI"
  category: ci_vs_prod_drift
  evidence:
    - "phpunit.xml lines 37-43: DB_CONNECTION=sqlite, DB_DATABASE=:memory:, QUEUE_CONNECTION=sync, BROADCAST_DRIVER=log, CACHE_DRIVER=array, PUSHER_APP_KEY/SECRET/ID all empty"
    - "OutboxConcurrentWorkerDedupeTest.php lines 24-32 explicitly disclaims: 'SQLite (CI test backend) treats lockForUpdate() as a no-op — Laravel does not emit SELECT ... FOR UPDATE outside MySQL/PostgreSQL. The tests below therefore exercise the post-claim idempotence path […], not the true concurrent-row-lock path.'"
    - "Round 1 SRE-001 already flagged ws:heartbeat cache key never written (Redis-only behaviour); this finding adds: the entire dispatch claim path also uses cache-less SQLite locks in CI"
    - "Production Outbox runs on MySQL (FOR UPDATE), Redis (queue:high lane), Pusher/Soketi (real WebSocket), Redis cache (Cache::lock on idempotency + retry-failed)"
  reasoning: |
    The claim transaction in DispatchDomainEventsJob::handle() (lines 65-86)
    is the load-bearing dedupe guard: two workers picking up the same
    DomainEvent id MUST race on `lockForUpdate`, the loser observes
    `dispatched_at != null` after acquiring the lock, and skips. In SQLite
    there is no `FOR UPDATE`. The lock-update sequence becomes "two
    serialised reads, two serialised writes". The test passes — but it
    proves serialisation works, not contention works. If a future commit
    accidentally drops the `lockForUpdate()` call, CI still passes. The
    duplicate broadcast would only surface in production under simultaneous
    workers. Combined with the queue=sync setting (no background worker at
    all in CI), we have NEVER exercised the actual queue retry path with
    the real backoff timer either — the $backoff=[1,5,15,60,300] curve
    documented in the job header is asserted by static reading only.
  cost_of_delay: |
    On the first production day the 10x deploy pushes a refactor that, say,
    introduces a regression to the claim transaction. CI green. Production
    midi rush: two queue workers (Horizon default supervisor=2) start
    racing on a single popular event (catalog-wide fanout, all branches).
    Each broadcasts the same envelope. KDS receives twice → either dedupes
    (good) or double-bumps (kitchen prep wasted). NF525 is not touched
    here, but customer-facing reliability is.
  remediation_hint: |
    Add a docker-compose-based MySQL + Redis CI variant gated to a single
    `@group mysql-integration` suite (run only on PRs touching
    Outbox/Sync/Queue files). Set up via `make ci-mysql` or a separate
    workflow. Minimum: spin MySQL 8.0 + Redis 7, ensure DB_CONNECTION=mysql
    + QUEUE_CONNECTION=redis + CACHE_DRIVER=redis. Re-run the dedupe
    + concurrent-worker tests against this. The OutboxConcurrentWorkerDedupeTest
    header note "tracked in plans/MEGA_PLAN_SYNC_HARDENING_v3 (Phase 3
    dette technique)" is a placeholder — close it.

- id: SRE-T314-003
  severity: P1
  title: "10k-events/60s budget is unjustified for V1 Le Cayenne (200-400 orders/day → ~1 event/sec sustained), and would be wildly under-provisioned for V2 SaaS (5000 orders/day × 20 branches across multi-event order create = ~10-20 events/sec peak)"
  category: budget_dimensioning_unmotivated
  evidence:
    - "BRAIN.md / GOAL ledger never derives the 10k figure from order volume; it appears to be a round number"
    - "1 POS order create emits ≥1 OrderCreated event; 1 catalog update emits 1 CatalogChanged event PER active branch (fanout, see OutboxProductionLikeSimulationTest::test_global_catalog_event_fans_out_*); kiosk paid emits OrderCreated + PaymentConfirmed → 2 events"
    - "Le Cayenne V1 = 1 branch, 200-400 orders/day. Even with 4 events/order = 1600 events/day = 0.02 events/sec average, peak rush ratio ~5x = 0.1 events/sec"
    - "V2 hypothetical = 20 branches × 250 orders/day = 5000 orders/day, fanout amplifies catalog/availability events ×20"
  reasoning: |
    A simulation that hits 10k events per 60s exercises a throughput two
    orders of magnitude above V1 reality. That is fine as a stress ceiling
    (find the breakpoint), but reporting it as the V1 SLA proof is
    misleading. For V1, what matters is: at the 1-event/sec sustained load
    with a single Pusher provider, does dispatch_latency p95 stay
    < 2000ms? The simulation should be sized to match the operational
    target with a 5-10x safety margin, not 100x. For V2 SaaS, fanout is
    the multiplier that matters: a single MENU_ITEM_AVAILABILITY_CHANGED
    over 20 branches × N kiosks per branch can already shape the load
    curve in a single second. The 10k flat figure does not model fanout
    asymmetry at all.
  cost_of_delay: |
    Owner reads the GOAL, sees "10k events tested", believes outbox is
    over-provisioned for V1 → defers MySQL/Redis CI lane (SRE-T314-002)
    → real prod regression sneaks in. Or: V2 sales conversation, customer
    asks "how do you scale to 100 branches" → answer cannot point to
    measured limit, only to a synthetic 10k that was never realistic.
  remediation_hint: |
    Replace the 10k-flat target with a 3-tier matrix in the GOAL ledger:
      - L1 (V1 sustained): 0.1 evt/s × 600s = 60 events, p95 dispatch < 500ms
      - L2 (V1 peak rush): 2 evt/s × 60s = 120 events, p95 < 2000ms
      - L3 (V2 fanout stress): 50 evt/s × 60s = 3000 events, p95 < 5000ms,
        zero dropped
    Implement L1+L2 as PHPUnit @group outbox-soak. L3 stays in the artisan
    command (foodking:e2e:stress) on dev server with real MySQL/Redis.

- id: SRE-T314-004
  severity: P0
  title: "Simulation does not export SLO metrics — only asserts 'dispatched_at NOT NULL'. The outbox.dispatch_latency_ms metric is recorded but never aggregated nor evaluated by SloEvaluatorJob (it lives in sync_metrics, never read)"
  category: slo_unwired
  evidence:
    - "OutboxProductionLikeSimulationTest assertions are all structural: assertCount, dispatched_at not null, last_error null, attempts == N. ZERO latency assertion."
    - "RushMidiSimulationTest::test_S75 line 489-493 explicit comment: 'In sync queue dispatch is inline (millis), so the structural assertion (NOT NULL + attempts=1) is the equivalent under sqlite-memory. Real timing is measured by the artisan command on dev server.'"
    - "SyncMetricsRecorder writes `outbox.dispatch_latency_ms` to sync_metrics table (line 54-63). SyncOverviewController reads it via /admin/observability/sync-overview (lines 162-164: p50/p95/p99)."
    - "SloMetricCollector::SLO_TARGETS (lines 30-36) enumerates 5 keys: uptime, tti_p95_ms, order_completion_rate, ws_reconnect_p95_ms, payment_success_rate. NO outbox key. SloEvaluatorJob (lines 51-62) iterates these 5 and never touches sync_metrics."
    - "Round 1 SRE-002 P0 already flagged this exact gap (outbox.dispatch_latency_ms SLO not in SLO_TARGETS) — round 3 confirms unhealed"
  reasoning: |
    The whole pyramid is built: SyncMetricsRecorder writes a latency row
    per dispatched event, with branch + event_type labels, into a
    well-indexed sync_metrics table. The admin observability dashboard
    reads p50/p95/p99. But the **automated alerting** layer
    (SloEvaluatorJob → ActionLog slo_evaluation → Slack breach alert)
    never asks "is outbox dispatch p95 within budget?". So a worker that
    keeps dispatching events but at 8s latency (Pusher degraded mode)
    will: produce green tests, produce green CI, render a yellow dashboard
    no-one watches, fire ZERO pager alerts. The kitchen experiences delays.
    The owner discovers the issue from a customer complaint. SLO unwired
    = production blind spot, regardless of how many events the simulation
    seeds.
  cost_of_delay: |
    Worst case: Pusher account hits its monthly message quota at 16:00,
    starts throttling. Every dispatch goes from 100ms to 7000ms. Events
    eventually deliver (no error, no last_error). The simulation would
    PASS. Production: KDS lag, kitchen prepares wrong orders, OSS screen
    shows stale state. Outage window = until ops happens to glance at the
    /admin/observability/sync-overview page.
  remediation_hint: |
    Add to SloMetricCollector::SLO_TARGETS:
      'outbox_dispatch_latency_p95_ms' => ['target'=>500, 'warn'=>2000, 'breach'=>5000],
      'outbox_event_failure_rate_5m'   => ['target'=>0.001,'warn'=>0.01, 'breach'=>0.05],
    Implement collectOutboxDispatchLatencyP95(branch, $windowMinutes=5) in
    SloMetricCollector — read sync_metrics WHERE metric_type='outbox.dispatch_latency_ms'
    AND branch_id IN (branch_id, NULL) AND occurred_at >= now()-5min, compute percentile.
    Then in SloEvaluatorJob::handle() include these 2 keys in the evaluate()
    payload. THEN soak tests can assert the percentile programmatically.

- id: SRE-T314-005
  severity: P0
  title: "Fault scenarios are partially covered (Pusher dead via Mockery) but worker SIGTERM mid-batch, DB connection saturation, and Redis cache eviction mid-claim are NEVER simulated"
  category: fault_injection_partial
  evidence:
    - "Covered: OutboxPipelineHealthSentinelTest lines 300-323 simulates 'soketi outage' via Mockery throw → dispatched_at released, last_error populated, retry path proven (single event)"
    - "Covered: OutboxProductionLikeSimulationTest::test_broadcast_failure_releases_claim_and_rescue_can_requeue_for_successful_retry — provider restart, single event, 2 phases"
    - "NOT covered (SIGTERM mid-batch): no test exercises `posix_kill` or `pcntl_signal` on a running queue worker mid-claim; the queue=sync setup forecloses this entirely"
    - "NOT covered (DB saturation): no `Mockery::mock(DB::class)->andThrow(QueryException 'connection refused')` test for the claim transaction"
    - "NOT covered (Redis eviction): no test where Cache::lock returns successfully but the key is evicted before release; production Redis under memory pressure (maxmemory-policy allkeys-lru) can do this"
    - "NOT covered (Pusher dead 5 minutes): existing tests fail the broadcaster ONCE; no test exercises the full $backoff=[1,5,15,60,300] curve with 6 consecutive failures landing the job in failed_jobs"
  reasoning: |
    Production reality is that all four faults happen, sometimes together.
    Worker SIGTERM is the most insidious: Kubernetes/systemd sends SIGTERM
    on rolling deploy. A worker mid-handle() will: (a) commit the claim
    transaction (lines 65-86), (b) be killed before broadcast(), (c)
    Laravel does not run failed() because it never threw. Result:
    `dispatched_at` is set, broadcast NEVER happened, event is forever
    lost — rescue's `scopePending()` filters out rows where
    `dispatched_at != null`. This is the same root cause class as
    the Sentinel test's assertion (line 328-334): "If dispatched_at stays
    set, scopePending() filters the row out and the rescue command can
    never re-queue it." We test the broadcaster-fails path; we do NOT test
    the worker-dies-after-claim-before-broadcast path. The fix exists in
    code (Phase 3b releases dispatched_at on broadcaster throw) but only
    if the throw happens; SIGTERM is silent.
  cost_of_delay: |
    Every Kubernetes rolling restart (weekly?) potentially loses 1-N
    domain_events. They never reach KDS, they never reach analytics, they
    never reach OSS. The kitchen sees the order from POS UI directly
    (degraded mode) but the KDS-driven workflow misses it.
  remediation_hint: |
    Three additions:
      (a) Add a graceful-shutdown trap in DispatchDomainEventsJob: register
          a `pcntl_signal(SIGTERM)` handler that, if invoked between Phase 1
          claim commit and Phase 2 broadcast, RELEASES the claim
          (dispatched_at=NULL, last_error='sigterm_pre_broadcast'). Laravel
          worker has `--max-time` + `--max-jobs` to bound exposure;
          combined with a signal trap, the window shrinks to microseconds.
      (b) Add a test that simulates SIGTERM by injecting a "broadcaster"
          that throws a custom AbortException AFTER mutating
          dispatched_at; assert the rescue command picks it up. This is the
          existing pattern in OutboxPipelineHealthSentinelTest extended.
      (c) Add fault-injection for QueryException on `lockForUpdate()` to
          prove the outer try/catch does not double-bump attempts.

- id: SRE-T314-006
  severity: P1
  title: "Exactly-once delivery hinges on lockForUpdate which is a no-op in SQLite (per Round 1 DBA finding) — replay correctness is unproven in CI under fault + recovery"
  category: replay_correctness_unprovable
  evidence:
    - "Same root as SRE-T314-002 (SQLite lockForUpdate no-op disclaimer from OutboxConcurrentWorkerDedupeTest line 26-32)"
    - "ReplayAudit + ListenerReplayDedupe tests exist (tests/Feature/Outbox/{OutboxReplayAuditTest, ListenerReplayDedupeTest}.php) but cover the listener side (consumers idempotently dedupe by event id), not the producer-claim side under concurrent workers"
    - "RetryFailedCommand line 35-52 wraps the whole batch in Cache::lock('outbox.retry-failed.lock', 60s) — cache=array in tests, true lock semantics also unproven"
  reasoning: |
    The "exactly once" guarantee in this design rests on two pillars:
    (a) producer side: lockForUpdate + dispatched_at gate prevents
    double broadcast from concurrent workers; (b) consumer side: listeners
    dedupe by event_id. Pillar (a) is unprovable in CI. Pillar (b) is
    tested but degrades to "duplicate broadcasts get filtered downstream"
    — not strictly the same. A duplicate broadcast still hits the WS
    layer, still costs Pusher message quota, still flickers KDS UIs.
  cost_of_delay: |
    Under prod load, duplicate broadcasts visible as "ghost order events"
    on KDS. Operationally annoying, not data-corrupting (consumer dedupe
    saves us). But it consumes Pusher quota at 2x rate → SRE-T314-004's
    Pusher throttle scenario becomes likelier.
  remediation_hint: |
    Per SRE-T314-002, gate the MySQL CI lane. Add one explicit test that
    spawns 2 PCNTL forks both invoking DispatchDomainEventsJob::handle()
    on the SAME event id, against MySQL, asserts exactly one broadcast
    and exactly one dispatched_at != null. Pattern: see
    plans/MEGA_PLAN_SYNC_HARDENING_v3 (Phase 3 dette technique).

- id: SRE-T314-007
  severity: P1
  title: "Performance regression detection is structural-only — a 20% slowdown in DispatchDomainEventsJob would NOT fail any existing test until catastrophic timeout"
  category: regression_blind
  evidence:
    - "All assertions in OutboxProductionLikeSimulationTest are equality/count/null-check — no timing constraint"
    - "RushMidiSimulationTest S7.5 line 488-493 explicit: 'structural assertion (NOT NULL + attempts=1) is the equivalent under sqlite-memory'"
    - "phpunit.xml line 34 memory_limit=512M, no test timeout config; default PHPUnit timeout 0 (off)"
    - "No CI workflow asserts a per-test wall-clock budget"
  reasoning: |
    A future commit adds, say, a sync API call in handle() (an
    AvailabilityService check, a Sentry breadcrumb, a metric over HTTP).
    Each event now takes 20-100ms instead of 1-5ms. Tests still pass. The
    regression is invisible until production p95 climbs over the 2000ms
    SLO — but we already established (SRE-T314-004) the SLO is not wired
    to alerts. Compound: silent regression slips past CI and past alerts,
    discovered by operators eyeballing dashboards.
  cost_of_delay: |
    Slow degradation: dispatch latency creeps from 200ms to 800ms over 3
    deploys, p95 hits 4000ms intermittently, KDS UX degrades visibly only
    during midi rush, owner blames the kitchen.
  remediation_hint: |
    In a new outbox-soak test (per SRE-T314-001 remediation), measure
    wall-clock for a 1000-event run. Assert `total_ms / 1000 < 50` as a
    cheap, machine-noise-tolerant regression gate. Adjust based on
    baseline. Tight enough to catch 20% slowdown, loose enough that CI
    runner variance doesn't flap.

- id: SRE-T314-008
  severity: P2
  title: "No production canary exists for Outbox at real scale — first prod load is the first prod test"
  category: canary_absent
  evidence:
    - "Searched the repo for 'canary', 'shadow_dispatch', 'percent_traffic' — no Outbox-related implementation"
    - "E2EStressCommand explicitly refuses APP_ENV=production (line 82-85). It is dev/staging only by design."
    - "AppServiceProvider production boot guard (Round 2 anchor) blocks dangerous flags but does NOT shape traffic"
  reasoning: |
    Le Cayenne V1 is a single-restaurant deployment — no V1 multi-branch
    SaaS, no real "1% traffic" canary makes sense at this size. For V2
    however, a SaaS rollout MUST have a canary lane. The Outbox is exactly
    the surface where a regression manifests as silent failures (no HTTP
    5xx visible to users, just delayed kitchen state). The current plan
    has no canary primitive.
  cost_of_delay: |
    V1: low — single tenant, owner is on-site, regressions are caught by
    direct kitchen observation. V2: high — multi-tenant, a regression
    deployed to all customers simultaneously is a global incident.
  remediation_hint: |
    Defer to V1.1+ : add a Feature\Flag `outbox.canary_branch_id`. When
    set, only that branch routes through a new code path. AppServiceProvider
    can fork DispatchDomainEventsJob based on the flag. Not required for
    V1 single-tenant.

- id: SRE-T314-009
  severity: P2
  title: "CI runtime is unmeasured for outbox tests — adding a 10k-event soak would balloon CI duration; no `@group` segregation yet"
  category: ci_throughput_unmonitored
  evidence:
    - "phpunit.xml has no per-test timeout config; OutboxProductionLikeSimulationTest tests are tiny (≤4 events each) and probably run in seconds"
    - "tests/load/RushMidiSimulationTest.php is tagged @group stress (line 65) but only 2 of 6 scenarios actually pass (S7.2 + S7.3 markTestIncomplete)"
    - "No `.github/workflows/*.yml` excerpt available in current scope; not asserted whether the stress group runs on PRs vs nightly"
  reasoning: |
    Adding a soak test of 10k events at sqlite-sync = best-case 10k iterations
    of (claim TX + sync broadcast + finalize) ≈ 2-5 minutes per CI run.
    Acceptable if gated to nightly. Catastrophic if run on every PR.
  remediation_hint: |
    Tag soak tests `@group outbox-soak` and segregate in CI: regular PRs
    run --exclude-group outbox-soak; nightly + pre-merge to main run the
    full suite. Document the boundary in phpunit.xml comment block.
```

---

## Cross-reference vs Round 1+2

| Round | Finding | Status in Round 3 |
|-------|---------|-------------------|
| R1 SRE-001 (P0) | ws:heartbeat cache key never written | NOT re-checked this audit (out of T-3.1.4 scope) — likely unchanged |
| R1 SRE-002 (P0) | outbox.dispatch_latency_ms SLO not in SLO_TARGETS | **CONFIRMED UNHEALED** — SRE-T314-004 |
| R1 SRE-003 (P0) | fiscal:verify-chain not scheduled in Kernel | HEALED — Kernel.php lines 174-211 implement per-branch daily verify (Wave 3b) |
| R1 SRE-007 (P1) | polling fallback 30s vs promised 5s | NOT re-checked (out of T-3.1.4 scope) |
| R2 T-3.2.1 SRE-013 | V1 5s p95 cross-surface unprovable | **AMPLIFIED** — SRE-T314-004 + SRE-T314-007 explain the mechanism |

---

## Verdict

**T-3.1.4 NO-GO as currently scoped**. The anchor file is misnamed and does
not implement the 10k-events simulation the GOAL claims. The 4-axis CI/prod
drift (SQLite + sync + log + array) renders all concurrency-sensitive
behaviours unprovable. The exported SLO is not wired to alerts. Critical
failure modes (SIGTERM mid-batch, Redis eviction, DB saturation) are uncovered.

**P0 hot path for Wave W6 (must-fix before merge to main)**:
1. SRE-T314-004 — wire `outbox_dispatch_latency_p95_ms` into SloEvaluatorJob.
   Without this, every other healing in this task is unfalsifiable.
2. SRE-T314-005 — add SIGTERM-mid-batch test + signal trap.
   Otherwise rolling deploys can silently lose events.
3. SRE-T314-001 — either rename the test or add the soak. Stop the
   false-advertising in the GOAL ledger.

**P1 pre-V2-pitch**:
4. SRE-T314-002 — MySQL/Redis integration CI lane.
5. SRE-T314-003 — replace flat 10k with V1/V2 tiered budget.

**Healing budget**: ~1.5-2 days backend + 0.5 day CI plumbing. Most of
this is plumbing, not redesign — the SyncMetricsRecorder + SloMetricCollector
+ Kernel.php scaffolding already exists and is well-factored. The
"production-like" naming is the harder lie to retract because it propagates
to GOAL ledger, BRAIN, and audit reports already shipped.

---

**Runbook signal**: if this audit's findings remain open after Wave W6, on
the first production midi rush we will not know whether Outbox is healthy.
We will be guessing from kitchen complaints. That is precisely the
operational invisibility window SRE work exists to close.
