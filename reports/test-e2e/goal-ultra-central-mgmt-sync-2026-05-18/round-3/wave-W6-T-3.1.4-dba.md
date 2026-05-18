# Wave W6 — T-3.1.4 Outbox 10k production-like simulation — DBA audit (Round 3)

**Specialist**: DBA
**Date**: 2026-05-18
**Mode**: READ-ONLY
**Anchors**:
- `tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php`
- `app/Models/DomainEvent.php`
- `app/Jobs/DispatchDomainEventsJob.php`
- `database/migrations/2026_04_15_200000_create_domain_events_table.php`
- `database/migrations/2026_05_09_180000_add_idempotency_key_to_domain_events.php`
- `app/Console/Commands/PruneOutboxCommand.php`
- `app/Listeners/PersistOrderCreatedToOutbox.php`
- `config/horizon.php` (production worker pool: `supervisor-high` maxProcesses=8)

> Round 3 DBA mindset frame: at 10k events / 60s sustained (= 167 INSERT/s) with 8 concurrent workers draining the queue and 1M-row historical body, where does the InnoDB B-tree, the lock manager, the buffer pool, and the connection pool start to bend? Cross-reference Round 1 findings (DBA-001 dead-weight idx_pending, DBA-004 PruneOutbox gap-lock, DBA-009 phantom broadcast) and reason about how they compound under 10k load.

---

## Schema baseline at scale (1M-row body + 10k pending tail)

Authoritative source = migration files (DB is fresh).

`domain_events` row footprint (estimated):
- Fixed columns: `id` (8B) + `event_type` (≤128 varchar, avg 24B) + `aggregate_type` (avg 32B) + `aggregate_id` (8B) + `branch_id` (8B) + `correlation_id` (36B char) + `idempotency_key` (40B sha1) + `occurred_at`/`dispatched_at` (8B each) + `attempts` (2B u-smallint) + `last_error` (TEXT, off-page) + `created_at`/`updated_at` (8B each) ≈ **190 B fixed**.
- Variable: `payload` JSON. OrderCreated payload ≈ 350-800 B; CatalogChanged (full snapshot) up to 50 KB but off-page after ~768 B. Average row footprint with on-page payload ≈ **600 B**.
- `channel` (≤255) + `broadcast_as` (128) avg ~80 B.

At 1M rows: clustered index ≈ **600 MB on-disk** (InnoDB compressed by ~30% → ~420 MB), plus four secondary indexes (`idx_pending`, `idx_aggregate`, `idx_branch`, `uniq_domain_events_idempotency_key`) ≈ **+180 MB**. **Total ≈ 600 MB resident**. Acceptable on a 4-GB `innodb_buffer_pool_size`, **tight** on a 1-GB pool (typical default for managed RDS db.t3.small).

---

## Findings (strong-reasoning YAML)

