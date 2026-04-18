# Audit: Synchronization & Broadcast Architecture

**Date:** 2026-03-31  
**Scope:** Events, Listeners, Broadcasting, KDS/OSS real-time, all order creation & status change paths  
**Auditor:** Claude (Architect)

---

## 1. Event Classes

### 1.1 `OrderCreated` (`app/Events/OrderCreated.php`)

| Aspect | Finding | Status |
|--------|---------|--------|
| Interface | `ShouldBroadcastNow` (sync, bypasses queue) | OK |
| Channel | `private-branch.{branch_id}` | OK |
| Broadcast name | `.OrderCreated` | OK |
| Payload | `order_id`, `queue_number`, `status`, `order_type`, `total`, `created_at` | OK |
| Constructor | `BroadcastableOrder $order` (polymorphic: Order + FrontendOrder) | OK |
| Risk | `ShouldBroadcastNow` blocks the HTTP response if Soketi is slow/down. Acceptable for now (QUEUE_CONNECTION=sync). | LOW |

### 1.2 `OrderStatusChanged` (`app/Events/OrderStatusChanged.php`)

| Aspect | Finding | Status |
|--------|---------|--------|
| Interface | `ShouldBroadcastNow` (sync, bypasses queue) | OK |
| Channel | `private-branch.{branch_id}` | OK |
| Broadcast name | `.OrderStatusChanged` | OK |
| Payload | `order_id`, `queue_number`, `old_status`, `new_status`, `token` | OK |
| Constructor | `BroadcastableOrder $order`, `int $oldStatus`, `int $newStatus` | OK |

### 1.3 `BroadcastableOrder` Contract

Both `Order` and `FrontendOrder` implement `BroadcastableOrder`. The interface is marker-only (empty body). This works because `broadcastWith()` accesses properties dynamically. **No type mismatch risk.**

---

## 2. Event-Listener Mapping (`EventServiceProvider`)

```
OrderCreated::class => [
    SendFcmOnOrderCreated::class,
]

OrderStatusChanged::class => [
    AwardLoyaltyPointsOnDelivery::class,
    SendFcmOnOrderStatusChange::class,
]
```

### 2.1 Listener: `AwardLoyaltyPointsOnDelivery`

| Aspect | Finding | Status |
|--------|---------|--------|
| Trigger | DELIVERED (all), PREPARED (kiosk/takeaway) | OK |
| Idempotency | Atomic sentinel (`loyalty_points_awarded = -1`) | EXCELLENT |
| Failure mode | Catches `\Throwable`, reverts sentinel, logs error | OK — never blocks status change |
| Cancelled guard | Checks `status !== CANCELED` before awarding | OK |
| Silent failure risk | **None** — all paths log explicitly | OK |

### 2.2 Listener: `SendFcmOnOrderCreated`

| Aspect | Finding | Status |
|--------|---------|--------|
| Kitchen notification | Sent unless status is already ACCEPT/PREPARING | OK |
| POS notification | Sent for non-POS/non-WEB sources | OK |
| Customer notification | Always sent | OK |
| Failure mode | `SendFcmNotificationJob::dispatch()` is queued — won't block | OK |
| Silent failure risk | If `branch_id` is empty, returns silently (no log) | LOW — shouldn't happen |

### 2.3 Listener: `SendFcmOnOrderStatusChange`

| Aspect | Finding | Status |
|--------|---------|--------|
| PREPARING | Kitchen notification | OK |
| PREPARED | Kitchen + Customer + OSS notifications | OK |
| DELIVERED | Customer notification | OK |
| CANCELED | Customer + Kitchen notifications | OK |
| ACCEPT | **No notification mapped** | NOTE — by design (ACCEPT is initial state for POS) |
| Failure mode | Queued jobs — non-blocking | OK |

---

## 3. Broadcasting Infrastructure

### 3.1 `config/broadcasting.php`

| Aspect | Finding | Status |
|--------|---------|--------|
| Default driver | `env('BROADCAST_DRIVER', 'null')` | **CRITICAL CONFIG** — must be `pusher` in production |
| Pusher config | Host, port, scheme from env vars (Soketi compatible) | OK |
| TLS | `useTLS` derived from `PUSHER_SCHEME` | OK |

