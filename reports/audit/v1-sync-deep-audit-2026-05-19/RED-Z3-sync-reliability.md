# RED-Z3 — Sync Reliability Audit (Outbox + Pusher + polling)
**Date**: 2026-05-19 · **Mode**: read-only adversarial · **Agent**: RED-Z3

> Scope correction noted: the "Outbox" in this codebase lives on table
> `domain_events`, NOT `outbox_events`. There is **no** `app/Services/Outbox/`
> directory and **no** `outbox.php` config. Anchors below reflect actual file
> paths.

---

## A. Anchors verified (file:line, Read this session)

1. `database/migrations/2026_04_15_200000_create_domain_events_table.php:11-30` — schema, NO explicit status enum (state is implicit via `dispatched_at` null/non-null + `attempts` counter).
2. `app/Models/DomainEvent.php:34-49` — scopes `pending()` / `stale($m)` / `failed($maxAttempts=4)` (DEFAULT 4, callers override).
3. `app/Jobs/DispatchDomainEventsJob.php:40-42` — `$backoff=[1,5,15,60,300]`, `$tries=6`. On the `high` queue (line 46).
4. `app/Jobs/DispatchDomainEventsJob.php:65-86` — Phase 1 atomic claim under `lockForUpdate`, sets `dispatched_at=now()` AND `attempts++` BEFORE broadcast.
5. `app/Jobs/DispatchDomainEventsJob.php:155-188` — Phase 3b on exception RESETS `dispatched_at=null` + `last_error=...`. PayloadMismatchException short-circuits via `$this->fail($e)` (line 183).
6. `app/Console/Commands/OutboxRescueCommand.php:17-20` — `stale(2)` + `attempts < 5`; dispatches via job (re-enters same `high` queue).
7. `app/Console/Commands/OutboxRetryFailedCommand.php:78-82` — `failed(5)` + Carbon-window + `BATCH_CAP=500` + `orderBy(id)`.
8. `app/Console/Commands/OutboxRetryFailedCommand.php:118-126` — resets `attempts=0`, `last_error=null`, `dispatched_at=null` and re-dispatches.
9. `app/Console/Commands/OutboxRetryFailedCommand.php:42-46` — `LOCK_KEY='outbox.retry-failed.lock'`, `LOCK_TTL_SECONDS=300`.
10. `app/Console/Commands/MonitorOutboxStaleness.php:31-83` — `--threshold=10`, `--stale-after=30`s, `Log::error` + `return self::FAILURE` (exit code nonzero) on breach.
11. `app/Console/Commands/PruneOutboxCommand.php:50-86` — UNION (dispatched_at NOT NULL AND < cutoff) OR (attempts >= 6 AND created_at < cutoff). 90d default.
12. `app/Console/Kernel.php:40-69` — rescue everyMinute, monitor everyMinute (threshold 10), retry-failed hourly with `--since=24h`.
13. `app/Events/OutboxBroadcastSwallowedEvent.php:24-44` — intentionally **unwired** (no listener registered) — `Log::error` is the only visible signal.
14. `app/Listeners/PersistOrderCreatedToOutbox.php:62-105` — `DB::afterCommit(fn() => DispatchDomainEventsJob::dispatch(...))`, wrapped in try/catch with Log::error + observability event dispatch.
15. `database/migrations/2026_05_09_120000_create_webhook_events_table.php:81-83` — `UNIQUE(provider, webhook_id)` enforced at DB layer.
16. `app/Models/WebhookEvent.php:108-115` — `markFailed()` increments `attempts` (no cap, monotonic).
17. `app/Jobs/ProcessWebhookEventJob.php:31-38` — `$tries=3`, `$backoff=[10,60,300]`.
18. `config/queue.php:16,42,51,62,71` — default `sync`, **all 4 driver blocks have `after_commit=false`**.
19. `config/broadcasting.php:31-35` — `polling_fallback.interval_ms = env(BROADCAST_POLLING_FALLBACK_MS, 30000)`.
20. `public/js/admin-kds.js:1565-1566` — KDS clamps polling at **5000 ms when WS down**, 60000 ms when WS up. Cap=60s baseline.
21. `public/js/admin-kds.js:6176,6227` — backoff doubles up to **30_000ms ceiling** on 5xx; floor 250ms.
22. `public/js/kiosk-shell.js:2954` — kiosk polls flat **15s** (no backoff escalation).
23. `app/Http/Controllers/Admin/Observability/SyncOverviewController.php:531,542` — `ws:heartbeat` cache key, considered "up" if age ≤ 60s, fallback to "recent dispatch ≤ 60s = up" (line 547-552).

