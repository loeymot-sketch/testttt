# Plan – KIOSK_RELIABILITY_001 – 2026-04-14

## TASK_ID
KIOSK_RELIABILITY_001

## PRIMARY_MODEL
claude-sonnet-4-5-20250514

## Test Strategy
`local-validation`
PHPUnit: 196 existing (no backend logic changes affecting tests)
Build: `npm run prod` must pass with 0 errors

## PRIOR_CONTEXT
- REALTIME_001 CLOSED: WebSocket service + adaptive polling
- PAYMENT_SAFETY_001 CLOSED: TPE idempotency + loyalty refund + cash change
- Kiosk .env configured: auto-login working, token OK
- Existing infrastructure: `KioskEventController` (POST /api/frontend/kiosk-event) for structured events
- Existing printing: `kioskPrinter.js` with `printReceipt()` (Electron → ESC/POS → browser fallback)
- Existing offline: `kioskOfflineQueue.js` with `startAutoSync()` (already flushes on boot)
- Existing drawer: `KioskPaymentComponent.vue` with `window.borne.openDrawer()` (console.warn only)

## Design Decisions
1. **Hardware logging**: Extend existing `KioskEventController` allowed types (zero new controllers, zero new routes)
2. **Logging channel**: Add Laravel Log channel `'hardware'` in `config/logging.php` for dedicated file
3. **Quantity limit**: Configurable via `config('kiosk.max_item_qty')` read from `KIOSK_MAX_ITEM_QTY` env
4. **Printer fallback**: Enhance existing `KioskConfirmationComponent.vue` — already shows order number, add prominent fallback display on print failure
5. **Offline sync**: `startAutoSync()` already runs immediate flush on boot — document and add explicit sync indicator

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write |
|---|---|---|
| resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue | E1: printer fallback display | Write |
| resources/js/helpers/kioskPrinter.js | E1: add hardware log on failure | Write |
| app/Http/Controllers/Frontend/KioskEventController.php | E1+E3: add new event types | Write |
| config/logging.php | E1: add hardware channel | Write |
| resources/js/helpers/kioskOfflineQueue.js | E2: explicit boot sync indicator | Write |
| resources/js/components/frontend/kiosk/KioskAppComponent.vue | E2: offline sync status display | Write |
| resources/js/components/frontend/kiosk/KioskPaymentComponent.vue | E3: hardware log on drawer failure | Write |
| resources/js/components/frontend/kiosk/KioskCartComponent.vue | E4: max quantity limit | Write |
| resources/js/store/modules/kioskCart.js | E4: max qty in mutations | Write |
| config/kiosk.php | E4: max_item_qty config | Write |

## SUBSYSTEMS_OFF_LIMITS
- Any frozen zone (OrderService, FrontendOrderService)
- Database migrations
- Auth middleware
- POS components
- Kiosk wizard components

## INVARIANTS_AT_RISK
None. No pricing, no order logic, no frozen zones.

## GATE_CONDITIONS
None. No frozen zones, no migrations.

## Execution Steps

### Step 1 — E1: Printer fallback (F-02 CRITICAL)
### Step 2 — E2: Offline queue boot sync indicator (F-08)
### Step 3 — E3: Cash drawer hardware logging (F-09)
### Step 4 — E4: Cart quantity limit (F-13)
### Step 5 — Execution report

## Audit Status
[x] Pending
[ ] Passed — cycle closed