> **GAP-B1:** If `.env` has `BROADCAST_DRIVER=null` (the default), ALL broadcast events are silently discarded. KDS and OSS will fall back to 30s polling only. This is the **#1 deployment risk**.

### 3.2 `BroadcastServiceProvider`

| Aspect | Finding | Status |
|--------|---------|--------|
| Auth route | `POST /api/broadcasting/auth` with `auth:sanctum` | OK |
| Prefix | `api` — matches SPA Echo config | OK |

### 3.3 Channel Authorization (`routes/channels.php`)

| Aspect | Finding | Status |
|--------|---------|--------|
| `branch.{branchId}` | Authorized for: admin (branch_id=0), own-branch staff, kiosk machines (via KioskMachine lookup) | OK |
| Kiosk token guard | Checks `kiosk:order` ability + machine branch match | OK |
| Privilege escalation | Prevented — kiosk can't subscribe to other branches | OK |

---

## 4. Frontend Consumers

### 4.1 KDS (`KitchenDisplaySystemComponent.vue`)

| Aspect | Finding | Status |
|--------|---------|--------|
| Echo subscription | `private branch.{branchId}` | OK |
| Events listened | `.OrderStatusChanged` + `.OrderCreated` | OK |
| Polling fallback | 30s interval (`setInterval`) | OK |
| Admin fallback | `branchId <= 0` → polling only (no Echo) | OK |
| Debounce | 300ms `_debouncedRefresh()` prevents triple API calls | OK |
| Cleanup | `unsubscribeEcho()` in `beforeUnmount` + `stopListening` before `leave` | OK |
| Duplicate listener guard | `unsubscribeEcho()` called before `subscribeEcho()` | OK |

### 4.2 OSS (`PreparingAndReadyComponent.vue`)

| Aspect | Finding | Status |
|--------|---------|--------|
| Echo subscription | `private branch.{branchId}` | OK |
| Events listened | `.OrderStatusChanged` + `.OrderCreated` | OK |
| Polling fallback | 30s interval | OK |
| Ready detection | Compares previous prepared IDs with new list + Echo pre-mark | OK |
| Sound | 4-tone ascending chime via Web Audio API | OK |
| Flash animation | 4s green flash on ready column | OK |
| Duplicate listener guard | `unsubscribeEcho()` called first | OK |
| De-duplication | `_echoMarkedReady` set prevents double chime from Echo + poll | EXCELLENT |

### 4.3 OSS Parent (`OrderStatusScreenComponent.vue`)

| Aspect | Finding | Status |
|--------|---------|--------|
| Echo subscription | **None** — delegates to `PreparingAndReadyComponent` | OK |
| `PopularItemComponent` | Fetches popular items on mount only — no real-time updates | OK (static data) |

---

## 5. Order Creation Paths — `OrderCreated` Dispatch Audit

| Path | Dispatches `OrderCreated`? | After TX commit? | Payload correct? | Status |
|------|---------------------------|-------------------|-------------------|--------|
| `FrontendOrderService::myOrderStore` | YES — via `dispatchNewOrderSignals()` (line 693) | YES — after `DB::transaction()` closure returns (line 517) | `$this->frontendOrder` (FrontendOrder) | **OK** |
| `OrderService::myOrderStore` | YES (line 502) | YES — after `DB::transaction()` closure (line 502) | `$this->order` (Order) | **OK** |
| `OrderService::posOrderStore` | YES (line 844) | YES — after `DB::transaction()` closure (line 844) | `$order` (Order) | **OK** |
| `OrderService::tableOrderStore` | YES (line 1112) | YES — after `DB::transaction()` closure (line 1112) | `$this->order` (FrontendOrder) | **OK** |
| `FrontendOrderService::finalizePaidKioskOrder` | YES — via `dispatchNewOrderSignals()` (line 679) | YES — after `DB::transaction()` closure (line 679) | `$frontendOrder` (FrontendOrder) | **OK** |