---

## B. Findings P0 → P3

### B-1 [P0] **Worker crash between Phase 1 claim and Phase 3b release strands the row forever.**
**File**: `app/Jobs/DispatchDomainEventsJob.php:65-86,155-165`.
**Code**: Phase 1 SETS `dispatched_at=now()` + `attempts++` atomically. Phase 3b on exception RESETS `dispatched_at=null`. **But** if the PHP process is killed (OOM kill, kill -9, host reboot, queue worker `restart`, `php artisan queue:restart` mid-handle) AFTER Phase 1 commits and BEFORE Phase 3b runs, the row stays with `dispatched_at != null`.
**Repro**: `kill -9` queue worker between line 86 and line 116 broadcast.
**Risk**: Both rescue (`pending()` scope, line 17 of OutboxRescueCommand) AND retry-failed (`failed()` → `pending()`, scope at DomainEvent.php:46) filter on `whereNull('dispatched_at')`. A crash-claimed row matches **neither**. The staleness monitor at MonitorOutboxStaleness.php:44-47 ALSO filters on `whereNull('dispatched_at')` — so the operator is **never paged**. Silent loss of an OrderCreated, OrderStatusChanged, or Pricing event. NF525 adjacency: an outbox-fired audit-side event would silently never broadcast.
**Fix vector**: a separate "crash recovery" lane that picks rows with `dispatched_at < now() - threshold` AND `attempts > 0` AND no successful broadcast evidence (e.g. JOIN with broadcast log) — or add a `claimed_at` column distinct from `dispatched_at` (claimed_at on Phase 1, dispatched_at on Phase 3a success).

### B-2 [P0] **`OutboxRetryFailedCommand` resets `attempts=0` → infinite retry flap.**
**File**: `app/Console/Commands/OutboxRetryFailedCommand.php:119-123`.
**Code**: `$event->forceFill(['attempts' => 0, 'last_error' => null, 'dispatched_at' => null])->save();`
**Risk**: For a NON-`PayloadMismatchException` chronic failure (Pusher misconfig, network blackhole, schema-drift on a SubLevel API), the row exhausts the 6 job retries, lands in `failed(5)` scope, hourly cron resets attempts to 0, fires 6 retries again. Each hour: ~6 `high` queue messages PER failed row. The `BATCH_CAP=500` (line 46) bounds per run, but if the DLQ has 500 chronic-fail rows, that's 3000 useless dispatches per hour, indefinitely, with NO upper bound on the number of replays per row (the original `attempts` is lost on the first reset). Combined with the prune predicate `attempts >= 6 AND created_at < cutoff` (90d), a row that's reset hourly NEVER reaches the 6-cap because its attempts counter is wiped — the prune lane can't reclaim it. Cumulative `last_error` history is also wiped (line 120 nulls it), losing forensic evidence.
**Fix vector**: track `replay_count` separately from `attempts`; cap replays (e.g. ≤ 3 replays before pager); preserve last_error in a sidecar JSON field; do NOT null attempts — just decrement to attempts-1 so retry budget is bounded.

### B-3 [P1] **`OutboxBroadcastSwallowedEvent` is dispatched but never listened to.**
**File**: `app/Events/OutboxBroadcastSwallowedEvent.php:24-31` confirms "intentionally unwired (no listener registered in EventServiceProvider)". The 3 PersistXToOutbox listeners (PersistOrderCreatedToOutbox.php:89-97, PersistOrderStatusChangedToOutbox.php:95-104, PersistOrderPaymentStatusChangedToOutbox.php) dispatch this event after a swallow.
**Risk**: The docblock claims "ops alerting (Sentry breadcrumb, Datadog pipeline, custom listener) can subscribe later". V1 ships with **only `Log::error`** as the visible signal (line 77-84 of PersistOrderCreatedToOutbox.php). Without log-shipping wired to a paging backend, the swallow alarm fires into `storage/logs/*.log` and nobody sees it. The DomainEvent row IS persisted (so cron retry-failed at attempts>=5 will eventually fire), but the **immediate** broadcast failure is silent.
**Fix vector**: register a listener — minimum a `Log::critical` channel that the host log-shipper greps for, or a thin Stub class that increments a Prometheus/Datadog counter.

