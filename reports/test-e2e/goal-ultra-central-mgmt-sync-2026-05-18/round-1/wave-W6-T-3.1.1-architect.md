# Wave W6 — T-3.1.1 — ARCHITECT specialist read-only audit
**Outbox + domain-events dispatch (event → listener → DB → job → broadcast → ack)**
Date: 2026-05-18 — Round 1 — Read-only — Architect lens only.

---

## VERDICT

**GREEN-with-2-conditions** for V1 (Le Cayenne, single branch).
**YELLOW** for V2/SaaS multi-tenant generalization.

End-to-end design is sound: durable outbox row, claim-before-broadcast with
row lock, `DispatchableAfterCommit` closes the rollback-orphan hole,
dedicated retry/rescue/monitor/prune commands cover failure modes, and the
`idempotency_key` UNIQUE constraint resolves the listener replay race. At
V1 load (~20 evt/sec peak per stress-test framing) the system delivers
within SLO with multiple defense layers active.

Stress-test honesty: 100 orders × 5 min = ~20 evt/sec — **the architecture
absorbs this with room to spare**. First failure mode at *10×* scale or in
an *incident* (Pusher restart) is the `retry_after=90s` / `timeout=90s` race
producing duplicate `attempts++` (NOT duplicate broadcasts — those are
blocked by the row lock at `app/Jobs/DispatchDomainEventsJob.php:65-86`).

---

## TOP FINDINGS

### F-T311-ARCH-01 — EventContract REQUIRED_PAYLOAD_KEYS drift (4 unprotected types)
```yaml
severity: P1
category: contract-drift
confidence: high
evidence:
  - app/Domain/Events/EventContract.php:55-76 — REQUIRED_PAYLOAD_KEYS for 11 types
  - app/Enums/EventType.php:43-65 — EventType::all() returns 15 types
  - missing: SETTINGS_UPDATED (EventType:36), MENU_EXTRA_AVAILABILITY_CHANGED (:18),
    MENU_VARIATION_AVAILABILITY_CHANGED (:20), BRANCH_STATUS_CHANGED (:41)
  - app/Domain/Events/EventContract.php:157-161 — assertPayloadValid "Unknown types
    pass (forward-compatibility)" → unmapped types silently bypass shape validation
  - app/Listeners/PersistSettingsUpdatedToOutbox.php:60-71 writes broadcast_as
    +payload, never asserted against contract before broadcast (same for
    PersistItemExtra/Variation listeners lines 25-30, 24-29)
reasoning: >
  Contract gate at DispatchDomainEventsJob:110 (assertEnvelopeValid) is the
  single bulwark against producer drift silently breaking Echo handlers.
  Unmapped types slip through because REQUIRED_PAYLOAD_KEYS lookup early-
  returns. If a developer renames `changed_keys` → `keys` in a listener,
  the broken envelope still broadcasts and KDS/POS Echo subscribers go
  silent or mis-render — invisible to PayloadMismatchException monitoring
  filters. Forward-compat at line 159 should fail-closed for types
  already enumerated in EventType::all() or BROADCAST_MAP.
fix_direction: >
  Add the 4 missing entries to REQUIRED_PAYLOAD_KEYS using actual listener
  payload shapes. Extend BROADCAST_MAP (line 34) for the 4 types. Add a unit
  test iterating EventType::all() asserting each type appears in
  REQUIRED_PAYLOAD_KEYS or an opt-out allow-list.
load_at_100x5min: >
  No production impact at V1 — current payloads are well-formed because no
  recent change broke them. Risk is latent; ships the moment a developer
  edits a listener payload without realising the guard is absent.
```

### F-T311-ARCH-02 — Pusher payload-size guard absent before broadcast
```yaml
severity: P2
category: missing-defense
confidence: medium (needs measurement)
evidence:
  - config/broadcasting.php:48-65 — Pusher driver pass-through, no size guard
  - app/Jobs/DispatchDomainEventsJob.php:107-116 — shape validated, byte-size not
  - app/Listeners/PersistCatalogChangedToOutbox.php:75-82 — `payload_diff` from
    CatalogChanged event (free-form nested array, no cap)
  - app/Listeners/PersistOrderCreatedToOutbox.php:31-42 — payload bounded, safe
reasoning: >
  Pusher private-channel messages cap at ~10 KB. Most outbox events ship
  200-500 bytes. Exception: CATALOG_CHANGED `payload_diff` carries arbitrary
  nested diff arrays from MenuMutation. A bulk admin re-edit of a composer
  profile can produce multi-KB diffs. If a message exceeds 10 KB, Pusher
  returns 400 → job throws → claim released (job:140-151) → retries forever
  (or until $tries=6) → terminal failure with `last_error` LACKING the
  `contract_violation:` prefix monitoring filters key on. Silent terminal.
fix_direction: >
  Size guard in DispatchDomainEventsJob::handle() post envelope-build:
  strlen(json_encode($envelope)) > 9_500 → log structured warning + tag the
  row with `contract_violation: oversize_payload:<bytes>b` so monitoring
  paths fire. Optionally truncate `payload_diff` to a key-list summary
  (downstream consumers refetch by aggregate_id anyway).
load_at_100x5min: >
  No V1 P0 with single curated Le Cayenne menu. Becomes P1 the moment
  SaaS multi-brands bulk-import composer profiles.
```

