# Sync Cascade Architect — Live-Sync Trace (W2, 2026-05-29)

READ-ONLY audit. Every claim carries a `file:line` + excerpt. No code changed.

## Verified cascade (end to end)

**1. PRODUCE (DomainEvent → Outbox).** Order events are dispatched by the
domain services, NOT by the `HasDomainEvents` trait (the trait exists but has
zero callers — `grep recordDomainEvent app/` returns only its own definition).
Real producers:
- Kiosk paid → `app/Services/FrontendOrderService.php:1212` `$locked->status = OrderStatus::ACCEPT` then `:1283` `OrderCreated::dispatch($locked)` (auto-PREPARING at `:1246`).
- Kiosk create (auto-accept) → `FrontendOrderService.php:579` ACCEPT + `:595` `OrderCreated::dispatch`.
- POS pay → `app/Services/PaymentService.php:409` `OrderPaidAtCounter::dispatch` + `:423` `OrderStatusChanged::dispatch(ACCEPT→PREPARING)` when auto-prepared.
- POS create → `OrderService.php:699` initial status ACCEPT (env-overridable) + `:583/:1187/:1524` `OrderCreated::dispatch`.
- Chef bump → `app/Services/KitchenDisplaySystemOrderService.php:431` `$locked->status = $newStatus` + `:458` `kdsTicketDispatcher->dispatch(...)` → `app/Listeners/DispatchKdsTicket.php:17` `OrderStatusChanged::dispatch`.

Listeners persist a `domain_events` row (`EventServiceProvider` map lines
150-177): `PersistOrderCreatedToOutbox.php:25` / `PersistOrderStatusChangedToOutbox.php:35`
/ `PersistOrderPaidAtCounterToOutbox.php:26` use `firstOrCreate([idempotency_key])`
then `DB::afterCommit(fn => DispatchDomainEventsJob::dispatch(id))`
(`PersistOrderCreatedToOutbox.php:62-105`). Channel stored as
`json_encode(['private-branch.'.branch_id])` (`:44`), `broadcast_as='OrderCreated'`.

**2. DISPATCH (3-phase job).** `app/Jobs/DispatchDomainEventsJob.php`:
- Phase 1 atomic claim under `lockForUpdate()` + `dispatched_at` guard (`:65-86`); loser observes `dispatched_at != null` → returns (`:88-94`). No double-broadcast.
- Phase 2 broadcast OUTSIDE txn (`:96-133`): `EventContract::assertEnvelopeValid` (`:110`) then `BroadcastManager::connection()->broadcast($channels, broadcast_as, $envelope)` (`:115-116`), honoring `broadcasting.default`. Stamps `ws:heartbeat` cache key (`:129`).
- Phase 3b failure (`:156-189`): releases claim (`dispatched_at=null`), persists `last_error`; `PayloadMismatchException` short-circuits via `$this->fail()` (`:184`, not retry-recoverable); other throws re-thrown → backoff `[1,5,15,60,300]`, `tries=6` (`:40-42`).

**3. BROADCAST.** Channel `private-branch.{id}` (raw, Pusher adds no prefix);
auth at `routes/channels.php:41-62` (kiosk token → own branch via token-name
check `:44-49`; Admin/Tenant cross-branch `:56`; staff own branch `:61`).

**4. CONSUME (WS).** `resources/js/services/eventContract.js:346` `onEvents` →
`Echo.private('branch.'+id)` (`:354`) → resolves to `private-branch.{id}`. Match
with server is exact (no double-prefix). `parseEvent` (`:59`) + dedupe via
`isDuplicateCorrelation` (`:286`). Consumers: KDS
`KitchenDisplaySystemComponent.vue:1900` (OrderCreated/StatusChanged/PaidAtCounter
→ `_debouncedRefresh`); OSS `PreparingAndReadyComponent.vue:264`; POS tracker
`PosOrdersTrackerComponent.vue:691`. All WS handlers trigger a **full re-fetch**,
not a delta-apply.

**5. CONSUME (POLL).** KDS `KdsSyncService.js:145` GET `/api/admin/kds-order/sync?since=&include_deleted=true`;
server `app/Services/KdsSyncService.php:37` returns deltas where `updated_at >= since`
(inclusive, `:98`) + per-order `version` (`:127`). Cadence = `Infinity` when WS
CONNECTED (60s drift timer only, `:301`+`:353`). OSS `OssSyncService.js` polls
`orderStatusScreenOrder/lists` at 60s connected / 2s disconnected (`:8-16`).

## Per-pair verdict