### B-4 [P1] **Polling-broadcast double-render not deduped on KDS client.**
**File**: `public/js/admin-kds.js:1565-1577` — both `setInterval(this._refresh, _pollingInterval)` AND the Echo subscription (line 1635-1637) deliver order updates. The polling cadence is 5s when `wsConnected=false` and 60s when true. When Pusher flaps (wsConnected oscillates), a polling fetch and a fresh Echo push for the SAME order arriving inside a 5s window both call into the order-state mutator.
**Risk**: I did NOT find a `_versionMap`-gated dedupe at the consumer level in admin-kds.js (the `_versionMap` at line 5855 is on KdsSyncService, but the `_refresh` setInterval at line 1577 bypasses the service and hits the REST endpoint directly). Counter-spike: same order can re-render twice (visual flicker), and any side-effect inside the render path (sound, animation, bump animation) fires twice. Owner mentioned operator complaint about ticket "flashing" — could be this.
**Fix vector**: route ALL update paths through KdsSyncService so `_versionMap` dedupes the version stamp; OR add a request-id/version check in the `_refresh` handler.

### B-5 [P1] **`failed(5)` retry-failed lane vs `attempts<5` rescue lane has an off-by-one gap.**
**File**: `app/Console/Commands/OutboxRescueCommand.php:19` (`attempts<5`) vs `app/Console/Commands/OutboxRetryFailedCommand.php:78` (`failed(5)` = `attempts>=5`).
**Risk**: A row with attempts=5 falls EXCLUSIVELY into retry-failed (hourly cron), NOT rescue (every minute). For an hour between worker death + cron firing, the row is invisible to the every-minute rescue lane. With staleness monitor `--threshold=10` (Kernel.php:50), a backlog of 10 attempts=5 rows during a 1-hour cron window pages the operator. Acceptable design, but ALSO: the `failed()` scope in DomainEvent.php:45 defaults `$maxAttempts=4`, NOT 5 — the command at line 78 explicitly overrides with `failed(5)`. If a future developer calls the scope without arg, the default of 4 catches rows the rescue (attempts<5) ALSO grabs → double-dispatch race. Subtle drift trap.
**Fix vector**: standardize on a class constant (e.g. `DomainEvent::MAX_DISPATCH_ATTEMPTS=5`), kill the default arg.

### B-6 [P1] **Polling interval contract drift: config says 30s, KDS client uses 60s ceiling, kiosk uses 15s flat.**
**File**: `config/broadcasting.php:33` says `BROADCAST_POLLING_FALLBACK_MS=30000` (30s). `public/js/admin-kds.js:1566` returns 60000ms when WS up. `public/js/kiosk-shell.js:2954` says 15s flat. There is NO single SSOT enforced. The `polling_fallback.interval_ms` config value is read by what? grep returns 0 usage in PHP/JS (only the config declaration). The config value is dead weight.
**Risk**: An operator who tunes `BROADCAST_POLLING_FALLBACK_MS=10000` expecting 10s polling everywhere will see no behavioral change. The config is a lie. Worse: an audit doc citing this config (e.g. CLAUDE.md or BRAIN.md) mis-describes runtime behavior.
**Fix vector**: either remove the unused config, or wire it through a `/api/config/sync-policy` endpoint the clients call at bootstrap.

### B-7 [P1] **`ws:heartbeat` is observability-fed by SUCCESSFUL dispatch, not by Pusher connection alive.**
**File**: `app/Jobs/DispatchDomainEventsJob.php:127-131` writes `Cache::put('ws:heartbeat', now()->timestamp, 120)` AFTER a successful `$broadcaster->broadcast(...)`. But `$broadcaster->broadcast()` against a Pusher driver only validates HTTP-200 from the Pusher REST endpoint — it does NOT prove that the Pusher cluster successfully delivered the message to any subscribed client. A Pusher/Soketi instance with 0 subscribers (or all client connections dead) still 200s the REST publish.
**Risk**: `SyncOverviewController:531-552` reports "websockets_serve: up" based on this heartbeat. Operator sees green dashboard while every KDS/OSS station is on polling fallback. False positive observability.
**Fix vector**: also probe `Echo.connector.pusher.connection.state` from a client-side keepalive POST to `/api/sync/heartbeat` — when 0 stations report in for 60s, flip the dashboard to "degraded".

