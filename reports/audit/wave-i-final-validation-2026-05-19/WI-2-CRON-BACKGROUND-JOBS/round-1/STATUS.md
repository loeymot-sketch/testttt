# WI-2 — Cron + Background Jobs Final Audit (Round 1)

Wave I Final Validation 2026-05-19. AUDIT-ONLY, read-only. Branch
`v1-0-1-hardening-2026-05-17` HEAD `1e7c65ecc`. 3 specialists run in
parallel (Architect / SRE / RED-team), each ≤ 1500 words.

## Scope

- `app/Console/Kernel.php` — schedule() inventory (15 entries)
- `app/Console/Commands/*.php` — 30 files
- `app/Jobs/*.php` — 4 ShouldQueue classes (Dispatch / ProcessWebhook
  / SendFcm / SloEvaluator) + 1 sync scheduler job
  (CleanupStalePendingKioskOrders)
- `config/queue.php` + `config/horizon.php`
- Cross-reference: WH-3 (ResetStaleDailyQuota TZ), Wave 3 (Outbox
  audit-then-dispatch), Wave 3c (lock TTL 60→300s + BATCH_CAP=500),
  Wave 2d FISCAL-ADV3C-01 (activeBranchIds helper)

## Verdict

**GO** for V1 LOCAL ship, **CONDITIONAL** on V1.0.2 follow-up. **1 P1
propagation gap** caught by both Architect and RED-team
cross-validation. No NF525 risk, no V1 LOCAL ship blocker. The P1 is a
**post-migration silent DOS** that only materializes when the
owner-flagged `UPDATE branches SET status=5 WHERE status=1` migration
runs (acknowledged in `Kernel.php:262-285` `activeBranchIds()`
docstring).

## 4-list

### P0
*(none — fiscal lanes are healed, NF525 chain monitor is multi-branch
post Wave 2d, outbox replay is audit-then-dispatch since Wave 3.)*

### P1 — 1 finding

- **WI-2-P1-01: Silent DOS post Status::ACTIVE migration in 2 crons.**
  Cross-validated by Architect (WI-2-ARCH-01) and RED (WI-2-RED-01).
  - `app/Jobs/Observability/SloEvaluatorJob.php:48` —
    `Branch::query()->where('status', 1)->get()`
  - `app/Console/Commands/StockScanRupture.php:168` —
    `$query->where('status', 1)->get()`
  - Wave 2d FISCAL-ADV3C-01 healed this exact bug class in the fiscal
    lanes by introducing `Kernel::activeBranchIds()` (whereIn ACTIVE,
    1). The propagation did not reach these two sites.
  - Post-migration impact: every 5-minute SLO sweep produces zero
    ActionLog entries, zero slo_breach broadcasts, zero Slack alerts.
    Every 5-minute auto-86 cron stops flipping rupture rows. Both
    crons return success silently (zero-iteration foreach).
  - Fix: replace both with
    `whereIn('status', [\App\Enums\Status::ACTIVE, 1])` or
    `App\Console\Kernel::activeBranchIds()`. Add sentinel tests.

### P2 — 7 findings

- **WI-2-P2-01: OutboxRescueCommand inconsistent with retry-failed
  siblings.** Architect WI-2-ARCH-02 + RED WI-2-RED-02. No Cache::lock
  LOCK_KEY, no BATCH_CAP, no audit_log.write per replay, no
  Log::channel summary. Worker-down + recovery scenario can saturate
  the `high` queue lane (12k stale events × 6 retry attempts each).
- **WI-2-P2-02: Otp-purge inline closure has zero observability AND
  missing `onOneServer()`.** SRE WI-2-SRE-01 + WI-2-SRE-08.
  `Kernel.php:29-34` — no Log call, no count return, no error
  handling. Silent failure mode. ALSO line 34 chains only
  `->withoutOverlapping()` without `->onOneServer()` — the only
  scheduled lane in the file missing the cross-host serialization
  primitive that the W9-AUDIT FIX-6 docstring (Kernel.php:36-39)
  explicitly mandates. Two adjacent gaps in one closure, one fix.
- **WI-2-P2-03: PosPurgeParkedOrders zero Log calls.** SRE
  WI-2-SRE-04. Only `$this->info` to stdout. Daily 03:15 cron with
  no logged trail.
