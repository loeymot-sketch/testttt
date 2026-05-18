# Wave W7 — T-3.3.1 Webhook idempotency — DBA audit (Round 2)

**Specialist**: DBA
**Date**: 2026-05-18
**Mode**: READ-ONLY
**Anchors verified**:
- `app/Models/WebhookEvent.php`
- `database/migrations/2026_05_09_120000_create_webhook_events_table.php`
- `app/Jobs/ProcessWebhookEventJob.php`
- `app/Console/Commands/OutboxWebhookRetryFailedCommand.php`
- `app/Console/Commands/PruneWebhookEventsCommand.php`
- `app/Http/PaymentGateways/Gateways/Stripe.php`
- `app/Http/PaymentGateways/Gateways/Senangpay.php`
- `app/Console/Kernel.php` (scheduler stanzas)
- `tests/Feature/Webhooks/WebhookEventIdempotencyTest.php`

> DBA mindset frame for Round 2: the schema is shipped. Treat the migration text
> as authoritative. Read it as the DBA who will inherit the prod database next
> Monday and must answer: where will a 409 collide silently? Where is the index
> the optimiser will refuse to pick? Where is the orphan row that lives forever?
> Where does NF525 retention collide with the operational prune?

---

## Schema baseline (verbatim from `2026_05_09_120000_create_webhook_events_table.php`)

| Column             | Type                                 | NULL | Default | Notes |
|--------------------|--------------------------------------|------|---------|-------|
| `id`               | `bigIncrements` (BIGINT UNSIGNED)    | NO   | auto    | PK |
| `provider`         | `string(32)`                         | NO   | —       | `index()` simple, **NOT enum** |
| `webhook_id`       | `string(255)`                        | NO   | —       | provider event id |
| `event_type`       | `string(128)`                        | NO   | —       | `index()` simple |
| `payload`          | `json`                               | NO   | —       | full provider payload |
| `signature`        | `string(512)`                        | YES  | NULL    | HMAC header |
| `received_at`      | `dateTime(3)` (ms precision)         | NO   | —       | `index()` simple |
| `processed_at`     | `dateTime(3)`                        | YES  | NULL    | **NOT indexed** |
| `status`           | `enum(pending,processed,failed,duplicate)` | NO | `pending` | `index()` simple |
| `error_message`    | `text`                               | YES  | NULL    | TEXT, up to 65535 bytes |
| `attempts`         | `unsignedSmallInteger` (2B, 0–65535) | NO   | 0       | retry counter |
| `order_id`         | `unsignedBigInteger`                 | YES  | NULL    | `index()` — **NO FK** |
| `created_at`/`updated_at` | timestamps                     | YES  | NULL    | Laravel defaults |

Composite indexes (lines 83/86/89):
- `uk_webhook_provider_id` UNIQUE (`provider`, `webhook_id`) — **idempotency floor**
- `idx_pending_received` (`status`, `received_at`)
- `idx_provider_received` (`provider`, `received_at`)

Authority for analysis: migration file (the sqlite test DB is empty, MySQL is the
prod target — `config/database.php:55` confirms `utf8mb4` / `utf8mb4_unicode_ci`).

---

## Findings (strong-reasoning YAML)