### Conditional dispatch note (FrontendOrderService::myOrderStore):
- `shouldDispatchNewOrderSignals` is `false` when: kiosk order + deferred payment (card/ticket restaurant) + NOT cash
- In that case, `finalizePaidKioskOrder()` dispatches it later after payment confirmation
- Cash kiosk orders dispatch immediately (auto-accepted)
- **This is correct behavior** — prevents KDS from showing unpaid orders

---

## 6. Status Change Paths — `OrderStatusChanged` Dispatch Audit

| Path | Dispatches `OrderStatusChanged`? | After TX commit? | Carries old+new status? | Status |
|------|----------------------------------|-------------------|-------------------------|--------|
| `OrderService::changeStatus` (admin/staff, `$auth=false`) | YES (line 1328) | YES — `$order->save()` is direct, no wrapping TX | `$oldStatus`, `$request->status` | **OK** |
| `OrderService::changeStatus` (customer self-cancel, `$auth=true`) | **NO** | N/A | N/A | **GAP-S1** |
| `OrderService::deliveryBoyOrderChangeStatus` | YES (line 1248) | YES — `$order->save()` is direct | `$oldStatus`, `$request->status` | **OK** |
| `KitchenDisplaySystemOrderService::changeStatus` | YES (line 132) | YES — after `DB::transaction()` (line 120-123) | `$oldStatus`, `$request->status` | **OK** |
| `FrontendOrderService::changeStatus` (customer cancel) | YES (line 605) | YES — after `$frontendOrder->save()` (line 602) | `$oldStatus`, `$request->status` | **OK** |
| `FrontendOrderService::finalizePaidKioskOrder` (PENDING→ACCEPT) | YES — via `dispatchOrderStatusSignals()` (line 683) | YES — after `DB::transaction()` (line 671) | `PENDING`, `ACCEPT` | **OK** |
| `FrontendOrderService::myOrderStore` (auto-accept kiosk cash) | YES — via `dispatchOrderStatusSignals()` (line 506) | YES — after `DB::transaction()` (line 503) | `PENDING`, `ACCEPT` | **OK** |

---

## 7. Identified Gaps & Risks

### GAP-S1: Customer self-cancel via `OrderService::changeStatus($auth=true)` — NO broadcast (MEDIUM)

**Location:** `app/Services/OrderService.php` lines 1270-1293

When a customer cancels their own order via the `$auth=true` path, `OrderStatusChanged` is **not dispatched**. The KDS and OSS will continue showing the cancelled order until the next 30s poll.

**Impact:** KDS shows a cancelled order for up to 30 seconds. Kitchen staff might start preparing a cancelled order.

**Fix:** Add `OrderStatusChanged::dispatch()` after `$order->save()` in the `$auth=true` branch (lines 1286-1289), mirroring the pattern in the `$auth=false` branch.

### GAP-B1: Default `BROADCAST_DRIVER=null` (CRITICAL CONFIG)

**Location:** `config/broadcasting.php` line 18

If the `.env` file doesn't explicitly set `BROADCAST_DRIVER=pusher`, all WebSocket broadcasts are silently discarded. Both KDS and OSS fall back to 30s polling.

**Impact:** No real-time updates. Kitchen staff see 30s-delayed orders.

**Mitigation:** This is a deployment/config issue, not a code bug. Ensure `.env` has:
```
BROADCAST_DRIVER=pusher
PUSHER_APP_KEY=...
PUSHER_APP_SECRET=...
PUSHER_APP_ID=...
PUSHER_HOST=127.0.0.1
PUSHER_PORT=6001
PUSHER_SCHEME=http
```

### GAP-S2: `ShouldBroadcastNow` blocks HTTP response (LOW)

Both events use `ShouldBroadcastNow` which fires synchronously. If Soketi is down or slow, the HTTP response to the client (kiosk, POS, web) will be delayed.

**Mitigation:** The events are wrapped in `try/catch` at all dispatch sites, so a Soketi failure won't crash the order flow. The response delay is the only impact.

### GAP-S3: `SendFcmOnOrderCreated` silent return on empty `branch_id` (VERY LOW)