- **WI-2-P2-04: RetryFiscalAllocCommand per-tick summary missing.**
  SRE WI-2-SRE-03. Per-event success/failure logged, but no
  aggregate Log::channel summary per tick.
- **WI-2-P2-05: CleanupStalePendingKioskOrders missing per-order
  isolation.** Architect WI-2-ARCH-03. `each(function ($order) {
  DB::transaction(...) })` with no outer try/catch. One throw
  abandons the batch. WG-2 listener-isolation pattern not
  propagated.
- **WI-2-P2-06: Inline scheduler closures abandon partial-failure
  context to log channel only.** RED WI-2-RED-03. The fiscal chain
  monitor + fiscal archive daily closures return void on
  per-branch failure; scheduler records run as success regardless.
- **WI-2-P2-07: Otp-purge missing `onOneServer()` chain.** SRE
  WI-2-SRE-08. See WI-2-P2-02 — this is the second half of the
  same closure's gap, broken out separately to ensure the
  horizontal-scaling lens is explicit. The fix is one method call.

### P3 — 6 findings

- WI-2-P3-01: schedule->call inline closures not unit-testable
  (Architect WI-2-ARCH-04 — sister of P2-06).
- WI-2-P3-02: RetryFiscalAllocCommand 5-min mutex vs unbounded
  backlog (SRE WI-2-SRE-05) — bounded in practice by inner
  lockForUpdate.
- WI-2-P3-03: Hour-budget concentration on fiscal lanes (SRE
  WI-2-SRE-06) — V1 single-branch = invisible cost; V2 multi-tenant
  risk.
- WI-2-P3-04: Horizon trim recent_failed=7d aggressive vs PCI 180d
  (SRE WI-2-SRE-07) — dashboard-only, DB persists.
- WI-2-P3-05: Orphan PENDING webhook_events after DLQ reset failure
  (RED WI-2-RED-04 downgrade) — orthogonal to WI-2 scope.
- WI-2-P3-06: OutboxRescueCommand attempts<5 boundary vs
  retry-failed >=5 boundary (RED WI-2-RED-05) — VERIFIED partition
  is clean.

### Verified-safe negatives (transparency)

- WI-2-NEG-01: FiscalArchiveCommand TZ-trap NOT a WH-3 sibling
  (RED WI-2-RED-06). `config('app.timezone') = 'Europe/Paris'`,
  Carbon::parse defaults to Paris, daily archive operates on
  TIMESTAMP columns (TZ-aware), not DATE columns. WH-3 bug class
  does not apply.
- WI-2-NEG-02: ProcessWebhookEventJob retry loop bounded
  (RED WI-2-RED-07). $tries=3, BATCH_CAP=500 on hourly DLQ retry,
  no infinite loop.
- WI-2-NEG-03: Mutex coverage = 14/15 lanes have both
  `withoutOverlapping` AND `onOneServer`. The single outlier is the
  otp-purge inline closure at Kernel.php:34 — see WI-2-P2-02. The
  W9-AUDIT FIX-6 docstring at Kernel.php:36-39 establishes both
  primitives as the project standard for horizontal-scaling safety.
- WI-2-NEG-04: Horizon $tries on each Job matches the supervisor
  that serves its queue (high=6 for Dispatch, default=3 for
  ProcessWebhook + SloEvaluator + FCM).

## Inventory snapshot

15 scheduled lanes (13 commands + 2 jobs + inline closures), 30
Console/Commands files total. Classification:

| Category | Count | Examples |
|---|---|---|
| Scheduled (cron) | 13 | outbox:rescue, fiscal:retry-alloc, stock:scan-rupture |
| Scheduled (job class) | 2 | CleanupStalePendingKioskOrders, SloEvaluatorJob |
| Inline scheduler closures | 3 | purge-otps, fiscal-chain-monitor, fiscal-archive-daily |
| Manual-only (seeders) | 4 | menu, menu:heal-light-v3, menu:reset-le-cayenne, seed:fresh-orders |
| Manual-only (UAT/test) | 5 | foodking:cleanup-test-fixtures, foodking:e2e:stress, kiosk:simulate-orders, iter15:cleanup-test-orders, foodking:cleanup-demo-data |
| Manual-only (ops) | 5 | foodking:backfill:allergens-snapshot, foodking:ensure-* (4 variants) |
| CI gate | 1 | app:preflight-production |
| On-demand operator (also used by scheduler closure) | 2 | fiscal:verify-chain, foodking:fiscal:archive |

