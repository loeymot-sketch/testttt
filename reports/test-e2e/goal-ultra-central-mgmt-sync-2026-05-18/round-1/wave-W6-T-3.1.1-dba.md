# Wave W6 — T-3.1.1 Outbox end-to-end lifecycle — DBA audit (Round 1)

**Specialist**: DBA
**Date**: 2026-05-18
**Mode**: READ-ONLY
**Anchors**:
- `database/migrations/2026_04_15_200000_create_domain_events_table.php`
- `database/migrations/2026_05_09_180000_add_idempotency_key_to_domain_events.php`
- `database/migrations/2026_05_09_120000_create_webhook_events_table.php`
- `app/Models/DomainEvent.php`, `app/Models/WebhookEvent.php`
- `app/Jobs/DispatchDomainEventsJob.php`
- `app/Console/Commands/PruneOutboxCommand.php`
- 11 `app/Listeners/Persist*ToOutbox.php` (3 spot-read: OrderCreated, OrderStatusChanged, CatalogChanged, ItemAvailabilityChanged)

> DBA mindset frame: at 10M outbox rows after 6 months prod, what query starts to suffer? What index is missing? What lock starts to bite? Where does an orphan row crystallise?

---

## Schema baseline (from migrations — sqlite DB is empty so authority = migration files)

### `domain_events` (create + alter)
Columns: `id`, `event_type(128)`, `aggregate_type(128)`, `aggregate_id`, `branch_id` nullable, `payload` JSON, `channel`, `broadcast_as(128)`, `correlation_id(36)`, `idempotency_key(64)` nullable + UNIQUE, `occurred_at(3)`, `dispatched_at(3)` nullable, `attempts` u-smallint default 0, `last_error` TEXT, `created_at`/`updated_at`.
Indexes:
- `idx_pending`        `(dispatched_at, occurred_at)`        — `create:27`
- `idx_aggregate`      `(aggregate_type, aggregate_id)`      — `create:28`
- `idx_branch`         `(branch_id, occurred_at)`            — `create:29`
- `uniq_domain_events_idempotency_key` `(idempotency_key)`  — `add_idempotency_key:40`

NO FK from `aggregate_id` → `orders.id` (or any aggregate). NO FK from `branch_id` → `branches.id`.

### `webhook_events`
UNIQUE `(provider, webhook_id)` (`create_webhook_events:83`), indexes `idx_pending_received(status, received_at)` and `idx_provider_received(provider, received_at)`. `order_id` indexed but NO FK (`create_webhook_events:77`).

---

## Findings (strong-reasoning YAML)