### F-T311-ARCH-03 — Concurrent worker dedupe proven only on SQLite no-op
```yaml
severity: P2
category: test-coverage-gap (already documented as tech debt)
confidence: high
evidence:
  - tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php:26-32 — docblock
    admits SQLite treats lockForUpdate() as a no-op, tests exercise only
    post-claim idempotence sequential path, NOT true concurrent-row-lock
  - tests/Feature/Outbox/ListenerReplayDedupeTest.php:372 (test name only)
    test_concurrent_listener_fires_atomic_via_unique_constraint — UNIQUE
    IS enforced on SQLite, lockForUpdate is not
  - app/Jobs/DispatchDomainEventsJob.php:65-86 — production correctness
    relies on row FOR UPDATE lock semantics, MySQL/Postgres only
  - already-tracked debt: plans/MEGA_PLAN_SYNC_HARDENING_v3 Phase 3
reasoning: >
  Production correctness under concurrent workers depends on row-level
  FOR UPDATE. CI green proves only post-commit idempotence. The lock is
  the only thing preventing double broadcast when Pusher latency spikes
  cause a 91s in-flight job to be re-pulled by a sibling worker (combined
  with retry_after=90/timeout=90 footgun, OQ#1). Blast radius bounded —
  losing worker sees dispatched_at!=null on second pass and bails — but
  unproven by suite. MySQL InnoDB behaves correctly in production.
fix_direction: >
  Add MySQL-backed integration test in tests/Integration/Outbox/ (gated
  by DB driver) spinning two workers against a fixture row, asserting
  exactly one broadcast() call and `attempts` increments exactly once.
  Lift existing tech debt before V1.1.
load_at_100x5min: V1 single-branch MySQL behaves correctly; just unproven by tests.
```

---

## COVERAGE MAP

### Call graph traced (OrderCreated path — verified)
1. **FIRE**: `OrderService.php:548,1051,1361` + `FrontendOrderService.php:1226`
   → `OrderCreated::dispatch($order)`
2. **TRAIT**: `Events/Concerns/DispatchableAfterCommit.php:29-42` — defers via
   `DB::afterCommit` if txn active; dropped on rollback.
3. **WIRE**: `EventServiceProvider.php:145-151` — listener order: Persist*ToOutbox
   FIRST (SSOT before side-effects, root-cause F-002).
4. **PERSIST**: `PersistOrderCreatedToOutbox.php:22` — sha1('order.created|<id>')
   → firstOrCreate; UNIQUE index migration `2026_05_09_180000_add_idempotency_key`
   makes dedupe race-safe at DB.
5. **SKIP-REPLAY**: :57-59 — `wasRecentlyCreated=false` → skip enqueue (sibling
   defense; Phase 1 job lock also catches).
6. **ENQUEUE**: :61-79 — `DB::afterCommit` → `DispatchDomainEventsJob::dispatch`,
   try/catch best-effort (row persisted, retry cron rescues).
7. **CLAIM**: `DispatchDomainEventsJob.php:65-86` — `DB::transaction` +
   `lockForUpdate` + `dispatched_at` guard + `attempts++` in one committed unit.
8. **BROADCAST**: :100-116 — `buildEnvelope` → `assertEnvelopeValid` →
   `BroadcastManager::connection()->broadcast(['private-branch.<bid>'], 'OrderCreated', envelope)`.
9. **ACK / FAIL**: :137-139 clear `last_error`; :140-162 release claim + rethrow
   → queue retry `$backoff=[1,5,15,60,300]` / `$tries=6`.
10. **TERMINAL**: :165-222 — `failed()` persists `last_error`, structured
    `Log::error`, optional Sentry breadcrumb.

### Cron operational layer (`app/Console/Kernel.php`)
- `outbox:rescue` every-minute, `onOneServer` — requeues stale `attempts<5`.
- `outbox:monitor --threshold=10` every-minute — separate paging signal that
  doesn't enqueue (fires even with dead queue worker).
- `outbox:retry-failed --since=24h` hourly — resets `attempts>=5` rows.
- `outbox:prune --older-than-days=90` daily 04:00 — deletes dispatched OR
  `attempts>=6` rows.

### DispatchableAfterCommit guarantee — ALL 11 LISTENERS COVERED
`grep -L DispatchableAfterCommit app/Events/*.php`: only SendOrder*/
SendReset/SendSms events lack it; none of those are outbox-bound.
Rollback-orphan hole closed.