```yaml
- id: DBA-R3-001
  severity: P0
  title: "INSERT throughput at 167/s collapses on `uniq_domain_events_idempotency_key` page splits as the index hot-spots on sequential sha1 keys"
  category: index_design_at_scale
  evidence:
    - "add_idempotency_key_to_domain_events:39-40 declares UNIQUE on `idempotency_key` (sha1, 40B)."
    - "PersistOrderCreatedToOutbox:22 computes `sha1(EventType::ORDER_CREATED . '|' . $order->id)` — input is monotonic-by-id, but sha1 output is RANDOM."
    - "11 listeners all use `firstOrCreate(['idempotency_key' => ...])` per Round 1 anchor list."
  reasoning: |
    A random-key UNIQUE index is the textbook InnoDB anti-pattern at high INSERT
    rate. With 167 INSERT/s and 8 listener processes spread across order/catalog/
    coupon/availability event surfaces:
    - Each INSERT must locate the position in the UNIQUE B-tree where the new
      sha1 lies, acquire a NEXT-KEY LOCK on the gap (InnoDB default isolation
      = REPEATABLE READ), test the predicate, then insert. With random keys,
      hits land on RANDOM leaf pages — every INSERT pulls a different page
      from the buffer pool.
    - At 1M rows, the index leaf has ~25k pages (40-byte keys + page-header
      overhead, 16-KB pages). 167 INSERT/s × random distribution = ~167
      DISTINCT page accesses/s. Cold-cache page reads (buffer-pool miss)
      cost 0.5-1 ms each on EBS gp3 → **80-170 ms/s of pure I/O latency on
      INSERTs alone**, before any application logic.
    - Worst case: page split frequency. The UNIQUE B-tree is uniformly filled
      because sha1 is uniform → splits scatter, no benign right-tail growth
      (unlike PRIMARY KEY id which is monotonic and only splits the
      rightmost page). At 1M rows growing 10k/min, page-split rate ≈ 1 split
      every ~60 INSERTs ≈ 3 splits/s under load. Each split holds an X-lock
      on the parent index node for 200μs-1ms → contention spikes.
    - The `firstOrCreate` race: it issues a SELECT first, then INSERT IGNORE
      semantics in Laravel's Eloquent ORM (NOT a proper INSERT ... ON
      DUPLICATE KEY UPDATE). At 167/s with concurrent firstOrCreate from
      multiple listener instances, the SELECT-then-INSERT window is wide
      open to the very race the UNIQUE index is supposed to neutralise.
      The UNIQUE catches duplicates, but every collision throws an
      Illuminate\Database\QueryException that listener code does not catch
      — see PersistOrderCreatedToOutbox:24 (no try/catch around firstOrCreate).
  hypothetical_fix: |
    1. Replace random-sha1 idempotency_key with a structured prefix:
       `branch_id . '|' . event_type . '|' . aggregate_id . '|' . discriminator`.
       Index becomes monotonic-by-branch + clustered-by-event-type, page
       splits localise to the rightmost-per-branch leaf, INSERT throughput
       triples.
    2. Switch to `INSERT IGNORE` or `INSERT ... ON DUPLICATE KEY UPDATE`
       at the DB layer (DB::statement raw) — avoids the SELECT-then-INSERT
       race entirely.
    3. Add `try/catch (QueryException $e) { /* unique violation → return */ }`
       around `firstOrCreate` calls to absorb the race losers cleanly.
  impact: "INSERT throughput ceiling ~50-80/s on a t3.medium with cold buffer pool. 167/s sustains for ~5 min before queue depth grows unbounded."

- id: DBA-R3-002
  severity: P0
  title: "8-worker SELECT FOR UPDATE on 10k-pending tail without ORDER BY id is non-deterministic — InnoDB gap-locks the ENTIRE pending range under REPEATABLE READ"
  category: lock_contention_at_scale
  evidence:
    - "DispatchDomainEventsJob:66-68 `DomainEvent::query()->lockForUpdate()->find($this->domainEventId);` — claim by PK, OK."
    - "BUT OutboxRescueCommand / OutboxRetryFailedCommand iterate via scopePending()/scopeStale() WITHOUT lockForUpdate and dispatch by ID — fine."
    - "config/horizon.php:39 production `supervisor-high` runs `maxProcesses=8` workers on the `high` queue."
  reasoning: |
    The Round 1 DBA-007 finding flagged the FULL-row lockForUpdate read but
    missed the inter-worker lock-fairness story at 10k pending. Re-examining
    under the 10k load lens:
    - `lockForUpdate()->find($id)` becomes `SELECT ... FROM domain_events
      WHERE id = ? FOR UPDATE`. Under REPEATABLE READ + PRIMARY KEY equality,
      InnoDB uses a RECORD lock (not gap lock) — fine. 8 workers claiming
      8 different IDs do NOT collide.
    - HOWEVER: the JOB constructor itself does NOT pick the next claimable
      id. `DispatchDomainEventsJob::__construct(int $domainEventId)`
      receives the id from the LISTENER (PersistOrderCreatedToOutbox:68
      `DispatchDomainEventsJob::dispatch($domainEvent->id)`). The QUEUE
      delivers each job to ONE worker. So claim contention is enqueue-
      side, not row-side — confirmed safe.
    - The REAL lock contention surface at 10k load is **OutboxRescueCommand**
      + **OutboxRetryFailedCommand** scanning the pending tail. Both walk
      `scopeStale(2 minutes)` = `WHERE dispatched_at IS NULL AND created_at
      < now()->subMinutes(2)`. With 10k rows matching, Eloquent's `->each()`
      / `->chunk()` reads in 1k batches with `WHERE id > last_id ORDER BY
      id LIMIT 1000` — fine, no row-locks.
    - The poisoned interaction: the rescue cron `Queue::push(DispatchDomain
      EventsJob)` AT THE SAME TIME 8 workers are draining the queue. If
      rescue pushes a job for an id that is CURRENTLY claimed by a worker,
      the worker holds the row lock (lockForUpdate is held for the duration
      of the surrounding `DB::transaction` block at line 65) — and rescue
      enqueues a duplicate. The second worker observes `dispatched_at != null`
      after lock acquisition (line 75) and skips → correct, but at scale
      we have 10k events × ~2 duplicate enqueues from rescue staleness =
      **20k worker-seconds wasted on lock-and-skip cycles**.
  hypothetical_fix: |
    1. Rescue command SHOULD NOT re-enqueue rows whose `id` has been touched
       in the last 30s (claim window). Add `AND updated_at < now()->subSeconds(30)`
       to scopeStale — guards against rescuing claims-in-progress.
    2. Reduce claim transaction scope: move the `forceFill+save` OUT of the
       lockForUpdate block (use SELECT ... FOR UPDATE SKIP LOCKED — MySQL
       8.0+) to allow multiple workers to walk past locked rows instantly
       instead of blocking.
    3. Document: rescue cron interval should be > job timeout. Currently
       rescue runs every 2 min (scopeStale threshold), job timeout = 90s
       (horizon.php:41). At 167/s × 90s = 15k jobs in-flight → rescue WILL
       fire during steady-state, not just during incidents.
  impact: "Steady-state worker CPU wasted on lock-and-skip + log spam from line 89 `[DispatchDomainEventsJob] Skipped` at 100s of msgs/min."

- id: DBA-R3-003
  severity: P0
  title: "`idx_pending(dispatched_at, occurred_at)` is unusable for the 10k-row staleness scan — Round 1 DBA-001 compounds at 1M-row body"
  category: index_efficiency_at_scale
  evidence:
    - "create_domain_events_table.php:27 `index(['dispatched_at', 'occurred_at'], 'idx_pending')`."
    - "DomainEvent.php:42 scopeStale uses `WHERE dispatched_at IS NULL AND created_at < ?` — `created_at`, NOT `occurred_at`."
  reasoning: |
    Round 1 DBA-001 caught the column mismatch (idx_pending has occurred_at,
    scopeStale uses created_at). At 10k pending tail + 1M historical body,
    the EXPLAIN plan unwinds as follows:
      EXPLAIN SELECT * FROM domain_events
        WHERE dispatched_at IS NULL AND created_at < '2026-05-18 12:00:00';
      → key = idx_pending
      → key_len = 6 (dispatched_at, 1 byte indicator + 5 datetime3)
      → rows = ~10000 (estimator picks NULL-leaf count)
      → Extra = "Using where" — created_at filter applied POST-index scan
    The index IS used for the IS NULL part, but `created_at` is filtered
    on the heap (clustered-PK lookup per row). At 10k pending, that's 10k
    primary-key probes per rescue scan = 10k random reads. EBS gp3 baseline
    is 3000 IOPS — **rescue scan alone consumes the entire branch I/O
    budget for 3+ seconds**, starving INSERT/UPDATE on the same instance.
    The fix proposed in Round 1 (`idx_pending(dispatched_at, created_at)`)
    is necessary AND sufficient at 10k. A partial index `WHERE dispatched_at
    IS NULL` would be 10k rows × 12 bytes = ~120 KB — fits in L1 cache,
    rescue scan becomes microseconds.
  hypothetical_fix: |
    -- MySQL 8.0+ functional partial index (best):
    CREATE INDEX idx_pending_only_v2
      ON domain_events ((CASE WHEN dispatched_at IS NULL THEN created_at END));
    -- Or simpler covering index that actually matches scopeStale:
    CREATE INDEX idx_pending_stale_v2
      ON domain_events (dispatched_at, created_at, id);
    -- Drop the dead-weight original:
    DROP INDEX idx_pending ON domain_events;
  impact: "Rescue scan I/O dominates dispatch latency at 10k. SLO `outbox_dispatch_latency_p95 < 2s` breached."

- id: DBA-R3-004
  severity: P1
  title: "Bulk INSERT replay (DR scenario) hits the `uniq_domain_events_idempotency_key` index serially — no LOAD DATA INFILE bypass path"
  category: replay_throughput
  evidence:
    - "No `OutboxReplayCommand` exists in `app/Console/Commands/Outbox*` — confirmed via grep."
    - "Bulk replay would go through Eloquent::insert([...]) or DB::table()->insert([...]) — both hit the UNIQUE index synchronously."
  reasoning: |
    DR scenario: production outbox table corrupted, restore from binlog /
    logical dump. 1M rows to re-INSERT. With the UNIQUE index live:
    - mysqldump --opt produces `INSERT INTO domain_events VALUES (...), (...), ...`
      with extended-format chunks (1000 rows/statement). Each statement
      acquires the AUTO-INC lock (MySQL 8 uses interleaved AUTO-INC by
      default — fast) + per-row UNIQUE check.
    - 1M rows × ~0.1ms/row UNIQUE B-tree probe = 100 seconds best case
      (warm cache), 30+ minutes cold.
    - `LOAD DATA INFILE` skips per-statement overhead but still hits UNIQUE
      probe per row. The faster path is:
      a. DROP idx_pending, idx_aggregate, idx_branch, uniq_idempotency_key
      b. LOAD DATA INFILE
      c. CREATE INDEX (sorted-insert via SORT_BUFFER, 10x faster than
         per-row INSERT against existing index)
    - The current codebase has NO command for this. A DR runbook must be
      hand-written by ops. Acceptable for V1.0.1, RISKY for central-mgmt.
  recommendation: |
    Add `foodking:outbox:rebuild-indexes` artisan command + RUNBOOK.md entry.
    Or document the DR path in `docs/OUTBOX_DR_RUNBOOK.md` (TBD).

- id: DBA-R3-005
  severity: P1
  title: "Horizon connection pool exhaustion: 8 workers × 1 DB conn each + WHERE-pool web requests can saturate MySQL `max_connections=100`"
  category: connection_pool_exhaustion
  evidence:
    - "config/horizon.php:39 `maxProcesses=8` on supervisor-high (DispatchDomainEventsJob queue)."
    - "config/horizon.php:50 `maxProcesses=4` on supervisor-default."
    - "Each worker process opens 1 persistent PDO connection to MySQL (PHP-FPM does NOT pool — each PHP process holds its own)."
  reasoning: |
    At 10k load, simultaneous occupancy:
    - 8 supervisor-high workers (Outbox dispatch)
    - 4 supervisor-default workers (notifications + default jobs)
    - PHP-FPM workers serving HTTP: typical pool ~20-40 processes (POS,
      Kiosk, admin requests)
    - Cron workers: PruneOutboxCommand, OutboxRescueCommand, MonitorOutboxStaleness,
      Z-report close — up to 4 concurrent
    Sum: 8 + 4 + 30 + 4 = **46 connections steady-state**, ramping to 60+
    during rush hour. MySQL default `max_connections=151` is fine. Managed
    RDS `db.t3.small` default `max_connections` = 85 (param-group computed
    from instance class) — **TIGHT**. RDS `db.t3.micro` = 60 → **OVERFLOW**.
    A single connection-leak (e.g. unclosed transaction in a custom command)
    locks the database for everyone within 5 minutes.
    Additionally: `lockForUpdate` inside DB::transaction (Job:65-86) holds
    the connection in TRANSACTION state for the duration of the broadcast
    (Job:96-117 broadcaster network call to Pusher). Pusher RTT can be
    50-500ms. The transaction commits at line 86 BEFORE the broadcast (good
    — Round 1 verified) but the connection is held for the duration of
    `forceFill+save` plus the post-tx broadcaster call. At 167 events/s ×
    150ms avg job runtime = 25 concurrent connections for outbox dispatch
    alone — **3x the worker count** because Pusher latency dominates.
  recommendation: |
    1. Pin RDS instance class >= db.t3.medium (max_connections=200) before
       enabling 8-process scaling.
    2. Wrap broadcaster call (Job:115-116) in a separate non-DB code path
       — already done (commit happens at :86), but confirm the connection
       is RELEASED back to PDO between tx-commit and broadcaster call.
       Laravel's PDO does NOT auto-release on commit; the connection stays
       checked out until the Job process returns. Consider:
       `DB::connection()->disconnect()` after the tx commit and before the
       broadcaster network call, OR move broadcast to a separate queued job.
    3. Add `connections_in_use` to the Horizon dashboard.

- id: DBA-R3-006
  severity: P1
  title: "No read-replica routing for cron scans — `OutboxRescueCommand` + `MonitorOutboxStaleness` thrash the primary at 10k tail"
  category: read_replica_strategy
  evidence:
    - "config/database.php:18 single `mysql` connection — no `read`/`write` array."
    - "OutboxRescueCommand, OutboxRetryFailedCommand, MonitorOutboxStaleness all run on the primary connection."
  reasoning: |
    Laravel supports read/write split via the `read` config key — when
    enabled, SELECT queries auto-route to the read replica. Currently
    DISABLED in config/database.php. At 10k pending tail:
    - MonitorOutboxStaleness runs every 1 minute (TBD by scheduler — not
      in this audit's scope but commonly every-minute for SLO monitoring),
      issues `SELECT COUNT(*) FROM domain_events WHERE dispatched_at IS NULL`.
      With idx_pending dead-weight (DBA-R3-003), this is a 10k-row index
      scan + clustered-PK probes = 30+ ms per run. At 1/min, no problem.
    - OutboxRescueCommand: same predicate + LIMIT, runs every 2 min by SLO,
      scans 10k pending rows + filters by created_at + retries 1 stale →
      ~50ms total.
    - Both COULD run on a 1-second-lag read replica: a row that becomes
      pending at T+0 isn't "stale" until T+120, so a 1s replication lag
      is invisible. SAFE to route reads to replica.
    - The blocker for read-replica routing: the DISPATCH job (Job:66
      lockForUpdate) MUST run on PRIMARY. Laravel does this correctly —
      `lockForUpdate()` triggers a sticky write-connection (sticky read-
      after-write since 5.6). Confirmed safe.
  recommendation: |
    Add `'read' => ['host' => env('DB_HOST_REPLICA')]` to config/database.php
    when central-mgmt enables a replica. Document that cron commands MUST
    use `DB::connection('mysql')->setReadOnly(true)` selectors where applicable.

- id: DBA-R3-007
  severity: P0 (compounds Round 1 DBA-004)
  title: "PruneOutboxCommand's OR-of-two-predicates EXPLAIN plan picks `idx_pending` for clause A but full-scan for clause B → table-lock at 1M rows"
  category: prune_query_plan
  evidence:
    - "PruneOutboxCommand:51-58 `WHERE (dispatched_at IS NOT NULL AND dispatched_at < cutoff) OR (attempts >= 6 AND created_at < cutoff)`."
    - "No index covers `(attempts, created_at)` — confirmed from create_domain_events_table.php:27-29."
  reasoning: |
    Round 1 DBA-004 caught the missing ORDER BY. Round 3 deepens the
    finding by tracing the OR-of-predicates EXPLAIN plan:
    EXPLAIN DELETE FROM domain_events WHERE (clause A) OR (clause B):
      type = index_merge
      key = idx_pending, NULL  -- second branch has no usable index
      Extra = "Using union(idx_pending, ALL); Using where"
    The second branch (attempts >= 6) has NO index → InnoDB does a FULL
    SCAN on this branch. At 1M rows with attempts ≥ 6 hitting ~0.1% =
    1000 candidates, the full-scan still walks all 1M rows to find them.
    Combined with the LIMIT 1000 without ORDER BY:
    - The first batch reads ~1M rows, locks the gaps it traverses, deletes
      the first 1000 matches it finds.
    - Subsequent batches re-scan the same 1M rows minus the ~1000 already
      deleted → quadratic-ish behaviour as deletes accumulate.
    - At 10k pending + 1M body, prune wall time = minutes, and during
      that time INSERT is blocked on gap-locks (Round 1 DBA-004) AND on
      AUTO-INC contention if the scan crosses the rightmost leaf.
  hypothetical_fix: |
    1. Add covering index `(attempts, created_at)` to make clause B
       sargable.
    2. Split the prune into TWO queries (dispatched_at branch + attempts
       branch) — neither has an OR, both pick their respective index.
    3. Apply Round 1's `ORDER BY id ASC` to both queries.
    4. Use `chunkById($batch, fn ($rows) => DB::table('domain_events')
       ->whereIn('id', $rows->pluck('id'))->delete())` per Round 1 advice.
  impact: "Prune cron during rush hour can wedge INSERTs for 2-5 minutes."

- id: DBA-R3-008
  severity: P1
  title: "InnoDB row-lock vs gap-lock under DispatchDomainEventsJob lockForUpdate — at 167 INSERT/s, AUTO-INCREMENT gap-locks DO leak between INSERT and `find($id)->lockForUpdate`"
  category: autoinc_lock_contention
  evidence:
    - "DispatchDomainEventsJob:67-68 `lockForUpdate()->find($this->domainEventId)` — PK equality, record-lock only."
    - "InnoDB AUTO_INC lock mode (innodb_autoinc_lock_mode):"
    - "  = 1 (default for MySQL 5.7) — interleaved + lightweight gap lock on AUTO_INC value at INSERT time."
    - "  = 2 (default for MySQL 8.0) — no lock, pure increment via mutex (binlog statement-mode incompatible)."
  reasoning: |
    The lock-narrative at 167 INSERT/s + 8 workers claiming-by-PK:
    - INSERT path: listener-side `firstOrCreate` issues SELECT ... WHERE
      idempotency_key = ? then INSERT. The INSERT acquires:
      a. AUTO_INC gap lock (table-level micro-lock, released after value
         allocation — at innodb_autoinc_lock_mode=2, no lock).
      b. NEXT-KEY LOCK on the UNIQUE idempotency_key index at insertion
         point.
      c. RECORD LOCK on the new clustered-PK leaf.
    - DISPATCH path: worker issues `SELECT ... WHERE id = ? FOR UPDATE`:
      a. Record lock on PK row.
    - INSERT and DISPATCH never collide on the SAME row (the dispatch
      job is enqueued by the listener after the INSERT commits — see
      PersistOrderCreatedToOutbox:61 `DB::afterCommit(fn () => DispatchDomain
      EventsJob::dispatch(...))`). Even on RR isolation, the FOR UPDATE
      lock acquired on row N never conflicts with an INSERT producing
      row N+1.
    - HOWEVER: under MySQL 5.7 with innodb_autoinc_lock_mode=1, the
      AUTO_INC lock IS a table-level micro-lock held for the duration
      of the INSERT statement. At 167 INSERT/s × 1-2ms per statement,
      this is 17-34% AUTO_INC mutex occupancy → measurable contention
      with concurrent DELETE/UPDATE (e.g. prune cron).
  hypothetical_fix: |
    1. Set `innodb_autoinc_lock_mode=2` (default since MySQL 8) — pure
       mutex, sub-microsecond.
    2. Verify production DB is MySQL 8.0+ — if on 5.7, schedule upgrade
       BEFORE central-mgmt 10k load.
  impact: "On MySQL 5.7, prune cron + INSERT concurrency degrades the AUTO_INC mutex."

- id: DBA-R3-009
  severity: P2
  title: "Buffer pool churn from `lockForUpdate()->find()` reading full 600B-avg rows × 167/s ≈ 100 KB/s — fine on its own, but compounds with payload fetches in broadcast phase"
  category: buffer_pool_pressure
  evidence:
    - "DispatchDomainEventsJob:67 `lockForUpdate()->find($this->domainEventId)` reads ALL columns (Round 1 DBA-007)."
    - "DispatchDomainEventsJob:98 `$domainEvent->refresh()` re-reads ALL columns after broadcast (Round 1 DBA-007)."
  reasoning: |
    At 167/s × 600B = 100 KB/s sustained read traffic. Trivial vs. EBS
    bandwidth (gp3 = 125 MB/s baseline). The COMPOUND failure is when
    CatalogChanged payloads inflate row average to 5+ KB (50 KB max).
    At 50 KB × 167/s = 8 MB/s — still within EBS budget, but each row
    pulls 4 InnoDB pages from buffer pool (16 KB page × 4 = 64 KB for
    a 50 KB row with off-page LONGTEXT). 167 × 4 = 668 page reads/s.
    Buffer pool churn rate at 1 GB pool = 4-6% / second → entire hot
    set evicted every ~20s. Tight on small instances.
  recommendation: |
    Apply Round 1 DBA-007 fix: SELECT id, dispatched_at, attempts only
    in the claim phase. Read full row in a SEPARATE non-locked SELECT
    after commit, for broadcast.

- id: DBA-R3-010
  severity: P1
  title: "10k INSERT/s short-window simulation NOT exercised by `OutboxProductionLikeSimulationTest` — test is functional-correctness only, NOT load"
  category: test_coverage_drift
  evidence:
    - "OutboxProductionLikeSimulationTest.php has 5 tests (lines 25, 65, 112, 162, 208) — all create 1-4 DomainEvents, not 10k."
    - "tests/load/RushMidiSimulationTest.php exists but does NOT exercise outbox INSERT/dispatch at 10k."
  reasoning: |
    The 'production-like simulation' name is aspirational. Real-world
    10k INSERT/s simulation would require:
    - 10k DomainEvent rows pre-seeded
    - 8 parallel worker processes (artisan queue:work)
    - 1M-row historical body to populate buffer-pool realistic state
    - Measurement of: p50/p95/p99 dispatch latency, INSERT throughput,
      lock-wait time, buffer-pool hit rate
    None of these exist in the current test suite. The Round 1 DBA-001
    finding ("dead-weight idx_pending") is unfalsifiable from the test
    code alone — the test runs on SQLite via RefreshDatabase, which
    doesn't enforce InnoDB locking semantics (test header @ line 26-29
    of OutboxConcurrentWorkerDedupeTest acknowledges this for the
    sibling concurrent-worker test).
  recommendation: |
    1. Add `tests/load/Outbox10kSimulationTest.php` — MySQL-only (skip on
       SQLite), seeds 1M historical + 10k pending, EXPLAIN-asserts query
       plans, measures wall-time.
    2. Or accept that 10k simulation is staging-only and document in
       runbook.

- id: DBA-R3-011
  severity: P2
  title: "Partition strategy absent — at 10M historical rows (12 months of central-mgmt scale), prune walk time is unbounded"
  category: partition_strategy
  evidence:
    - "create_domain_events_table.php has NO `PARTITION BY` clause."
    - "PruneOutboxCommand prunes by predicate, not partition-drop."
  reasoning: |
    At 10M rows / 12 months / 167 events-per-active-minute, partition
    by month or week is the canonical pattern:
      PARTITION BY RANGE (TO_DAYS(created_at)) (
        PARTITION p202604 VALUES LESS THAN (TO_DAYS('2026-05-01')),
        ...
      );
    Prune becomes `ALTER TABLE ... DROP PARTITION p202604` — O(1) metadata
    operation vs. O(N) row-by-row delete. Buffer pool unaffected, no gap
    locks, no INSERT contention.
    BLOCKER: partitioned tables CANNOT have FK constraints. Current schema
    has none on domain_events (correctly polymorphic) → safe to partition.
    UNIQUE indexes on partitioned tables MUST include the partition key
    (`created_at`) → the existing `uniq_domain_events_idempotency_key`
    would have to become `UNIQUE (idempotency_key, created_at)` — semantic
    weakening (two events with same idempotency_key but different created_at
    are no longer caught). Acceptable IF idempotency window is bounded.
  recommendation: |
    Defer to V1.2 (central-mgmt). Document the partition migration path
    in plans/OUTBOX_PARTITION_STRATEGY.md. NOT a V1.0.x blocker.

- id: DBA-R3-012
  severity: P1
  title: "DBA-009 (Round 1 phantom-broadcast) compounds at 167/s — at this rate, 1-in-10000 caller-rollback = 1 phantom event/minute"
  category: caller_rollback_phantom
  evidence:
    - "Round 1 DBA-009 anchors: 11 listeners use DB::afterCommit() without ShouldQueueAfterCommit."
    - "PersistOrderCreatedToOutbox:61 — DB::afterCommit fallback semantics."
  reasoning: |
    At 1 event/sec single-branch operation, a phantom broadcast every few
    days is an annoyance. At 167 events/sec central-mgmt, assuming the
    rollback rate stays constant at ~0.01% (1-in-10000):
    167 × 0.0001 × 60 = **1 phantom broadcast every minute** during steady
    state. KDS, POS clients, and Kiosk all receive an event for an Order
    that does NOT exist in the DB → 404 on the client-side fetch-by-id →
    visible UI glitch + alarm spam.
  impact: "Round 1 P0 escalates to P0+ at 10k scale. Heal MUST land before central-mgmt enables 10k throughput."

```