### B-8 [P2] **`WebhookEvent::markFailed` increments `attempts` without an upper bound.**
**File**: `app/Models/WebhookEvent.php:108-115`.
**Risk**: A `webhook_events` row that flaps forever (provider keeps re-sending the same `webhook_id` because we return 5xx) accumulates `attempts` indefinitely. The `OutboxWebhookRetryFailedCommand` doesn't reset attempts (verified line 142-144 doesn't touch it — only status + error_message), so it grows monotonic. Eventually `unsignedSmallInteger` (max 65535) overflows. 65535 hourly retries = ~7.5 years; long V1 lifetime safe but the model design is sloppy. Larger concern: NO `--max-attempts` check in OutboxWebhookRetryFailedCommand:97-102, so a `failed` row is retried forever as long as it's inside `--since=24h`.
**Fix vector**: add `attempts >= X` cap to the query at line 97, mirror outbox; OR rely on PCI-window pruning at PruneWebhookEventsCommand.php (180d) — but pruning only deletes `processed`+`duplicate`, NOT `failed`. So a chronically-failed row lives forever.

### B-9 [P2] **`after_commit=false` on all 4 queue drivers + listeners rely on `DB::afterCommit`.**
**File**: `config/queue.php:42,51,62,71`. The 3 PersistXToOutbox listeners (PersistOrderCreatedToOutbox.php:62) wrap dispatch in `DB::afterCommit(...)`. This is correct AS LONG AS every Outbox-emitting code path goes through the listener pattern. If a future contributor calls `DispatchDomainEventsJob::dispatch($id)` from a controller mid-transaction, the job COULD execute before the transaction commits → `DomainEvent::find($id)` returns null (line 68 of the job) and we silently log "already dispatched by concurrent worker" (line 88). The outer transaction commit then has a payload no worker ever picks up because the rescue lane only catches `whereNull('dispatched_at')` AND `attempts<5` — but the row was never inserted into `domain_events` at the time of the failed find.
**Risk**: Subtle, requires a future regression. Right now I cannot find a violator. But the queue-config default of `after_commit=false` is a footgun waiting for a junior dev.
**Fix vector**: flip `after_commit=true` on the redis driver block (line 71); the dispatching is already wrapped, so this is defense-in-depth.

### B-10 [P2] **Kiosk polling has NO backoff escalation on consecutive errors.**
**File**: `public/js/kiosk-shell.js:2954` "Polling interval is always 15s". Verified via grep `consecutiveErrors|backoff` against kiosk-shell.js — returns 0 hits for kiosk-side polling backoff (KDS has it at lines 6222-6227, kiosk does not).
**Risk**: A misconfigured kiosk hitting `/api/kiosk/order/{id}/status` with HTTP 500 will hammer the backend at 15s indefinitely. PHP-FPM saturation if 20 kiosks all see the same backend error.
**Fix vector**: mirror the KDS backoff curve (`Math.min(base*2, 30000)` on 5xx).

### B-11 [P3] **No prune for `failed_jobs` table.**
**File**: searched cron schedule — outbox-prune (line 101), webhook-prune (line 117), but no `php artisan queue:prune-failed`. Laravel's `failed_jobs` table grows monotonically.
**Risk**: V1-local will not OOM, but a 2-year deployment with sustained low-rate failures grows the table indefinitely. Operator surprise.

---

## C. Hard questions for owner