If `branch_id` is somehow empty, the listener returns without logging. This is extremely unlikely in practice since all order creation paths set `branch_id`.

---

## 8. Architecture Summary

```
┌─────────────────────────────────────────────────────────────┐
│                    ORDER CREATION PATHS                       │
│                                                               │
│  FrontendOrderService::myOrderStore (kiosk/web)              │
│  OrderService::myOrderStore (web/app)                         │
│  OrderService::posOrderStore (POS)                            │
│  OrderService::tableOrderStore (QR dine-in)                   │
│  FrontendOrderService::finalizePaidKioskOrder (deferred pay)  │
│                                                               │
│  ALL dispatch OrderCreated AFTER transaction commit ✓         │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│              OrderCreated (ShouldBroadcastNow)                │
│              Channel: private-branch.{branch_id}              │
│              Event name: .OrderCreated                         │
│                                                               │
│  Listeners:                                                   │
│    → SendFcmOnOrderCreated (FCM push to kitchen/POS/customer) │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│                   STATUS CHANGE PATHS                         │
│                                                               │
│  OrderService::changeStatus (admin/staff) ✓                   │
│  OrderService::changeStatus (customer self-cancel) ✗ GAP-S1   │
│  OrderService::deliveryBoyOrderChangeStatus ✓                 │
│  KitchenDisplaySystemOrderService::changeStatus ✓             │
│  FrontendOrderService::changeStatus (customer cancel) ✓       │
│  FrontendOrderService::finalizePaidKioskOrder ✓               │
│  FrontendOrderService::myOrderStore (auto-accept) ✓           │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│           OrderStatusChanged (ShouldBroadcastNow)             │
│           Channel: private-branch.{branch_id}                 │
│           Event name: .OrderStatusChanged                     │
│                                                               │
│  Listeners:                                                   │
│    → AwardLoyaltyPointsOnDelivery (idempotent, non-blocking)  │
│    → SendFcmOnOrderStatusChange (queued FCM push)             │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│                   FRONTEND CONSUMERS                          │
│                                                               │
│  KDS (KitchenDisplaySystemComponent.vue)                      │
│    → Echo: .OrderCreated + .OrderStatusChanged                │
│    → Polling: 30s fallback                                    │
│    → Debounce: 300ms                                          │
│                                                               │
│  OSS (PreparingAndReadyComponent.vue)                         │
│    → Echo: .OrderCreated + .OrderStatusChanged                │
│    → Polling: 30s fallback                                    │
│    → Sound + flash on PREPARED                                │
│    → De-duplication via _echoMarkedReady                      │
└─────────────────────────────────────────────────────────────┘
```

---

## 9. Verdict

| Category | Score | Notes |
|----------|-------|-------|
| Event design | **A** | Clean, typed, polymorphic, correct payloads |
| Listener safety | **A** | Idempotent loyalty, non-blocking FCM, proper error handling |
| Broadcast infra | **A-** | Correct auth, channel scoping, Sanctum integration. Config-dependent. |
| Order creation coverage | **A** | All 5 paths dispatch OrderCreated after commit |
| Status change coverage | **B+** | 6/7 paths dispatch OrderStatusChanged. **GAP-S1** (customer self-cancel on Order model) is the only miss. |
| Frontend consumers | **A** | Both KDS and OSS subscribe to both events, with polling fallback, debounce, and cleanup |
| Overall | **A-** | Solid architecture with one functional gap (GAP-S1) and one config risk (GAP-B1) |

---

## 10. Recommended Actions

| Priority | Action | Effort |
|----------|--------|--------|
| **P1** | Fix GAP-S1: Add `OrderStatusChanged::dispatch()` in `OrderService::changeStatus` `$auth=true` branch after customer cancel | 5 min |
| **P2** | Verify `.env` has `BROADCAST_DRIVER=pusher` on all deployment targets | 2 min |
| **P3** | Add a log line in `SendFcmOnOrderCreated` when `branch_id` is empty (GAP-S3) | 1 min |
| **P4** | Consider moving to `ShouldBroadcast` (queued) instead of `ShouldBroadcastNow` once a proper queue driver is configured | Future |
