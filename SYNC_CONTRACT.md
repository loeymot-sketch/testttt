# SYNC_CONTRACT — Realtime sync SSOT (cold-start, grounded file:line)

The bus is a **SHARED ZONE** (SYSTEM_MAP §6): no single system lane edits it alone. This doc lets a KDS-agent and a CAISSE-agent derive the SAME contract without re-reading code. Refreshed 2026-06-21 (full cartography: ~22 outbox events, was ~4). HEAD `b40390a31`.

## 0. Architecture in one line
There are **ZERO true `ShouldBroadcast` events**. Every realtime signal rides an **OUTBOX**: a plain domain event (trait `DispatchableAfterCommit`) → a `Persist*ToOutbox` listener writes a `domain_events` row → `DispatchDomainEventsJob` (queue `high`) broadcasts the canonical **envelope** to soketi → Echo consumers on `private-branch.{id}` react (refetch/patch), gated by a global **correlation-id dedup**. REST polling is the degradation path on every surface. No data loss when the bus dies — only latency.

## 1. Transport
- **soketi** (Pusher-protocol WS) on `:6001` (`soketi.json`). Echo client built in `resources/js/bootstrap.js:327-350` (guarded by `MIX_PUSHER_APP_KEY`); auth `POST /api/broadcasting/auth` Bearer token. State machine `resources/js/services/WebSocketService.js` (CONNECTED/UNAVAILABLE/SESSION_INVALID, auth-failure breaker 3/60s, reconnect-storm breaker 4/30s, `window._wsService`).
- **queue:work redis** consumes the broadcast job. `DispatchDomainEventsJob` on queue `high` (`app/Jobs/DispatchDomainEventsJob.php:46`, `tries=6`, backoff `[1,5,15,60,300]`s).
- **`BROADCAST_DRIVER`** (`config/broadcasting.php:18`): prod must be `pusher` (soketi). Drivers `log`/`null` **silently swallow** broadcasts (job still marks the row dispatched → no error, no client delivery). Boot guard refuses prod on `null` (`AppServiceProvider:273`); a `log`-driver guard was added `72d6a01ad`.
- Daemons on the one box: `php artisan serve` · `queue:work redis --queue=high,default` · `soketi :6001` · redis · `schedule:run` (outbox cron).

## 2. Channels (`routes/channels.php`) — exactly 2, both PRIVATE
- **`branch.{branchId}`** (`channels.php:41-62`) — wire name **`private-branch.{branchId}`** (Echo prefixes `private-`). **Every** outbox event broadcasts here. Auth: kiosk token (token *name* `'kiosk-token'`) → only its `KioskMachine.branch_id`; `Admin`/`Tenant Admin` role → any branch; else staff → own `branch_id` only. V1 = `branch_id=1`.
- **`App.Models.User.{id}`** (`channels.php:16-18`) — per-user. **No SPA subscriber found → dormant for the SPA.** No presence/public channels exist.
- ⚠️ The **public OSS wall** runs as admin `branch_id<=0` → `subscribeEcho()` early-returns (`PreparingAndReadyComponent.vue:302`) → it **polls** (see §7).

## 3. Events — the OUTBOX catalog (broadcast_as = PascalCase, event_type = dotted `EventType.php`)
The **`domain_events` row** is the real contract: `channel='["private-branch.{id}"]'`, `broadcast_as='<PascalCase>'`, `payload={...}`. Payload keys below are what the `Persist*ToOutbox` listener writes.

### Order lifecycle
| broadcast_as | event_type | Payload keys (listener:line) |
|---|---|---|
| `OrderCreated` | `order.created` | order_id, queue_number, _origin, payment_method, payment_status, payment_pending_counter, status, order_type, total, created_at — `PersistOrderCreatedToOutbox:32` |
| `OrderStatusChanged` | `order.status_changed` | order_id, queue_number, _origin, payment_*, old_status, new_status, token — `PersistOrderStatusChangedToOutbox:42` |
| `OrderTableChanged` | `order.table_changed` | order_id, branch_id, previous_table_id, new_table_id, action, queue_number, dining_table_name — `PersistOrderTableChangedToOutbox:66` |
| `KdsOrderRecalled` | `kds.order_recalled` | order_id, branch_id, queue_number, actor_id, recalled_at — `PersistKdsOrderRecalledToOutbox:51` |

