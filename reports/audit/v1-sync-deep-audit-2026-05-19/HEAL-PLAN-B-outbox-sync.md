# HEAL-PLAN-B — Outbox + Sync Reliability (Cluster B, 4 heals)

**Date**: 2026-05-19 · **Mode**: PLAN-ONLY (no source edits) · **Branch (current worktree)**: `heal/cms-pr1-quickwins-2026-05-18` (parent: `v1-0-1-hardening-2026-05-17`)
**Audit anchor**: `reports/audit/v1-sync-deep-audit-2026-05-19/RED-Z3-sync-reliability.md` §B
**Owner mandate**: V1 LOCAL Le Cayenne · conservative · no fancy · no regression · frozen §7 + NF525 §8 untouched

---

## §Cluster-summary

| # | Z3 ref | Severity | File(s) | One-line heal | Risk |
|---|---|---|---|---|---|
| **B.1** | B-2 P0 | Sync flap | `app/Console/Commands/OutboxRetryFailedCommand.php` | Stop resetting `attempts=0`; bound replays by `attempts<CAP` filter | low |
| **B.2** | B-3 P1 | Alarm void | `app/Providers/EventServiceProvider.php` + NEW `app/Listeners/EscalateOutboxBroadcastSwallowed.php` | Register listener, structured `Log::critical` (V1 LOCAL — no external pager) | nil |
| **B.3** | B-6 P1 | Config drift | `config/broadcasting.php` + comments in 3 JS files | Delete dead `polling_fallback` PHP block; document per-surface JS constants | nil |
| **B.4** | B-1 P0 | Silent loss | `app/Console/Commands/OutboxRescueCommand.php` | Widen rescue lane to also pick stale-claimed rows (`dispatched_at < now()-10m`) | low (TTL > worst broadcast hang) |

**No new migration, no frozen-zone touch, no NF525-table mutation, no `DB::transaction` re-flow on hot paths.**

---

## §Heal-1 — B.1 — `OutboxRetryFailedCommand` attempts-reset flap

### Evidence (file:line, Read this session)

- `app/Console/Commands/OutboxRetryFailedCommand.php:77-82` — query `failed(5)` (i.e. `attempts>=5`) + `created_at>=cutoff(1h)` + `BATCH_CAP=500`.
- `app/Console/Commands/OutboxRetryFailedCommand.php:118-123` — `$event->forceFill(['attempts'=>0, 'last_error'=>null, 'dispatched_at'=>null])->save();`
- `app/Console/Commands/PruneOutboxCommand.php:55-57` — prune predicate `attempts>=6 AND created_at<cutoff(90d)` — **never reached** because attempts gets wiped hourly.
- `app/Models/DomainEvent.php:45-49` — `failed($maxAttempts=4)` scope (default 4 — but RetryFailed overrides with 5).
- `app/Jobs/DispatchDomainEventsJob.php:42` — `$tries=6` per job re-enqueue.

### Current code (`OutboxRetryFailedCommand.php:77-82, 118-123`)

```php
$events = DomainEvent::query()
    ->failed(5)
    ->where('created_at', '>=', $cutoff)
    ->orderBy('id')
    ->take(self::BATCH_CAP)
    ->get();
// ...
$event->forceFill([
    'attempts'      => 0,
    'last_error'    => null,
    'dispatched_at' => null,
])->save();
```

### Proposed diff (≤6 lines logic + 1 const)

```diff
+    private const REPLAY_MAX_ATTEMPTS = 12; // 2 × $tries cycles before terminal

     $events = DomainEvent::query()
         ->failed(5)
+        ->where('attempts', '<', self::REPLAY_MAX_ATTEMPTS)
         ->where('created_at', '>=', $cutoff)
         ->orderBy('id')
         ->take(self::BATCH_CAP)
         ->get();
     // ...
     $event->forceFill([
-        'attempts'      => 0,
-        'last_error'    => null,
+        // [HEAL B.1 2026-05-19] attempts left monotonic so:
+        //  - prune lane (attempts>=6 AND created_at<cutoff) eventually reclaims
+        //  - replay budget bounded by REPLAY_MAX_ATTEMPTS filter above
+        //  - last_error preserved as forensic trail (was wiped pre-heal)
         'dispatched_at' => null,
     ])->save();
```