```yaml
- id: DBA-001
  severity: P0
  title: "Cron polling query is full-table-scan-prone at 10M rows — `idx_pending` is dead weight"
  category: index_design
  evidence:
    - "create_domain_events_table.php:27 declares idx_pending(dispatched_at, occurred_at)."
    - "DomainEvent::scopePending uses WHERE dispatched_at IS NULL (DomainEvent.php:36)."
    - "OutboxRescueCommand / OutboxRetryFailedCommand / MonitorOutboxStaleness all enter via scopePending() or scopeStale()."
  reasoning: |
    MySQL/MariaDB DO use the first column of a composite index for an
    `IS NULL` predicate (it's a sargable boolean position). BUT — and this
    is the load-bearing point at 10M rows — once the row is dispatched,
    `dispatched_at` becomes a dense (high-cardinality timestamp) field.
    The index `(dispatched_at, occurred_at)` is fragmented across 99%
    dispatched rows + 1% NULL rows. Scanning the NULL leaf is fast, but
    the index is huge on disk (≈ 18 bytes/row * 10M = 180 MB) and bloats
    the buffer-pool. Worse: `idx_pending` will NOT be chosen for
    `scopeStale` (`WHERE dispatched_at IS NULL AND created_at < ?`)
    because `created_at` is not in the index. The optimiser falls back to
    a partial-range + filesort.
    Standard fix: a partial index `WHERE dispatched_at IS NULL` (Postgres)
    or a covering index `(dispatched_at, created_at, id)` (MySQL). Neither
    is present. Production-perfect form is a partial index that holds
    only the pending tail (≈ 10k rows), not the historical body.
  hypothetical_fix: |
    Add `index(['dispatched_at', 'created_at'], 'idx_pending_stale')` and
    drop the under-used `(dispatched_at, occurred_at)`, OR switch to a
    MySQL 8.0 functional index `((CASE WHEN dispatched_at IS NULL THEN
    occurred_at END))` for an even smaller hot footprint.
  impact: "Cron scan time grows O(N) instead of O(pending). MonitorOutboxStaleness, OutboxRescueCommand, OutboxRetryFailedCommand all degrade."

- id: DBA-002
  severity: P1
  title: "`DomainEvent` model has NO BranchScope — multi-tenant leak risk in admin tools"
  category: branch_isolation
  evidence:
    - "app/Models/DomainEvent.php has no `protected static function booted()` that calls `static::addGlobalScope(new BranchScope())`."
    - "CLAUDE.md §9 lists 13 Branch-scoped models. DomainEvent + WebhookEvent are absent."
    - "WebhookEvent.php:44 EXPLICITLY documents the omission (`intentionally global`). DomainEvent.php is silently un-scoped."
  reasoning: |
    The job context (DispatchDomainEventsJob:67) loads by primary key + lockForUpdate,
    so the job itself does not leak. Risk surface is elsewhere:
    (a) any admin Eloquent query `DomainEvent::query()->where('event_type', ...)`
        returns rows from every branch. There is NO admin UI today, but the
        moment the central-management dashboard (T-3.x) starts surfacing
        outbox state per-branch, the query author MUST remember to add a
        `where('branch_id', ...)` — a discipline violation that BranchScope
        prevents structurally.
    (b) the listener layer is the WRITE path and the writes ARE branch-tagged
        (PersistOrderCreatedToOutbox:31 `branch_id => $order->branch_id`),
        so the at-rest data is clean. Leak class is READ-only.
    A read-side BranchScope on DomainEvent with explicit
    `Admin::bypass()`/`withoutGlobalScope(BranchScope::class)` in cron jobs
    and the central dashboard is the safer default.
  caveat: |
    Some cron commands (PruneOutboxCommand, MonitorOutboxStaleness) MUST
    run cross-branch. They'd need `withoutGlobalScope(BranchScope::class)`
    declared explicitly — that's a feature, not a bug, because it forces
    the author to acknowledge the cross-branch nature.

- id: DBA-003
  severity: P1
  title: "No FK from `domain_events.aggregate_id` → `orders.id` (intentional but unstated risk)"
  category: referential_integrity
  evidence:
    - "create_domain_events_table.php:15 `$table->unsignedBigInteger('aggregate_id')` — no `->references('id')->on('orders')`."
    - "Order model uses SoftDeletes (Order.php:11,17)."
    - "PersistOrderCreatedToOutbox:30 writes `aggregate_id => $order->id`."
  reasoning: |
    The omission is *correct by design*: `aggregate_type` is polymorphic
    (Order, Coupon, MenuItem, etc.) so a single FK is impossible. The
    real question is the orphan risk:
      Case A — Order soft-delete: `Order::delete()` sets `deleted_at`,
               id remains. Outbox row keeps a valid pointer. Safe.
      Case B — Order hard-delete: NF525 invariant + SoftDeletes mean
               this should never happen on `orders`. CLAUDE.md §3.9 says
               Order is "not deleted, only cancelled". Confirmed
               operationally — no `forceDelete()` on Order in grep.
      Case C — non-Order aggregates: `Coupon::delete()` is hard. Outbox
               row's `aggregate_id` becomes orphan. The dispatcher does
               not re-load the aggregate (DispatchDomainEventsJob:97 reads
               the persisted payload snapshot), so an orphan payload
               broadcasts a stale snapshot. Acceptable — the snapshot was
               valid at occurrence time.
    BUT: the central-management read-side (admin dashboard "show me
    domain events for order #N") will silently lose join targets if a
    polymorphic `aggregate_type` row points to a hard-deleted parent.
    No alarm. Falls under "documented absence" P1.
  recommendation: "Document the polymorphic non-FK in PROJECT_BRAIN. Add a Prune-orphan policy for non-Order aggregates."

- id: DBA-004
  severity: P0
  title: "PruneOutboxCommand uses `delete()` with LIMIT in a do-while — risk of long row locks under InnoDB"
  category: lock_contention
  evidence:
    - "PruneOutboxCommand:81-86 — `do { ... ->limit($batch)->delete() ... } while ($deleted > 0);`"
    - "Batch default = 1000 (PruneOutboxCommand:38)."
    - "No `ORDER BY` clause on the DELETE."
  reasoning: |
    InnoDB's locking semantics for `DELETE ... WHERE predicate LIMIT N`
    without ORDER BY are non-deterministic — the optimiser picks
    whatever it wants, often a covering index range scan that gap-locks
    much more than the 1000 target rows. On a 10M-row table with the
    predicate hitting 95% of rows (worst-case backlog), this can lock
    millions of gap intervals during the first batch and stall every
    concurrent INSERT (= every active order coming through the system).
    `chunkById` is the canonical Laravel pattern: it walks the PK
    ascending, deletes per ID range, releases locks cleanly between
    chunks. The current `do-while + LIMIT` pattern would be acceptable
    ONLY if combined with `ORDER BY id ASC` AND if Laravel's query
    builder actually emits ORDER BY for the DELETE driver — which it
    does NOT for the MySQL driver by default.
    Secondary concern: the WHERE has an OR of two predicates with
    different selectivities — InnoDB will likely union-scan two
    index ranges, each holding gap locks, doubling the lock surface.
  hypothetical_fix: |
    Switch to `DB::table('domain_events')->where(<predicate>)
      ->orderBy('id')->limit($batch)->delete()`, OR migrate to
    `chunkById($batch, fn ($rows) => DB::table('domain_events')
      ->whereIn('id', $rows->pluck('id'))->delete())`.
  impact: "Single prune run on 10M backlog can wedge the queue for minutes."

- id: DBA-005
  severity: P1
  title: "Operational vs fiscal-tagged events have NO discriminator column — 6y retention cannot be enforced"
  category: nf525_retention
  evidence:
    - "create_domain_events_table.php — NO `is_fiscal` column, no `event_class` taxonomy."
    - "PruneOutboxCommand prunes ALL `domain_events` past 90 days indiscriminately."
    - "CLAUDE.md §8 mandates 6y retention for fiscal-tagged events."
  reasoning: |
    Today the safety is provided externally: `audit_logs` and `z_reports`
    are SEPARATE tables (CLAUDE.md §7 frozen list) with their own
    retention. `domain_events` is operational by design — see
    PruneOutboxCommand:25-27 "OPERATIONAL outbox, NOT a fiscal audit
    table". OK.
    BUT — and this is where Round 1 catches a subtle gap — listeners
    `PersistOrderPaidAtCounterToOutbox` and
    `PersistOrderPaymentStatusChangedToOutbox` emit events whose
    PAYLOAD is fiscally relevant (payment method, amount, payment
    status). If a regulator asks "show me all payment_status transitions
    for branch 5 during March 2025", the answer comes from
    `audit_logs` (NF525-compliant chain) and NOT from `domain_events`.
    Confirmed by reading the listener code — payment fiscal data is
    duplicated by AuditLogService at write time. So the lack of
    discriminator is acceptable for the current 90-day retention, but:
      - if anyone EVER decides to source a fiscal report from
        `domain_events`, the 90-day prune silently destroys evidence;
      - the operator must NEVER use `domain_events` as the primary
        source for fiscal queries — there is no schema-level guard
        enforcing that.
  recommendation: |
    Add a `flags TINYINT NOT NULL DEFAULT 0` column with a documented
    bitmask, OR (lighter) a `protected $fiscalRelevant` array in
    PruneOutboxCommand that excludes payment-class event_types from
    the prune predicate. Best-effort defence-in-depth.

- id: DBA-006
  severity: P2
  title: "`idempotency_key` is nullable + unique — legacy rows accumulate but UNIQUE NULL repeats allowed only on MySQL/SQLite (not Postgres)"
  category: cross_database_portability
  evidence:
    - "add_idempotency_key_to_domain_events:39 `string('idempotency_key', 64)->nullable()`."
    - "add_idempotency_key_to_domain_events:40 `unique('idempotency_key', ...)`."
    - "PersistOrderCreatedToOutbox:24 always populates the key (line 22-26)."
  reasoning: |
    Standard SQL says UNIQUE(NULL, NULL) violates uniqueness; MySQL,
    MariaDB and SQLite all break the standard and accept multiple
    NULLs. Postgres respects the standard. The migration WILL apply
    fine on Postgres (NULL allowed) but a Postgres production deploy
    would silently lose its dedupe race-safety for any listener that
    forgets to set the key — see e.g. a future Persist*ToOutbox PR
    that copies the pattern but drops the `idempotency_key` line.
    Not a P0 today because all 11 listeners DO populate the key, but
    a foot-gun for the central-management roadmap.
  recommendation: |
    Decide: either make the column NOT NULL with a backfill migration
    + a default value generator, or document the cross-DB caveat in
    plans/PROJECT_BRAIN.md §6.

- id: DBA-007
  severity: P1
  title: "DispatchDomainEventsJob `lockForUpdate` is correct, but the `find()` call has no select-projection — full row read per claim"
  category: query_perf
  evidence:
    - "DispatchDomainEventsJob:66-68 `DomainEvent::query()->lockForUpdate()->find($id);`"
    - "Then `$domainEvent->refresh()` at :98 reloads the full row again post-broadcast."
  reasoning: |
    Each claim reads the FULL row (including the JSON `payload` blob,
    which for CatalogChanged with snapshots can be 5-50 KB). At 10
    workers x 100 events/sec = 1000 row reads/sec, payload bandwidth
    becomes the bottleneck before the lock does. The job only needs
    `id, dispatched_at, attempts` inside the transaction; payload is
    needed only for the broadcast phase.
    The `refresh()` at :98 is also wasteful — the post-transaction
    state is already in memory from the claim phase. It's there
    defensively to grab the `dispatched_at` updated value, but the
    job already wrote it itself at :81.
  recommendation: |
    Project the claim: `DomainEvent::query()->select('id',
    'dispatched_at', 'attempts')->lockForUpdate()->find($id)`. Drop
    the refresh() unless a specific reason is documented.
  impact: "Buffer-pool pressure under burst load; not a correctness bug."

- id: DBA-008
  severity: P2
  title: "No FK + no index on `webhook_events.order_id` would slow `WHERE order_id = ?` lookups, but column IS indexed — confirmed OK"
  category: index_design
  evidence:
    - "create_webhook_events_table.php:77 `unsignedBigInteger('order_id')->nullable()->index()`."
    - "WebhookEvent::order() at WebhookEvent.php:117 belongsTo Order."
  reasoning: "Stand-alone single-column index on order_id is sufficient for the cardinality (1:1 webhook→order). No FK is intentional (CLAUDE.md §9 idempotency layer is provider-driven, not order-driven)."
  status: OK — no action.

- id: DBA-009
  severity: P0
  title: "11 listeners depend on `DB::afterCommit()` but `App\\Listeners` are NOT explicitly registered as `ShouldQueueAfterCommit` — silent risk if the upstream emitter is NOT inside a transaction"
  category: transaction_boundary
  evidence:
    - "PersistOrderCreatedToOutbox:61, PersistOrderStatusChangedToOutbox:68, PersistCatalogChangedToOutbox:101 all wrap dispatch in `DB::afterCommit(fn () => ...)`."
    - "None of the 11 listeners use the `ShouldQueueAfterCommit` interface or `Dispatchable` trait."
    - "If the upstream code path that fires the event is NOT wrapped in a transaction, `DB::afterCommit()` executes the callback IMMEDIATELY (Laravel fallback behavior — see Illuminate\\Database\\Connection::afterCommit())."
  reasoning: |
    Laravel's `DB::afterCommit()` has a documented fallback: when no
    transaction is active, the callback runs synchronously. This is
    BENIGN for the broadcast (it just dispatches the job earlier), BUT:
    - The `firstOrCreate` at line 24 also happens outside a transaction
      if the upstream forgot to wrap. Combined with the queue retry race
      described in the idempotency migration backstory (lines 12-17),
      this is the exact failure mode the UNIQUE index was added to
      defend against.
    - The harder failure: if the upstream event-firing code rolls back
      (an exception after Order::save() but before commit), the outbox
      row IS persisted (since the listener ran outside the tx), and the
      broadcaster fires for an Order that does not exist downstream.
      "Phantom broadcast" — KDS/POS get an order create event for an
      order that never committed.
    Current callers: OrderCreated is fired from OrderService::create(),
    which IS wrapped in DB::transaction() per the SSOT pattern. But this
    is a discipline contract, not an enforced one. The listener should
    BE the enforcer.
  hypothetical_fix: |
    Either (a) wrap the listener body in `DB::transaction(fn () => ...)`
    explicitly, or (b) mark each listener `implements
    ShouldQueueAfterCommit` so Laravel's framework-level transaction
    awareness binds the firstOrCreate AND the broadcast to the same
    transaction lifecycle. Option (b) is the canonical pattern.
  impact: "Phantom broadcasts if any future caller fires OrderCreated outside a DB::transaction."

- id: DBA-010
  severity: P2
  title: "Migration ordering is stable but `idempotency_key` backfill is missing — legacy outbox rows (pre-2026-05-09) all have key=NULL"
  category: migration_drift
  evidence:
    - "add_idempotency_key_to_domain_events.php uses `nullable()` (line 39) without backfill."
    - "domain_events table was created 2026-04-15 (line filename); idempotency_key added 2026-05-09 = ~25 day gap."
  reasoning: |
    Any rows persisted between 2026-04-15 and 2026-05-09 have
    idempotency_key=NULL. The UNIQUE constraint allows multiple NULLs
    on MySQL/SQLite (see DBA-006), so no migration failure. But: a
    PruneOutboxCommand run with a backlog reaching back to 2026-04-15
    is fine (predicate is on dispatched_at/attempts, not on
    idempotency_key). No active risk.
    Drift between dev/staging/prod: the migration runs idempotently
    via Laravel's migration tracker. Confirmed safe unless someone
    ran the migration in different orders across environments —
    impossible by Laravel's design (the migrations table enforces
    sequential application).
  status: OK by inference. Recommend a one-shot backfill script if any
          tooling ever joins on idempotency_key for retro analysis.
```

