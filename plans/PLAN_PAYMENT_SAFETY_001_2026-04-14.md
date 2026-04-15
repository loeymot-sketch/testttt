# Plan – PAYMENT_SAFETY_001 – 2026-04-14

## TASK_ID
PAYMENT_SAFETY_001

## PRIMARY_MODEL
claude-sonnet-4-5-20250514

## Test Strategy
`local-validation`
PHPUnit: 194 existing + new tests for idempotency and cancellation
Build: `npm run prod` must pass with 0 errors

## PRIOR_CONTEXT
- REALTIME_001 just closed: WebSocket service, adaptive polling
- SYNC_WIZARD_DEEP_001: round() on both order services, 194 tests passing
- Existing idempotency: `X-Idempotency-Key` on order creation (posOrder.js + OrderService), `orders.idempotency_key` column
- POS payment flow: single POST to `admin/pos` with `pos_payment_method` (CASH/CARD), no separate TPE endpoint

## GRAPHITI
UNAVAILABLE — server not active in this session. Non-blocking per plan-context.md.

## Codebase Reality (correcting task file assumptions)
1. **No `PaymentController` for TPE** — POS card payments go through same `admin/pos` endpoint. The "TPE idempotency" (F-03) maps to POS order creation idempotency, which ALREADY EXISTS via `X-Idempotency-Key` header. What's missing: **timeout + UI protection + key generation for card path**.
2. **No `cancelOrder()` method** — Cancellation is `OrderService::changeStatus()` (L.1263) and `FrontendOrderService::changeStatus()` (L.568). Both handle CANCELED/REJECTED status with `PaymentService::cashBack()` but **neither refunds loyalty points**.
3. **No `LoyaltyService`** — Loyalty logic lives in `LoyaltyController` and inline in `FrontendOrderService`. No standalone service class.
4. **`loyalty_customer_code`** exists on orders. Points are deducted in `FrontendOrderService` during kiosk order creation with `LoyaltyTransaction` ledger entries.

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| resources/js/components/admin/pos/PaymentComponent.vue | E1: disable button + spinner + timeout message; E3: cash change display | Write | No | No |
| resources/js/store/modules/posOrder.js | E1: generate UUID idempotency key if missing | Write | No | No |
| app/Services/OrderService.php | E2: refund loyalty in changeStatus() on CANCELED | Write (GATE REQUIRED) | No | No (after commit) |
| app/Services/FrontendOrderService.php | E2: refund loyalty in changeStatus() on CANCELED | Write (GATE REQUIRED) | No | No |
| app/Models/LoyaltyTransaction.php | Read only — existing model | Read | No | No |
| tests/Feature/OrderCancellationLoyaltyTest.php | E2: new test | Write (NEW) | No | No |

## SUBSYSTEMS_OFF_LIMITS
- Database migrations (no new columns needed — loyalty_customer_code exists)
- Payment gateway drivers (Stripe, etc.)
- LoyaltyController.php (not modifying earn/redeem API)
- Kiosk payment components

## INVARIANTS_AT_RISK
- **Backend pricing SSOT** — not affected (no price changes)
- **Frozen zones** — OrderService.php AND FrontendOrderService.php: GATE REQUIRED for E2
- **OrderService / FrontendOrderService symmetry** — E2 must apply same refund pattern to BOTH
- **Dispatch after DB commit** — loyalty refund happens inside DB::transaction, which is correct

## GATE_CONDITIONS
- **GATE REQUIRED (E2):** Both OrderService.php and FrontendOrderService.php are frozen zones. Adding loyalty refund logic in `changeStatus()` requires human approval.

## SYMMETRY_NOTE
E2 modifies both OrderService::changeStatus() and FrontendOrderService::changeStatus() with identical loyalty refund logic. Symmetry review mandatory at audit.

## Execution Steps

### Step 1 — E1: POS payment safety (F-03)
**Files:** `resources/js/store/modules/posOrder.js`, `resources/js/components/admin/pos/PaymentComponent.vue`
**Changes:**
- posOrder.js `save` action: auto-generate UUID `idempotency_key` if not present in payload (ensures card payments also get idempotency)
- PaymentComponent.vue: already has `:disabled="loading.isActive"` and single-flight guard. Add: explicit 30s timeout on the axios call via `AbortController`. On timeout: show "Paiement en cours de traitement, ne relancez pas" instead of generic error.

### Step 2 — E3: Cash change display (F-06)
**File:** `resources/js/components/admin/pos/PaymentComponent.vue`
**Changes:**
- Add reactive `cashReceived` computed from `#cashInput` via a watcher or input event
- Display "Monnaie à rendre: X.XX €" below the received amount input, only when `cashReceived > total`
- Green text, prominent font, visible at a glance

### Step 3 — E2: GATE — Loyalty refund on cancel (F-04)
**STOP: Write gate brief BEFORE implementing.**
**Gate file:** `docs/gates/GATE_PAYMENT_SAFETY_001_2026-04-14.md`
**Proposed changes:**
- OrderService::changeStatus() L.1312-1326: after cashBack, add loyalty point refund
- FrontendOrderService::changeStatus() L.593-599: after cashBack, add loyalty point refund
- Pattern: find user via `loyalty_customer_code`, re-credit points, write LoyaltyTransaction(type='refund')
- SYMMETRIC in both services

### Step 4 — Execution report + ACTIVE_CYCLE update

## SCOPE_PRESSURE
[Populated mid-cycle only.]

## ESCALATION
[Populated mid-cycle only.]

## Audit Status
[x] Pending
[ ] Passed — cycle closed
