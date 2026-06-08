# SYNC-RACE AUDIT — FoodKing V1 (Le Cayenne single-box)

**Auditor role:** Synchronization-race auditor (READ-ONLY deep source).
**Date:** 2026-06-08 · **Branch:** heal/cms-pr1-quickwins / worktree pre-cloud-exec
**Scope:** Real-time path — Laravel outbox→broadcast (pusher/soketi :6001), per-branch private channel `private-branch.{id}`, events OrderCreated / OrderStatusChanged / OrderPaidAtCounter / ItemAvailabilityChanged / CatalogChanged / KdsOrderRecalled; consumers KDS / OSS / POS-tracker / kiosk-waiting + kiosk-shell; polling fallbacks (OssSyncService / PosSyncService).
**Method:** file:line proof for every claim; concurrent-sequence repro narrative; strict skeptic (core-holds is a valid verdict with evidence).

> **Headline:** The **backend** sync core is genuinely race-safe and the **envelope contract is enforced loudly server-side** — these are not the bug surface. The real races live in the **front-end view layer**: (a) a root-cause channel-teardown defect in `eventContract.js` that lets one co-subscriber kill the shared channel for all others, and (b) uncoordinated, AbortController-less refetch fan-out in the POS-tracker and OSS order views. NF525 / fiscal chain is untouched by any finding here.

---

## 0. Page / surface → producer / consumer map

| Surface | File | Channel | Events consumed | Refetch discipline |
|---|---|---|---|---|
| Producer (all) | `app/Listeners/PersistOrder*ToOutbox.php` → `app/Jobs/DispatchDomainEventsJob.php` → `app/Domain/Events/EventContract.php` | `private-branch.{id}` | — | outbox + idempotency-key + atomic claim (lockForUpdate) |
| KDS | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1918` | `branch.{id}` | OrderStatusChanged (status-filtered), OrderCreated, OrderPaidAtCounter, ItemAvailabilityChanged, OrderTableChanged, KdsOrderRecalled | **`_debouncedRefresh()`** + `_echoMarkedReady` dedup — GOOD |
| OSS (customer wall) | `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:280` | `branch.{id}` | OrderStatusChanged, OrderCreated | **`list()` — NO abort/seq guard** + OssSyncService poll + window event + ws-connected ⇒ 4 uncoordinated triggers |
| POS tracker | `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue:691` | `branch.{id}` | OrderCreated, OrderStatusChanged, OrderPaidAtCounter | **`fetchOrders()` — NO abort/seq guard**; poll + ws + 3 echo handlers |
| POS caisse | `resources/js/components/admin/pos/PosComponent.vue:2775` | `branch.{id}` | (availability/order) | — |
| Encaissement | `resources/js/components/admin/encaissement/EncaissementComponent.vue:148` | `branch.{id}` | — | co-subscriber (see P1-SYNC-01) |
| Kiosk shell (parent) | `resources/js/components/frontend/kiosk/KioskAppComponent.vue:546` | `branch.{id}` | ItemAvailabilityChanged, CatalogChanged, ComposerProfileChanged, CouponChanged | subscribe once in `loadBranch()` (line 526) |
| Kiosk waiting (child) | `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:272` | `branch.{id}` | OrderCreated, OrderStatusChanged | mounts/unmounts inside shell's `<router-view>` |
| Polling svc OSS | `resources/js/services/OssSyncService.js` | poll | — | **AbortController + single timer + clamp — GOOD pattern** |
| Polling svc POS | `resources/js/services/PosSyncService.js` | poll | — | AbortController + single timer + clamp — GOOD |
| Kiosk offline replay | `resources/js/helpers/kioskOfflineQueue.js` | — | — | stable `localKey` ⇒ `X-Idempotency-Key`, `_syncInFlight` single-flight, cross-tab lock — GOOD |

---

## FINDINGS (prioritized)

### [P1] SYNC-01 — `eventContract.js` `unsubscribe()` tears down the WHOLE shared channel for every co-subscriber (root-cause channel-death race) — NEW (root-cause reframe of falsification flag #3)

**File:** `resources/js/services/eventContract.js:393-404` (the `Echo.leave(channelName)` call at **line 401**).

**Race narrative.** `onEvents(branchId, ...)` opens `window.Echo.private('branch.{id}')` (line 354) — a **shared** channel object. Echo does **not** refcount listeners across `.private()` calls, so every component that calls `onEvents` for the same branch attaches its `.listen()` bindings to the *same* underlying channel. On `unsubscribe()` the code correctly does targeted `channel.stopListening(...)` (line 396) — but then **unconditionally** also calls `window.Echo.leave(channelName)` (line 401), which **destroys the entire channel and its pusher subscription**, ripping out every *other* component's still-live listeners. First co-subscriber to unmount silently kills push for all the rest.

**Confirmed victim (kiosk).** `KioskAppComponent` (shell, parent route, `kioskRoutes.js:143`) subscribes once in `loadBranch()` → `_subscribeEchoChannel` (`KioskAppComponent.vue:526,546`) for **ItemAvailabilityChanged / CatalogChanged / ComposerProfileChanged / CouponChanged**. `KioskWaitingComponent` is a **child** route (`kioskRoutes.js:212-213`) mounted in the shell's `<router-view>` and subscribes to **OrderCreated / OrderStatusChanged** (`KioskWaitingComponent.vue:272`) on the SAME `branch.{id}`. When the customer leaves the waiting screen (`newOrder()` → route to idle, or the 10s/20s auto-redirect), the child's `beforeUnmount` → `_unsubscribeEcho()` → `Echo.leave('branch.{id}')` **kills the shell's availability subscription**. The shell only re-subscribes when the branch changes (single call site, line 526) — which never happens for a running kiosk → **push 86/availability/catalog is dead until full page reload**.

**Repro (exact sequence).** Kiosk boots → shell subscribes (86 pushes live) → customer orders → routes to `/kiosk/waiting/{id}` (child subscribes) → order ready / customer taps "Nouvelle commande" → child unmounts → `Echo.leave('branch.1')` fires → admin 86's a product → **kiosk never receives `ItemAvailabilityChanged`**; the 86'd item stays sellable until the kiosk-menu TTL cache (`_subscribeEchoChannel` `[C3]` comment: "TTL cache remains the fallback") expires or the kiosk is reloaded.

**Suspected sibling (HAND TO MAIN THREAD FOR LIVE VERIFY — do not assert blind).** Admin route→route navigation between two Echo surfaces on the same branch — **POS tracker ↔ KDS ↔ OSS ↔ Encaissement** (`PosOrdersTrackerComponent.vue:691`, `KitchenDisplaySystemComponent.vue:1918`, `PreparingAndReadyComponent.vue:280`, `EncaissementComponent.vue:148`). Depending on Vue Router mount/unmount ordering, the destination subscribes first, then the source's `beforeUnmount` fires `Echo.leave` and kills the destination's just-added listeners → destination silently falls to polling. Plausible from the same root cause; needs a live two-tab/navigation repro to confirm ordering.

**Severity honesty.** P1 (silent loss of the entire point of push on co-subscribed surfaces) but NOT total blindness: kiosk has the TTL menu-cache fallback; admin surfaces have polling fallback (but at the *connected* cadence — see P3-SYNC-05). Single-box V1, so blast radius = one kiosk + the admin boards.

**Scope-minimal fix (non-frozen).** In `eventContract.js`, **drop the `window.Echo.leave(channelName)` call** (line 401) — the targeted `channel.stopListening()` (line 396) already removes this subscriber's listeners and is sufficient. OR refcount subscribers per channel name and only `Echo.leave` on the last unsubscribe. One patch covers kiosk + all admin siblings. `owner_gated: NO` (sync service is explicitly non-frozen). Add a Vitest spec asserting two `onEvents` on the same branch, then one `unsubscribe`, leaves the other's listener live.

---

### [P2] SYNC-02 — POS tracker `fetchOrders()` last-write-wins: no AbortController, no sequence stamp — CONFIRMED (falsification flag #1)

**File:** `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue:747-764` (`fetchOrders`), invoked from poll (`_startPolling`/`_pollTimer` line 720), ws-connected (`_onWsConnected` line 675), and 3 echo handlers (lines 692, 702, 705).

**Race narrative.** Every WS event AND every poll tick fires a fresh `fetchOrders()`; each does `this.orders = res.data.data` (line 758) with **no AbortController and no request-sequence guard**. Under a burst (cashier bumps + kiosk pays + status push all within ~1s), N overlapping requests resolve in arbitrary order; the **last to resolve wins**, which may be the one that started **earliest** with the stalest snapshot → the board momentarily shows an out-of-date column placement (e.g., an order re-appears in "À encaisser" after it was paid).

**Repro.** Two cashiers on two tracker tabs; tab A marks order #N delivered (triggers OrderStatusChanged → both tabs `fetchOrders`); simultaneously kiosk pays order #M (OrderPaidAtCounter → both tabs `fetchOrders`). On a tab whose earlier slower request resolves last, #N briefly reappears in a non-delivered lane until the next tick corrects it.

**Severity honesty.** P2, self-healing: backend is the source of truth (every event refetches truth), the window is one poll/round-trip on a single-box LAN, and the lockForUpdate backend (see "core holds") guarantees the *data* is never corrupted — only the *view* flickers stale for <1 cadence. Not a lost update.

**Scope-minimal fix.** Adopt the OssSyncService pattern: hold an `AbortController`, abort the in-flight request at the top of `fetchOrders`, and/or stamp a monotonic `_fetchSeq` and ignore a response whose seq < latest. `owner_gated: NO`.

---

### [P2] SYNC-03 — OSS `list()` uncoordinated hydration: 4 triggers, no abort guard, races OssSyncService poll — CONFIRMED (falsification flag #1)

**File:** `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:403-414` (`list()`), triggered from `mounted` (line 114), `window 'realtime-order-update'` (line 115), `_onWsConnected` (line 223), and two echo handlers (lines 294, 299) — **plus** OssSyncService independently calls `_hydrateFromRows` from its own abort-guarded poll (line 240). So two *different* hydration paths (`list()` and the service) write `this.preparingItems/preparedItems` with **no shared coordination**, and `list()` itself has no AbortController.

**Race narrative.** A WS reconnect (`_onWsConnected` → `list()`, line 223) fires concurrently with an Echo OrderStatusChanged (→ `list()`, line 294) AND the OssSyncService poll tick (→ `_hydrateFromRows`, line 240). Three overlapping fetches resolve out of order; the slowest-but-earliest wins and writes a stale `preparedItems` set. Because `_markNewReady` diffs against the *previous* `preparedItems` (line 386, 394), a stale overwrite can either **drop** a "ready" chime/flash or **re-fire** it on the next correct hydration.

**Severity honesty.** P2, same self-healing rationale as SYNC-02 (next tick corrects). The customer wall mis-chiming or briefly mis-listing is cosmetic, not data loss.

**Scope-minimal fix.** Route ALL OSS order hydration through OssSyncService (which already has AbortController + single-timer); have the Echo/window/ws-connected handlers call a public "refresh now" that aborts in-flight (mirroring `_burstPoll`) instead of the bare `list()`. Removes the second uncoordinated writer entirely. `owner_gated: NO`.

---

### [P3] SYNC-04 — `markDelivered` / per-row `_delivering` guard lost on concurrent refetch — CONFIRMED (falsification flag #1), backend-idempotent

**File:** `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue:772-790`. `markDelivered` sets `order._delivering = true` **directly on the order object** (line 774) to debounce the button, but `fetchOrders()` replaces `this.orders` **wholesale** (line 758) with fresh objects that have no `_delivering` field.

**Race narrative.** Cashier taps "Livré" on order #N (`_delivering=true`, button disabled via `:disabled="!!order._delivering"` line 274). Before the change-status round-trip returns, a concurrent OrderStatusChanged/poll fires `fetchOrders` → `this.orders` is replaced → #N's `_delivering` flag is gone → the button re-enables → a fast cashier (or an autoclicker / double-tap) can fire a second `posOrder/changeStatus` for the same order.

**Severity honesty.** P3 — the **backend is fully idempotent**: `OrderService::changeStatus` re-reads under `lockForUpdate` and early-returns when `(int)$locked->status === $targetStatus` (`app/Services/OrderService.php:2130`), and the route carries `idempotency` + `throttle:kds-bump` middleware (`routes/api.php:1174`). Worst case is a redundant POST that the backend no-ops — **not** a lost update or duplicate transition. Cosmetic UX only.

**Scope-minimal fix.** Track delivering-state in a component-level `Set` of order ids (`this._deliveringIds`) keyed by id, not on the replaceable row object; read it in the template via a method. `owner_gated: NO`.

---

### [P3] SYNC-05 — Admin (`branch_id<=0`) push/poll gap: socket "connected" but no private-channel push ⇒ up-to-60s lag — NEW (sibling of the OSS TRAP-4 fix)

**File:** `PosOrdersTrackerComponent.vue:688-689` and `KitchenDisplaySystemComponent.vue:1913-1914` — both early-return from `subscribeEcho()` when `authBranchId() <= 0` ("Admin: polling fallback is sufficient"). But `realtimeConnected` / poll cadence is driven by the WS *transport* state, which is still `connected` (Echo/Pusher is up) → the component uses `POLL_WS_MS = 60000` (PosTracker line 425) even though it receives **zero push** on those boards.

**Race narrative.** An admin (branch_id=0) viewing the POS tracker or KDS sees order updates lag up to 60s, because they never joined `private-branch.{id}` yet poll at the *connected* (slow) cadence. This is exactly the class the OSS audit fixed for its **public wall** via the `intervalMsWhenConnected: 5000` override (`PreparingAndReadyComponent.vue:259-263`, `[TRAP-4]`) — but the **same fix was not applied** to PosTracker/KDS for the admin-viewer case.

**Severity honesty.** P3, low V1 relevance: in single-branch Le Cayenne the cashier and chef are `branch_id=1` (subscribe fine, full push). This only bites a **branch_id=0 admin** watching those two boards — a rare operator scenario. Note-and-defer.

**Scope-minimal fix.** Mirror the OSS TRAP-4 override: when `authBranchId() <= 0`, drive the poll at a snappy 5s instead of `POLL_WS_MS`. `owner_gated: NO`.

---

### [P3] SYNC-06 — Consumer dedup key omits old/new_status: a legitimate second status transition in one request is collapsed (missed chime/flash) — NEW

**File:** consumer key `type:branch:agg:correlationId` (`eventContract.js:264-284`, `isDuplicateCorrelation`) vs producer idempotency key which **includes** `old_status|new_status` (`PersistOrderStatusChangedToOutbox.php:27-33`).

**Race narrative.** If a single request legitimately emits **two** OrderStatusChanged for the same order (e.g., a chained `AutoPrepareOnPaid` ACCEPT→PREPARING inside one correlation), the producer writes two distinct DomainEvent rows (different idempotency keys), broadcasts both — but the **consumer** dedup collapses them to one (its key ignores status), **dropping the second push**.

**Severity honesty.** P3 — harmless for the refetch-based consumers (POS/OSS/KDS/kiosk all re-fetch the truth on any event, so they still converge). The only observable effect is a **missed `_markNewReady` chime / `_markFresh` flash** for the dropped transition's terminal status. No data impact.

**Scope-minimal fix.** Either (a) include `new_status` in the consumer dedup key for OrderStatusChanged, or (b) accept the drop as harmless given refetch-on-event. Document the asymmetry. `owner_gated: NO`.

---

## CORE-HOLDS (substantiated negatives — the strong points)

1. **Concurrent status-change at the DB is race-safe (NOT a lost-update).** `OrderStateMachine::apply` (`app/Domain/Order/OrderStateMachine.php:224-269`) wraps `lockForUpdate` + re-read `$from` from the locked row + **idempotent early-return** when `$from === $next` (line 231) + `allows()` guard (line 238). `OrderService::changeStatus` mirrors it with `lockForUpdate` + early-return on `(int)$locked->status === $targetStatus` (`OrderService.php:2118,2130`). KDS bump and POS markDelivered both route here. Two surfaces mutating the same order serialize on the row lock; the loser no-ops cleanly. **No 1s-`updated_at`-collapse lost-update at the data layer** — the falsification flag's "version-stamp collapse" is a *view*-layer flicker (SYNC-02/03), not a DB lost-update.

2. **Envelope contract is enforced LOUDLY, server-side, before broadcast.** `EventContract::buildEnvelope` (`app/Domain/Events/EventContract.php:81-90`) stamps `version => 1`, `type => event_type` (mapped to the same `EVENT_TYPES`/`BROADCAST_MAP` the JS consumer uses, line 36), and wraps `payload`. `assertEnvelopeValid` (line 99-145) validates `version`, `type`, `aggregate_id`, `branch_id`, `occurred_at`, `correlation_id`, AND **per-type required payload keys** (`REQUIRED_PAYLOAD_KEYS` line 57 + `assertPayloadValid`) — and it runs **before** `$broadcaster->broadcast(...)` in the job (`DispatchDomainEventsJob.php:110` precedes line 116). A key mismatch throws `PayloadMismatchException` → `$this->fail()` → lands in `failed_jobs` immediately (job line 184). So the "kiosk-promo `discount_amount` silent-drop class" of bug **cannot** silently drop a sync event — it fails loudly server-side and never broadcasts a malformed envelope. (Consumer-side `parseEvent` also re-validates `version===1` + non-empty `type` + object `payload`, `eventContract.js:35-57`, as belt-and-suspenders.)

3. **Outbox dispatch is exactly-once under worker race.** `DispatchDomainEventsJob` claims the row under `lockForUpdate` + `dispatched_at` guard in a committed transaction (`DispatchDomainEventsJob.php:65-86`); the losing worker observes `dispatched_at != null` and returns silently — broadcaster never fires twice. Producer listeners use idempotency-keyed `firstOrCreate` + `wasRecentlyCreated` skip (`PersistOrder*ToOutbox.php`). Consumer-side correlation dedup (`eventContract.js:286-301`) drops WS+poll double-delivery.

4. **Kiosk offline replay is idempotent and single-flight.** `syncQueue` replays with the **stable `localKey` as `X-Idempotency-Key`** on every retry (`kioskOfflineQueue.js:551-555`), guarded by `_syncInFlight` (line 507) and a cross-tab BroadcastChannel lock (`_acquireLock`, line 300). Backend `IdempotencyKeyMiddleware` collapses replays of the same key. Exponential backoff + MAX_ATTEMPTS abandonment. No duplicate-order-on-reconnect race.

5. **Polling fallbacks (OSS/POS sync services) are correctly built.** Both use a single timer, AbortController per poll (`OssSyncService.js:281-321`, `PosSyncService.js:247`), cadence clamp `[250ms, 60s]`, 5xx backoff, and a burst-poll on visibility/reconnect. When WS drops, `_scheduleNormalCadence` switches to `intervalMsWhenDisconnected` (OSS 2s, POS 30s). These are the GOOD pattern the order-view refetches (SYNC-02/03) should adopt.

---

## Open offline→online handoff note (SYNC-07, P3, UX)

`KioskWaitingComponent` mounted with an `offline_*` orderId shows the "syncing" placeholder and **never polls** (`KioskWaitingComponent.vue:246-249`). When `kioskOfflineQueue.syncQueue` later replays the order and the backend returns the REAL order id + queue number, the `syncCb` in the shell only updates pending/abandoned counters (`KioskAppComponent.vue:340-343`) — it does **not** propagate the real id/queue to the waiting screen. So a customer sitting on `/kiosk/waiting/offline_xxx` never sees their real queue number on that screen; they rely on the parent's auto-redirect / "Nouvelle commande". P3 — offline kiosk ordering is an edge case on single-box LAN and the auto-redirect prevents a permanent stuck state; no data loss (the order DID persist idempotently).

---

## Verdict

**The sync CORE holds** (backend transitions, outbox exactly-once, envelope contract, offline idempotency, fallback services) — substantiated, not assumed. The **front-end view layer** carries the real defects: **one P1 root-cause** (`Echo.leave` shared-channel teardown — fix once in `eventContract.js`, covers kiosk + all admin co-subscribers) and **P2/P3 view-flicker races** from AbortController-less refetch fan-out (self-healing, backend-idempotent, no data loss). Nothing here touches NF525 / fiscal chain / frozen zones; all fixes are in non-frozen JS (`owner_gated: NO`). The P1 admin sibling and the SYNC-05 admin gap want a **live two-surface repro** before the main thread patches them.