1. **Worker-crash recovery**: when `kill -9` hits a queue worker mid-Phase-1-to-Phase-3, the orphan row stays `dispatched_at != null, attempts=N` forever. What's the operational answer? Manual SQL? A cron lane I missed? (B-1).
2. **Replay flap budget**: should `OutboxRetryFailedCommand` cap the number of replays per row, or is hourly flap acceptable for V1-local? Owner happy with 6 messages/hour per chronic-fail row indefinitely? (B-2).
3. **`OutboxBroadcastSwallowedEvent` unwired**: the event is dispatched but no listener exists. What ops backend should subscribe in V1-local? File logs only? (B-3).
4. **KDS double-render**: are operators reporting "ticket flashing" when Pusher flaps? Could be the polling vs broadcast race (B-4).
5. **`failed(5)` vs `failed()`**: the default `$maxAttempts=4` in the scope is a footgun. Replace with named constant?
6. **Polling SSOT**: `BROADCAST_POLLING_FALLBACK_MS` config value appears dead. Should I confirm it's read by client bootstrap somewhere I missed, or is the config dead weight? (B-6).
7. **`ws:heartbeat` is broadcaster-200 not Pusher-delivered**: false-green risk on the admin observability dashboard. Acceptable for V1-local with 1 station? (B-7).
8. **WebhookEvent attempts grow forever**: should `failed` rows pruned after N attempts/M days? Or status-tag them to `dead` for prune? (B-8).
9. **Kiosk polling no backoff**: 20 kiosks × 15s = 80 req/min steady; on 5xx, 80 req/min still. Acceptable for V1-local? (B-10).
10. **`failed_jobs` table prune**: never schedules `queue:prune-failed`. Add to cron? (B-11).
11. **`onOneServer` lock store**: rescue/monitor/retry-failed all use `onOneServer()`. This relies on the cache store implementing locks. Confirm `CACHE_DRIVER=redis` in V1-local? If it's `file` or `array`, `onOneServer` is a no-op and we get duplicate runs cross-host (V1-local single-host = irrelevant, but document it).
12. **`OutboxRetryFailedCommand` BATCH_CAP=500 + hourly cron**: a 24h DLQ surge with 6000+ failed rows takes `ceil(6000/500)=12h` to fully drain. Is this acceptable on V1-local? Should the batch cap be operator-tuneable?
13. **`ProcessWebhookEventJob` `$tries=3` vs Outbox `$tries=6`**: deliberate asymmetry? Stripe/SenangPay providers have their own retry budgets, so 3 might be too few if our app is genuinely down for 1h.
14. **PusherHost=127.0.0.1**: in `.env`, V1-local Soketi confirmed. What's the Soketi process supervision strategy? systemd? Or is operator expected to run it manually? When Soketi dies, the heartbeat decays at the 120s TTL → admin observability flips to "down" — is THIS visible to the operator at the till? Or just admin dashboard?
15. **Echo reconnect ceiling**: searched `Echo.connector` for reconnect tuning — found none explicitly. Laravel Echo defaults to Pusher's `maxReconnectAttempts=infinite`. Is that the intent? Or should it bounded so the kitchen falls cleanly to polling?
16. **No `OutboxBroadcastSwallowAlarmSentinel` listener but a Sentinel TEST exists** (tests/Feature/Sentinels/OutboxBroadcastSwallowAlarmSentinelTest.php). Does the sentinel verify the event class exists but not that a listener is wired? Drift indicator.
17. **Rescue dispatches via `DispatchDomainEventsJob::dispatch`, which re-enqueues on the `high` lane**. If `high` is the worker that's DOWN, rescue does nothing useful. Should there be a separate "recovery" lane?
18. **`PayloadMismatchException` short-circuit at `$this->fail()` (line 183)** — that lands in `failed_jobs` table directly without further retry. But `last_error` in domain_events also gets `contract_violation:` prefix (line 162). When this row is later re-driven by `OutboxRetryFailedCommand`, it RESETS last_error to null (line 120) — losing the contract_violation marker. Is that the intent? An ops eng triaging the row loses the original prefix.
19. **`audit_logs.write` in retry commands**: a single retry-failed run can write 500 audit rows (BATCH_CAP). Chain HMAC contention could ladle 30s+ per row. Has the worst-case wall-clock been load-tested? `LOCK_TTL_SECONDS=300` gives 600ms/row budget — tight if the chain lock is contended.
20. **Idempotency_key UNIQUE** (migration 2026_05_09_180000) — what's the migration order on a fresh seed? If listeners fire before this migration runs, `firstOrCreate(['idempotency_key' => ...])` would fail. Confirm fresh seed runs migrations before any HTTP traffic.
21. **`OutboxRescueCommand` has no Cache::lock + no BATCH_CAP**: at every-minute cadence with `withoutOverlapping()`, if a single rescue run takes >60s (e.g. 10K stale rows × insert latency), the next tick overlaps and `withoutOverlapping()` skips. Is silent-skip acceptable, or should rescue ALSO use a Lock + log lock_contended like retry-failed (line 53-56)?