Queue lanes observed: `high` (Dispatch), `notifications` (FCM),
`default` (ProcessWebhook + SloEvaluator + sync scheduler jobs).

## Pattern consistency

| Pattern | Healed in | Propagated to | Gap |
|---|---|---|---|
| activeBranchIds() multi-status | Fiscal lanes (Wave 2d) | NONE | SLO + Stock (P1) |
| Audit-then-dispatch | OutboxRetryFailed + OutboxWebhookRetryFailed (Wave 3) | NONE | OutboxRescue (P2) |
| Cache::lock + BATCH_CAP + LOCK_TTL=300s | OutboxRetryFailed + OutboxWebhookRetryFailed (Wave 3c) | NONE | OutboxRescue (P2) |
| Listener failure isolation | WG-2 (4 listeners) | NONE | CleanupStalePendingKioskOrders (P2) |
| PayloadMismatchException fail-once | DispatchDomainEventsJob (F-3 quick win 2026-05-19) | N/A | N/A — single-site |
| TZ-aware DATE column | ResetStaleDailyQuota (WH-3 heal) | N/A | No DATE-column siblings — verified |

## V1 LOCAL ship gate

**GO** for V1 LOCAL today — no NF525 risk, no payment-flow risk, no
ship blocker. The P1 is gated on a future data migration that the
owner has not yet run; the `Kernel::activeBranchIds()` docstring at
`Kernel.php:262-285` is the canonical project-internal acknowledgment
that this migration is pending. Fix can ship in V1.0.2 alongside the
migration itself, with a sentinel test guarding the propagation.
(Hence the "CONDITIONAL on V1.0.2 follow-up" qualifier in the verdict
header.)

## Recommended action

1. **V1.0.2 P1 fix**: replace `where('status', 1)` with
   `whereIn('status', [Status::ACTIVE, 1])` in SloEvaluatorJob:48 +
   StockScanRupture:168. Add sentinel test `tests/Feature/Sentinels/
   BranchSelectorActiveStatusMigrationSentinelTest.php` that fakes a
   branch with status=Status::ACTIVE and asserts both crons iterate it.
2. **V1.0.2 P2 fixes**: harden OutboxRescueCommand (LOCK + BATCH_CAP
   + audit_log + summary), add observability to otp-purge +
   PosPurgeParkedOrders + RetryFiscalAllocCommand summary, wrap
   CleanupStalePendingKioskOrders per-order in try/catch.
3. **Defer to V2 backlog**: P3 hour-budget refactor of inline
   scheduler closures into invokable classes; Horizon trim window
   bump.

## Deliverables

- `architect.json` — inventory + scheduling coverage + retry/DLQ
  pattern consistency (4 findings, all severities)
- `sre.json` — observability + idempotency + saturation + hour-budget
  (7 findings, all severities)
- `red.json` — adversarial sweep + TZ trap verification + queue
  saturation realism + status-migration silent DOS attack (7
  findings, all severities)
- `STATUS.md` — this synthesis

## Cross-validation summary

| Finding | Architect | SRE | RED |
|---|---|---|---|
| Silent DOS post-migration | ARCH-01 | (not in scope) | RED-01 |
| OutboxRescue inconsistency | ARCH-02 | SRE-02 | RED-02 |
| Otp-purge silent | (not in scope) | SRE-01 | (not in scope) |
| CleanupStalePending isolation | ARCH-03 | (not in scope) | (not in scope) |
| Inline closure observability | ARCH-04 | (not in scope) | RED-03 |

P1 finding is **2/2 cross-validated** (Architect + RED). P2 findings
are mostly 1-specialist with corroborating context from a second.

---

**End STATUS.md WI-2 round 1.** Total findings: **1 P1 + 7 P2 + 6 P3
+ 4 verified-safe negatives**. Total wall-clock ~32 min.