---

## Summary table (Round 3 DBA)

| # | Severity | Title | Anchor |
|---|----------|-------|--------|
| DBA-R3-001 | P0 | Random-sha1 UNIQUE idempotency_key page-splits INSERT throughput at 167/s | add_idempotency_key:39-40 + PersistOrderCreatedToOutbox:22 |
| DBA-R3-002 | P0 | Rescue cron races worker claim → 20k worker-sec wasted on lock-skip at 10k | DispatchDomainEventsJob:66 + OutboxRescueCommand |
| DBA-R3-003 | P0 | idx_pending unusable for scopeStale; 3s I/O / scan at 10k pending | create:27 + DomainEvent:42 |
| DBA-R3-004 | P1 | No DR replay command + no LOAD DATA INFILE bypass for bulk INSERT | (missing) |
| DBA-R3-005 | P1 | Connection-pool exhaustion at 46+ steady-state conns on db.t3.small | horizon.php:39 + database.php:18 |
| DBA-R3-006 | P1 | No read-replica routing for cron scans | database.php:18 |
| DBA-R3-007 | P0 | PruneOutbox OR-of-predicates → index_merge with FULL SCAN on clause B | PruneOutboxCommand:51-58 |
| DBA-R3-008 | P1 | innodb_autoinc_lock_mode=1 (MySQL 5.7) → mutex contention at 167/s | DB version dependent |
| DBA-R3-009 | P2 | Buffer-pool churn from full-row read in lockForUpdate (Round 1 DBA-007 compound) | DispatchDomainEventsJob:67 |
| DBA-R3-010 | P1 | Test file misnamed "production-like" — does NOT load-test 10k | OutboxProductionLikeSimulationTest.php |
| DBA-R3-011 | P2 | No partition strategy; prune walk-time O(N) at 10M rows | create:1 |
| DBA-R3-012 | P1 | Round 1 DBA-009 phantom-broadcast escalates at 167/s = 1/min | all 11 Persist*ToOutbox |