### Payment
| broadcast_as | event_type | Payload keys |
|---|---|---|
| `OrderPaymentStatusChanged` | `order.payment_status_changed` | order_id, branch_id, queue_number, _origin, payment_method, old_status, new_status, total, fiscal_sequence_no, token — `PersistOrderPaymentStatusChangedToOutbox:48` |
| `OrderPaidAtCounter` | `order.payment_confirmed` | order_id, queue_number, _origin, payment_method, payment_status, fiscal_sequence_no — `PersistOrderPaidAtCounterToOutbox:33` |
- **`RefundCreated`** is **internal-only** (`RefundCreated.php:13` "NOT broadcast"): its listener sets `payment_status=REFUNDED` then **re-dispatches `OrderPaymentStatusChanged`** (`PersistOrderPaymentStatusChangedOnRefundCreated:128`) → refunds reach clients via the payment-status broadcast, not their own event.

### Catalog / Menu (fan-out: one row per active branch when branch_id is null)
| broadcast_as | event_type | Payload keys |
|---|---|---|
| `CatalogChanged` | `catalog.changed` | entity_type, entity_id, change_type, branch_id, snapshot_version, payload_diff — `PersistCatalogChangedToOutbox:75` |
| `ItemAvailabilityChanged` | `menu.item_availability_changed` | item_id, status, price, type, is_available, branch_id, reason — `:25` |
| `ItemExtraAvailabilityChanged` | `menu.extra_availability_changed` | extra_id, branch_id, is_available, reason — `:25` |
| `ItemVariationAvailabilityChanged` | `menu.variation_availability_changed` | variation_id, branch_id, is_available, reason — `:24` |
- `ItemCreated/Updated/Deleted`, `Category*`, `ComposerProfileChanged`, `StockLevelChanged` are plain events that **funnel into `CatalogChanged`** via `PersistCatalogChangedToOutbox` (EventServiceProvider:216-292).

### Stock / Settings / Branch / Coupon
| broadcast_as | event_type | Payload keys |
|---|---|---|
| (`StockLevelChanged`→) `CatalogChanged` | `catalog.changed` | + `NotifyStockLowOnStockLevelChanged` side-listener |
| `SettingsUpdated` | `settings.updated` | changed_keys, branch_id — `PersistSettingsUpdatedToOutbox:64` (null→all branches). ⚠️ **No SPA subscribes** to it (V1.0.2 wiring backlog) |
| `BranchStatusChanged` | `branch.status_changed` | branch_id, old_status, new_status — `:68` |
| `CouponChanged` | `promo.coupon_changed` | coupon_id, code, status, change_type, branch_id, surfaces, payload_diff — `:64` (empty scope→all branches) |

### Internal / ops (NOT client broadcasts)
- `OutboxBroadcastSwallowedEvent` → `EscalateOutboxBroadcastSwallowed` (outbox-write-failure alarm).
- `StockDecrementFailedEvent` — **intentionally UNWIRED** (live signal is a structured `Log::error`).

## 3b. Outbox mechanism (why it degrades gracefully)
1. Plain event uses `DispatchableAfterCommit` (`app/Events/Concerns/DispatchableAfterCommit.php:27` — defers to `DB::afterCommit`, **drops on rollback**).
2. `Persist*ToOutbox` listener (13 of them) writes a `domain_events` row via `firstOrCreate(['idempotency_key'=>sha1(...)])` (write-side idempotency). It is registered **FIRST** in `$listen` (sync dispatcher halts on throw → outbox is SSOT; FCM/loyalty run after).
3. `DispatchDomainEventsJob`: atomic `lockForUpdate` + `dispatched_at` claim (dup-worker returns silently) → `EventContract::buildEnvelope` + `assertEnvelopeValid` → `BroadcastManager->broadcast($channels, $broadcast_as, $envelope)` → stamps `ws:heartbeat`. `PayloadMismatchException`→`fail()` (no retry); other failure resets `dispatched_at=null` for retry.
4. **Cron** (`app/Console/Kernel.php`): `outbox:rescue` (everyMinute, re-queue attempts<5), `outbox:monitor --threshold=10` (everyMinute, Log::error + detects crash-claimed orphans), `outbox:retry-failed --since=24h` (hourly), `outbox:prune --older-than-days=90` (daily 04:00).

## 4. Payload contract — the wire envelope (consume-side SSOT)
`EventContract::buildEnvelope` (`app/Domain/Events/EventContract.php:84-90`):
```
{ version:1, type:<dotted EventType>, aggregate_id, branch_id, occurred_at(ISO8601), correlation_id(UUID), payload:{…listener keys…} }
```
- `BROADCAST_MAP` maps PascalCase↔dotted; `REQUIRED_PAYLOAD_KEYS` enforced pre-broadcast. **Gap:** `menu.{extra,variation}_availability_changed`, `settings.updated`, `branch.status_changed`, `kds.order_recalled` are in `EventType::all()` (pass `assertEnvelopeValid`) but have **no `REQUIRED_PAYLOAD_KEYS` entry** → payload shape unenforced (V1.0.2 hardening).
- **KdsOrder consume-side** (`KDSOrderDetailsResource` + `KDSOrderItemsResource`, customization via `helpers/kdsCustomization.js`): rendered FROM `composition_snapshot` (frozen at creation, never recomputed — HIST-10).

