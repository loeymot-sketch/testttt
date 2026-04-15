# Plan – REALTIME_001 – 2026-04-14

## TASK_ID
REALTIME_001

## PRIMARY_MODEL
claude-sonnet-4-5-20250514

## Test Strategy
`local-validation` + `static-inspection`
PHPUnit: 194 existing (no backend changes)
Build: `npm run build` must pass with 0 errors

## PRIOR_CONTEXT
Echo is ALREADY initialized in `bootstrap.js` (not commented). F-01 is resolved from prior work.
KDS, OSS, POS all have `subscribeEcho()` / `unsubscribeEcho()` with proper cleanup.
Polling exists at 30s (KDS/OSS) and 60s (POS) as always-on timers independent of Echo state.
Missing: heartbeat/reconnection logic (F-05), smart fallback that activates polling when Echo is down (F-17).

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| resources/js/services/WebSocketService.js | E2+E3: Create new service | Write (NEW) | No | No |
| resources/js/bootstrap.js | E1: Wire WebSocketService after Echo init | Write | No | No |
| resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue | E2+E3: Use WebSocketService for reconnect awareness | Write | No | No |
| resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue | E2+E3: Use WebSocketService for reconnect awareness | Write | No | No |
| resources/js/components/admin/pos/PosComponent.vue | E2+E3: Use WebSocketService for reconnect awareness | Write | No | No |

## SUBSYSTEMS_OFF_LIMITS
- Any backend file (no Laravel changes)
- config/broadcasting.php (already correct)
- .env (configuration, not code)
- OrderService.php / FrontendOrderService.php (frozen zones)
- Laravel events (OrderStatusChanged, OrderCreated)
- Kiosk components (separate reliability task)

## INVARIANTS_AT_RISK
- **Backend pricing SSOT** — not affected (frontend-only changes)
- **Frozen zones** — not touched
- **Dispatch after DB commit** — not affected (no backend changes)

## GATE_CONDITIONS
None anticipated. No frozen zones, no backend changes, no auth/security modifications.

## Execution Steps

### Step 1 — E1: Confirm Echo activation (F-01)
**Status:** Already resolved. Echo is initialized in `bootstrap.js` L.29-72 with Pusher config, Bearer auth, and `_refreshEchoAuth()` helper.
**Action:** No code changes needed. Static inspection confirms F-01 is addressed.

### Step 2 — E2: Create WebSocketService.js (F-05)
**File:** `resources/js/services/WebSocketService.js` (CREATE NEW)
**Features:**
- Bind to Pusher connection `state_change` events
- Track connection state: connected, connecting, disconnected, unavailable
- Exponential backoff reconnection (1s, 2s, 4s, 8s, 16s, max 30s)
- Heartbeat monitoring (detect stale connections)
- Event emitter for components to subscribe to state changes
- `onConnected()`, `onDisconnected()` callbacks

### Step 3 — E2b: Wire WebSocketService in bootstrap.js
**File:** `resources/js/bootstrap.js`
**Change:** After Echo initialization, import and start WebSocketService.
Expose `window._wsService` for components to access.

### Step 4 — E3: Smart fallback polling in KDS
**File:** `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`
**Change:**
- Replace fixed 30s polling with adaptive polling:
  - Echo connected: polling at 60s (safety net only)
  - Echo disconnected: polling at 10s (aggressive fallback)
- Show/hide reconnection banner based on WebSocketService state
- When Echo reconnects: force immediate refresh + restore normal polling

### Step 5 — E3: Smart fallback polling in OSS
**File:** `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue`
**Same pattern as KDS.

### Step 6 — E3: Smart fallback polling in POS
**File:** `resources/js/components/admin/pos/PosComponent.vue`
**Same adaptive polling pattern. POS currently uses 60s polling.

### Step 7 — Execution report
Write `reports/execution/REPORT_REALTIME_001_2026-04-14.md`.

## SCOPE_PRESSURE
[Populated mid-cycle only.]

## ESCALATION
[Populated mid-cycle only.]

## Audit Status
[x] Pending
[ ] Passed — cycle closed