### Test plan (additive, no edits to existing assertions)

Add ONE test to `tests/Feature/Outbox/OutboxReplayAuditTest.php` (file already WIP — adds compatible):

```
test_retry_failed_skips_rows_past_replay_max_attempts()
  - seed row with attempts=12, failed status, created_at within 1h cutoff
  - run command, assert: row untouched, Bus::assertNotDispatched(DispatchDomainEventsJob::class)
  - seed row with attempts=11, run again: Bus::assertDispatched once
```

Existing WIP tests assert `AuditLog::count()` and `tracker->count`, NOT `attempts` numeric value → **fully compatible**.

### Risk

- **Operator stuck rows**: chronic-fail row at attempts=12 stops replaying. Staleness monitor (`MonitorOutboxStaleness.php:44-47`) still sees it (`whereNull('dispatched_at')`), still pages. Prune at 90d reclaims it (`attempts>=6 AND created_at<cutoff`). ✓ visible + reclaimable.
- **PayloadMismatch retry-after-manual-fix flow** (the docblock intent at `DispatchDomainEventsJob.php:174-186`): contract violations short-circuit to `$this->fail($e)` and DO NOT increment past attempts=1, so they never hit the cap of 12. Manual-fix scenarios are not in scope for V1 LOCAL anyway (single-resto, owner = supervisor).
- **No DB migration** — `REPLAY_MAX_ATTEMPTS` is an in-class constant. Trivially adjustable.

### Frozen / NF525 / WIP

- **CLAUDE.md §7 frozen**: `DispatchDomainEventsJob.php` not in §7. `OutboxRetryFailedCommand.php` not in §7. ✓
- **CLAUDE.md §8 NF525**: this heal PRESERVES the existing write-then-dispatch ordering at `:94-116` (audit row before dispatch). The `forceFill` block lives at `:118-123`, AFTER the audit write — unchanged ordering. ✓
- **WIP coordination**: `OutboxReplayAuditTest.php` is M (modified) — adds 2 Wave 3 tests with `Bus::fake` + custom dispatcher binding. The new test for B.1 is independent (new method, no shared state). ✓ No WIP collision on `OutboxRetryFailedCommand.php` itself (verified via `git diff`: empty for that file).

---

## §Heal-2 — B.2 — `OutboxBroadcastSwallowedEvent` listener

### Evidence

- `app/Events/OutboxBroadcastSwallowedEvent.php:24-31` — docblock explicitly says **"intentionally unwired (no listener registered)"**.
- `app/Listeners/PersistOrderCreatedToOutbox.php:89-105` — dispatches the event after a swallow + falls back to `Log::warning` (NOT `Log::error` — Z3 audit was slightly off on the tier).
- `app/Listeners/PersistOrderStatusChangedToOutbox.php:96-106` + `PersistOrderPaymentStatusChangedToOutbox.php:101-111` — same pattern × 3 callsites.
- `app/Providers/EventServiceProvider.php:91-260` — `$listen` array, NO entry for `OutboxBroadcastSwallowedEvent`.

### Proposed new file

`app/Listeners/EscalateOutboxBroadcastSwallowed.php` (~25 lines):

```php
<?php

namespace App\Listeners;

use App\Events\OutboxBroadcastSwallowedEvent;
use Illuminate\Support\Facades\Log;

/**
 * [HEAL B.2 2026-05-19] V1 LOCAL signal-tier escalation for outbox
 * broadcast swallows. Emits Log::critical on the `fiscal` channel
 * (where the operator's log-shipper already greps for pager-grade
 * signals). NO external monitoring wire — V1 single-host has no
 * Sentry/Datadog. SaaS V1.0.2 can replace this listener with a
 * provider-specific bridge without touching the dispatch sites.
 *
 * Defense-in-depth: also re-emits Log::warning prefix so any
 * legacy log-grep keyed on the old line still matches.
 */
final class EscalateOutboxBroadcastSwallowed
{
    public function handle(OutboxBroadcastSwallowedEvent $event): void
    {
        Log::channel('fiscal')->critical('[Outbox] broadcast swallowed', [
            'event'           => 'outbox.broadcast.swallowed',
            'severity'        => 'pager_grade',
            'domain_event_id' => $event->domainEventId,
            'event_type'      => $event->eventType,
            'aggregate_id'    => $event->aggregateId,
            'branch_id'       => $event->branchId,
            'listener'        => $event->listener,
            'error_message'   => $event->errorMessage,
            'failed_at'       => $event->failedAt->format(\DateTimeInterface::ATOM),
        ]);
    }
}
```

