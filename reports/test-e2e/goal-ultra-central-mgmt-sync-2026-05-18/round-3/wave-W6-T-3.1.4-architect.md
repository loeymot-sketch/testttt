# Round 3 — Wave W6 — T-3.1.4 — ARCHITECT (READ-ONLY)

**Task**: Outbox production-like simulation (10k events / 60s)
**Specialist**: Architect
**Date**: 2026-05-18
**Status**: AUDIT (no code changed)

---

## TL;DR

The file currently named `OutboxProductionLikeSimulationTest.php` is **NOT a
10k-event / 60s production-like simulation**. It is a 5-test feature suite
covering 4-event micro-cases (rescue + retry-failed + claim release + 2-branch
fan-out + contract violation). The CI volet that DOES claim a stress shape
(`tests/load/RushMidiSimulationTest.php` S7.5) caps at **12 events**, runs
sequentially via `sync` queue, exercises only `event_type='order.created'` with
`channel=null` (broadcast-skip path), has S7.2 + S7.3 `markTestIncomplete`, and
explicitly documents in its docblock that *"Tests stress en sqlite-memory ne
sont PAS du vrai concurrent (RefreshDatabase + SQLite serialise tout,
lockForUpdate no-op)."*

The HTTP-driven volet (`foodking:e2e:stress`) is owner-driven, defaults to 50
orders, has no documented 10k run, has no p95 / p99 / per-event latency
assertions, no fault-injection beyond happy-path HTTP, and ships a stub
`outbox_stale_30s` count (target=0) without enforcing it in the exit code.

**Verdict**: the simulation gap is **P0 architectural** — the production claim
"outbox SLO p95 < 2s under 10k/60s" is unproven by any artifact in this repo.

---

## 1. Existing simulation scope (`OutboxProductionLikeSimulationTest.php`)

Lines 21-278 — five test methods, **none exercise concurrency or volume**:

| # | Test                                                       | Events | Concurrency | Fault injection            |
|---|------------------------------------------------------------|--------|-------------|-----------------------------|
| 1 | `rescue_requeues_only_stale_retriable_pending_events`      | 4      | 0 (sync)    | None — state-only           |
| 2 | `retry_failed_resets_only_recent_failed_events`            | 3      | 0 (sync)    | None — state-only           |
| 3 | `broadcast_failure_releases_claim_and_rescue_can_requeue…` | 1      | 0 (sync)    | 1× Mockery RuntimeException |
| 4 | `global_catalog_event_fans_out_to_active_branch_channels…` | 2      | 0 (sync)    | None — happy path           |
| 5 | `contract_violation_never_reaches_broadcaster_and_remains…`| 1      | 0 (sync)    | 1× PayloadMismatchException |

**Total events touched: 11**. Naming drift: the file name advertises
"ProductionLike" + "Simulation"; the contents are unit-grade rescue/retry
contract tests. This is a **R3 P0 misnamed-artifact** finding — the name leads
a future maintainer to believe the 10k requirement is closed when it is not.

---

## 2. 10k events / 60s scope gap

No artifact in the repository asserts:

- **10,000 events** in any run (max found: `STRESS_OUTBOX_N=12`, line 80 of
  `tests/load/RushMidiSimulationTest.php`; CLI default `--orders=50`,
  `E2EStressCommand.php:71`).
- **60-second wall-clock budget** for full drain (`computeMetrics` returns
  `batch_duration_s` but no assertion).
- **Sustained throughput** (CLI computes `throughput_rps` for logging only,
  line 370 — never asserted).

The gap to the GOAL claim is roughly **3 orders of magnitude on volume** (12 →
10 000) and **untested on time-to-drain**. Any inference that "the small
sequential test proves the 10k case" relies on the assumption that the system
is linear-scaling and that no contention regime (Redis Cache::lock contention,
MySQL row-lock queue depth, Pusher batch-flush, queue worker autoscale lag) is
crossed between 12 and 10 000 events — which is exactly the regime
production-readiness needs to validate.

---

## 3. Fault injection coverage

Only **two fault paths** are exercised across all outbox tests:

1. Generic `RuntimeException('provider restart')` from a mocked Broadcaster
   (`OutboxProductionLikeSimulationTest.php:122`) — verifies the claim-release
   semantics of `DispatchDomainEventsJob::handle()` `catch (Throwable $e)`
   branch.