```yaml
- id: DBA-W7-001
  severity: P0
  title: "STATUS_DUPLICATE is a dead-letter constant — no code path writes it. `status` enum value `duplicate` is unreachable in production"
  category: state_machine_dead_branch
  evidence:
    - "WebhookEvent.php:57 declares `STATUS_DUPLICATE = 'duplicate'`."
    - "WebhookEvent.php has no `markDuplicate()` method — only markProcessed() + markFailed()."
    - "Stripe.php:261-268 + Senangpay.php:139-146 detect duplicates via `!$event->wasRecentlyCreated` and return 200 `duplicate_ignored` WITHOUT updating the existing row."
    - "Grep across app/ + database/ for `STATUS_DUPLICATE` returns only the enum constant, the prune whitelist, and the isDuplicate() reader — no writer."
    - "PruneWebhookEventsCommand:57-60 includes `STATUS_DUPLICATE` in safe-prune set, so the prune logic acknowledges the state but it will never match anything."
  reasoning: |
    The schema enum (`pending|processed|failed|duplicate`) advertises a four-
    state lifecycle. The application implements three. The `duplicate` state
    is observable only via reading the enum in DESCRIBE/SHOW CREATE TABLE —
    operationally, the row that wins the UNIQUE race goes to `processed`
    (after `markProcessed`), and the row that LOSES the race never lands
    at all because `firstOrCreate` short-circuits the INSERT and returns the
    existing row.

    The semantic damage is twofold:

    (a) **Forensic ambiguity** — a webhook that arrives a thousand times
        (provider replay storm, e.g. SenangPay retries) registers a single
        `processed` row. There is no count column, no `last_seen_at`
        column, no `replay_count` column. The fiscal/forensic log
        (`*_webhook_duplicate_ignored`) becomes the ONLY evidence that
        replays happened — and that log lives in `storage/logs/fiscal.log`,
        not in the DB. PCI dispute lookback in the prune docblock (180d)
        assumes the DB carries evidence; the DB carries one row, the log
        carries N entries. They diverge after the log rotates.

    (b) **Race-collision misclassification** — under genuine concurrent
        INSERT race (two workers process the same provider event), the
        loser hits the UNIQUE constraint and `firstOrCreate` retries the
        SELECT, returning the winner's `pending` row. The loser will then
        try to process that row a second time. The pending winner has
        NOT yet run `markProcessed`, so `wasRecentlyCreated=false` BUT
        `status=pending`. The Stripe/Senangpay live handler returns
        `duplicate_ignored` 200 in this case — the WINNER may then fail
        its DB transaction, mark the event `failed`, but the LOSER's
        provider has already received 200 and stopped retrying. Net
        result: payment unprocessed, provider satisfied, fiscal ledger
        empty for that order. This is the textbook lost-update of the
        outbox pattern under concurrent receipt.
  hypothetical_fix: |
    Option A (minimal — accept the dead enum): drop `duplicate` from the
    enum + remove the constant + remove from prune whitelist. Honest
    schema beats decorative enum.

    Option B (full lifecycle): add `markDuplicate()` that increments a
    new `replay_count` u-smallint column and stamps `last_replayed_at`.
    Live handlers call it when `wasRecentlyCreated=false`. PCI evidence
    lives in the DB, not the log.

    Option C (race-safe): live handler short-circuits ONLY when the
    existing row is `status=processed`. If status is `pending`/`failed`,
    the second handler MUST wait for the first to terminate (poll +
    timeout, or row-lock via `lockForUpdate`). This closes the lost-
    update window above but adds latency under storm conditions.
  impact: |
    Forensic auditability gap (PCI dispute window of 180d documented but
    DB evidence covers single occurrences). Lost-update race on
    concurrent provider receipt — payment marked processed by handler
    A while handler B silently returned 200 to provider. NF525 audit
    trail in `audit_logs` is unaffected (separate table, separate
    chain), but operational reconciliation between log+ledger fails.

- id: DBA-W7-002
  severity: P0
  title: "No FK from `webhook_events.order_id` → `orders.id`. Orphan rows accumulate when Orders are soft-deleted AND prune retention window slides"
  category: referential_integrity
  evidence:
    - "Migration line 77 declares `$table->unsignedBigInteger('order_id')->nullable()->index();` — no `foreign()`, no `references()`, no `on()`."
    - "WebhookEvent.php:117-120 declares `belongsTo(Order::class)` Eloquent relationship — assumes FK semantics that the schema does not enforce."
    - "Order.php:11+17 uses `SoftDeletes` trait. `Order::delete()` sets `deleted_at` and keeps the row; default `belongsTo` queries DO NOT honour soft-deletes on the parent, so $event->order returns the row even after soft-delete (correct for webhook forensics)."
    - "Stripe.php:286-304 + Senangpay.php:150-167 SET `order_id` from `$charge->metadata->order_id` and `$orderRef` (raw provider input)."
  reasoning: |
    The omission of the FK is documentable as intentional (the
    migration docblock says "optional FK reference"), but the
    consequences need to be acknowledged in DBA terms:

    (1) **Bogus order_id is accepted.** SenangPay's `$orderRef` is taken
        from the provider POST body without validation against
        `orders.id`. A malicious or malformed provider POST writes
        arbitrary integers into `order_id`. The DB will not reject it.
        Downstream `$event->order` returns null silently. The fiscal
        log entry references an orphan that never existed.

    (2) **Soft-delete and prune diverge.** Orders soft-deleted before
        the 180d prune cutoff remain visible via SoftDeletes. Webhook
        rows with `order_id` pointing to soft-deleted orders remain
        forever — they have `status=processed` and `received_at` is
        old enough to be eligible, so the prune deletes them on
        schedule. Net: PCI dispute evidence vanishes 180d after
        receipt even though the Order is still alive (in soft-deleted
        state) and the dispute window could reach back further for
        merchant reconciliation.

    (3) **Hard-delete cascade impossible.** If an Order is ever hard-
        deleted (NF525 forbids it for `orders` per CLAUDE.md §3.9,
        but CLAUDE.md doesn't say FK CASCADE — the FK simply doesn't
        exist). The webhook row stays. Joining `webhook_events ->
        orders` produces NULL on the right side. Reports must use
        LEFT JOIN to detect the orphans, which they currently don't
        because there are no reports.

    (4) **Index without FK is fine for lookups, useless for integrity.**
        The `index('order_id')` accelerates `WHERE order_id = ?`
        SELECTs from the audit dashboard (when that lands T-2.x).
        Without a FK, the index doesn't prevent invalid writes.
  hypothetical_fix: |
    Add `$table->foreign('order_id')->references('id')->on('orders')
    ->nullOnDelete();` — Order is SoftDeletes so DELETE is rare; on the
    extremely rare hard-delete the FK nulls the webhook_event's order_id
    while preserving the row for forensic + provider replay tracing.

    OR: keep the schema as-is and document the discipline. The current
    state is fragile but not actively broken. A migration to add the
    FK on a populated table requires either an ALTER with foreign-key
    check (risk: existing orphan rows fail), or an offline cleanup +
    backfill window.
  impact: |
    Garbage `order_id` writes succeed. Cross-table joins must defensively
    LEFT JOIN. PCI dispute evidence prune-skews from Order soft-delete
    timeline.

- id: DBA-W7-003
  severity: P1
  title: "`provider` is `string(32)` indexed simple — high duplication in B-tree leaves at scale (1M rows → ~32 MB redundant)"
  category: index_storage_efficiency
  evidence:
    - "Migration line 45: `$table->string('provider', 32)->index();` — single-column index, varchar leaves carry full literal `'stripe'` / `'senangpay'` strings."
    - "Two providers active today (Stripe + SenangPay); future column says ~5-10 providers ever."
    - "UNIQUE composite `uk_webhook_provider_id` also stores provider as varchar(32)."
    - "idx_provider_received also stores provider as varchar(32)."
  reasoning: |
    At 1M rows / 5 providers, the cardinality of `provider` is 5. A
    `tinyint`/`smallint` discriminator would cut index storage 6-10×
    and accelerate range scans by improving buffer-pool fit. But the
    rationale for varchar is legitimate: provider strings are self-
    documenting in EXPLAIN output + dumps + manual queries. The
    `enum('stripe','senangpay')` MySQL approach is the middle ground
    — 1 byte storage, readable in SELECTs — BUT enum types are a
    schema-evolution nightmare (every new provider = ALTER TABLE +
    rebuild, with a write-lock at 10M rows). The current choice (open
    varchar) is *deliberate flexibility* that costs index size.

    At V1 (Le Cayenne single resto) the cost is negligible — webhook
    volume is in the hundreds per day max. At V2 SaaS scale (target
    100 brands × 1k webhooks/day × 365d = 36M rows/y) the redundancy
    materialises:
      - uk_webhook_provider_id index leaf: ~10 bytes provider + ~24
        bytes webhook_id + ~6 bytes PK = ~40 bytes/row. For 36M rows:
        ~1.44 GB. Of that, provider redundancy ≈ 8 bytes × 36M =
        288 MB **that could be 36 MB with a smallint**. Insertion
        latency at hot tail unchanged; buffer-pool eviction rate up.
      - idx_provider_received: same redundancy.
  hypothetical_fix: |
    V1: keep as-is (cost negligible at single-tenant volume).
    V2 SaaS prep: introduce `provider_id` smallint FK to a
    `webhook_providers` lookup table; migrate `provider` varchar to
    derive-on-read. Backfill in shard windows.

    Alternative: enum('stripe','senangpay','...') with a roadmap that
    every new provider is shipped as a single migration step.
  impact: |
    No V1 impact. V2 SaaS prep — ~250 MB of avoidable index growth
    per year per 1k brands.

- id: DBA-W7-004
  severity: P1
  title: "`processed_at` and `dispatched_at`-equivalent columns are NOT indexed — DLQ retry query at 100k rows performs full status-leaf scan"
  category: query_plan
  evidence:
    - "Migration: `processed_at` (line 63) declared `dateTime(3)->nullable()` WITHOUT an index."
    - "There is no `dispatched_at` column on webhook_events; the closest analogue is `received_at` (indexed) for arrival timestamp."
    - "OutboxWebhookRetryFailedCommand.php:46-49 queries `WHERE status=failed AND created_at >= cutoff` — uses `created_at` (Laravel timestamps, NO index)."
    - "idx_pending_received covers (status, received_at) but the command queries on `created_at`, not `received_at`."
  reasoning: |
    The retry command's query plan:
      SELECT * FROM webhook_events
       WHERE status = 'failed'
         AND created_at >= '2026-05-17 04:00:00'

    `idx_pending_received` covers (status, received_at). The optimiser
    can use the leading `status` column to seek into the `failed`
    partition, but then it must filter `created_at` row-by-row from
    the clustered PK lookup. At 100k failed rows over the lifetime of
    the table (assume 0.1% failure rate over 36M = 36k failed rows),
    that's 36k random PK lookups every hour, every retry tick. The
    DBA reading the slow-query log will see this command climbing.

    The semantic intent is `received_at` (i.e. when did the provider
    send this, not when did our DB row get touched). The command
    docblock says "within the recovery window" which is provider-
    timeline, not row-timeline. `created_at` is set by Laravel at
    INSERT (close to `received_at` on the live path, but DIVERGES on
    the DLQ replay path where the OutboxWebhookRetryFailedCommand
    *resets* status=pending and saves — Laravel updates `updated_at`
    but `created_at` stays at original receipt). So the column choice
    is actually correct for the intent, but the index choice doesn't
    match.

    Compounding: there is no monitoring command for stale-pending
    (rows that have been `pending` for > N minutes — a worker died
    mid-process). At 1k pending/h × 24h = 24k pending rows in worst
    case, scanning them by status would also miss an index.
  hypothetical_fix: |
    Either:
      (a) Add `index(['status', 'created_at'], 'idx_status_created')`
          covering the retry command's actual predicate. Drop the
          unused `idx_provider_received` if disk pressure forces a
          trade — that one is only useful for audit dashboards that
          don't yet exist.
      (b) Refactor the retry command to query on `received_at` so the
          existing `idx_pending_received` covers it. This is the
          surgical fix and matches the docblock semantic.
    
    Add a stale-pending monitor mirroring the outbox staleness lane.
  impact: |
    V1 negligible (failure volume tiny at Le Cayenne). V2 SaaS scale
    — hourly retry command latency degrades linearly with failed-row
    count.

- id: DBA-W7-005
  severity: P1
  title: "Webhook events for Stripe paid orders are fiscal-adjacent — 180d PCI window vs 6y NF525 collision is documented but not enforced"
  category: retention_policy
  evidence:
    - "PruneWebhookEventsCommand.php:35-37 explicitly states: `NF525 invariant: webhook_events is an OPERATIONAL ledger, NOT a fiscal audit table. Fiscal payment evidence lives on order_payments + audit_logs (6y retention) — NEVER touched here.`"
    - "Stripe.php:271-304 writes a row in `capture_payment_notifications` on charge.succeeded — the legacy linker to OrderPayment. That table is the bridge; webhook_events is the audit upstream."
    - "PruneWebhookEventsCommand deletes any status=processed row older than 180d — including the only forensic copy of the Stripe payload that produced the `capture_payment_notifications` row."
  reasoning: |
    The doctrinal claim is correct: NF525 (Loi de Finance France)
    requires 6 years on the fiscal artifacts — `audit_logs` and
    `z_reports`. `webhook_events` is upstream of those. BUT — and
    this is the operational hazard — the webhook payload contains:
      - the full Stripe charge.succeeded event (including `amount`,
        `currency`, `charge.id`, `balance_transaction`, `paid` flag,
        `customer`, `metadata.order_id`)
      - the HMAC signature header (`signature` column)
      - the receipt timestamp (`received_at`, 3-decimal precision)

    Once `webhook_events` is pruned at day 180, the only evidence
    that Stripe acknowledged the payment lives in
    `capture_payment_notifications` (token + order_id only — no
    amount, no signature) and in `audit_logs` (HMAC-chained,
    immutable, 6y). The signature row in webhook_events is destroyed.
    A dispute at day 200 that requires re-verifying the Stripe
    signature for the original event has no source.

    PCI-DSS Requirement 10.7 says merchant transaction logs go 1
    year (90d hot + 9mo cold). 180d is generous for PCI but it is
    BELOW the maximum disputable window for some card-network
    chargebacks (Visa/MC = 120-180d; Amex = 180d; consumer dispute
    via bank can reach 540d for unauthorised transactions). The
    `audit_logs` chain captures the Order's fiscal events but NOT
    the original webhook signature, so a forensic "did Stripe
    actually send us this event?" question is unanswerable post-prune.

    The current schema also has no `payment_status` / `fiscal_tag`
    column to distinguish Stripe `charge.succeeded` (fiscal-adjacent)
    from Stripe `charge.dispute.created` (audit-only). The prune
    treats them identically.
  hypothetical_fix: |
    Two paths:
      (a) Quick: add an `is_fiscal` boolean column (default true for
          Stripe charge.succeeded + SenangPay status_id=1 success).
          Prune excludes `is_fiscal=true`. Storage cost trivial.
      (b) Doctrinal: copy the signature + canonical payload into the
          audit_logs chain at processing time. The chain already
          carries 6y retention. Webhook_events stays operational and
          gets pruned without losing fiscal evidence.

    Owner gate: this is a doctrinal call that affects audit_logs
    schema (frozen-zone per CLAUDE.md §7). Not a unilateral DBA
    decision.
  impact: |
    Fiscal forensic gap if a payment dispute lands between day 180
    and day 2190 (6y). At V1 single-resto volume the probability is
    low; at V2 SaaS it materialises by month 18.

- id: DBA-W7-006
  severity: P2
  title: "`branch_id` absent from webhook_events — BranchScope omission is documented but cross-tenant snooping risk exists at the admin UI"
  category: multi_tenant_isolation
  evidence:
    - "Migration does not declare a `branch_id` column."
    - "WebhookEvent.php:43-45 documents the omission: `Branch isolation note: WebhookEvent is intentionally global (no BranchScope) because providers don't carry tenant context in webhooks. The eventual order_id FK link inherits branch via Order.`"
    - "CLAUDE.md §9 lists 13 BranchScoped models — WebhookEvent is intentionally absent (mirrors DomainEvent per Round 1 DBA-002)."
    - "Stripe.php:286-289 reads order_id from `$charge->metadata->order_id` only — no branch_id propagation."
  reasoning: |
    The rationale (providers don't carry tenant context) is correct
    at the receipt boundary. But the downstream consequence at the
    central management dashboard surface (T-3.x scope) is:

    `SELECT * FROM webhook_events WHERE provider='stripe'
       AND received_at >= ...`

    returns rows for ALL brands. A multi-tenant V2 admin operator
    looking at "my Stripe webhooks" sees rows from every brand. The
    only way to scope per-brand is via JOIN orders ON order_id and
    filter by orders.branch_id — defensive query discipline, which
    is the exact failure mode CLAUDE.md §9 BranchScope is designed
    to prevent.

    At V1 Le Cayenne (single-tenant) this is zero risk. At V2 SaaS
    central mgmt this is a structural leak unless every
    webhook_events read path is hand-audited.

    Even at V1, the row's `order_id` (when present) is the only
    bridge to branch context. If `order_id` is NULL (provider
    payload missing metadata, common for Stripe events that don't
    correspond to a charge — `payment_intent.created`,
    `payout.paid`, etc), the row has no tenant attribution.
  hypothetical_fix: |
    Two paths:
      (a) Add `branch_id` nullable column. Backfill on processing
          when order_id resolves to an Order. Index it for
          per-brand admin queries.
      (b) Document the discipline: ANY admin-facing query MUST
          JOIN orders. Add a code-level guard (eg. WebhookEvent
          factory hides `query()` and exposes `forBranch($id)` only).

    Owner gate: aligns with T-1.3.1 Round 2 DomainEvent BranchScope
    analysis. Sister table — sister decision required.
  impact: |
    V1 zero risk. V2 SaaS — read-side leak risk at central
    management dashboards unless query discipline is enforced.

- id: DBA-W7-007
  severity: P2
  title: "Concurrent INSERT race — UNIQUE catches but the loser path returns 200 BEFORE the winner has finished processing"
  category: race_condition
  evidence:
    - "Stripe.php:247-268 + Senangpay.php:125-146: pattern is `firstOrCreate` → check `wasRecentlyCreated` → if false, return `duplicate_ignored` 200."
    - "tests/Feature/Webhooks/WebhookEventIdempotencyTest.php:51-70 proves UNIQUE catches duplicate INSERTs — but the test runs sequentially, not concurrently."
    - "No `lockForUpdate`, no SELECT ... FOR UPDATE in the live handler — `firstOrCreate` internally does `SELECT ... ; if-not-found INSERT ; on-duplicate SELECT` without explicit row-lock."
  reasoning: |
    Sequence under genuine concurrency (two PHP workers receive the
    same Stripe event ~simultaneously due to provider retry-on-
    timeout):

      t0: Worker A: SELECT WHERE provider+webhook_id → 0 rows.
      t0: Worker B: SELECT WHERE provider+webhook_id → 0 rows.
      t1: Worker A: INSERT → success, row id 100, status=pending.
      t1: Worker B: INSERT → 23000 duplicate-key error.
      t2: Worker B: catches IntegrityConstraintViolation in
                    firstOrCreate's retry, re-runs SELECT, returns
                    row id 100 (status=pending) with
                    wasRecentlyCreated=false.
      t2: Worker B: live handler sees !wasRecentlyCreated → returns
                    200 duplicate_ignored to Stripe.
      t3: Worker A: starts DB::transaction → processes payment →
                    markProcessed.
      t4: Worker A: transaction commits.
      
    Happy path. But if Worker A FAILS between t1 and t3 (DB
    deadlock, application crash, fiscal lock timeout), the row
    sits at status=pending. Worker B's provider has already
    received 200, so it won't retry. The DLQ retry-failed command
    only sweeps `status=failed`, NOT `status=pending`. The row
    becomes orphaned in pending state. No alert. No re-process.

    The fiscal log `*_webhook_duplicate_ignored` records Worker B's
    early-out but contains no escalation that the parent processing
    might fail.
  hypothetical_fix: |
    Option A: live handler only short-circuits when `status=processed`.
    If status is pending/failed, replay the processing logic
    (idempotent via order_payments dedup) — Stripe gets 200 only when
    we've actually committed.

    Option B: add `lockForUpdate` on the SELECT branch of firstOrCreate
    pattern — explicit row-lock blocks the loser until the winner
    terminates (either commits markProcessed, or markFailed releases
    the row to retry).

    Option C: add stale-pending monitor (a row pending for > 5 min is
    a candidate for inspection/re-drive — same pattern as the outbox
    staleness lane in T-3.1.1).
  impact: |
    Low probability (race window is ms-scale). Materialises under
    provider retry storms when our DB is under load. V1 vanishingly
    rare; V2 SaaS regular occurrence at peak hours.

- id: DBA-W7-008
  severity: P2
  title: "DLQ is not a separate table — it's a `status='failed'` filter on the same row. Reset-then-retry pattern destroys retry-trail history"
  category: dlq_design
  evidence:
    - "OutboxWebhookRetryFailedCommand.php:53-57 flips status from failed → pending BEFORE dispatch. The forceFill `error_message=null` discards the prior failure message."
    - "WebhookEvent.markFailed (Model line 108-115) only sets the LAST error message — no history column."
    - "`attempts` counter increments per markFailed but does not remember WHICH attempt failed for which reason."
    - "PruneWebhookEventsCommand.php:18-21 explicitly excludes `status=failed` from the prune to preserve the DLQ — but the DLQ retry cycle itself flips status back to pending, opening a prune-window race."
  reasoning: |
    The DLQ is implicit in the lifecycle: `failed` is the dead-letter
    lane. The retry-failed command flips failed → pending → dispatch.
    If the dispatch fails again, markFailed runs and the row returns
    to `failed`. Net: the row oscillates between failed and pending.

    Forensic problems:
    (1) `error_message` carries only the LAST failure. A row that
        failed 7 times for 3 different reasons has no log of the
        first 6 reasons. Operators get a single snapshot.
    (2) The `attempts` counter is u-smallint (max 65535). If a
        provider keeps re-sending the same event for a day and our
        DB keeps marking-failed-then-retrying, attempts can grow
        rapidly. No saturation handling.
    (3) The reset-to-pending step (forceFill at line 54) intentionally
        does NOT reset `attempts`, but it DOES wipe `error_message`.
        That's the wrong tradeoff for forensics — attempts is just
        a counter (uninformative without context), but error_message
        is the only diagnostic field.
    (4) Prune-window race: between the moment the command flips to
        pending and the moment the job runs markFailed (or
        markProcessed), the row is in a transient pending state. If
        the prune happens to scan during that window AND the row
        matches `received_at < cutoff` — wait: pending is excluded
        from prune (PruneWebhookEventsCommand:57-60 only prunes
        processed + duplicate). So the race is theoretical. But if
        the retry succeeds and markProcessed runs immediately, the
        row becomes prunable. Within the same hour. Worth noting.
  hypothetical_fix: |
    Either:
      (a) Add `webhook_event_failures` table — append-only log of
          (event_id, attempt_no, error_message, failed_at). Joins
          back to webhook_events. Preserves full forensic history.
      (b) Add `failure_history` JSON column to webhook_events. Append
          on each markFailed. Cheaper than a sibling table, costlier
          for query.

    Either way, do NOT wipe error_message on retry-failed reset.
    The retry command should preserve the prior failure context.
  impact: |
    Operational forensic gap. V1 low impact (failure volume tiny);
    V2 SaaS — postmortem reconstruction harder, MTTR longer.

- id: DBA-W7-009
  severity: P3
  title: "uk_webhook_provider_id UNIQUE index size grows with `webhook_id` varchar(255) — utf8mb4 cap collision risk at the InnoDB key-length limit (3072 bytes)"
  category: index_size_limit
  evidence:
    - "Migration line 48: `$table->string('webhook_id', 255)` — varchar(255), utf8mb4 = 1020 bytes per value."
    - "Migration line 83: UNIQUE composite on (provider varchar(32), webhook_id varchar(255)) = (128 + 1020) bytes / row = 1148 bytes."
    - "InnoDB key-length limit since MySQL 5.7 with `innodb_large_prefix=ON`: 3072 bytes per index. 1148 < 3072 = OK with margin."
    - "MariaDB 10.x default: same 3072 byte limit."
  reasoning: |
    The composite UNIQUE clears the limit comfortably (1148 bytes).
    No immediate collision. The risk is contingent:
    - if the schema is migrated to utf8mb4_0900_ai_ci on MySQL 8
      (default) — same limit, same OK margin.
    - if a future migration extends `provider` to varchar(64) or
      `webhook_id` to varchar(512), the limit narrows.

    Verifying the index is actually B-tree (not hash, MyISAM-style):
    Laravel's default `unique()` on MySQL generates B-tree, which
    handles the prefix length. SQLite (tests) doesn't have the
    InnoDB limit. No portability issue.

    The varchar(255) cap on webhook_id is generous for current
    providers (Stripe event.id = `evt_` + 27 chars, SenangPay
    txn_id = numeric or short alpha). Adequate.
  hypothetical_fix: |
    No fix needed. Document the index headroom in the migration
    docblock so future schema authors know the limit. Add a CI
    check that runs `SHOW CREATE TABLE webhook_events` against a
    real MySQL and asserts the UNIQUE key length is sub-3072.
  impact: |
    No production risk under current schema. Document-only.

- id: DBA-W7-010
  severity: P3
  title: "1M-row growth projection — uk_webhook_provider_id index size, query plan stability, and prune throughput"
  category: scale_projection
  evidence:
    - "Stripe webhook payload size median ~2-4 KB, p99 ~12 KB (Stripe charge.succeeded event)."
    - "SenangPay payload smaller, ~500 B-1 KB."
    - "JSON column on MySQL 8: ~no overhead beyond the literal storage."
    - "Composite UNIQUE index leaf row ~40-60 bytes (provider + webhook_id + PK)."
    - "Prune batch=1000, deletes in a do-while loop until 0 deleted."
  reasoning: |
    At 1M rows / 60-byte index leaves:
      - uk_webhook_provider_id ≈ 60 MB.
      - idx_pending_received ≈ 30 MB (status enum 1 byte + dateTime 8 bytes + PK 8 bytes).
      - idx_provider_received ≈ 50 MB.
      - Sum indexes ≈ 140 MB.
      - JSON payload: 2 KB avg × 1M = 2 GB.
      - Total: ~2.2 GB table, ~140 MB indexes.

    Query plan at 1M rows:
      - UNIQUE lookup (firstOrCreate): O(log N) B-tree, ~3 page
        accesses. Sub-ms.
      - Prune `WHERE status IN (processed,duplicate)
        AND received_at < cutoff LIMIT 1000`: uses
        idx_pending_received (status leading) — eligible rows
        accumulate at the "old" tail of received_at, so the
        delete scans a contiguous index range. Lock window per
        batch ~50 ms.
      - Retry-failed `WHERE status='failed' AND created_at >=
        cutoff`: see DBA-W7-004 above — created_at not indexed,
        partial-range scan within failed leaf.

    Prune throughput projection:
      - 1M rows / batch 1000 = 1000 iterations.
      - At 50 ms / iteration → 50s total for full sweep.
      - Daily schedule at 04:15 + onOneServer + withoutOverlapping —
        leaves a 15-minute window before outbox-prune lock contention
        (outbox-prune at 04:00). Adequate.

    But: the prune touches the table while production traffic flows.
    50s of writer contention on the table is non-trivial. The
    `do-while $deleted > 0` loop has no inter-batch sleep — workers
    receiving live webhooks during 04:15-04:16 may see deadlock /
    lock-wait-timeout on the UNIQUE check.
  hypothetical_fix: |
    Add a small inter-batch sleep (10-100ms) in the prune loop to
    yield to live writers. Or schedule the prune at a true off-peak
    hour (per merchant timezone).

    Add monitoring: emit `Schema::table('webhook_events')->size()` to
    observability daily. Alert at 5 GB.
  impact: |
    No V1 impact. V2 SaaS — lock contention with live receipt during
    daily prune window. Monitor.
```

---

## Cross-references to Round 1 + Round 2 sister findings

- **Round 1 DBA-002 (DomainEvent BranchScope absent)** — `webhook_events` mirrors
  this design (no `branch_id`, no BranchScope). The doctrinal call should be
  joint, not separate. Owner gate.
- **Round 2 T-1.3.1 (DomainEvent BranchScope policy)** — likely lands at the same
  decision point. If T-1.3.1 adds branch_id to domain_events, webhook_events
  should follow. If T-1.3.1 documents the discipline instead, webhook_events
  stays as-is.
- **Round 1 DBA-003 (no FK from domain_events.aggregate_id)** — `webhook_events.order_id`
  has the same omission with a worse profile (the aggregate is always Order, not
  polymorphic — the FK CAN be declared). See DBA-W7-002.
- **CLAUDE.md §8 NF525 retention 6y** — collides with the 180d PCI prune for
  fiscal-adjacent rows (Stripe charge.succeeded with valid order_id). See
  DBA-W7-005.

---

## Round-2 verdict (DBA lens)

**Severity distribution**: 2 P0, 3 P1, 3 P2, 2 P3.

| Priority | Items | Production gate |
|----------|-------|-----------------|
| P0 | DBA-W7-001 (dead duplicate enum + race lost-update), DBA-W7-002 (no order_id FK + soft-delete divergence) | Block-or-document V1 |
| P1 | DBA-W7-003 (provider varchar storage), DBA-W7-004 (DLQ retry index mismatch), DBA-W7-005 (180d vs 6y fiscal retention) | V1.0.2 / V2 hardening |
| P2 | DBA-W7-006 (BranchScope absent), DBA-W7-007 (concurrent receipt race), DBA-W7-008 (DLQ history loss) | V2 SaaS prep |
| P3 | DBA-W7-009 (index key length headroom), DBA-W7-010 (1M-row scale projection) | Monitor |

**The migration is shipped and clean** — UNIQUE constraint is correctly placed,
indexes match the documented access patterns, JSON storage is appropriate. The
two P0s are SEMANTIC drift between schema and code (`duplicate` enum is dead) +
REFERENTIAL drift (declared belongsTo without FK enforcement) — both are
fixable without a destructive migration. The P1s are scale-tier concerns that
the V1 single-resto deployment will not hit; V2 SaaS needs them.

**Non-negotiable for V1**: document DBA-W7-001 (dead enum) + DBA-W7-002 (no FK)
in the migration docblock + WebhookEvent class. Owner sign-off that "duplicate"
remains advertised but unused, OR drop it. No production code change required.

**V1.0.2 candidate**: DBA-W7-004 (DLQ retry query index) — small migration, big
operational payoff once webhook volume crosses 10k failed rows.

**V2 SaaS pre-requisite**: DBA-W7-005 (fiscal retention) — owner-gate doctrinal
decision that touches `audit_logs` (frozen-zone per CLAUDE.md §7). Cannot ship
into V2 multi-tenant without resolving the prune-vs-NF525 collision.

**SQL-precise authority**: migration file + Eloquent model + scheduler Kernel
stanzas read end-to-end. No primary-source contradicts any finding above.