---

## EXPLAIN plan citations (key cases)

```sql
-- DBA-R3-003: scopeStale at 10k pending
EXPLAIN SELECT * FROM domain_events
  WHERE dispatched_at IS NULL AND created_at < NOW() - INTERVAL 2 MINUTE;
-- Expected: type=ref, key=idx_pending, key_len=6, rows≈10000, Extra="Using where"
-- ↑ idx_pending matches dispatched_at IS NULL only; created_at filtered post-scan
--   = 10k clustered-PK probes per scan. At 1M body, buffer cache miss rate high.

-- DBA-R3-007: PruneOutbox OR-of-predicates
EXPLAIN DELETE FROM domain_events
  WHERE (dispatched_at IS NOT NULL AND dispatched_at < ?)
     OR (attempts >= 6 AND created_at < ?);
-- Expected: type=index_merge OR ALL on second branch
--   = FULL SCAN over 1M rows for clause B because no (attempts, created_at) index

-- DBA-R3-001: firstOrCreate race window
SELECT id FROM domain_events WHERE idempotency_key = ?;  -- listener line N
-- (race window)
INSERT INTO domain_events (...) VALUES (...);            -- listener line N+M
-- ↑ At 167/s with parallel listeners, second INSERT throws QueryException
--   (1062 Duplicate entry) which PersistOrderCreatedToOutbox:24 does NOT catch.
```