2. `PayloadMismatchException` from contract validation (line 219) — verifies
   the `contract_violation:` prefix path.

**Untested in any "production-like" artifact**:

- **Pusher mid-flight disconnect** (TCP RST, TLS reset, HTTP 502). Production
  reality during a Soketi/Pusher restart, not a Mockery shouldReceive.
- **Worker SIGKILL with claim held** — process dies after the
  `DB::transaction` claim commits but before the broadcast call (lines 65-86
  of `DispatchDomainEventsJob`). The system **must** rely on
  `foodking:outbox:rescue` to detect `dispatched_at != null AND last_error IS
  NULL AND created_at < now()-stale_window` is **not** what rescue scans for
  (rescue only requeues `dispatched_at IS NULL`). This is a **silent
  half-dispatched class** the simulation does not cover.
- **DB connection drop mid-claim** (MySQL `gone away` on commit) — the
  transaction would roll back, but the `attempts` increment is inside the
  transaction so no orphan counter inflation; however, the **next attempt**
  retried by the queue layer can race with rescue. Untested.
- **Cache::lock failure** under Redis split-brain — out of scope for this test
  (the lock is in `FiscalSequenceService`, not the outbox job), but the
  outbox-issuing path on the producer side (Listeners) writes inside
  `afterCommit` callbacks (per R1 DBA F-T311-DBA-03) — if MySQL rollback
  happens after the listener fires its `dispatchAfterCommit`, behavior is
  undefined. Untested.
- **Backoff curve real-time** (1s → 5s → 15s → 60s → 300s, line 40 of the
  job). Asserted only by the existence of the array constant — no run
  measures the actual delay distribution under retry pressure.

---

## 4. Architectural risk: SQLite-CI vs MySQL-prod unprovable failure modes

The CI test environment is `sqlite::memory:` with `RefreshDatabase` (line 23
of the test class, line 69 of `RushMidiSimulationTest`). SQLite serializes
writes — `lockForUpdate` is documented as a **no-op** (R1 Architect
F-T311-ARCH-03). This means the following production failure modes are
**fundamentally unprovable** in CI:

| Production failure mode                                                      | Why unprovable in CI                                                  |
|-----------------------------------------------------------------------------|------------------------------------------------------------------------|
| Two Horizon workers claim the same `domain_event` simultaneously             | SQLite serializes; no real row lock contention                         |
| Cache::lock Redis tenant key collision under network split                   | No Redis in CI; cache.driver=array                                     |
| Pusher rate-limit backpressure (100 msg/s default tier)                      | Broadcaster mocked to `log` or Mockery                                 |
| MySQL `idx_pending` partial-index usage at scale (R1 DBA F-T311-DBA-01 dead) | SQLite has no partial index; index plan is MySQL-only                  |
| `lockForUpdate` queue depth under 6-worker concurrency (Horizon `supervisor-high` minProcesses=1 maxProcesses=8) | SQLite no-op                                                          |
| Backoff curve interaction with Horizon `wait` thresholds (`redis:high=2s`)   | Sync queue executes inline; no real Horizon                            |
| `failed_jobs` table contention under 10k throughput                          | SQLite single-writer                                                   |

The architectural implication: **the only place the 10k claim can be honestly
validated is on a MySQL + Redis + Horizon staging environment**. The current
artifacts (PHPUnit `S7.5` + `E2EStressCommand`) cannot substitute. This must
be either (a) re-scoped (drop the 10k SLO claim) or (b) backed by a
non-CI artifact (staging run report archived in `reports/loadgen/`).

---

## 5. Cross-system fan-out coverage

11 producer listeners exist (verified by `find`):

```
PersistOrderTableChangedToOutbox
PersistSettingsUpdatedToOutbox
PersistCouponChangedToOutbox
PersistOrderCreatedToOutbox
PersistCatalogChangedToOutbox
PersistOrderStatusChangedToOutbox
PersistOrderPaidAtCounterToOutbox
PersistOrderPaymentStatusChangedToOutbox
PersistItemAvailabilityChangedToOutbox
PersistItemExtraAvailabilityChangedToOutbox
PersistItemVariationAvailabilityChangedToOutbox
```

`EventType::all()` enumerates **15** event types (counted in
`app/Enums/EventType.php`, including SETTINGS_UPDATED, BRANCH_STATUS_CHANGED,
MENU_EXTRA_AVAILABILITY_CHANGED, MENU_VARIATION_AVAILABILITY_CHANGED).

