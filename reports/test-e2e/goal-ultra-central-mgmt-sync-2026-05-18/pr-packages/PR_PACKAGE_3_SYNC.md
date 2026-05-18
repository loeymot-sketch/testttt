# PR Package 3 — SYNC backbone heal — 2026-05-18

> **Source-of-truth audit dossier:** `reports/test-e2e/goal-ultra-central-mgmt-sync-2026-05-18/round-{1,2}/` + `FINAL_ROUND_1_2_VERDICT.md` §4.
> Every heal commit below cites the agent finding ID — re-read that finding before patching.

## Branch
- name: `heal/sync-backbone-2026-05-18`
- base SHA: `5b147f9e7`
- rebased on: `heal/mgmt-backbone-2026-05-18` (PR #2 → which rebases on PR #1)
- merge order: ships last.

## Heal commits (in order)

### Commit 1 — `sync-heal-sp0-b` — S-P0-B Add outbox latency to SLO_TARGETS (1-line)
- **Title:** `fix(sync-heal-2026-05-18): add outbox_dispatch_latency_p95_ms to SLO_TARGETS (S-P0-B)`
- **Files modified:**
  - `app/Services/Observability/SloMetricCollector.php` lines 30-36 — add 1 entry to SLO_TARGETS array
  - Same file — add `collectOutboxLatencyP95(int $branchId, int $hours = 24): float` method (~15 lines) reading from `sync_metrics` table on metric_type='outbox.dispatch_latency_ms'
- **Patch scope:**
  ```
  At app/Services/Observability/SloMetricCollector.php:30-36, EXTEND SLO_TARGETS:
    'outbox_dispatch_latency_p95_ms' => ['target' => 2000, 'warn' => 5000, 'breach' => 10000],

  At same file, ADD method `collectOutboxLatencyP95($branchId, $hours)`:
    Query the existing sync_metrics table (the recorder already writes — see SyncMetricsRecorder.php:11-15 docblock + DispatchDomainEventsJob.php:124-130) and compute p95 over $hours.
  Why: R1-SRE-002 — the value is recorded by DispatchDomainEventsJob but SloEvaluatorJob iterates SLO_TARGETS only; outbox metric never evaluated, never Slack-alerted.
  Rollback: revert the array entry + drop the method.
  ```
- **Tests added:** `tests/Feature/Observability/OutboxLatencySloTest.php` — `(file TO BE CREATED)` — seeds `sync_metrics` rows with simulated latencies; asserts SloEvaluatorJob fires WARN at p95=5000ms and BREACH at p95=10000ms.
- **Acceptance evidence command:** `php artisan test --filter=OutboxLatencySloTest` → PASS
- **Frozen-zone status:** 0 lines touched.

### Commit 2 — `sync-heal-sp0-c` — S-P0-C Schedule fiscal:verify-chain
- **Title:** `feat(sync-heal-2026-05-18): schedule fiscal:verify-chain --branch=all daily 03:00 (S-P0-C)`
- **Files modified:**
  - `app/Console/Kernel.php` — add 1 schedule entry; ~6 lines
- **Patch scope:**
  ```
  At app/Console/Kernel.php (inside `protected function schedule(Schedule $schedule)` body, alongside the existing 14 schedule entries), ADD:
    $schedule->command('foodking:fiscal:verify-chain --branch=all')
             ->dailyAt('03:00')   // between fiscal:archive 02:00 and outbox:prune 04:00 — clean window
             ->onOneServer()
             ->withoutOverlapping()
             ->emailOutputOnFailure('fiscal@foodking.test')
             ->appendOutputTo(storage_path('logs/fiscal-verify-chain.log'));
  Why: R1-SRE-003 — chain integrity HMAC-SHA-256 unverified periodically. DGFiP audit risk per CLAUDE.md §8 + Art. 1743 CGI. The command exists (verified: `tests/Feature/Fiscal/FiscalVerifyChainCommandTest.php`); just not scheduled.
  Rollback: revert 1 schedule entry.
  ```
- **Tests added:** Extends existing `tests/Feature/Console/CronEntriesSentinelTest.php` — `(file TO BE CREATED if not present, otherwise add new case)` — asserts `foodking:fiscal:verify-chain` is in the schedule output.
- **Acceptance evidence command:** `php artisan schedule:list | grep verify-chain` → present; `php artisan test --filter=CronEntries` → PASS.
- **Frozen-zone status:** 0 lines touched.

### Commit 3 — `sync-heal-sp0-a` — S-P0-A Pusher webhook handler writes ws:heartbeat
- **Title:** `fix(sync-heal-2026-05-18): write ws:heartbeat cache key from Pusher webhook handler (S-P0-A)`
- **Files modified:**
  - `app/Http/Controllers/Admin/PusherWebhookController.php` — `(file TO BE CREATED if absent, OR locate existing Pusher webhook handler — grep `routes/api.php` for `pusher`/`beyondcode`)` — add `Cache::put('ws:heartbeat', now()->timestamp, 90)` on every event reception
  - If no existing webhook handler exists, register a new route under `routes/api.php` + the Pusher beyondcode hook
- **Patch scope:**
  ```
  Locate the receiver for Pusher's connection events (subscribe / disconnect / ping). Two options:
    (A) If a webhook receiver already exists: at its handler, ADD at the top of action:
        Cache::put('ws:heartbeat', now()->timestamp, ttl: 90);  // 90s > monitor 60s window per SyncOverviewController:547

    (B) If no receiver exists, install via beyondcode/laravel-websockets's existing pulse hook, OR create a new Pusher webhook receiver that consumes Pusher's `presence_state_change` / `client_event` callbacks and writes the cache key.

  Why: R1-SRE-001 — `Cache::get('ws:heartbeat')` at SyncOverviewController:531 reads from a key NOBODY writes. The fallback at line 547 (dispatched_at < 60s) ALWAYS masks the gap; dashboard shows green WS while Pusher is dead.
  Rollback: revert the Cache::put line + drop the new route if added.
  ```
- **Tests added:** `tests/Feature/Observability/WsHeartbeatProbeTest.php` — `(file TO BE CREATED)` — simulates webhook reception → asserts cache key 'ws:heartbeat' set with current timestamp; asserts SyncOverviewController returns ws_up=true. Without the webhook → ws_up=false (current behavior).
- **Acceptance evidence command:** `php artisan test --filter=WsHeartbeatProbeTest` → PASS
- **Frozen-zone status:** 0 lines touched.

### Commit 4 — `sync-heal-sp0-d` — S-P0-D Refactor 11 listeners to ShouldQueueAfterCommit
- **Title:** `fix(sync-heal-2026-05-18): listeners implement ShouldQueueAfterCommit (S-P0-D)`
- **Files modified:** 11 listener files
  - `app/Listeners/PersistOrderCreatedToOutbox.php`
  - `app/Listeners/PersistOrderStatusChangedToOutbox.php`
  - `app/Listeners/PersistOrderTableChangedToOutbox.php`
  - `app/Listeners/PersistOrderPaidAtCounterToOutbox.php`
  - `app/Listeners/PersistOrderPaymentStatusChangedToOutbox.php`
  - `app/Listeners/PersistCatalogChangedToOutbox.php`
  - `app/Listeners/PersistCouponChangedToOutbox.php`
  - `app/Listeners/PersistSettingsUpdatedToOutbox.php`
  - `app/Listeners/PersistItemAvailabilityChangedToOutbox.php`
  - `app/Listeners/PersistItemExtraAvailabilityChangedToOutbox.php`
  - `app/Listeners/PersistItemVariationAvailabilityChangedToOutbox.php`
- **Patch scope:**
  ```
  Per listener:
    1. ADD class declaration `implements ShouldQueueAfterCommit`
    2. REMOVE the manual `DB::afterCommit(fn () => ...)` wrapper inside handle() — the interface now binds the same lifecycle declaratively
    3. KEEP existing firstOrCreate dedupe logic (Outbox table has UNIQUE on idempotency_key — defense-in-depth)
    4. KEEP try/catch around DispatchDomainEventsJob::dispatch at lines 75-85
    5. Use `Illuminate\Contracts\Queue\ShouldQueueAfterCommit` interface
  Why: R1-DBA-009 + R1-SRE-008 — manual DB::afterCommit fallback when no transaction = phantom broadcasts on rollback. Framework's ShouldQueueAfterCommit enforces the contract.
  Rollback: per-file revert (re-add manual afterCommit + drop interface).
  ```
- **Tests added:** `tests/Feature/Outbox/ListenerAfterCommitSentinelTest.php` — `(file TO BE CREATED)` — uses reflection: iterates `app/Listeners/Persist*ToOutbox.php`, asserts each declares `implements ShouldQueueAfterCommit`. ALSO: a rollback test — fire an event inside `DB::transaction()` that throws; assert NO domain_events row was created + NO broadcast occurred.
- **Acceptance evidence command:** `php artisan test --filter=Outbox` (full suite — existing 5 tests stay green + the new sentinel + rollback test) → PASS
- **Frozen-zone status:** 0 lines touched.

### Commit 5 — `sync-heal-cvp0-3` — CVP0-3 Outbox prune chunkById + new index
- **Title:** `fix(sync-heal-2026-05-18): PruneOutboxCommand chunkById + new partial index (CVP0-3)`
- **Files modified:**
  - `app/Console/Commands/PruneOutboxCommand.php` lines 81-86 — replace `do-while LIMIT` with `chunkById`
  - `database/migrations/2026_05_19_000020_add_partial_index_pending_to_domain_events.php` — `(file TO BE CREATED)` — new migration adding `(dispatched_at, occurred_at, branch_id)` index and dropping the dead-weight `idx_pending` (or keeping both during transition)
- **Patch scope:**
  ```
  At app/Console/Commands/PruneOutboxCommand.php:81-86, REPLACE the existing `do { ... ->limit($batch)->delete() ... } while ($deleted > 0);` with a chunkById walk:
    DomainEvent::query()
      ->where(<existing predicate>)
      ->chunkById($batch, function ($rows) {
          DB::table('domain_events')->whereIn('id', $rows->pluck('id'))->delete();
      }, 'id');
  Why: R1-DBA-004 — without ORDER BY id, MySQL gap-locks across millions of rows during the first batch on a 10M backlog, stalling concurrent INSERTs.

  At database/migrations/2026_05_19_000020_add_partial_index_pending_to_domain_events.php (new):
    Schema::table('domain_events', function (Blueprint $table) {
        // MySQL 8 functional partial-index pattern OR keep simple multi-column index:
        $table->index(['dispatched_at', 'occurred_at', 'branch_id'], 'idx_pending_v2');
    });
    // Optional: drop old idx_pending in a follow-up migration after observation period.
  Why: R1-DBA-001 — `idx_pending` (dispatched_at, occurred_at) is dead weight at 10M rows; scopeStale uses created_at (not in old index).

  Rollback: revert command + drop new index.
  ```
- **Tests added:** `tests/Feature/Outbox/PruneChunkByIdTest.php` — `(file TO BE CREATED)` — seeds 5000 prunable rows; asserts prune completes in <2s without table-level lock contention (use SHOW ENGINE INNODB STATUS spot-check or assume timing budget).
- **Acceptance evidence command:** `php artisan test --filter=PruneChunkByIdTest` → PASS
- **Frozen-zone status:** 0 lines touched.

### Commit 6 — `sync-heal-sp0-i-sp0-j` — S-P0-I + S-P0-J Webhook STATUS_DUPLICATE + order_id FK
- **Title:** `fix(sync-heal-2026-05-18): WebhookEvent duplicate write path + order_id FK (S-P0-I + S-P0-J)`
- **Files modified:**
  - `app/Models/WebhookEvent.php` — add `markDuplicate()` method + new `replay_count` u-smallint cast (1 column, increment on duplicate detection)
  - `app/Http/PaymentGateways/Gateways/Stripe.php` lines 261-268 — call markDuplicate() when wasRecentlyCreated=false
  - `app/Http/PaymentGateways/Gateways/Senangpay.php` lines 139-146 — same
  - `database/migrations/2026_05_19_000030_add_replay_count_and_fk_to_webhook_events.php` — `(file TO BE CREATED)` — new migration adding `replay_count` u-smallint + FK on `webhook_events.order_id → orders.id (nullOnDelete)`
- **Patch scope:**
  ```
  At app/Models/WebhookEvent.php, ADD method:
    public function markDuplicate(): void {
        $this->increment('replay_count');
        $this->status = self::STATUS_DUPLICATE;  // ONLY if existing status == pending; otherwise preserve processed/failed
        $this->save();
    }
  Note: subtle — duplicate write path should ONLY mark STATUS_DUPLICATE if status is still pending (race-safety from R2-DBA-W7-001 reasoning). If status is already processed, just increment replay_count.

  At app/Http/PaymentGateways/Gateways/Stripe.php:261-268 + Senangpay.php:139-146:
    When `!$event->wasRecentlyCreated`:
      $event->markDuplicate();  // NEW
      Log::channel('fiscal')->info('webhook_duplicate_ignored', [...]);  // existing
      return response()->json(['status' => 'duplicate_ignored'], 200);  // existing

  At database/migrations/2026_05_19_000030_add_replay_count_and_fk_to_webhook_events.php (new):
    Schema::table('webhook_events', function (Blueprint $table) {
        $table->unsignedSmallInteger('replay_count')->default(0)->after('attempts');
        $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
    });
    Note: requires offline backfill of orphan rows (replay_count=0) before FK add (run UPDATE webhook_events SET replay_count=0 WHERE replay_count IS NULL first, but column default handles new inserts).

  Why: R2-DBA-W7-001 (STATUS_DUPLICATE dead enum + lost-update on race) + R2-DBA-W7-002 (no FK = garbage order_id accepted).
  Rollback: drop migration + revert markDuplicate + revert gateway changes.
  ```
- **Tests added:**
  - `tests/Feature/Webhook/WebhookDuplicateStatusWritePathTest.php` — `(file TO BE CREATED)` — Stripe replay → second receipt increments replay_count + writes STATUS_DUPLICATE (if pending) or just increments (if processed).
  - `tests/Feature/Webhook/WebhookEventOrderFkTest.php` — `(file TO BE CREATED)` — INSERT with bogus order_id → FK rejects; hard-delete Order → webhook_events.order_id nulled (cascade); soft-delete Order → webhook_events.order_id retained.
- **Acceptance evidence command:** `php artisan test --filter=Webhook` (existing + new) → PASS
- **Frozen-zone status:** 0 lines touched.

### Commit 7 — `sync-heal-sp0-f-sp0-g` — S-P0-F + S-P0-G Cross-surface latency SLO + stuck-order monitor
- **Title:** `feat(sync-heal-2026-05-18): cross-surface latency recorder + stuck-order monitor (S-P0-F + S-P0-G)`
- **Files modified:**
  - `app/Services/Observability/SyncMetricsRecorder.php` — add METRIC_ORDER_STATE_TRANSITION_LATENCY_MS constant + record method (~20 lines)
  - `app/Services/Observability/SloMetricCollector.php` — add `order_state_transition_p95_ms` to SLO_TARGETS + collector method (~20 lines, mirrors Commit 1)
  - `routes/api.php` — add a thin endpoint `POST /api/client-metrics/state-transition-latency` for frontend taps (already exists or add)
  - Frontend taps: `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`, `resources/js/components/admin/posV5/PosV5Component.vue`, `resources/js/components/admin/orderStatusScreen/`, `resources/js/components/frontend/*` — add Echo listener tap that POSTs `(Date.now() - envelope.occurred_at_ms)` to the endpoint
  - `app/Console/Commands/MonitorStuckOrdersCommand.php` — `(file TO BE CREATED)` — ~80 lines
  - `app/Console/Kernel.php` — add schedule entry; ~5 lines
- **Patch scope:**
  ```
  S-P0-F (cross-surface latency):
    1. SyncMetricsRecorder: add METRIC_ORDER_STATE_TRANSITION_LATENCY_MS + recordOrderStateTransition($branchId, $fromStatus, $toStatus, $latencyMs)
    2. SloMetricCollector: add SLO_TARGETS entry + collector that reads from sync_metrics
    3. Frontend taps: on Echo listener for OrderStatusChanged, compute $latency = Date.now() - envelope.occurred_at_ms; POST to /api/client-metrics/state-transition-latency with payload {branch_id, from_status, to_status, latency_ms}
    4. Server endpoint persists row in sync_metrics with branch_id + label tuple (from_status, to_status)
  Why: R2-SRE-013 — the headline V1 5s p95 promise is unobservable. Add producer, SLO, evaluator path end-to-end.

  S-P0-G (stuck-order monitor):
    At app/Console/Commands/MonitorStuckOrdersCommand.php (new):
      handle():
        $stuck = Order::query()
          ->where('status', OrderStatus::PREPARING)
          ->where('updated_at', '<', now()->subMinutes(30))
          ->whereNull('deleted_at')
          ->get();
        foreach ($stuck as $order):
          Log::error('stuck_order_alert', ['order_id' => $order->id, 'branch_id' => $order->branch_id, 'preparing_since' => $order->updated_at]);
          ActionLog::create([
            'branch_id' => $order->branch_id,
            'category' => 'stuck_order',
            'payload' => ['order_id' => $order->id],
          ]);
          // optional: Slack webhook hook

    At app/Console/Kernel.php:
      $schedule->command('monitor:stuck-orders')
               ->everyFiveMinutes()
               ->onOneServer()
               ->withoutOverlapping();
  Why: R2-SRE-016 — 30+ min stuck PREPARING never pages. Industry standard is 18 min default.
  Rollback: per-file reverts.
  ```
- **Tests added:**
  - `tests/Feature/Observability/CrossSurfaceLatencyRecorderTest.php` — `(file TO BE CREATED)` — POST to endpoint records sync_metrics row; SloMetricCollector::collectOrderStateTransitionP95 reads it.
  - `tests/Feature/Console/MonitorStuckOrdersTest.php` — `(file TO BE CREATED)` — seed orders with updated_at < 30min; command flags them; Log::error spy captures the alert.
- **Acceptance evidence command:** `php artisan test --filter=CrossSurfaceLatency` + `php artisan test --filter=MonitorStuckOrders` → PASS
- **Frozen-zone status:** 0 lines touched.

### Commit 8 — `sync-heal-sp0-h` — S-P0-H Reconciliation runbook + replay commands
- **Title:** `feat(sync-heal-2026-05-18): reconciliation runbook + sync:replay command (S-P0-H)`
- **Files modified:**
  - `docs/runbooks/cross-surface-divergence.md` — `(file TO BE CREATED)` — ~150 lines (Step 1: confirm authoritative state; Step 2: inspect outbox; Step 3: decision tree; Step 4: replay commands)
  - `app/Console/Commands/SyncReplayCommand.php` — `(file TO BE CREATED)` — signature `sync:replay --order={id}` — emits synthetic OrderStatusChanged for all active orders in branch OR specific order
  - `app/Console/Commands/OutboxReplayCommand.php` — `(file TO BE CREATED)` — signature `outbox:replay --domain-event-id={id}` — resets dispatched_at=null + dispatches DispatchDomainEventsJob; logs ActionLog category='outbox_manual_replay' with actor_id
- **Patch scope:**
  ```
  At docs/runbooks/cross-surface-divergence.md (new):
    # Cross-surface divergence playbook
    ## When this triggers
    - "POS says paid, KDS still has it queued"
    - "OSS shows READY but customer waiting"
    ## Step 1: Determine authoritative state
    - SQL: SELECT id, status, payment_status, updated_at FROM orders WHERE id = N;
    - The DB is canonical. POS/KDS/OSS are projections.
    ## Step 2: Inspect outbox row state
    - SQL: SELECT id, event_type, dispatched_at, attempts, last_error FROM domain_events WHERE aggregate_id = N ORDER BY id DESC LIMIT 5;
    ## Step 3: Decision tree
    - Outbox dispatched_at NULL + attempts < 5 → wait for rescue (or `php artisan foodking:outbox:rescue --domain-event-id=N`).
    - Outbox dispatched_at set + downstream stale → `php artisan outbox:replay --domain-event-id=N` (force re-dispatch, logs ActionLog).
    - Pusher dead → check beyondcode dashboard; if down, KDS auto-falls-back to polling (5s post-Commit-1).
    ## Step 4: Force re-emit
    - For a specific order: `php artisan sync:replay --order=N --branch=B`
    - For a whole branch: `php artisan sync:replay --branch=B`

  At app/Console/Commands/SyncReplayCommand.php (new):
    handle():
      foreach (Order $order in scope):
        event(new OrderStatusChanged($order, null, $order->status));  // re-emit with current state — forces re-fanout

  At app/Console/Commands/OutboxReplayCommand.php (new):
    handle():
      DomainEvent::find($id) → forceFill(['dispatched_at' => null])->save()
      DispatchDomainEventsJob::dispatch($id);
      ActionLog::create(['category' => 'outbox_manual_replay', 'branch_id' => $event->branch_id, 'payload' => ['operator' => Auth::id() ?? 'console', 'event_id' => $id]]);

  Why: R2-SRE-019 — no runbook, no replay tools; first production divergence event = 30+ min confusion.
  Rollback: drop commands + runbook (preserve git history).
  ```
- **Tests added:** `tests/Feature/Sync/SyncReplayCommandTest.php` + `tests/Feature/Sync/OutboxReplayCommandTest.php` — `(both TO BE CREATED)` — assert re-emit, ActionLog written.
- **Acceptance evidence command:** `php artisan test --filter=Replay` → PASS
- **Frozen-zone status:** 0 lines touched.

## PR description template

Title: `feat(sync-backbone-2026-05-18): observability + outbox + webhook + replay heal — 10 P0 closed`

Body: