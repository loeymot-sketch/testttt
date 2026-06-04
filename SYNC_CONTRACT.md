# SYNC_CONTRACT — Realtime sync SSOT (cold-start, grounded file:line)

The bus is a **SHARED ZONE** (SYSTEM_MAP §6): no single system lane edits it alone. This doc lets a KDS-agent and a CAISSE-agent derive the SAME contract without re-reading code. HEAD `d6487f716`. Unconfirmed exact lines tagged `(à vérifier)`.

## 1. Transport
- **soketi** (Pusher-protocol WS) on `:6001` (`soketi.json`). Laravel Echo client via `resources/js/services/WebSocketService.js`.
- **queue:work redis** dispatches broadcast jobs (outbox pattern). Redis is the cache/queue store.
- Daemons on the one box: `php artisan serve :8000` · `queue:work redis` · `soketi :6001` · redis. (Operability gaps → see PROJECT_BRAIN §2 / deep-review.)

## 2. Channel
- **`branch.{branchId}`** — PRIVATE channel, authorized in `routes/channels.php:41`. A kiosk-machine token is **restricted to its own branch** (channels.php:22-42 comment + check). V1 = `branch_id=1` only.
- User channel `App.Models.User.{id}` (`channels.php:16`) — per-user (notifications).
- ⚠️ The **public OSS wall does NOT subscribe** (it has `branchId<=0`, `subscribeEcho()` early-returns — `PreparingAndReadyComponent.vue:262-263`); it **polls** instead (see §5).

## 3. Events (broadcast)
| Event | File | Broadcast | Meaning |
|---|---|---|---|
| `OrderCreated` | `app/Events/OrderCreated.php` (**plain `DispatchableAfterCommit` — NOT ShouldBroadcast**) | outbox → `private-branch.{id}` | new order placed (borne/caisse/web) → appears on KDS |
| `OrderStatusChanged` | `app/Events/OrderStatusChanged.php:15` (**plain event**, dispatched from OrderService/PaymentService/FrontendOrderService/KDS) | outbox → `private-branch.{id}` | status transition (ACCEPT→PREPARING→PREPARED→…) → KDS/OSS/customer tracker update |
| `KdsOrderRecalled` | `app/Events/KdsOrderRecalled.php` | outbox → `private-branch.{id}` (`PersistKdsOrderRecalledToOutbox.php:58`) | chef recalls a bumped order |
| `OutboxBroadcastSwallowedEvent` | `app/Events/OutboxBroadcastSwallowedEvent.php` | internal (not client) | broadcast-failure alarm (outbox monitor) |

**⚠️ Broadcast mechanism (corrected, verified code-side 2026-06-03):** the 3 order events do **NOT** use Laravel `ShouldBroadcast`. They are **plain events** → a `Persist{Event}ToOutbox` listener writes a `domain_events` row (`channel=['private-branch.'.$branch_id]`, `broadcast_as='<Event>'`) → `DispatchDomainEventsJob->broadcast()` pushes to soketi. **Outbox pattern** (gate C9/KI-001 — `OrderCreated.php:12` comment: "replacing direct ShouldBroadcastNow"). Listeners: `app/Listeners/Persist{OrderCreated,OrderStatusChanged,KdsOrderRecalled}ToOutbox.php`. This is WHY worker-death degrades gracefully (rows persist, replay on recovery). **Channel naming:** `branch.{branchId}` (auth name, `channels.php:41`) == `private-branch.{branchId}` (wire name, Pusher `private-` convention) — same channel.

## 4. Payload contract — canonical KdsOrder (consume-side SSOT)
From `app/Http/Resources/KDSOrderDetailsResource.php:21+` (header fields) + `KDSOrderItemsResource.php` (line items):
- Header: `id`, `order_serial_no`, `token`, `order_type`, `source_surface`, `created_at_iso`, `updated_at` (ISO8601), `order_datetime`/`order_date`/`order_time`, status fields.
- Line items via `KDSOrderItemsResource` + customization rendered client-side by `resources/js/helpers/kdsCustomization.js` (sandwich/taco/burger/assiette/menu_formule shapes).
- **Immutability:** `composition_snapshot` is frozen at order creation; the read path renders FROM the snapshot, never recomputed from live menu (verified HIST-10: live price→999 ignored on read).

## 5. Publishers / Subscribers (who emits, who listens)
| Producer | Emits | Consumer | How |
|---|---|---|---|
| BORNE (kiosk) | `OrderCreated` on place (card/TR defer dispatch until TPE confirm; Plan-B cash auto-accepts → KDS preps) | KDS | WS push on `branch.{id}` |
| CAISSE (POS) | `OrderCreated` / `OrderStatusChanged` (collect, status) | KDS, OSS, customer tracker | WS push |
| KDS | `OrderStatusChanged` (bump/recall) | OSS, customer tracker | WS push |
| **KDS screen** | — | subscribes `branch.{id}` (chef `branch_id=1`) | Echo private |
| **OSS public wall** | — | **POLLS, no push** | `OssSyncService.js:9` `intervalMsWhenConnected: 60_000` |
| Customer web/app tracker | — | subscribes `branch.{id}` | Echo private |
| CENTRAL dashboard | — | passive poll (~60s by design) | REST |

## 6. Latency (measured, prior cycles — not re-measured here)
- WS push: **~6 ms** (chef channel, living-sync 2026-05-29).
- End-to-end status change (PREPARING→PREPARED → chef screen): **~512 ms**.
- Cold first-paint after fix: **2292 → 269 ms** (sync heal F-LAT-01, `block_for=5`).
- OSS public wall: **up to ~60 s stale** (poll, no push — §5; flagged as a customer-experience weak point).

## 7. Degradation behavior (no data loss is the invariant)
- **queue:work dies** → broadcasts stop; screens fall back to **poll** (KDS ~30s, admin ~60s) reading `orders` directly → **no data loss**, only latency. `domain_events` pile `dispatched_at=NULL`; `MonitorOutboxStaleness` detects (but only `Log::error` — alerting gap).
- **soketi dies** → `WebSocketService` flips to UNAVAILABLE (reconnect circuit-breaker) → poll fallback. ⚠️ KDS "polling mode" banner is **suppressed when `APP_ENV=local`** (`KitchenDisplaySystemComponent.vue:1314-1321`) → on the local box the kitchen gets no visual cue.
- **Outbox** durably persists broadcast intents; crash-claimed orphans detected (`MonitorOutboxStaleness.php:49-102`).

## 8. Rules for any lane touching sync
- The bus is SHARED → edits require a LOCK doc + gate (PARALLEL_PROTOCOL). A system lane normally only **produces/consumes** via the existing events; it does not change channel/event/payload shape without coordination.
- If you change the KdsOrder payload shape, you break KDS + OSS + tracker simultaneously → cross-lane change, never parallel.
- Acceptance: a KDS-agent and a CAISSE-agent reading this doc agree on channel name, event names, payload fields, and degradation cadence without opening the code.
