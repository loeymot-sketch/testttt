# Codex Deep Backend + Sync Audit — C0/C1/C2 — 2026-04-27

Scope:
- C0 kiosk waiting/confirmation auto-return.
- C1 kiosk full process audit.
- C2 POS full process audit.

Verdict: PASS for C0/C1/C2 backend and process synchronization.

Release posture: proceed to C3 cross-channel realtime audit. No C0-C2 blocker remains.

## 1. Backend Chain Reviewed

### C0 Waiting/Confirmation

Files reviewed:
- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`
- `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue`
- `resources/js/store/modules/kioskCart.js`
- `app/Http/Controllers/Frontend/OrderController.php`
- `app/Services/FrontendOrderService.php`

Findings:
- Waiting now routes to confirmation for paid or pending-counter orders before kitchen preparation starts:
  - `KioskWaitingComponent.vue:292-321`
- It does not incorrectly route once kitchen is already `PREPARING` or when order is `PREPARED/DELIVERED`:
  - `KioskWaitingComponent.vue:313-321`
- Confirmation countdown starts at mount, does not depend on browser print, and returns to idle:
  - `KioskConfirmationComponent.vue:320-328`
  - `KioskConfirmationComponent.vue:352-360`
  - `KioskConfirmationComponent.vue:416-428`
- Simulated browser printing cannot suspend the auto-return path because auto-print only runs on the real kiosk bridge:
  - `KioskConfirmationComponent.vue:322-327`

Audit result: PASS.

### C1 Kiosk Process

Files reviewed:
- `tests/e2e/kiosk-full-process/c1-kiosk-process-audit.spec.js`
- `tests/e2e/helpers/process-audit.js`
- `app/Http/Resources/OrderDetailsResource.php`
- `app/Services/Stock/StockService.php`
- `app/Services/Kiosk/KioskMenuService.php`

Coverage:
- K1 card simple: paid kiosk order confirms, fiscal sequence exists, stock decremented.
- K2 composition tacos: immutable `composition_snapshot` includes role lines.
- K3 cash-at-counter: kiosk confirms while `payment_status=PENDING_COUNTER`; `fiscal_sequence_no=NULL`.
- K4 rupture: zero stock blocks decrement and kiosk projection exposes `stock_rupture`.
- K5 abandon/new-order: confirmation CTA returns to locked `/kiosk/idle`.

Important audit correction:
- The first fixture implementation used the POS operator for kiosk orders, which caused the frontend show endpoint to reject with `Access denied: you do not own this order`.
- The final helper assigns `surface=kiosk` orders to the kiosk-machine user, preserving the real backend ownership rule.

Audit result: PASS.

### C2 POS Process

Files reviewed:
- `tests/e2e/pos-full-process/c2-pos-process-audit.spec.js`
- `routes/api.php`
- `app/Services/PaymentService.php`
- `app/Http/Requests/PosOrderRequest.php`
- `app/Services/Delivery/DeliveryFeeService.php`

Coverage:
- P1 POS paid cash: POS surface loads, fiscal sequence exists, stock decremented.
- P2 takeaway card: card-paid order keeps fiscal and composition snapshot.
- P3 delivery quote: forged `delivery_charge=999` is ignored; backend recomputes `10` at `5.01 km`.
- P4 counter-collect confirm: POS sees kiosk `PENDING_COUNTER`, confirms payment, allocates fiscal sequence, emits `OrderPaidAtCounter`.
- P5 counter-collect cancel: POS cancels `PENDING_COUNTER`, sets `REFUNDED` + `CANCELED`, keeps fiscal null, releases stock.

Routes reviewed:
- Pending list branch-scoped and kiosk-only:
  - `routes/api.php:654-669`
- Confirm route calls `PaymentService::confirmCounterPayment`:
  - `routes/api.php:670-693`
- Cancel route calls `PaymentService::cancelCounterPayment`:
  - `routes/api.php:694-713`

Payment service reviewed:
- Confirm uses row lock and state machine:
  - `PaymentService.php:141-155`
- Fiscal sequence allocated only on confirm:
  - `PaymentService.php:163-165`
- Payment event dispatched after successful transaction:
  - `PaymentService.php:207-209`
- Cancel uses row lock and does not allocate fiscal sequence:
  - `PaymentService.php:219-235`

Audit result: PASS.

## 2. Synchronization / Outbox Reviewed

Files reviewed:
- `app/Listeners/PersistOrderCreatedToOutbox.php`
- `app/Listeners/PersistOrderStatusChangedToOutbox.php`
- `app/Listeners/PersistOrderPaidAtCounterToOutbox.php`
- `app/Jobs/DispatchDomainEventsJob.php`
- `app/Domain/Events/EventContract.php`
- `app/Providers/EventServiceProvider.php`

Confirmed:
- `OrderCreated` payload contains `order_id`, `queue_number`, `_origin`, `payment_method`, `payment_status`, `payment_pending_counter`, `status`, `order_type`, `total`:
  - `PersistOrderCreatedToOutbox.php:24-35`
- `OrderCreated` dispatch is after commit:
  - `PersistOrderCreatedToOutbox.php:42-45`
- `OrderPaidAtCounter` payload includes fiscal sequence and payment status:
  - `PersistOrderPaidAtCounterToOutbox.php:24-34`
- `OrderPaidAtCounter` dispatch is after commit:
  - `PersistOrderPaidAtCounterToOutbox.php:38-40`
- Domain dispatch job atomically claims events under lock before broadcast:
  - `DispatchDomainEventsJob.php`
- Event contract enforces required payload keys before broadcast:
  - `EventContract.php`

Audit result: PASS for outbox contract and after-commit discipline.

Boundary:
- This is not a substitute for C3. C0-C2 prove outbox rows/contracts and browser-visible flows. C3 must still prove sub-second realtime fanout across simultaneously open Kiosk/POS/KDS/OSS surfaces.

## 3. Invariants

| Invariant | Status | Evidence |
| --- | --- | --- |
| Backend pricing SSOT | PASS | POS delivery quote recomputes forged fee; frontend delivery tests recompute forged values. |
| No frontend business pricing authority | PASS | C1/C2 tests assert server values; frontend only observes total/payment states. |
| PaymentStatus/OrderStatus enums | PASS | C1/C2 tests use enum modules; backend tests use PHP enum interfaces. |
| Branch isolation | PASS | Counter collect pending route filters by authenticated branch; feature test confirms cross-branch confirm is blocked. |
| Kiosk ownership | PASS | C1 fixture bug exposed ownership guard; final helper preserves kiosk-machine user ownership. |
| Fiscal NF525 guard | PASS | Cash-at-counter fiscal remains null until POS confirm; cancel path remains fiscal null. |
| Stock decrement/release | PASS | C1/C2 Playwright plus backend Stock tests validate decrement, rupture, idempotent release. |
| Dispatch after commit | PASS | Outbox listeners use `DB::afterCommit`; dispatch job validates envelope. |
| Queue uniqueness | PASS | DB unique guard tests pass for branch+business_date+queue_number. |

## 4. Validation Executed

### Playwright

```bash
npx playwright test tests/e2e/kiosk-full-process/c1-kiosk-process-audit.spec.js tests/e2e/pos-full-process/c2-pos-process-audit.spec.js --project=chromium --repeat-each=5 --retries=0
```

Result:

```text
50 passed (3.8m)
```

Regression:

```bash
npx playwright test tests/e2e/kiosk-post-payment-auto-return.spec.js tests/e2e/composer-mega-flow.spec.js --project=chromium --retries=0
```

Result:

```text
3 passed (52.7s)
```

### Backend PHPUnit

Executed targeted backend tests:

```text
Tests\Feature\KioskPaymentStateMachineTest: 5 passed
Tests\Feature\Payment\CounterDeferredPaymentLifecycleTest: 5 passed
Tests\Feature\Stock\StockReleaseOnCancelTest: 1 passed
Tests\Feature\Stock\StockConcurrentDecrementTest: 2 passed
Tests\Feature\Stock\StockRuptureAvailabilitySyncTest: 3 passed
Tests\Feature\Delivery\DeliveryFeeForgePosTest: 1 passed
Tests\Feature\Frontend\OrderRequestDeliveryFeeAuthorityTest: 4 passed
Tests\Feature\KioskRealtimeBroadcastTest: 2 passed
Tests\Feature\EventContractTest: 9 passed
Tests\Feature\QueueNumberConcurrencyTest: 4 passed
```

Total targeted backend assertions: 36 tests passed.

## 5. Residual Risks

No P0/P1 for C0/C1/C2.

P2 / C3-bound risks:
- Realtime latency across live multi-tab Kiosk/POS/KDS/OSS is not yet proven by C0-C2. This is explicitly C3.
- Counter-collect routes remain inline closures in `routes/api.php`; already classified P2 cleanup, not a behavior blocker.
- POS live board aggregation beyond counter-collect is not in C2 scope.
- C1/C2 process fixtures intentionally isolate DB state; they are strong process audits, not a replacement for hardware/TPE/printer UAT.

## 6. Decision

AUDIT_VERDICT: PASS

NEXT: Run C3 cross-channel sync audit with simultaneous browser surfaces and explicit timing assertions:
- kiosk order appears on KDS/POS without reload;
- POS counter collect sees pending cash order without reload;
- KDS prepared/cancel changes propagate to kiosk/OSS;
- branch B receives no branch A events;
- reconnect recovers missed state by polling/resync.