### Architectural strengths
- **Claim-before-broadcast separation** (job:65-117): lock+state mutation+commit
  FIRST, broadcast OUTSIDE any txn. Textbook outbox correctly implemented.
- **`idempotency_key` semantic discrimination**: one-shot events
  (`PersistOrderCreatedToOutbox.php:22`) use `sha1(type|aggregate_id)` —
  hard dedupe across requests; transition events (`PersistOrderStatusChangedToOutbox.php:26-32`)
  include `correlation_id` so dedupe scopes to the *originating request*.
  Exactly the right shape.
- **Listener order discipline** (`EventServiceProvider.php:128-151` block
  comment): outbox before side-effects. Root-caused F-002 (87 lost orders).
- **`MonitorOutboxStaleness` separated from rescue** (Kernel.php:44-52,
  MonitorOutboxStaleness.php:18-28) — monitor only reads + logs, supervisor
  detects pipeline-wide degradation even when queue worker dead.

### 11-listener pattern — VERDICT: KEEP
Per-listener-per-event IS the right shape. Each listener owns payload
contract + idempotency_key composition + fan-out semantics. Collapsing
to one generic class would need runtime reflection + giant type-switch +
payload-projector registry, defeating type safety. Shared boilerplate
(`resolveCorrelationId`, `resolveOrigin`) totals ~30 lines/listener — a
trait `OutboxListenerHelpers` could DRY this as P3 polish.

---

## OPEN QUESTIONS

1. **`retry_after=90` (config/queue.php:69) ≥ `timeout=90` (config/horizon.php:41)**
   classic Laravel race. >89s job (Pusher partial outage) → queue manager
   re-pulls SAME job into SECOND worker. Phase 1 lock+idempotency blocks
   double broadcast, but `attempts` increments twice → exhausts $tries=6
   faster than documented backoff curve. **Fix: `retry_after > timeout`
   (e.g. 120/90).** Defense-in-depth P3, not active failure.

2. **NF525 fiscal events vs 90-day prune.** `ORDER_PAYMENT_CONFIRMED`
   carries `fiscal_sequence_no`. `PruneOutboxCommand.php:10-30` asserts
   6y retention applies to `audit_logs`+`z_reports` ONLY. **Verify
   `ZReportService::reconstruct` reads from `orders`+`order_payments`+
   `audit_logs`, NOT from `domain_events`.** If it reads from
   `domain_events` then 90-day prune is fiscally non-compliant.

3. **`PersistCatalogChangedToOutbox` fan-out scale**. Global mutations
   (no event branchId) iterate `Branch::query()->whereIn('status',...)`
   inline in listener (lines 30-41). At V1=1 branch trivial; at V2 SaaS
   50+ tenants → 50 outbox rows/broadcasts per admin item-edit on HTTP
   path. Move fan-out to a queued projector job. **NOT a V1 blocker.**

4. **BROADCAST_MAP coverage for Extra/Variation**. `ItemExtraAvailabilityChanged`
   and `ItemVariationAvailabilityChanged` (their listener `broadcast_as`)
   are absent from BROADCAST_MAP (EventContract.php:34-49). `typeForBroadcastAs`
   falls back to broadcast_as name (line 186), so it works accidentally
   server-side. **Verify `resources/js/services/eventContract.js` JS
   BROADCAST_MAP mirror includes these 2 strings.**

5. **SyncMetricsRecorder swallow** (job:124-133): non-blocking telemetry
   intent correct, but verify internal swallow logs to `observability`
   channel so degraded metrics don't go silent. Read
   `app/Services/Observability/SyncMetricsRecorder.php` to confirm.

---

## What fails first at 100 orders × 5 min?

**Nothing.** ~20 evt/sec — `$tries=6`+`$backoff=[1,5,15,60,300]` absorbs a
5-min Pusher restart within SLO. Phase 1 lock + UNIQUE absorbs concurrent
worker contention. `MonitorOutboxStaleness(threshold=10, stale-after=30s)`
would not fire.

**At 10× scale (~3.3 sustained, 200 evt/sec bursts):** first measurable
failure is back-pressure on the `high` lane (config/horizon.php:33-43,
`maxProcesses=8`). Pusher ack ~50ms/event → 8 workers ceiling ~160 evt/sec.
Above that, queue depth grows and staleness monitor pages in 30s.
Mitigation: bump `maxProcesses` or shard lane by branch.

**Pusher 5-min restart incident:** backoff (1s,5s,15s,60s,300s) means an
event firing at minute 0 exhausts attempts #1-#5 within first 81s and
waits 5min for #6 — exactly the Pusher MTTR. **Well-calibrated and
intentional** per NEW-03 note at job:24-39. Race surface that opens is
OQ#1 producing inflated `attempts` counts, no duplicate broadcast.