## 5. Publishers / Subscribers
| Surface | Subscribes (broadcast_as → action) | File:line |
|---|---|---|
| **POS (Caisse)** | OrderCreated, OrderStatusChanged, OrderPaidAtCounter, ItemAvailabilityChanged, CatalogChanged → reload loaders | `PosComponent.vue:2801`, `PosOrdersTrackerComponent.vue:686`, `EncaissementComponent.vue:177` |
| **Borne (Kiosk)** | Waiting: OrderCreated/OrderStatusChanged (match orderId→poll). App: ItemAvailabilityChanged, CatalogChanged, ComposerProfileChanged, CouponChanged | `KioskWaitingComponent.vue:265`, `KioskAppComponent.vue:542` |
| **KDS** | OrderStatusChanged (ACCEPT/PREPARING/PREPARED→debouncedRefresh), OrderCreated, OrderPaidAtCounter, ItemAvailabilityChanged, OrderTableChanged, KdsOrderRecalled (re-inject RAPPELÉ 60s) | `KitchenDisplaySystemComponent.vue:2026` |
| **OSS (public wall)** | OrderStatusChanged (PREPARED→chime+list()), OrderCreated → **only if branch_id>0; admin branch≤0 ⇒ poll-only** | `PreparingAndReadyComponent.vue:299` |
| **Stock dash** | Item{,Variation,Extra}AvailabilityChanged → debouncedReload | `StockRuptureDashboardComponent.vue:472` |
| CENTRAL dashboard | passive REST poll (~60s by design) | — |

## 6. Latency (measured prior cycles — NOT re-measured here)
- WS push ~6 ms (chef channel). End-to-end PREPARING→PREPARED→chef ~512 ms. Cold first-paint after heal 2292→269 ms. OSS public wall up to ~60 s stale (poll, no push — §5, known customer-experience weak point).

## 7. Degradation behavior (no data loss is the invariant)
Per-surface poll cadence (connected vs disconnected):
- **KDS**: `wsConnected?60000:5000` (`KitchenDisplaySystemComponent.vue:2004`); `KdsSyncService.js` delta poll CONNECTED→60s drift-tick, DEGRADED 5s, DISCONNECTED 10s, high-activity 3s (clamp [250,60000]).
- **OSS**: `OssSyncService.js:8` connected 60s / disconnected 2s; 4xx/5xx backoff 5000×2 cap 30000.
- **POS**: tracker `POLL_WS_MS=60000`/`POLL_NO_WS_MS=8000`; `PosSyncService.js:41` disconnected-only 30s.
- **Kiosk**: `KioskWaitingComponent.vue` fixed 15s (not WS-conditional).
- **queue:work / soketi dies** → broadcasts stop → all surfaces poll `orders` directly → no data loss, latency only. `domain_events` pile `dispatched_at=NULL` → `outbox:rescue`/`monitor` recover/alert on worker recovery.
- **Consumer dedup** (at-least-once → dedupe): single global gate `isDuplicateCorrelation(correlationId,type,branchId,aggregateId)` BEFORE every handler (`eventContract.js:378`; bounded Set 2048, TTL 10min, persisted to `sessionStorage`). KDS adds version-gating (`_versionMap`); OSS adds local `_echoMarkedReady`. Events without `correlation_id` bypass dedup.
- ⚠️ **Offline banner suppressed on the local box**: KDS banner hidden when `appEnv==='local' && FK_KDS_SHOW_FALLBACK_BANNER===false` (`KitchenDisplaySystemComponent.vue:1404`); POS warn hidden when `isDevEnv` → on the single local box the kitchen gets no visual cue (accepted V1; revisit for prod).

## 8. Rules for any lane touching sync
- The bus is SHARED → edits require a LOCK doc + gate (PARALLEL_PROTOCOL). A system lane normally only **produces/consumes** via existing events; it does not change channel/event/payload shape without coordination.
- Changing the envelope or a payload shape breaks KDS + OSS + POS + Kiosk simultaneously → cross-lane change, never parallel. Add a new `event_type` + `REQUIRED_PAYLOAD_KEYS` entry rather than mutating an existing one.
- Acceptance: a KDS-agent and a CAISSE-agent reading this doc agree on channel name, broadcast_as/event_type, payload fields, envelope shape, and degradation cadence without opening the code.