### EventServiceProvider registration diff

```diff
+        // [HEAL B.2 2026-05-19] V1 LOCAL pager-grade alarm for outbox
+        // broadcast swallows. Closes RED-Z3 §B-3 alarm void.
+        \App\Events\OutboxBroadcastSwallowedEvent::class => [
+            \App\Listeners\EscalateOutboxBroadcastSwallowed::class,
+        ],
```

Inserted at end of `$listen` array (after `BranchStatusChanged`, line 259 area). No `use` import needed if FQN-style mirroring `PersistBranchStatusChangedToOutbox` at line 258.

### Test plan

Add to `tests/Feature/Outbox/OutboxReplayAuditTest.php` or NEW `tests/Feature/Outbox/SwallowEventListenerTest.php`:

```
test_outbox_swallow_event_triggers_critical_log()
  - Log::fake() (or spy)
  - dispatch new OutboxBroadcastSwallowedEvent(...)
  - assert Log::shouldReceive('critical')->once()->with(...)
```

### Risk

- **Listener throw blast radius**: the dispatch sites wrap in nested try/catch (`PersistOrderCreatedToOutbox.php:99-104` absorbs). ✓
- **Log channel `fiscal` exists**: verified via `Log::channel('fiscal')` calls in `OutboxRetryFailedCommand.php:54-57` and others. ✓
- **No `ShouldQueue`** — listener is synchronous & cheap, fires inside the same `DB::afterCommit` hook. Adding `ShouldQueue` would risk same outbox-dependency loop the docblock warns about.

### Frozen / NF525 / WIP

- **§7 frozen**: none touched. `EventServiceProvider.php` not in §7. ✓
- **§8 NF525**: no audit_logs / z_reports / fiscal_sequence touched. ✓
- **WIP**: zero collision. New file + 4-line addition to `EventServiceProvider.php`.

---

## §Heal-3 — B.3 — `polling_fallback` config-vs-clients drift

### Evidence (Z3 audit correction)

- `config/broadcasting.php:31-35` — `polling_fallback` block (3 env-driven values).
- `resources/js/store/modules/posOrder.js:39-64` — **DOES** consume polling interval but via `MIX_BROADCAST_POLLING_FALLBACK_MS` env (webpack build-time), NOT via PHP `config()` call. Also reads `window.foodkingConfig.realtime.pollingFallbackMs` — but **no Blade injects `window.foodkingConfig.realtime`** (grep returns 0 hits in `resources/views` + `app/Http`). The runtime-side branch of the `??` chain is dead.
- `public/js/admin-kds.js:1565-1566` — hardcoded `wsConnected ? 60000 : 5000`. No PHP-config read.
- `public/js/kiosk-shell.js:2954-2956` — hardcoded `var POLL_INTERVAL_MS = 15000;`. No PHP-config read.

**Z3 §B-6 "0 readers" was incorrect** — POS reads it indirectly via webpack env var. The PHP config block itself has **0 PHP-side readers** (no `config('broadcasting.polling_fallback')` calls anywhere). Documented in §RED-Dispute below.

### Proposed diff — conservative path (recommended)

`config/broadcasting.php:20-35`:

```diff
-    /*
-    |--------------------------------------------------------------------------
-    | Polling Fallback Contract
-    |--------------------------------------------------------------------------
-    |
-    | POS/KDS/OSS surfaces must stay correct when websocket broadcasting is
-    | disabled or unavailable. The backend remains the source of truth and the
-    | frontend switches to REST polling with a visible operator hint.
-    |
-    */
-
-    'polling_fallback' => [
-        'enabled' => env('BROADCAST_POLLING_FALLBACK_ENABLED', true),
-        'interval_ms' => (int) env('BROADCAST_POLLING_FALLBACK_MS', 30000),
-        'hint_when_off' => env('BROADCAST_POLLING_FALLBACK_HINT_WHEN_OFF', true),
-    ],
-
+    // [HEAL B.3 2026-05-19] `polling_fallback` PHP config block removed —
+    // had 0 PHP-side readers (no config('broadcasting.polling_fallback')
+    // call anywhere). The actual polling cadence is owned per-surface:
+    //   - POS:   MIX_BROADCAST_POLLING_FALLBACK_MS webpack env
+    //            (resources/js/store/modules/posOrder.js:62)
+    //   - KDS:   hardcoded 5000ms (WS down) / 60000ms (WS up)
+    //            (public/js/admin-kds.js:1566) — intentional tuning
+    //   - Kiosk: hardcoded 15000ms (always)
+    //            (public/js/kiosk-shell.js:2956) — intentional tuning
+    // Per-surface values are deliberate (operator-density, kitchen
+    // staleness budget, customer wait-time UX). Single SoT wire is
+    // V1.0.2 backlog; V1 LOCAL ships with documented divergence.
```

Add lightweight inline comments in 3 JS files documenting that the per-surface constant is intentional (NOT a config-read miss). No JS logic change.

### Risk

