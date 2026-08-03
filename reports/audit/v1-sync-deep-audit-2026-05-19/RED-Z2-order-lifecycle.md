# RED-Z2 — Order Lifecycle Sync Audit
**Date**: 2026-05-19 · **Mode**: read-only adversarial · **Agent**: RED-Z2 · **Branch**: `v1-0-1-hardening-2026-05-17`

Scope = order placed (kiosk paid OR POS cash) → DB persist → DispatchableAfterCommit event → PersistOrderCreatedToOutbox → DomainEvent row → DispatchDomainEventsJob → Pusher/Soketi → KDS/OSS Echo subscribers → polling fallback. Disputed for ordering, atomicity, idempotency, branch isolation, replay risk. Z1/Z3/Z4/Z5/Z6/Z7/Z8 deliberately out of scope.

---

## A. Anchors verified (file:line, Read this session)

- `app/Providers/EventServiceProvider.php:146-152` — `OrderCreated` listeners: `PersistOrderCreatedToOutbox` FIRST, then `DecrementItemAvailabilityOnOrder`, `DecrementStockOnOrderCreated`, `SendFcmOnOrderCreated`. Comment 129-138 ties order to F-002 round-3.
- `app/Providers/EventServiceProvider.php:139-144` — `OrderStatusChanged` listeners: PersistOrderStatusChangedToOutbox, AwardLoyaltyPointsOnDelivery, SendFcmOnOrderStatusChange.
- `app/Events/Concerns/DispatchableAfterCommit.php:27-74` — trait: if `transactionLevel()>0` register via `afterCommit`; else dispatch immediately (l.41). `dispatchNow()` bypasses.
- `app/Events/OrderCreated.php:19-26`, `OrderStatusChanged.php:15-25`, `OrderPaidAtCounter.php:8-17`, `OrderPaymentStatusChanged.php:11-20` — all use `DispatchableAfterCommit`.
- `app/Listeners/PersistOrderCreatedToOutbox.php:23` — idempotency_key = `sha1(ORDER_CREATED|aggregate_id)` (no discriminator → strict one-shot per aggregate). Channel = `private-branch.{branch_id}` (l.44). `wasRecentlyCreated` short-circuit (l.58-60). `DB::afterCommit` wraps `DispatchDomainEventsJob::dispatch($domainEvent->id)` (l.62-105) with Throwable-swallowing inline guard.
- `app/Listeners/PersistOrderStatusChangedToOutbox.php:27-33` — idempotency_key = `sha1(ORDER_STATUS_CHANGED|aggregate_id|old|new|correlation_id)` — request-scoped dedupe.
- `app/Jobs/DispatchDomainEventsJob.php:65-86` — Phase 1 `lockForUpdate` + `dispatched_at` claim inside `DB::transaction`. Phase 2 broadcast OUTSIDE tx (l.96-116). `tries=6`, `$backoff=[1,5,15,60,300]` (l.40-42). PayloadMismatchException → `$this->fail()` (l.183).
- `routes/channels.php:41-62` — `branch.{branchId}` auth: `tokenName==='kiosk-token'` → KioskMachine match; else Admin/Tenant Admin → ALL channels (l.56); else `$user->branch_id===$branchId` (l.61).
- `app/Services/KdsSyncService.php:37-149` — sync delta endpoint; 5s minute-bucket `Cache::remember`; TZ-aware Paris→UTC bounds (l.77-94); 50-row cap; `version=updated_at unix seconds` (l.166). TODO l.152-158 acknowledges D-03bis (`status_changed_at` planned).
- `resources/js/services/eventContract.js:337-338` — frontend subscribes `Echo.private('branch.'+branchId)`.
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1759-1761` — polling: `60_000ms` ws-connected / `5_000ms` disconnected. `subscribeEcho` early-returns `branchId<=0` (l.1777-1778 — admin polling-only).
- `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:252-272` — OSS subscribes only `OrderStatusChanged`+`OrderCreated`. `_echoMarkedReady` Set dedupes Echo-vs-`list()` reentry.
- `app/Services/OrderService.php:572, 1075, 1385` ; `app/Services/FrontendOrderService.php:597-606, 1212-1226` — `OrderCreated::dispatch` fires OUTSIDE the `DB::transaction({...})` closure but inside an outer try.
- `app/Services/KitchenDisplaySystemOrderService.php:170-252` — KDS bump: `lockForUpdate` + `expected_status` mismatch → abort(409). `kdsTicketDispatcher->dispatch` AFTER tx commit (l.230-245).
- `app/Domain/Order/OrderStateMachine.php:179-253` — `apply()` lockForUpdate + idempotent same-status early-return + state-machine allows. Comment l.21-23: legacy OrderService/FrontendOrderService do NOT use `apply()`.
- `database/migrations/2026_05_09_180000_*.php:40` — UNIQUE `idempotency_key` on `domain_events`.
- `app/Http/Controllers/Auth/GuestSignupController.php:121, 146` — guest user `branch_id=0`, token name `auth_token`, ability `['kiosk:order']`.

---

## B. Findings

### P1 — DispatchableAfterCommit guard is DEAD CODE on the primary order-creation paths
**Files**: `OrderService.php:563-575, 1068-1078, 1379-1390` ; `FrontendOrderService.php:596-610, 1212-1227`.

```php
// OrderService.php:560-572
});  // <-- closes DB::transaction
// [BUG-C1 FIX] Dispatch notifications AFTER transaction commit ...
try {
    \App\Events\OrderCreated::dispatch($this->order);
}
```

**Risk**: Trait at `DispatchableAfterCommit.php:31-39` only registers `afterCommit` if `transactionLevel()>0`. Every `OrderCreated::dispatch` site is OUTSIDE the closing `});` of `DB::transaction`. At that point `transactionLevel()===0`, so the trait falls to l.41 (`event(new static(...))`) — immediate dispatch. The "deferred ... dropped entirely on rollback" guard (trait l.34-37, advertised in OrderCreated.php docstring l.14-17) is never exercised on the hot path. Future refactor moving dispatch INSIDE the closure would silently re-engage the guard. Today it relies on comment-driven trust, not compile-time enforcement.

**Fix**: Move every dispatch INSIDE the closure (forces trait engagement, deterministic semantics) OR add sentinel test that grep-asserts placement.

---

### P1 — OrderCreated idempotency_key is request-agnostic — outbox dedupes BROADCAST, but sibling listeners (stock decrement, FCM) re-fire
**Files**: `PersistOrderCreatedToOutbox.php:22-23` ; `EventServiceProvider.php:146-152`.

```php
// PersistOrderCreatedToOutbox.php:22-23
// [iter14 SPECIALIST-2] OrderCreated is a one-shot per aggregate.
$idempotencyKey = sha1(EventType::ORDER_CREATED . '|' . $order->id);
```

**Risk**: If `OrderCreated::dispatch($order)` is called twice (buggy retry path, `finalizePaidKioskOrder` re-fire, queued listener replay), the DomainEvent row dedupes correctly (UNIQUE constraint). BUT — the THREE sibling listeners in EventServiceProvider:148-151 (`DecrementItemAvailability`, `DecrementStockOnOrderCreated`, `SendFcmOnOrderCreated`) are NOT idempotent on their own — they run twice. Stock decrements twice. Verified: none of them implement `ShouldQueue`; they execute inline. Z1 owns stock decrement but the listener-FANOUT contract is Z2.

**Fix**: Document the invariant "OrderCreated::dispatch fires EXACTLY ONCE per order.id" with a sentinel; alternatively introduce a marker `IdempotentDomainEvent` interface consulted by the event dispatcher BEFORE listener fan-out.

---

### P2 — `private-branch.0` is broadcast-addressable; Admin role bypasses ALL branch filters
**Files**: `PersistOrderCreatedToOutbox.php:44` ; `channels.php:56-58`.

**Risk**: If any order lands with `branch_id=0` (admin-owned, misconfigured kiosk, malformed POST default), the listener emits channel `private-branch.0`. channels.php:56-58 grants Admin/Tenant Admin role ANY `branchId` including 0. No code currently creates `branch_id=0` orders legitimately (FrontendOrderService:191 forces kiosk branch), but no INVARIANT enforces `branch_id > 0`. A future bug enabling 0 would broadcast to an Admin-only ghost channel — silent cross-tenancy via Tenant Admin.

**Fix**: Assert `$order->branch_id > 0` at the top of every `PersistOrder*ToOutbox::handle`. Mirror in PaidAtCounter / PaymentStatusChanged / TableChanged.

---

### P2 — KDS version-gate uses second-precision `updated_at` — sub-second flips dropped when Pusher is degraded
**Files**: `KdsSyncService.php:160-167` server ; `resources/js/services/KdsSyncService.js:168-178` client.

```php
return (int) (optional($order->updated_at)->getTimestamp() ?? 0);
```

**Risk**: Two status flips on same order within 1 second (POS bump 14:32:08.300 → kitchen bump 14:32:08.700). Both `lockForUpdate` serialize correctly server-side, both broadcast. KDS Echo handler debounces refresh, then calls `/sync`. Server returns `version=14...808` for BOTH transitions. Client `_versionMap` (KdsSyncService.js:168-170) considers `version <= previousVersion` as gated — second event is `versionGated=true`. UI shows first state, not second. Echo nominally covers via direct `new_status` payload — UNLESS Pusher is down and sync poll is the only signal. TODO at KdsSyncService.php:152-158 acknowledges as planned D-03bis dette.

**Fix**: Land D-03bis (`status_changed_at` ms-precision column). Promoted to P1 if Pusher availability target is below 99.9%.

---

### P2 — Outbox new-row failure path: `attempts=0` rows are NOT picked up by `outbox:retry-failed` (requires `attempts >= 5`)
**Files**: `OutboxRetryFailedCommand.php:80` ; `PersistOrderCreatedToOutbox.php:74-104` ; `Kernel.php:64`.

**Risk**: If Pusher down AND queue worker down at order-creation time (concurrent outage), the row sits `dispatched_at=null` + `attempts=0`. `outbox:retry-failed` filters `->failed(5)` — i.e. `attempts>=5`. Rows with attempts=0 NEVER touched by retry-failed; they depend on `outbox:rescue` (Z3 territory). Polling (KDS 5s on disconnect) saves user-facing observable, but the broadcast lane is structurally fragile during dual-outage. Z3 owns rescue cadence; flagged for cross-zone coordination.

**Fix**: Verify with Z3 that `outbox:rescue` runs everyMinute. No Z2 code change.

---

### P3 — DispatchKdsTicket class location/naming confusion
**Files**: `app/Listeners/DispatchKdsTicket.php` ; `KitchenDisplaySystemOrderService.php:242`.

The class lives in `app/Listeners/` but is invoked as a service (`$this->kdsTicketDispatcher->dispatch(...)`). No event handler signature. Pure code-smell; no double-broadcast in current call graph because `PersistOrderStatusChangedToOutbox` correlation-keyed dedupe absorbs the second fire.

**Fix**: Move to `app/Services/Kds/KdsTicketDispatcher.php`.

---

## C. Hard questions for owner

1. **Listener placement convention**: every `OrderCreated::dispatch` fires OUTSIDE `DB::transaction`. Is this locked in (no Migration to inside-closure), or is the trait supposed to do its job?
2. **Listener order invariant**: EventServiceProvider:146-152 — is there a sentinel test locking Outbox-FIRST? A reorder PR could regress F-002 silently.
3. **Duplicate OrderCreated fire**: outbox dedupes the broadcast row but DecrementStock/DecrementItemAvailability/SendFcm fire twice. What invariant prevents that?
4. **Kiosk paid path double-event**: FrontendOrderService:1226 dispatches OrderCreated from `dispatchNewOrderSignals`. For card/TR `finalizePaidKioskOrder` ALSO dispatches `OrderStatusChanged(PENDING→ACCEPT)` next. KDS gets TWO events in ms. `_debouncedRefresh` window sized for both?
5. **Channel auth `branch.0`**: channels.php:56-58 grants ALL channels to Admin/Tenant Admin. Tenant Admin assumed corp-tier only — confirmed?
6. **Nested transactions + `EventFake`**: trait calls `DB::connection()->afterCommit(...)` — binds to outermost tx. In RefreshDatabase tests, does `afterCommit` ever fire? Are broadcast lane tests covering this?
7. **POS counter cash**: PaymentService.php:251 dispatches OrderPaidAtCounter AFTER `DB::transaction`. fiscal_sequence_no allocated INSIDE same tx (l.206-208). Ordering invariant guaranteeing KDS sees `fiscal_sequence_no` non-null?
8. **KdsSyncService 5s cache** (l.49): 5s degradation acceptable, or reduce on disconnected branches?
9. **`version` field collision** (l.144): `(int)(microtime(true)*1000)` — concurrent pollers in same ms see same version for different data. Replica drift?
10. **Polling 60s ws-connected**: full minute catch-up window after Pusher reconnect — acceptable, or force-sync on `wsConnected→true` transition?
11. **Inline-dispatch swallow + cron**: row persisted, but if worker+scheduler both dead (dev local), order INVISIBLE to KDS forever. Production startup smoke that verifies both are alive?
12. **`_origin` vs `source_surface` parity**: PersistOrderCreatedToOutbox.php:35 vs KitchenDisplaySystemComponent:1861-1868. Always same value?
13. **OrderStateMachine::apply() vs legacy**: comment l.21 — legacy sites use inline lockForUpdate. Divergence audited?
14. **DispatchKdsTicket location**: move to `app/Services/Kds/`?
15. **Guest token + branch.0**: branch_id=0 + auth_token name → channels.php:61 fails on `0===N`. Does any UI subscribe to `branch.0`? If yes, guests pass.
16. **OrderPaidAtCounter fanout**: KDS subscribes (KitchenDisplaySystemComponent:1795), OSS does NOT (PreparingAndReadyComponent:252-272). OSS team OK with not seeing counter-paid until KDS bumps to PREPARED?
17. **OrderStatusChanged correlation_id dedupe** (PersistOrderStatusChangedToOutbox.php:27-33): same-request retry collapses. SAME request replayed by outer queue retry → correlation_id matches → dedupe wins. Could a stale row dispatch at retry after order moved to 3rd state?
18. **OutboxBroadcastSwallowedEvent**: any listener wired, or observability theater?
19. **`tries=6` total ~6.4min** then nothing until hourly cron: gap window between min 6.5 and next tick — acceptable?
20. **`updated_at` denormalization** (KdsSyncService.php:84): all transitions guaranteed to bump `updated_at`?
21. **Echo vs polling race**: Echo refresh + `kdsSyncService` poll both refresh Vuex. Last-write-wins or merged via version?
22. **Channel `branch.{N}` not `branch.{tenant}.{N}`**: V2 plan tenant-prefixed?
23. **OrderTableChanged collapse on debounce**: free + re-occupy within debounce window → both flips collapse — visible to kitchen?
24. **`branch_id=null` envelope** (EventContract.php:87): legitimately null for Settings/branch-status. Order persistors confirmed never null?
25. **409 frontend retry**: KitchenDisplaySystemOrderService:189-202 throws 409 on `expected_status` mismatch. Frontend auto-retries with refreshed expected_status, or surfaces to user?

---

## D. Sync invariants verified GREEN

1. **Listener ordering** Outbox-FIRST for OrderCreated/OrderStatusChanged/OrderPaidAtCounter (EventServiceProvider:139-155). F-002 round-3 comment block 129-138 documents incident.
2. **DispatchableAfterCommit semantics** correct WHEN engaged (l.31-39). Rollback drops event silently.
3. **DomainEvent UNIQUE constraint** at DB layer (migration 2026_05_09_180000:40) — race-safe duplicate listener fire.
4. **Phase-1 atomic claim** (DispatchDomainEventsJob:65-86): `lockForUpdate`+claim inside tx, broadcast outside. Concurrent workers cannot both broadcast same row.
5. **Payload contract validation** (l.110): `assertEnvelopeValid` before broadcast; PayloadMismatchException → `$this->fail()` (l.183) — does NOT waste 6 retries on malformed row.
6. **Kiosk channel auth token-name** (channels.php:44-49): `tokenName==='kiosk-token'` un-spoofable by Sanctum `['*']` wildcard. Comment l.27-40 documents the bypass risk.
7. **KDS optimistic-lock 409** (KitchenDisplaySystemOrderService:189-202): `expected_status` mismatch under lockForUpdate. Sentinel `KdsExpectedStatusConflictTest`.
8. **TZ-aware sync bounds** (KdsSyncService.php:77-94): Paris→UTC before binding, sentinel `KdsSyncTzAwareTest`. Wave 3 KDS-ADV3-01.
9. **5s cache salts branch_id** (KdsSyncService.php:42-47): multi-tenant safe; admin=`all` salt, others explicit.
10. **Network-error self-heal** (KdsSyncService.js:216-218): re-schedule on catch so DNS/TLS hiccup doesn't leave kitchen blind.

---

## E. Out-of-scope or unverifiable

- Z3 owns `outbox:rescue` cadence — P2 #5 above just flags the boundary.
- Z4 pricing SSOT / Z5 fiscal alloc on cash close — broadcast happens AFTER tx commit calling those services; their internal correctness is Z4/Z5.
- Z6 BranchScope leaks — channel auth (channels.php) inherits whatever guarantees Z6 provides for Tenant Admin role.
- Z7 idempotency HTTP middleware — `idempotency_key` column referenced (FrontendOrderService:617-622, OrderService:1099) but the middleware proper is Z7.
- Z8 refund chain — verified PersistOrderPaymentStatusChangedOnRefundCreated bridges RefundCreated→OrderPaymentStatusChanged via DispatchableAfterCommit; full chain not traced.
- I did NOT run any test or `php artisan` command — read-only. Sentinel tests (`AfterCommitDispatchTest`, `OutboxConcurrentWorkerDedupeTest`, `KdsExpectedStatusConflictTest`, `KdsSyncTzAwareTest`) exist but were not executed this session.

---

## F. RED verdict

**Quality**: **7.5 / 10**. Solid outbox pattern, well-instrumented logs, correlation-keyed dedupe, optimistic-lock 409 on KDS bumps, TZ-aware sync bounds, polling fallback wired. In-code documentation tying findings to plan IDs and audit waves is the strongest in the repo. Loses points on: (1) `DispatchableAfterCommit` semantics being dead code on the hot path while docstring advertises rollback-safety; (2) `OrderCreated` outbox dedupes broadcast but NOT fanout — stock/FCM listeners re-fire on duplicate dispatch; (3) version-gate at second precision drops sub-second flips when Pusher is degraded.

**Top 3 risks**:
1. (P1) `DispatchableAfterCommit` guard bypassed on every order-creation path — comment-driven trust, no enforcement.
2. (P1) `OrderCreated` duplicate dispatch → outbox dedupes ONE row, but three sibling listeners (stock decrement, item availability, FCM) fire twice.
3. (P2) `version=updated_at` second precision drops sub-second status flips during Pusher degradation.

**Shippable**: **YES with caveat** — Z2 lifecycle is safe for V1 Le Cayenne LOCAL single-restaurant single-day operation. P1 findings are latent (need bad refactor or double-dispatch to bite). P2 version-gate is bounded by Pusher availability — accepted dette per code TODO. Recommend before V1.0.2: (a) sentinel test grep-asserting `OrderCreated::dispatch` placement relative to `DB::transaction({...})`; (b) `branch_id > 0` invariant assertion in every `PersistOrder*ToOutbox::handle`; (c) target the D-03bis `status_changed_at` ms-precision column.

**Cross-zone follow-up**: verify with Z3 that `outbox:rescue` cadence is everyMinute so attempts=0 stuck rows are bounded to seconds, not an hour.