- **Kiosk paid → KDS** — SOUND. ACCEPT/PREPARING (`FrontendOrderService.php:1212/1246`) is KDS-visible (`KitchenReleaseRule.php:16-23`); `OrderCreated` broadcast + KDS poll both surface it.
- **POS order → KDS** — SOUND. `OrderService.php:699` ACCEPT initial + `OrderPaidAtCounter`/`OrderStatusChanged` (`PaymentService.php:409/423`).
- **KDS "Prêt" → OSS + POS tracker** — SOUND. PREPARING→PREPARED dispatch at `KitchenDisplaySystemOrderService.php:458`; OSS handler `PreparingAndReadyComponent.vue:272` + POS `PosOrdersTrackerComponent.vue:699` both react to `new_status===PREPARED`.
- **OSS/KDS status change → POS tracker** — SOUND. POS tracker subscribes OrderStatusChanged (`:694`) + polls.

## Findings

**[P1] config/queue.php:16 — WS "connected" masks a dead broadcast pipeline.**
Evidence: `'default' => env('QUEUE_CONNECTION', 'sync')`; prod uses
`QUEUE_CONNECTION=redis` (.env.example:172) requiring a `queue:work --queue=high`
daemon (.env.example:165). Client cadence keys off the soketi socket, not event
delivery: `KdsSyncService.js:301` returns `Infinity` (60s drift) when
`WS_CONNECTED`; `OssSyncService.js:9` `intervalMsWhenConnected: 60_000`. If
soketi stays up but the worker dies (or `DispatchDomainEventsJob` keeps
failing), every surface shows "connected/live" yet only refreshes every 60s.
Impact: all-day kitchen/OSS lag up to ~60s with no visible degraded state.
Mitigation already present: `outbox:monitor --threshold=10` every minute
(`app/Console/Kernel.php:50`) + `/api/health/ready` 503 (.env.example:168) +
`ws:heartbeat` stamp (`DispatchDomainEventsJob.php:129`). Scope-minimal rec:
ensure prod runbook pages on `outbox:monitor` non-zero exit AND consider the
client treating a stale `ws:heartbeat` (exposed via SyncOverviewController) as
"degraded" to shorten cadence — no code change required for V1 single-box if the
worker + cron are supervised.

**[P2] resources/js/services/eventContract.js:264-300 — aggregateId dedupe key is SOUND (audit of the recent fix).**
Evidence: key = `[eventType]:[branch:X]:[agg:Y]:correlationId` (`:278-283`),
passed at `:378`. The recent `aggregateId` addition cannot drop legitimately-distinct
events: `eventType` was ALREADY in the key, so cross-type events (OrderCreated
vs StatusChanged, same order/correlation) never collided. `aggregateId` only
discriminates SAME-type + SAME-correlation + DIFFERENT-entity bursts — exactly
multi-item auto-86 (`ItemAvailabilityChanged` per item, one correlation) which
was previously collapsed to the first event. For order events `aggregate_id =
order->id` is constant (`PersistOrderCreatedToOutbox.php:30` etc.), so the key is
**byte-identical** to the prior format (backward-compatible per the `null` guard
`:275`). A true WS re-delivery carries the same agg → still dedups. No defect.

**[P2] app/Services/KdsSyncService.php:180 + KdsSyncService.js:178 — poll path drops sub-second double-updates.**
Evidence: `version = (int) updated_at->getTimestamp()` (seconds); client gate
`const gated = previousVersion !== undefined && version <= previousVersion`
(`.js:178`). Two `updated_at` writes in the same wall-clock second produce equal
versions → the 2nd is `versionGated` and its state is held until `updated_at`
advances a second. Impact: a rapid PREPARING→PREPARED within one second could be
gated on the poll path. Backstopped by WS (full re-fetch ignores the version
gate) and already documented as tech debt D-03bis (`KdsSyncService.php:165-172`,
"switch to status_changed_at"). Scope-minimal rec: defer to D-03bis column;
no V1 change.

**[P3] eventContract.js (WS) vs poll — no shared dedupe set, but no double-apply.**
Evidence: the poll path (`KdsSyncService.forceSync` / OSS `_poll`) never calls
`isDuplicateCorrelation`; only WS handlers do (`:378`). WS↔poll coexistence is
safe NOT via the correlation set but because every WS handler triggers a **full
idempotent re-fetch** (`KitchenDisplaySystemComponent.vue:1909` `_debouncedRefresh`;
OSS `:278` `list()`), and the poll itself carries the per-order `_versionMap`
gate. So WS + poll converge on the same server snapshot; no delta is applied
twice. No fix needed — documented here so the "double-apply" question is closed
with the real mechanism.

## Notes
- OSS public customer wall (`authBranchId() <= 0`) does NOT subscribe Echo
  (`PreparingAndReadyComponent.vue:260` early-return) but always runs
  `startOssSync()` (`:115`) — covered by polling fallback. Correct for V1.
- `DispatchKdsTicket` is a helper (method `dispatch()`), invoked explicitly by
  `KitchenDisplaySystemOrderService` (`:458`), not a Laravel event listener.