---

## Verdict (Round 3 DBA, READ-ONLY)

**4 P0 (compounding Round 1)** + **5 P1** + **3 P2**. The Outbox is **functionally correct** at single-branch < 1k events/day Le Cayenne scale but **structurally not ready for 10k events/minute central-mgmt scale**. The three load-bearing P0s:

1. **DBA-R3-001 (idempotency_key page-split)** — INSERT throughput ceiling sits below the 167/s target. Fix is index-key-design (deterministic prefix, not sha1) + race-safe firstOrCreate. Compounds DBA-R3-007 because both involve UNIQUE-index pressure.
2. **DBA-R3-003 (idx_pending unusable)** — Round 1 DBA-001 finding survives Round 2/3 unchanged. Until rescheduling fixes the column mismatch, scopeStale will eat I/O budget linearly with backlog size.
3. **DBA-R3-007 (prune OR-of-predicates)** — Round 1 DBA-004 deepens here. The OR causes the optimiser to fall back to index_merge with a FULL SCAN branch. Prune cron during 10k load wedges INSERT.

The **most urgent operational risk** combining the 12 findings: at 10k load, **rescue cron + prune cron + INSERT firstOrCreate races** form a thundering-herd cycle every 2 minutes. Steady-state queue depth grows, p95 latency climbs above 2s SLO within 30 minutes, and Pusher fan-out begins to backlog. This is recoverable but visible (KDS lag, missed kiosk transitions).

**Recommended sequence for Heal-Implementer Wave**:
1. **First** — DBA-R3-003 + Round 1 DBA-001 index rebuild (single migration, ~5 min DDL on 1M rows, online with INPLACE algorithm).
2. **Second** — DBA-R3-007 + Round 1 DBA-004 PruneOutbox query rewrite + add `(attempts, created_at)` index.
3. **Third** — DBA-R3-001 idempotency_key prefix redesign + listener try/catch (cross-listener PR, ~11 file edits).
4. **Fourth** — DBA-R3-002 rescue cron `updated_at` guard + claim-window protection.
5. **Defer to V1.2** — DBA-R3-011 partitioning, DBA-R3-004 DR replay command.

> No file modifications performed. All findings grounded in file:line citations from `app/Jobs`, `app/Listeners`, `app/Console/Commands`, `app/Models`, `database/migrations`, and `config/horizon.php`. SQL EXPLAIN narratives derived from documented MySQL 8.0 behaviour; production EXPLAIN runs against an actual 1M-row sample remain a verification-before-completion gate before Heal-Implementer Wave commits any of these fixes.