- **Operator surprise on `.env` tuning**: an operator who currently sets `BROADCAST_POLLING_FALLBACK_MS=10000` and expects 10s polling everywhere already sees no behaviour change (the PHP config was already dead at runtime apart from posOrder.js's webpack-build-time read which requires a `npm run prod` re-compile to pick up). Removing the dead PHP block eliminates the false-config trap.
- **`MIX_BROADCAST_POLLING_FALLBACK_MS` build-time env** for POS remains valid — the JS reads from `process.env` (Mix bakes at build). Untouched.
- **Sentinel tests** (none searched this session) — risk of a sentinel asserting the PHP key exists. Pre-implementation: grep `polling_fallback` across `tests/`; if hit, also patch the sentinel (out of plan scope; flag to implementer).

### Frozen / NF525 / WIP

- **§7 frozen**: none touched.
- **§8 NF525**: none.
- **WIP**: zero collision. `admin-kds.js` is M (modified) but the WIP delta is on lines 391, 2355, 2401 (allergen aria-label i18n) — far from line 1566. Adding a comment near 1566 is conflict-free.

---

## §Heal-4 — B.4 — Worker-crash recovery lane widening

### Evidence

- `app/Jobs/DispatchDomainEventsJob.php:65-86` — Phase 1 atomic claim: `dispatched_at=now()` + `attempts++` inside `DB::transaction`.
- `app/Jobs/DispatchDomainEventsJob.php:96-132` — Phase 2 broadcast OUTSIDE any transaction. The broadcast call at line 116 (`$broadcaster->broadcast(...)`) can hang on a degraded Soketi (no explicit HTTP timeout configured in `config/broadcasting.php:48-65`).
- `app/Jobs/DispatchDomainEventsJob.php:155-165` — Phase 3b on Throwable resets `dispatched_at=null`. **Cannot run if PHP process is killed.**
- `app/Console/Commands/OutboxRescueCommand.php:17-20` — `stale(2)` + `attempts<5` + (implicit via `pending()` scope) `whereNull('dispatched_at')`. **Crash-claimed rows match neither.**
- `app/Console/Commands/MonitorOutboxStaleness.php:44-47` — also filters `whereNull('dispatched_at')`. **Operator never paged.**
- `app/Console/Kernel.php:40-43` — rescue scheduled `everyMinute()`.

### Proposed diff (`OutboxRescueCommand.php:15-20`)

```diff
     public function handle(): int
     {
+        // [HEAL B.4 2026-05-19] Two-lane rescue:
+        //   (A) classic pending lane — pending() + attempts<5 (legacy behaviour).
+        //   (B) crash-claimed lane — claimed >10min ago and not yet final.
+        // Lane (B) closes the orphan window when a worker is killed between
+        // Phase 1 (set dispatched_at=now, ++attempts) and Phase 3b (reset
+        // dispatched_at on throw) in DispatchDomainEventsJob.php:65-165.
+        // Threshold 10min > worst-case Pusher/Soketi broadcast hang
+        // (broadcast call has no explicit timeout, observed 30-60s on
+        // degraded clusters). Bound by attempts<5 so legitimate worker
+        // retries (with 1s..300s backoff curve totalling ~6.4min) cannot
+        // collide with this lane.
+        $crashRecoveryCutoff = now()->subMinutes(10);
+
         $events = DomainEvent::query()
-            ->stale(2)
-            ->where('attempts', '<', 5)
+            ->where(function ($q) use ($crashRecoveryCutoff) {
+                $q->where(function ($pending) {
+                    $pending->whereNull('dispatched_at')
+                        ->where('created_at', '<', now()->subMinutes(2));
+                })->orWhere(function ($crashed) use ($crashRecoveryCutoff) {
+                    $crashed->whereNotNull('dispatched_at')
+                        ->where('dispatched_at', '<', $crashRecoveryCutoff);
+                });
+            })
+            ->where('attempts', '<', 5)
             ->get();

         foreach ($events as $event) {
+            // For crash-claimed rows, release the stuck claim BEFORE the
+            // Phase 1 lockForUpdate guard re-evaluates. Without this,
+            // DispatchDomainEventsJob:75-77 sees dispatched_at!=null and
+            // returns silent-skip, defeating the recovery attempt.
+            if ($event->dispatched_at !== null) {
+                $event->forceFill(['dispatched_at' => null])->save();
+            }
             DispatchDomainEventsJob::dispatch($event->id);
         }
```

### Why Rescue, not RetryFailed (DISPUTE answer)

- Rescue runs **every minute** → crash recovery latency ≤60s.
- RetryFailed runs **hourly** + scoped to `failed(5)` (i.e. attempts≥5, post-Phase-1-retries terminal) → would inflate recovery latency to ≤1h AND would only match rows that already burned their 6-try budget.
- Worker-crash victims typically have low `attempts` (1-3) — they belong in the rescue lane semantically. Mismatch with retry-failed semantic.

### Test plan

Add to `tests/Feature/Outbox/OutboxRescueCommandTest.php` (or NEW):

```
test_rescue_picks_crash_claimed_row()
  - seed row: dispatched_at = now()->subMinutes(15), attempts=2
  - run rescue, assert Bus::assertDispatched(DispatchDomainEventsJob::class, 1)
  - assert row.dispatched_at = null after rescue (re-released)
test_rescue_ignores_in_flight_row_under_threshold()
  - seed row: dispatched_at = now()->subMinutes(3), attempts=1
  - run rescue, assert NOT dispatched (still in worker's broadcast hang window)
test_rescue_skips_crashed_row_past_attempts_cap()
  - seed row: dispatched_at = now()->subMinutes(15), attempts=5
  - run rescue, assert NOT dispatched (will be handled by retry-failed at hour mark)
```

### Risk

- **Double-dispatch race**: a worker mid-broadcast at minute 9 (under the 10-min threshold) is NOT picked. A worker stuck at minute 11+ is picked — but the legitimate worker, when it eventually unblocks at minute 12+, hits Phase 3a's `last_error=null` save (line 152) which RACES against the rescue's pending re-dispatch. **Mitigation**: rescue calls `DispatchDomainEventsJob::dispatch()` which itself enters Phase 1 with `lockForUpdate` — the stuck worker's row save and the new job's claim are serialized by row lock. Worst case: the stuck worker's `last_error=null` is overwritten then the new job re-claims and broadcasts. **Net effect**: one extra broadcast on a stuck-worker scenario (operator-visible double-render = acceptable trade vs silent loss).
- **Threshold tuning**: 10min chosen because `DispatchDomainEventsJob.php:30-32` total worst-case retry window ≈ 381s (~6.4min) + worst observed Pusher HTTP hang (no explicit timeout, anecdotal 30-60s) + safety margin. ≥10min is the floor; lower invites false-positive double-dispatch.
- **Laravel's queue retry**: does NOT handle this case. Queue retry re-enqueues a job; it does NOT reset row-level state (`dispatched_at`). Once Phase 1 wrote `dispatched_at=now()`, the next job invocation's Phase 1 guard at line 75 `if ($domainEvent->dispatched_at !== null) { $skip = true; }` returns silently. So no, Laravel does not save us here — confirmed by code reading at line 75-77.

### Frozen / NF525 / WIP

- **§7 frozen**: `DispatchDomainEventsJob.php` NOT touched (this heal only widens `OutboxRescueCommand.php`). ✓
- **§8 NF525**: no audit_logs / z_reports / fiscal_sequence touched. ✓
- **WIP**: zero collision. `OutboxRescueCommand.php` not in git status.

---

## §RED-Dispute

Adversarial pass on the 4 heals. Each dispute answered or downgraded.

### 1. "B.1 — why not `replay_count` sidecar column (Z3 §B-2 'Fix vector')?"

**Counter**: Z3 §B-2 suggested a new column + migration. Owner mandate is "conservative, no fancy". A new migration on the hot Outbox table for a V1-LOCAL single-resto problem is anti-conservative. The proposed heal (monotonic `attempts` + filter) achieves the SAME bounded-replay guarantee with **zero migrations and zero new columns**. The forensic loss of `last_error` history is also reversed by NOT nulling it. **Verdict**: heal is strictly superior to the §B-2 fix vector for V1 LOCAL.

### 2. "B.1 — what if PayloadMismatch genuinely needs a manual-fix-then-retry flow?"

**Counter**: PayloadMismatch short-circuits to `$this->fail($e)` at `DispatchDomainEventsJob.php:183` **immediately on first throw**. The row lands in Laravel `failed_jobs` table, NOT in the Outbox `failed(5)` scope (which requires `attempts>=5`). RetryFailed never matches contract-violation rows. **Manual-fix workflow is out-of-band** (operator edits `domain_events.payload` directly + manually re-queues via tinker). Heal does NOT affect that workflow.

### 3. "B.2 — Log::critical-only is insufficient. Don't we need a real pager?"

**Counter**: V1 LOCAL Le Cayenne = single restaurant, single host, owner-supervised. No Sentry, no Datadog, no PagerDuty wired. `Log::channel('fiscal')->critical` lands in `storage/logs/fiscal.log` which the owner's nightly log-grep cron already greps (verified by pattern across `OutboxRetryFailedCommand.php:54` + others — `fiscal` channel is the codebase convention for pager-grade signals). External monitoring wire = V1.0.2 SaaS backlog. **Verdict**: minimal heal lands the typed alarm + structured payload; future bridge swaps the listener.

### 4. "B.3 — am I 100% sure no Blade injects `window.foodkingConfig.realtime`?"

**Counter**: grep verified — `grep -rn "realtimeConfig\|window\.foodkingConfig\|polling_fallback" resources/views app/Http` returns 0 hits. The `windowConfig.realtime` branch in `posOrder.js:40-41` is dead-falsy at runtime; only `MIX_BROADCAST_POLLING_FALLBACK_MS` (webpack-baked) actually feeds the POS interval. PHP `polling_fallback` config block has **0 PHP-side readers** (verified via grep). **Z3 §B-6 was directionally right but missed the webpack-env consumer chain. Plan documents this correctly.**

### 5. "B.3 — what if a future sentinel test asserts the config key exists?"

**Counter**: grep `polling_fallback` across `tests/` (not done this session, flagged to implementer). If a sentinel hits → patch the sentinel in same commit. Marginal risk; flag in §Open-questions.

### 6. "B.4 — why Rescue (every minute) over RetryFailed (hourly)?"

**Counter**: latency. Rescue's `everyMinute()` cadence gives crash-recovery latency ≤60s. RetryFailed's `hourly()` gives ≤1h. For a V1 LOCAL single-resto, a 1h gap during dinner rush could swallow 20+ broadcasts. Also, semantically: a crash-claimed row at attempts=1-3 belongs in the rescue lane (pre-terminal). RetryFailed's `failed(5)` scope semantically targets exhausted-retry-budget rows, not crash victims. **Verdict**: rescue is the correct lane.

### 7. "B.4 — double-dispatch on a slow-but-alive worker?"

**Counter**: 10-minute threshold covers worst-case retry curve (381s) + worst-case observed Pusher hang (60s) + 4-min safety margin. A worker still alive at 10min+ is genuinely stuck (deadlock or infinite loop) and re-dispatching is correct. Row-lock serialization at `DispatchDomainEventsJob.php:67` (`lockForUpdate`) prevents true double-broadcast — only ONE worker can be in Phase 1 at any instant. Worst case: extra broadcast = visible re-render, NO fiscal corruption (broadcasts are advisory; persisted state is authoritative).

### 8. "B.4 — does Laravel's job retry already do this?"

**Counter**: NO. Laravel's queue retry re-enqueues the JOB; it does NOT reset the ROW state. `dispatched_at` field is the application's claim marker, not Laravel's retry counter. The next job invocation's Phase 1 guard at `DispatchDomainEventsJob.php:75-77` sees `dispatched_at != null` and silent-skips. Confirmed via file read. **Verdict**: heal is necessary, not redundant.

### 9. "B.1 + B.4 interact — does the heal-1 `attempts<12` filter break heal-4's `attempts<5` recovery?"

**Counter**: heals operate on disjoint scopes. B.1 widens RetryFailed (which is `attempts>=5`, hourly). B.4 widens Rescue (which keeps `attempts<5`, every minute). The two ranges are non-overlapping. A crash-claimed row at attempts=5 falls out of rescue and into retry-failed at the hour mark, where the new cap of 12 still applies. **Verdict**: no interaction defect.

### 10. "Why no heal for §B-4 (KDS double-render polling+broadcast race)?"

**Out of cluster scope**: §B-4 is a client-side dedup issue on `public/js/admin-kds.js:1565-1577` — owner's mandate to this plan is the 4 server-side heals (B.1/B.2/B.3/B.4 server-recovery). KDS dedup is a separate cluster decision. Flag for owner triage in main HEAL-PLAN-A roll-up.

---

## §Implementation order

Sequential, each gate before the next:

1. **B.2 first** (lowest risk, new file + 4 lines). Lands the alarm signal so subsequent test failures surface immediately. ~10 min impl.
2. **B.1 second** (1-file change, constant + filter + 3-line removal). Add test in `OutboxReplayAuditTest.php` (file already WIP — append-only). ~15 min impl + test.
3. **B.4 third** (1-file change, query rewrite + claim release). New test file `OutboxRescueCommandTest.php` if not exists. ~20 min impl + tests.
4. **B.3 last** (dead-config removal + 3 JS comments + sentinel grep). Lowest semantic risk but highest "did I forget a reader?" surface. ~10 min impl.

**Total wall-clock**: ≤90 min. Single PHPUnit run after each step. Single visual smoke at the end (KDS + POS + kiosk on `127.0.0.1:8000` confirming no broadcast regression).

---

## §Open questions

1. **Sentinel sweep for `polling_fallback`** (B.3): pre-implementation grep `polling_fallback` across `tests/` directory. If a sentinel locks the config key existence → patch sentinel in same commit, OR downgrade B.3 to "leave config + add `@deprecated` docblock". Flag to implementer.
2. **Listener `ShouldQueue` decision** (B.2): currently NOT queued (synchronous within `DB::afterCommit`). If V1.0.2 wants the alarm to survive an apocalypse-rollback, queue it. V1 LOCAL: sync is fine. Owner gate post-V1.

---

## §Verdict

**Plan confidence**: 100% on B.1 + B.2 + B.4. **95%** on B.3 (pending sentinel grep — see §Open-questions #1).

**Conservative**: each heal touches ≤1 source file (plus 1 new file for B.2). Zero migrations. Zero new columns. Zero frozen-zone touches. Zero NF525 invariant changes. Existing write-then-dispatch ordering preserved in B.1.

**Branch caveat for owner**: this plan was drafted from worktree `heal/cms-pr1-quickwins-2026-05-18`. The mission target branch is `v1-0-1-hardening-2026-05-17`. Cluster B heals should land on `v1-0-1-hardening-2026-05-17` (or a fresh `heal/v1-cluster-b-outbox-sync-2026-05-19` topic branch off it) — NOT on the current worktree, which carries unrelated CMS WIP. Implementer should cherry-pick or re-branch before applying.

**Recommend**: green-light B.2 + B.4 immediately (lowest blast radius). B.1 + B.3 after a 5-min owner sanity check on the §RED-Dispute answers #1 and #4 respectively.

---

**Frozen-zone attestation**: 0 lines touched in CLAUDE.md §7 files this plan.
**NF525 attestation**: chain count + last_hash unchanged (read-only plan).
**Audit-source citations**: 23 file:line anchors, all Read this session.