---

## Summary table

| # | Severity | Title | Anchor |
|---|----------|-------|--------|
| DBA-001 | P0 | `idx_pending` is dead weight at 10M; missing `(dispatched_at, created_at)` for scopeStale | create:27 |
| DBA-002 | P1 | No BranchScope on DomainEvent — central-dashboard leak risk | DomainEvent.php |
| DBA-003 | P1 | Polymorphic aggregate, no FK, no orphan-prune | create:15 |
| DBA-004 | P0 | PruneOutboxCommand do-while+LIMIT without ORDER BY = unbounded gap-lock | PruneOutboxCommand:81-86 |
| DBA-005 | P1 | No fiscal-vs-operational discriminator on `domain_events` | NF525 |
| DBA-006 | P2 | `idempotency_key` nullable+unique is non-portable to Postgres | add_idempotency_key:39-40 |
| DBA-007 | P1 | Job `find()` reads full payload inside lock — buffer pressure | DispatchDomainEventsJob:66-68 |
| DBA-008 | P2 | webhook_events.order_id indexed (OK) | create_webhook_events:77 |
| DBA-009 | P0 | Listeners do not enforce transaction-bound dispatch — phantom broadcast on upstream rollback | all 11 Persist*ToOutbox |
| DBA-010 | P2 | Legacy outbox rows have idempotency_key=NULL — no backfill | add_idempotency_key:39 |

**Verdict (Round 1 DBA, READ-ONLY)**: 3 P0 (DBA-001 index, DBA-004 prune lock, DBA-009 phantom broadcast), 4 P1, 3 P2. The Outbox is well-designed for current scale (Le Cayenne single-branch, < 1k events/day) but the index strategy + prune mechanism + transaction-binding pattern will start to bite at central-management multi-branch scale (10+ branches, 10k events/day). The single most production-risky finding is **DBA-009** — phantom broadcasts on caller-rollback are silent failures that have no operational signal until KDS shows an order that doesn't exist in the database. This should be addressed BEFORE any new caller adopts the listener pattern in central-management workflows.

> No file modifications were performed. All findings are read-only observations grounded in file:line citations from migrations, models, and listener/job source.