---

## D. Sync invariants verified GREEN

1. **`UNIQUE(provider, webhook_id)` on webhook_events** — migration line 83 → DB-layer dedup against provider retry storm. ✓
2. **`idempotency_key` UNIQUE on domain_events** — migration 2026_05_09_180000 line 39-40 → listener replay dedup at DB layer. ✓
3. **Phase 1 atomic claim under `lockForUpdate`** — DispatchDomainEventsJob.php:65-86 prevents double-broadcast under concurrent workers. ✓
4. **`PayloadMismatchException` short-circuits to `failed_jobs`** — DispatchDomainEventsJob.php:183 `$this->fail($e)` — no useless 6-retry storm on malformed payload (V1.0.1 quick-win confirmed in code comment). ✓
5. **`outbox:retry-failed` write-then-dispatch ordering** — OutboxRetryFailedCommand.php:94-116 audit_logs row written BEFORE dispatch; failure on audit skips dispatch (line 115 `continue`) → no orphan broadcast without audit trail. ✓
6. **Cache::lock 5min TTL + BATCH_CAP 500** — OutboxRetryFailedCommand.php:42-46 prevents double-cron + bounds wall-clock. ✓
7. **`failed()` scope filters by `pending()` AND `attempts>=N`** — DomainEvent.php:45-49 — ensures retry-failed only matches truly-stuck rows, not in-flight. ✓
8. **Prune lane respects NF525 invariant** — PruneOutboxCommand.php:25-27 + PruneWebhookEventsCommand.php:36-37 explicitly document audit_logs + z_reports never touched. ✓
9. **Staleness monitor returns non-zero exit** — MonitorOutboxStaleness.php:83 `return self::FAILURE` + Log::error → cron/supervisor can wire pager. ✓
10. **`DB::afterCommit` wraps Outbox dispatch** — all 3 PersistXToOutbox listeners + PersistCatalogChangedToOutbox + PersistCouponChangedToOutbox guard against pre-commit dispatch race. ✓

---

## E. Out-of-scope or unverifiable

- Pusher cluster cardinality + Soketi production tuning — V1-local single-Soketi confirmed via `.env` PUSHER_HOST=127.0.0.1. Production scaling NOT my zone.
- Z6 BranchScope + Z7 idempotency-middleware interactions on Outbox payload privacy — owned by other agents.
- `tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php` claimed by docblock — did not Read it; sentinel claim deferred.
- Frontend `WebSocketService.js` (`resources/js/services/WebSocketService.js`) reconnect logic — not Read this session; assertions about Echo reconnect (Q-15) are based on Pusher SDK defaults, not file evidence.
- Whether `redis` is the actual `CACHE_DRIVER` in V1-local (Q-11) — `.env` was Read for QUEUE/BROADCAST only; cache store not confirmed.

---

## F. RED verdict

**Score**: 7.2 / 10. Strong write-side discipline (atomic claim, after-commit, idempotency-key UNIQUE, NF525-isolated prune, write-then-dispatch audit ordering). **Three structural gaps prevent a 9+.**

**Top 3 risks**:
1. **B-1 worker-crash orphan rows** (silent loss; no recovery lane catches them).
2. **B-2 attempts-reset infinite flap** (chronic-fail row burns ~6 queue messages/hour forever, prune lane can't reclaim because attempts is wiped).
3. **B-4 polling-broadcast double-render not deduped on KDS REST refresh path** (operator-visible flicker on Pusher flap).

**Shippable V1 LOCAL?** **YES, with caveat.** For Le Cayenne single-restaurant single-host, the failure surface is:
- One restaurant, ~50-100 events/day → B-2 flap budget is negligible bandwidth.
- Worker crashes (B-1) are recoverable via manual SQL if/when detected — owner is the supervisor and will notice missing broadcasts.
- B-4 double-render is cosmetic, no fiscal impact.

**Caveat**: before V1.0.2 multi-restaurant SaaS, ALL THREE P0/P1 structural gaps must be closed. The current Outbox design is sound but its recovery surface is under-engineered for a 24/7 multi-tenant deployment.

**No "8/8 GO" attestation.** Owner should sign off on the 21 hard questions in §C before merge.