`REQUIRED_PAYLOAD_KEYS` (`EventContract.php:55-76`) covers **11** types — the
4 missing types are exactly the R1 Architect F-T311-ARCH-01 contract-drift
finding:

- `MENU_EXTRA_AVAILABILITY_CHANGED`
- `MENU_VARIATION_AVAILABILITY_CHANGED`
- `SETTINGS_UPDATED`
- `BRANCH_STATUS_CHANGED`

**The simulation test exercises 2 event types**:

- `EventType::ORDER_CREATED` (default in `domainEvent()` helper, line 235)
- `EventType::CATALOG_CHANGED` (test #4, line 198)

So 9 out of 11 contract-validated types, and **all 4 contract-missing types**,
are **unexercised** under any simulation load. The `RushMidiSimulationTest`
S7.5 uses only `event_type='order.created'` with `channel=null` (line 451) —
which **skips the broadcast block entirely** (`DispatchDomainEventsJob:100`
gates broadcast on `channel !== null && broadcast_as !== null`), so even
that test does not exercise the `EventContract::assertEnvelopeValid` path
under load.

**Net coverage**: 0% of types are load-tested through the full
`broadcast + assertEnvelopeValid + recordEventDispatched` path.

---

## 6. Performance assertions

Audit of all simulation-claim files for `p95`, `p99`, `latency`, `throughput`,
`SLO`:

| File                                          | p95 assertion | p99 | per-event latency | throughput  |
|-----------------------------------------------|---------------|-----|-------------------|-------------|
| `OutboxProductionLikeSimulationTest.php`      | none          | none| none              | none        |
| `tests/load/RushMidiSimulationTest.php`       | none (S7.5 only asserts `dispatched_at NOT NULL` + `attempts=1` + `last_error IS NULL`, lines 471-487) | none | none | none |
| `app/Console/Commands/E2EStressCommand.php`   | none — computes `avg_latency_ms` (line 369) and `throughput_rps` (line 370) for **logging only**, never asserted in exit code (lines 192-197 check only `failed=0 + invariants`) | none | none | logged only |

The `DispatchDomainEventsJob` records latency via
`SyncMetricsRecorder::recordEventDispatched` (line 125) with the ms diff
`now()->diffInMilliseconds($domainEvent->occurred_at)` — but no test reads
that recorder back to assert distribution. No anchor in this audit's scope
contains a histogram or quantile assertion.

---

## 7. Memory / CPU baseline under 10k load

Currently asserted: **nothing**. `memory_limit: 128` (MB) is set in
`config/horizon.php:30`. Under 10k events queued at once, the Horizon worker
process must:

1. Hydrate each `DomainEvent` model (~1-3 KB each, ~10-30 MB hydrated).
2. Hold the per-job `SerializesModels` payload in memory until completion.
3. Allocate per-event Pusher payload buffers.

Memory pressure risk: at concurrency 8 (`supervisor-high.maxProcesses=8`),
each worker independently allocating ~5 MB transient + Laravel kernel ~30 MB =
~280 MB worker pool. The 128 MB per-worker limit is a per-process kill; under
load, OOM-restarts of Horizon supervisors will drop in-flight claims and
require rescue. **Untested** by any artifact.

---

## 8. Recommended next-gen test additions

To honestly close the 10k/60s production-like simulation gap:

### 8.1 — New test: `tests/Feature/Outbox/OutboxScaleSimulationTest.php`

Scope explicitly bounded for CI feasibility: **1000 events** (not 10k) under
sync queue, exercising **all 11 event types in proportion to production
traffic**. Assertions:

- 100% `dispatched_at NOT NULL`
- 0% `last_error NOT NULL`
- 0% `attempts > 1` (no spurious retries on the happy path)
- All 11 event types broadcast at least once with valid envelope
- Wall-clock CI budget: < 30s; flag with `@group scale`

This **does not** validate concurrency — it validates fan-out breadth and
contract robustness at moderate volume. Naming is precise (`Scale`, not
"ProductionLike").

### 8.2 — New artifact: `scripts/loadgen/outbox-10k-staging.sh`

Documented as **non-CI**, owner-driven on staging MySQL + Redis + Horizon.
Drives 10k events via direct `DomainEvent::create()` (bypassing HTTP order
flow to isolate the outbox path from order-creation cost). Asserts:

- `count(dispatched_at IS NULL AND created_at < now() - 60s) = 0`
- `percentile_disc(0.95) within group (order by latency_ms) < 2000`
- `percentile_disc(0.99) within group (order by latency_ms) < 5000`
- Reads `SyncMetricsRecorder` output, not just DB counters
- Output archived as `reports/loadgen/outbox-10k-<timestamp>.md`

### 8.3 — Fault-injection extensions to `OutboxScaleSimulationTest`

Three deterministic fault scenarios runnable in CI:

1. **Broadcaster intermittent failure** — Mockery sequence that fails 1 of
   every 10 broadcasts. Assert all 1000 events eventually land
   `dispatched_at NOT NULL` within `tries=6` budget; assert `last_error`
   cleared on the successful retry attempt.
2. **Claim-then-crash simulator** — for 50 events, after the
   `DB::transaction` claim commits, throw before broadcast. Verify the new
   rescue clause **does** requeue these (currently rescue only requeues
   `dispatched_at IS NULL` — this scenario reveals a real gap, see §3
   bullet 2).
3. **Listener `afterCommit` rollback** — wrap a listener in a transaction
   that rolls back. Assert no `domain_events` row was created (proves the
   afterCommit semantics survive listener-side errors).

### 8.4 — Contract-drift test

Already implicit in R1 Architect F-T311-ARCH-01, but worth pinning here: add
`tests/Unit/EventContractCoverageTest.php` that iterates
`EventType::all()` and asserts every value has a `REQUIRED_PAYLOAD_KEYS` entry.
This is a 5-line test that catches the R1 finding in CI permanently.

### 8.5 — Rename the existing file

Rename `OutboxProductionLikeSimulationTest.php` → `OutboxRescueAndContractTest.php`.
The current name overpromises and creates exactly the kind of false-comfort
artifact a production-readiness audit must surface.

---

## 9. Cross-reference to R1 findings

- **F-T311-ARCH-01 (P1, contract drift, 4 types)** — confirmed unexercised
  under any simulation; §5 above quantifies coverage as 0%.
- **F-T311-ARCH-03 (P2, SQLite no-op lockForUpdate)** — load-bearing for this
  audit: the entire simulation premise is undermined by SQLite serialization;
  see §4.
- **F-T311-SRE 3 P0** — `outbox SLO gap` is corroborated here: no p95 assertion
  exists; `ws:heartbeat` + `fiscal:verify-chain` cron findings remain
  orthogonal to this task but reinforce the absence of production-grade
  observability around the outbox path.
- **F-T311-DBA P0 (3 findings)** — `idx_pending dead` is decisive for §4:
  even a real MySQL run cannot prove p95 < 2s if the partial index is unused
  by the query planner; this must be paired with `EXPLAIN ANALYZE` evidence
  in the staging report (§8.2).

---

## 10. Verdict

**Architectural verdict for T-3.1.4**: **P0 NO-GO** in current state.

The 10k/60s production-like simulation **does not exist** in the artifacts
audited. The closest candidate (`OutboxProductionLikeSimulationTest.php`) is
misnamed and covers contract-level rescue/retry semantics on 4-11 events
sequentially. The CI volet (`RushMidiSimulationTest::test_S75…`) covers 12
sequential `order.created` events with `channel=null` (broadcast-skipped).
The owner-driven volet (`foodking:e2e:stress`) defaults to 50 orders, has no
p95 assertion, and silently logs metrics without enforcement.

**Minimum closure** for the GOAL claim: (a) ship §8.1 (CI-feasible 1000-event
fan-out across all 11 types with envelope assertions), (b) ship §8.2 (staging
artifact + archived report), (c) close R1 F-T311-ARCH-01 (contract drift)
before the staging run so all 4 missing types are exercised, (d) rename §8.5.

Without these, the production-readiness statement on outbox SLO is unbacked
by evidence — which under §13 of `CLAUDE.md` requires the verdict to
downgrade to **block / heal**, not continue.

---

## Files referenced (absolute paths)

- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/load/RushMidiSimulationTest.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Console/Commands/E2EStressCommand.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Jobs/DispatchDomainEventsJob.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Domain/Events/EventContract.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Enums/EventType.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/config/horizon.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/config/queue.php`
- 11× `app/Listeners/Persist*ToOutbox.php`

— word count ~1500 (cap respected)
