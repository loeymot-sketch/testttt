# L2 — SYNC LAYER AUDIT (Outbox + Pusher + Polling Fallback)

**Date:** 2026-05-17
**Auditor:** Claude (read-only, anti-drift)
**Scope:** Cross-surface domain-event propagation: Outbox listeners → DispatchDomainEventsJob → Pusher (private channels) → polling 5s fallback in OSS/POS/KDS.
**Agent 1 claim:** "Best piece of code in repo" — verified, with several real defects below.

## SCORES BY DIMENSION (/10 each → /100)

| # | Dimension                              | Score | One-line verdict                                            |
|---|----------------------------------------|-------|-------------------------------------------------------------|
| 1 | Outbox completeness                    | 5/10  | 3 declared event types have ZERO Persist*ToOutbox listener  |
| 2 | Idempotency (sha1 key, UNIQUE)         | 9/10  | Solid (UNIQUE on idempotency_key, firstOrCreate, race-safe) |
| 3 | Listener ordering invariant            | 6/10  | Documented for OrderStatusChanged only — silent elsewhere   |
| 4 | Polling fallback discipline            | 7/10  | OSS solid; POS gated off by default; KDS strong             |
| 5 | Channel auth (Pusher subscription)     | 7/10  | OK for branch staff; admin (branch_id=0) = open subscribe   |
| 6 | Payload contract drift (JS BROADCAST_MAP) | 4/10 | 3 broadcasts emitted PHP-side absent from JS BROADCAST_MAP  |
| 7 | Retry logic + dead letter              | 8/10  | Tight backoff curve, retry-failed schedule, claim release   |
| 8 | Monitoring (staleness + alerts)        | 9/10  | MonitorOutboxStaleness + /ready 503 + scheduler discipline  |
| 9 | Race conditions                        | 8/10  | Phase 1 atomic claim under lockForUpdate; SQLite caveat     |
|10 | Tests (mocks vs real Pusher)           | 6/10  | 100% mocked Broadcaster; no real-Pusher harness; SQLite no FOR UPDATE |
|   | **TOTAL**                              | **69/100** | Strong design with concrete completeness + drift gaps     |

---

## 1. OUTBOX COMPLETENESS — 5/10

### Persist*ToOutbox listeners enumerated (10 total)

`app/Listeners/Persist*ToOutbox.php`:

1. `PersistOrderCreatedToOutbox` → `OrderCreated`
2. `PersistOrderStatusChangedToOutbox` → `OrderStatusChanged`
3. `PersistOrderPaidAtCounterToOutbox` → `OrderPaidAtCounter`
4. `PersistOrderPaymentStatusChangedToOutbox` → `OrderPaymentStatusChanged`
5. `PersistOrderTableChangedToOutbox` → `OrderTableChanged`
6. `PersistCatalogChangedToOutbox` → `CatalogChanged` + bridge from `Item*Changed`/`StockLevelChanged`/`Category*`
7. `PersistItemAvailabilityChangedToOutbox` → `ItemAvailabilityChanged`
8. `PersistItemExtraAvailabilityChangedToOutbox` → `ItemExtraAvailabilityChanged`
9. `PersistItemVariationAvailabilityChangedToOutbox` → `ItemVariationAvailabilityChanged`
10. `PersistCouponChangedToOutbox` → `CouponChanged`

### P0 / HIGH — Declared event types with NO Persist listener
`app/Enums/EventType.php:7-31` declares:

- `EventType::ORDER_ITEM_ADDED = 'order.item_added'` — **NO Persist listener**
- `EventType::ORDER_CANCELLED = 'order.cancelled'` — **NO Persist listener** (the `OrderCanceled` event only triggers stock-release listeners; no outbox row, so KDS/OSS/POS don't get broadcast notification)
- `EventType::STOCK_LOW = 'stock.low'` — **NO Persist listener** (only `NotifyStockLowOnStockLevelChanged` exists, but it does not persist to `domain_events`)

`EventContract::REQUIRED_PAYLOAD_KEYS` reserves payload contracts for all three at `app/Domain/Events/EventContract.php:59-71` → contract drift: documented schema with no producer.

**Consequence:** Frontend `BROADCAST_MAP` in `resources/js/services/eventContract.js:16-25` references `OrderCreated`/`OrderStatusChanged`/`OrderPaidAtCounter`/`OrderTableChanged`/`ItemAvailabilityChanged`/`CatalogChanged`/`CouponChanged` — but the constants `ORDER_ITEM_ADDED`/`ORDER_CANCELLED`/`STOCK_LOW` are exported (`eventContract.js:5,6,11`) and consumed nowhere. Dead exports + missing producers.

### P1 — `RefundCreated` has NO outbox row
`app/Providers/EventServiceProvider.php:161-164` registers `RefundCreated` with only `ReleaseStockOnRefundCreated` + `ReleaseAvailabilityOnRefundCreated`. POS refund flow → no domain-event broadcast. KDS/OSS only learn via stock-level fan-out (best-case latency: 60s polling) or `OrderStatusChanged`/`OrderPaymentStatusChanged` if the refund triggers them.

### P2 — `ComposerProfileChanged` / `IngredientAvailabilityChanged` partially covered
- `ComposerProfileChanged` → goes through `PersistCatalogChangedToOutbox` (good).
- `IngredientAvailabilityChanged` → only `InvalidateMenuProjectionOnIngredientChange` (cache invalidation). No outbox emit → kiosk/POS see staleness window until next catalog change or polling tick.

---

## 2. IDEMPOTENCY — 9/10

### Idempotency key generation patterns

**One-shot events** (cannot legitimately repeat for same aggregate):
- `PersistOrderCreatedToOutbox:22` — `sha1(EventType::ORDER_CREATED . '|' . $order->id)` ✓ simple, correct
- `PersistOrderPaidAtCounterToOutbox:23` — `sha1(EventType::ORDER_PAYMENT_CONFIRMED . '|' . $order->id)` ✓

**Transition events** (can legitimately repeat — e.g. status revert):
- `PersistOrderStatusChangedToOutbox:26-32` — `sha1(event_type | order_id | old_status | new_status | correlation_id)` ✓ scoped to request via correlation_id
- `PersistOrderPaymentStatusChangedToOutbox:32-38` — same pattern ✓
- `PersistOrderTableChangedToOutbox:51-57` — same pattern with `prev/new` table id ✓
- `PersistItemAvailabilityChangedToOutbox:62-69` — same pattern incl. `branchId` (null=global) discriminator ✓
- `PersistItemExtraAvailabilityChangedToOutbox:40-46` — ✓
- `PersistItemVariationAvailabilityChangedToOutbox:39-45` — ✓

**Fan-out events** (one row per active branch):
- `PersistCatalogChangedToOutbox:59-66` — adds `branchId` AND `changeType` to key ✓ prevents collision across branches and across legitimate distinct change types
- `PersistCouponChangedToOutbox:49-55` — same pattern ✓

### DB enforcement
`database/migrations/2026_05_09_180000_add_idempotency_key_to_domain_events.php:39-40`:
- `idempotency_key VARCHAR(64) NULL UNIQUE` — race-safe at DB layer.
- Comment line 31 correctly identifies that "firstOrCreate without a unique index is theatre under contention".

### Listener replay guard (consistent across listeners)
Each listener has the parity block: `if (! $domainEvent->wasRecentlyCreated) return;` before scheduling `DispatchDomainEventsJob` — saves queue serialization on replay. Tracked at `PersistOrderCreatedToOutbox:57-59`, `PersistOrderStatusChangedToOutbox:64-66`, etc. **All 10 listeners adopt the pattern.** Good consistency.

### Minor risk — sha1 collision
sha1 is 160-bit → collision probability negligible at our scale. Could be upgraded to sha256 with no code change (column is `VARCHAR(64)` already reserves room). Accepted by design.

### Webhook idempotency (separate ledger)
`app/Models/WebhookEvent.php:8-46` + migration `webhook_events`:
- UNIQUE `(provider, webhook_id)` enforced at INSERT — `database/migrations/2026_05_09_120000_create_webhook_events_table.php:83`.
- Pattern: `firstOrCreate` + `isProcessed()` short-circuit + `markProcessed/markFailed`. Clean.
- Branch isolation note in model docblock — intentional non-scoping (providers don't carry tenant context).

---

## 3. LISTENER ORDERING INVARIANT — 6/10

### Documented invariant
`app/Providers/EventServiceProvider.php:124-133` documents:
> "Listener order matters: Persist\*ToOutbox MUST run BEFORE side-effect listeners (FCM, loyalty) because the outbox is the SSOT for KDS/Kiosk/POS sync."

Applied correctly at `EventServiceProvider:134-139` for `OrderStatusChanged`:
```
PersistOrderStatusChangedToOutbox::class,
AwardLoyaltyPointsOnDelivery::class,
SendFcmOnOrderStatusChange::class,
```
and at `EventServiceProvider:141-147` for `OrderCreated`.

### P1 — Ordering invariant SILENT for non-Order events
The "Persist first" comment block sits between only those two arrays. For other events the invariant is observed but **without comment** AND with reversed order on at least one critical event:

**`ItemAvailabilityChanged` at `EventServiceProvider:169-177`:**
```
BumpMenuSnapshotOnItemAvailabilityChanged::class,        // runs FIRST
InvalidateKioskMenuCacheOnItemAvailabilityChanged::class,// runs SECOND
PersistCatalogChangedToOutbox::class,                    // THIRD
PersistItemAvailabilityChangedToOutbox::class,           // FOURTH (last)
```
Persist*ToOutbox is **last**. Under `QUEUE_CONNECTION=sync`, if `BumpMenuSnapshotOnItemAvailabilityChanged` or `InvalidateKioskMenuCacheOnItemAvailabilityChanged` throws (e.g. Redis blip), the outbox never gets the row — same defect class fixed for `OrderCreated`/`OrderStatusChanged` in F-002 round-3. The defense-in-depth try/catch in `PersistItemAvailabilityChangedToOutbox` does NOT help here because we never reach it.

**Recommendation:** Move `PersistCatalogChangedToOutbox` + `PersistItemAvailabilityChangedToOutbox` to the top of the `ItemAvailabilityChanged` array (and the same for `ItemExtraAvailabilityChanged`, `ItemVariationAvailabilityChanged`, all `Item*`/`Category*`/`StockLevelChanged` arrays).

### P2 — `StockLevelChanged` ordering (`EventServiceProvider:223-227`)
`NotifyStockLowOnStockLevelChanged` is third. If it throws, the kiosk/POS still get the outbox event (Persist is FIRST and SECOND). Acceptable order. But same audit pattern should be enforced systematically.

---

## 4. POLLING FALLBACK DISCIPLINE — 7/10

### OSS (`resources/js/services/OssSyncService.js`)
- **Cadence:** 60s when WS connected (`DEFAULTS.intervalMsWhenConnected:9`), **2s** when disconnected (`DEFAULTS.intervalMsWhenDisconnected:16` — tightened from 5s for the SYNC-2 8s POS-pay→OSS budget; documented).
- **Reconnect catch-up:** `_burstPoll('ws_reconnected')` immediate fetch on WS state change → connected (`OssSyncService:174`).
- **Visibility burst:** `_burstPoll('visibility')` on tab `visibilitychange` → `visible` (`OssSyncService:207-215`), throttled by `visibilityBurstMinIntervalMs:24` = 1000ms — solid defense against setTimeout throttling in backgrounded tabs.
- **Dev warn after disconnect:** `_maybeWarnDisconnect` at `OssSyncService:243-263` warns once after `devWarnAfterDisconnectMs=10000` in non-production. Operator visibility.
- **5xx backoff:** Exponential (`OssSyncService:317-321`), cap = 30s. ✓
- **Abort on overlapping polls:** AbortController + `_abortInFlight` (`OssSyncService:376-381`) ✓

### POS (`resources/js/services/PosSyncService.js`)
- **Default cadence:** `intervalMsWhenDisconnected: 30_000` (`DEFAULTS:41`). Slower than OSS (consistent — POS has wizard flow that masks catalog staleness).
- **P1 finding — opt-in only:** `_runtimeConfig` returns `enabled: cfg.enabled === true || cfg.enabled === 1 || cfg.enabled === '1'` (`PosSyncService:142`). **Default is `false`** because the config key must be explicitly set in `window.foodkingConfig.posFallbackPolling.enabled`. If config isn't propagated from backend `catalog_v15.pos_fallback_polling` → cashier sees frozen catalog with no fallback when Pusher dies. Compare with OSS which is opt-out (`enabled !== false`, `OssSyncService:115`). Asymmetric and risky.
- **Suspend on WS connected:** `_suspend()` clears timer (`PosSyncService:223-229`). ✓

### KDS (`resources/js/services/KdsSyncService.js`)
- **Cadence map:** high-activity 3s, degraded 5s, disconnected 10s (`DEFAULT_CADENCE_OPTIONS:21-27`).
- **Connected → Infinity:** stops polling when WS healthy (`KdsSyncService:285-287`); drift safety timer fires every 60s (`KdsSyncService:337-345`).
- **Reconnect-storm jitter:** 0-500ms uniform (`KdsSyncService:247-262`) → herd protection across 50+ kitchens.
- **Version gating:** Per-order optimistic-concurrency check (`KdsSyncService:160-171`, cap 256 entries `_maxVersionEntries:48`). Strong.
- **5xx backoff:** doubles, cap 30s (`KdsSyncService:361-364`).
- **Network-error re-schedule:** `KdsSyncService:208-210` — was a real bug; correctly fixed with comment trail. Excellent.

### Doc drift
`config/broadcasting.php:31-35` documents `polling_fallback` envs (`BROADCAST_POLLING_FALLBACK_*`) that are **not read** by the JS services (which read `window.foodkingConfig.{ossFallbackPolling,posFallbackPolling,kdsFallbackPolling}`). Two parallel config namespaces. Confusing for operators.

---

## 5. CHANNEL AUTH (Pusher subscription security) — 7/10

### `routes/channels.php` audit

**`App.Models.User.{id}` (line 16-18):** ✓ standard per-user auth, integer cast safety.

**`branch.{branchId}` (line 25-39):** Three auth paths:
1. Kiosk token (`tokenCan('kiosk:order')`) → restricted to KioskMachine.branch_id ✓ correct fix from GAP-21-5 (kiosk token has branch_id=0 admin scope which would have allowed cross-branch subscribe).
2. **Admin (branch_id === 0) → returns `true` ALWAYS** (line 33-35). Means any admin user can subscribe to any branch private channel. **P1 finding:** Even though this is "the admin scope", a leaked admin Sanctum token = full live broadcast surveillance across every branch in production. There is no `permission:settings` check. Consistent with admin-bypass elsewhere in the codebase, but the broadcast channel is the **only** path where a stale/leaked admin token gets cross-branch live feeds in real-time.
3. Staff (`branch_id > 0`) → own branch only ✓

### P1 — No tests for `routes/channels.php` auth callback
`find tests -iname "*channel*"` returns no dedicated channel-auth test. Only static assertions in `tests/Feature/Isolation/MultiBranchIsolationE2ETest.php:39` say "validé statiquement par broadcast channel scope" — i.e. **never executed**. The authorization callback at `routes/channels.php:25` has zero automated test coverage. Manual review only.

### P2 — `branch.{branchId}` accepts any integer
The closure receives `$branchId` from the channel name and doesn't validate that the branch actually exists or is active. A request to `branch.99999999` will pass through to: kiosk check (false unless they're on machine 99999999), admin check (`branch_id === 0` → true). For an admin token, subscribing to bogus branch ids creates noise but no security issue. Minor cleanup.

### P3 — Sanctum auth endpoint
`app/Providers/BroadcastServiceProvider.php:22` correctly uses `auth:sanctum` middleware (GAP-34-1 fix). Bearer token comes from `bootstrap.js:236-242` — re-injected via `window._refreshEchoAuth()` post-login (`bootstrap.js:248-253`). Subscription error → token refresh + wsService promotes to `SESSION_INVALID` after 3 failures in 60s (`bootstrap.js:261-274`). Solid.

### Echo broadcasting setup audit
`resources/js/bootstrap.js:184-296`:
- Uses `MIX_PUSHER_*` not `VITE_PUSHER_*` (V5-BUGFIX comment line 192-194 explains the historical silent-break). ✓
- `activityTimeout: 30000`, `pongTimeout: 5000` → 35s stale-detection (`bootstrap.js:234-235`).
- Falls back to `WS_STATE.UNAVAILABLE` if `MIX_PUSHER_APP_KEY` missing (`bootstrap.js:291-295`) → polling-only mode in dev without Pusher.

---

## 6. PAYLOAD CONTRACT DRIFT (PHP ↔ JS) — 4/10

### `EventContract::BROADCAST_MAP` (PHP) — `app/Domain/Events/EventContract.php:34-49`
Maps 10 broadcast names → 10 EventType constants. Complete on PHP side.

### `BROADCAST_MAP` (JS) — `resources/js/services/eventContract.js:16-25`
Maps **only 7 broadcast names**:
- OrderCreated, OrderStatusChanged, OrderPaidAtCounter, OrderTableChanged, ItemAvailabilityChanged, CatalogChanged, CouponChanged

### MISSING from JS BROADCAST_MAP (P0 drift)
The following broadcasts are emitted PHP-side with `broadcast_as` set, but JS has no map entry → `expectedType` lookup at `eventContract.js:349` returns undefined and the type-mismatch warning at line 351-357 is **bypassed silently**:

1. **`OrderPaymentStatusChanged`** — emitted at `PersistOrderPaymentStatusChangedToOutbox:60` for every payment status mutation. JS map missing entry. No type-check enforcement.
2. **`ItemExtraAvailabilityChanged`** — emitted at `PersistItemExtraAvailabilityChangedToOutbox:57`. JS map missing entry.
3. **`ItemVariationAvailabilityChanged`** — emitted at `PersistItemVariationAvailabilityChangedToOutbox:56`. JS map missing entry.

### P0 — `ItemExtraAvailabilityChanged` / `ItemVariationAvailabilityChanged` have ZERO JS consumers
`grep -rn "ItemExtraAvailabilityChanged\|ItemVariationAvailabilityChanged" resources/js` returns **empty**. The whole F-016a-BIS branch-scoped rupture toggle pipeline emits domain events that **no kiosk/POS/admin component subscribes to**. Real-time rupture for extras/variations is **non-functional** on the frontend — falls back to next polling tick or page refresh. Whole feature half-shipped.

The compensation `PersistCatalogChangedToOutbox` (bridge added in WAVE5-DATA-004 at `EventServiceProvider:184-191`) does fire `CatalogChanged` which kiosk consumes — but with the full menu invalidation, not the surgical update intended. This is the documented mitigation, but it means:
- Kiosk gets `CatalogChanged` and refetches whole menu (heavy)
- The dedicated `ItemExtraAvailabilityChanged` event is broadcast but consumed by NO ONE — wasted Pusher message + DB row

### Schema file
`resources/js/services/eventContract.schema.json` exists but only documents `version` / `type` / `payload`. No JSON-schema-per-event-type → JS validation only at envelope level.

### PHP `REQUIRED_PAYLOAD_KEYS` enforcement is asymmetric
`EventContract::assertPayloadValid` at line 155-177 fails closed for known types but silently passes for unknown types (`if ($required === null) return;` line 159). With the 3 declared-but-unused event types (`ORDER_ITEM_ADDED`, `ORDER_CANCELLED`, `STOCK_LOW`), if a future producer emits them with malformed payload it WILL be caught (entries exist at lines 59-60, 71). Good defense.

---

## 7. RETRY LOGIC + DEAD LETTER — 8/10

### `DispatchDomainEventsJob` retry curve (`app/Jobs/DispatchDomainEventsJob.php:40-46`)
- `$backoff = [1, 5, 15, 60, 300]` seconds, `$tries = 6`
- Curve correctly justified in docblock at lines 24-39 (Audit T G2 noted that earlier `tries=5` made the 300s entry unreachable — fixed).
- Total worst-case ~6.4 min retry window → outlasts typical Pusher/Soketi restart.

### Claim-and-release pattern (`DispatchDomainEventsJob:50-162`)
- **Phase 1**: `lockForUpdate()` + dispatched_at NULL guard + atomic claim in transaction. Concurrent worker sees `dispatched_at != null` after lock and exits silently (no re-broadcast).
- **Phase 2**: Validation + broadcast OUTSIDE any transaction.
- **Phase 3a (success)**: clear `last_error`.
- **Phase 3b (failure)**: NULL out `dispatched_at` + persist `last_error` (with `contract_violation:` prefix for `PayloadMismatchException`) → release claim for retry.
- `failed()` callback at line 165-222 preserves prefix for monitoring filters (Audit T G3) — explicit, well-commented.

### Best-effort inline dispatch
Every Persist listener wraps `DispatchDomainEventsJob::dispatch($id)` in `try/catch` that logs a warning but does NOT bubble (`PersistOrderCreatedToOutbox:67-78`, all 10 listeners). Rationale: under `QUEUE_CONNECTION=sync`, broadcaster failure (Pusher down in dev) would HTTP-500 the controller. Outbox row IS persisted; `outbox:retry-failed` cron picks up `dispatched_at=null` rows. **Excellent isolation.**

### Schedulers (`app/Console/Kernel.php:39-68`)
- `foodking:outbox:rescue` — everyMinute, re-queues rows with `attempts < 5` AND created > 2 minutes ago (`OutboxRescueCommand:18-19`). Hits backoff cap at ~6 minutes total. ✓
- `foodking:outbox:retry-failed --since=24h` — hourly (`Kernel:63-68`). Resets attempts to 0 and re-queues rows with `attempts >= 5`. Sprint 3B P1-SYNC-02 — closes the "stuck at attempts=6, dispatched_at=null forever" gap.
- `foodking:outbox:monitor --threshold=10` — everyMinute, raises Log::error + non-zero exit. ✓

### Dead-letter
`failed_jobs` table (Laravel default) catches terminal failures. No dedicated UI to drain. `last_error` column on `domain_events` preserves audit trail per row.

### P2 — `OutboxRescueCommand` does NOT check `last_error` to skip contract violations
Re-queues anything with `attempts < 5` regardless of why it failed. A `PayloadMismatchException` row will keep cycling for 5 attempts before stalling. Should short-circuit if `last_error` starts with `contract_violation:`.

---

## 8. MONITORING — 9/10

### `MonitorOutboxStaleness` (`app/Console/Commands/MonitorOutboxStaleness.php`)
- Signature: `--threshold=10`, `--stale-after=30` seconds.
- Single targeted query against `idx_pending` index (line 58-62, comment confirms index coverage).
- Pull oldest stuck row → operator sees id, event_type, age, attempts, last_error.
- `Log::error` + `FAILURE` exit code → supervisor pages. ✓
- Scheduled `withoutOverlapping()->onOneServer()` → no double-paging across nodes.

### `/api/health/ready` (tested in `OutboxDeliveryTest:56-88`)
- Flips to 503 when stale count > 10 (`OutboxDeliveryTest:56-73`).
- Flips to 503 in production when `QUEUE_CONNECTION=sync` or `BROADCAST_DRIVER=null|log` (covered at `OutboxDeliveryTest:94+`).
- Stays 200 when stale count <= threshold. ✓
- **Closes F-015 production blocker:** queue worker accidentally not started ⇒ catastrophic silent failure ⇒ now caught.

### `SyncMetricsRecorder` (`app/Services/Observability/SyncMetricsRecorder.php` invoked from `DispatchDomainEventsJob:125-130`)
- Records dispatch latency per event type + branch_id + correlation_id.
- Best-effort wrapped in try/catch — never blocks broadcast.

---

## 9. RACE CONDITIONS — 8/10

### Concurrent same-aggregate domain events
Two requests modifying the same `Order` produce two distinct domain events with different correlation_ids → different idempotency_keys (because transition listeners include correlation_id) → BOTH persist and broadcast. Correct behavior.

### Race within same request (listener fired twice e.g. queue retry mid-flight)
- `firstOrCreate(idempotency_key)` with DB UNIQUE → second fire collapses to existing row.
- `wasRecentlyCreated` short-circuits the `afterCommit` dispatch registration → no duplicate Pusher message.
- Test coverage: `OutboxConcurrentWorkerDedupeTest:38-55` (sequential), `:57-75` (commit-before-broadcast), `:77-110` (broadcaster failure release).

### Worker-side concurrent dispatch race
`DispatchDomainEventsJob:60-86` — `lockForUpdate` + transaction. Loser observes `dispatched_at != null`, exits silently. Test: `OutboxConcurrentWorkerDedupeTest:152-170` confirms skip path doesn't bump attempts or clear last_error.

### Audit caveat (test environment)
Comment at `OutboxConcurrentWorkerDedupeTest:27-32` honestly flags: **SQLite (CI backend) treats `lockForUpdate()` as no-op** → tests exercise post-claim idempotence path, NOT true concurrent-row-lock path. Production MySQL has the real semantic. Phase 3 tech debt: dedicated MySQL integration test.

### Frontend dedupe race
`eventContract.js:270-285` (`isDuplicateCorrelation`) — sessionStorage persisted (10min TTL), 2048-entry FIFO cap, debounced 250ms write, sync-flush on `pagehide`. Per-tab (W3C limitation acknowledged at line 87-94). Solid layered defense against double Pusher messages reaching UI handlers.

---

## 10. TESTS — 6/10

### Coverage strengths
- `tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php` — 7 test methods covering claim/release/skip/failed/contract violation paths.
- `tests/Feature/Outbox/OutboxDeliveryTest.php` — health probe transitions, env-coerced production checks.
- `tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php` — fan-out fanin, rescue + retry-failed schedules.
- `tests/Feature/Outbox/CatalogEventDispatchAfterCommitTest.php` — afterCommit semantics, rollback path.
- `tests/Feature/Outbox/ListenerReplayDedupeTest.php` — replay guard pattern.
- `tests/Feature/Sync/ListenerReplayGuardTest.php`, `OutboxRetryFailedScheduleTest.php` — sentinels.
- `tests/Feature/AfterCommitDispatchTest.php:108` — order listeners' branch channel + dispatch-after-commit.
- `tests/Feature/Catalog/CatalogOutboxIdempotencyTest.php` — idempotency under stress.

### Test weaknesses

**P1 — Zero real-Pusher harness.** Every test mocks `BroadcastManager`/`Broadcaster` (e.g. `OutboxConcurrentWorkerDedupeTest:42-44`). Real WS round-trip (PHP → Soketi/Pusher → JS Echo handler → store mutation) is untested. The closest is `tests/e2e/__screenshots__/test-e2e-pos-kds-sync-F/05-f-outbox-retry-curve.*` but those are playwright captures, not assertion harnesses.

**P1 — No `routes/channels.php` auth callback test.** No automated coverage for kiosk vs admin vs staff branch channel auth.

**P2 — SQLite caveat for `lockForUpdate`.** Acknowledged at `OutboxConcurrentWorkerDedupeTest:27-32`. Phase 3 tech debt.

**P3 — `MonitorOutboxStaleness` not unit-tested.** Only the side-effect (`/ready` flip) is tested; the command's own assertion (oldest row pulled, log context shape) is not.

---

## CONSOLIDATED FINDINGS LIST

### P0 (block / data drift)
1. **`ItemExtra` / `ItemVariation` availability events have ZERO JS consumers** → real-time branch-scoped rupture toggle is non-functional. Frontend depends on the WAVE5-DATA-004 `CatalogChanged` bridge fallback (heavy refetch) instead of surgical update.
   - Files: `PersistItemExtraAvailabilityChangedToOutbox.php:57`, `PersistItemVariationAvailabilityChangedToOutbox.php:56`; `resources/js/services/eventContract.js:16-25` (missing BROADCAST_MAP entries).
2. **`OrderPaymentStatusChanged` broadcasts emitted but JS BROADCAST_MAP lacks entry** → no type-mismatch validation; any payload drift goes silent.
   - Files: `PersistOrderPaymentStatusChangedToOutbox.php:60`, `resources/js/services/eventContract.js:16-25`.

### P1 (high)
3. **Declared event types `ORDER_ITEM_ADDED`, `ORDER_CANCELLED`, `STOCK_LOW` have ZERO Persist listener.** Producers do not exist. JS exports orphan constants.
   - Files: `app/Enums/EventType.php:10-11,22`; `resources/js/services/eventContract.js:5,6,11`; `app/Domain/Events/EventContract.php:59-60,71` (REQUIRED_PAYLOAD_KEYS reserved but unused).
4. **`PosSyncService` defaults to disabled** (`enabled === true` required). If backend config drift loses `posFallbackPolling.enabled=true`, cashier sees frozen catalog with no fallback when Pusher dies. Asymmetric with OSS which defaults on.
   - File: `resources/js/services/PosSyncService.js:142`.
5. **Listener ordering invariant SILENT for `ItemAvailabilityChanged` / Item*/Category* / StockLevelChanged.** `Persist*ToOutbox` is LAST on `ItemAvailabilityChanged` array (`EventServiceProvider:169-177`). Under sync queue, earlier listener crash kills outbox persist — same defect class fixed in F-002 round-3 for orders.
   - File: `app/Providers/EventServiceProvider.php:169-177` (and other Item*/Category*/StockLevelChanged arrays).
6. **`RefundCreated` has no outbox row.** POS refund → no broadcast notification to KDS/OSS, only stock-release listeners fire.
   - File: `app/Providers/EventServiceProvider.php:161-164`.
7. **Admin (branch_id=0) can subscribe to ANY branch private channel.** Leaked admin Sanctum token = cross-branch live broadcast surveillance. No `permission:settings` gate.
   - File: `routes/channels.php:33-35`.
8. **Zero automated tests for `routes/channels.php` auth callback.** Kiosk/admin/staff branch isolation only validated statically. No regression guard.
   - File: `routes/channels.php:25-39`; no `tests/Feature/Channels/*` directory.
9. **No real-Pusher integration test.** All tests mock `BroadcastManager`. JS-PHP round-trip never exercised in CI.

### P2 (medium)
10. **`OutboxRescueCommand` re-queues `contract_violation:` rows blindly.** Should short-circuit on `last_error` prefix and route to dead-letter / human triage.
    - File: `app/Console/Commands/OutboxRescueCommand.php:17-25`.
11. **Config namespace drift.** `config/broadcasting.php:31-35` documents `BROADCAST_POLLING_FALLBACK_*` envs that the JS services do not read. Operators see two namespaces.
12. **SQLite test backend treats `lockForUpdate` as no-op.** Real concurrent claim semantic not exercised in CI.
    - File: `tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php:27-32` (acknowledged).
13. **`IngredientAvailabilityChanged` not persisted to outbox.** Only `InvalidateMenuProjectionOnIngredientChange` cache-clear runs.

### P3 (low)
14. **`branch.{branchId}` channel accepts any integer.** No existence check on branch_id from channel name.
15. **`MonitorOutboxStaleness` command itself untested.** Only side-effect (/ready flip) covered.
16. sha1 collision negligible; could be sha256 (column reserves 64 chars).

---

## CONCLUSION

The Outbox is genuinely the strongest subsystem in the codebase — claim-and-release pattern with `lockForUpdate`, idempotency_key + DB UNIQUE, defense-in-depth try/catch in producers, backoff curve mathematically justified, three complementary cron schedules (rescue / retry-failed / monitor), and `/ready` 503 closing the F-015 silent-failure trap. Agent 1's "best piece of code in repo" assessment is defensible.

But the layer is **incomplete and drift-prone at the edges**:
- 3 declared event types have no producer
- 1 broadcast event (`OrderPaymentStatusChanged`) is unmapped on the JS receiver
- 2 broadcast events (`ItemExtra*`, `ItemVariation*`) have zero consumers — the F-016a-BIS feature ships its backend half but not its frontend half
- 1 listener ordering invariant is documented for orders but violated for items
- 1 frontend service (POS polling) is fail-closed by default
- Real-Pusher round-trip and channel-auth callbacks have zero automated coverage

**Score 69/100.** Strong center, weak completeness/contract/test edges. Production-acceptable for V1 single-resto Le Cayenne where the outbox carries Order events (which ARE complete and consistent end-to-end). NOT production-acceptable for V2 SaaS multi-tenant where the rupture and refund broadcast gaps become incident sources.
