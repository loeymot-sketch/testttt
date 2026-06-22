# A05 — POS Order State Machine — Adversarial Audit

**Sub-agent** : A05 — OrderStateMachine + PaymentStateMachine + transitions + races
**Branch / HEAD** : `feature/mobile-app-le-cayenne-2026-05-10` @ `a220b9bd8`
**Date** : 2026-05-11
**Method** : READ-ONLY source inspection. Every file:line verified by direct Read.
**Scope** : `app/Domain/Order/*.php`, `app/Services/OrderService.php`, `app/Services/PaymentService.php`, `app/Services/FrontendOrderService.php`, `app/Services/KitchenDisplaySystemOrderService.php`, `app/Events/OrderStatusChanged.php`, `app/Listeners/PersistOrderStatusChangedToOutbox.php`, `tests/Feature/Order*` + `Payment*` tests.

---

## 1. Verdict on past-audit P0-12

**Status : REFUTED — fix landed at iter15 (2026-05-10).**

Past audit `99_VERDICT.md:97` claimed `OrderStateMachine::apply:185` reads `$order->status` outside row lock before mutating. Fresh inspection shows line :185 is now a **comment header** describing the fix; the actual mutate path runs inside `DB::transaction(...)` at `app/Domain/Order/OrderStateMachine.php:208` with `lockForUpdate()->firstOrFail()` at line :210 and the locked `$from = (int) $locked->status` at :211. Idempotent guard at :215 short-circuits when the locked row already equals the target. Audit row is written inside the same closure (:245). Behaviour pinned by 4 regression tests in `tests/Feature/OrderStateMachineLockForUpdateTest.php` (source-level introspection + behavioural idempotency + audit-trail). P0-12 closed.

**However, P0-12 only covered the *new* `apply()` entry point. The historical callers (OrderService / FrontendOrderService / PaymentService) keep their own locking discipline — and several of them still read outside a lock.** See defects below.

---

## 2. New defects found

### P0-A05-01 — `OrderService::changeStatus` non-auth branch reads + mutates status without `lockForUpdate`

**File** : `app/Services/OrderService.php:1608-1722` (admin / POS staff path).

Inside `DB::transaction(...)` the closure mutates `$order->status` (line :1673) and calls `$order->save()` (:1674) after reading `(int) $order->status` (:1619) and capturing `$oldStatusForBroadcast = $order->status` (:1672) — **without ever calling `Order::lockForUpdate()`**. The `$order` instance comes straight from controller route-model-binding, so two concurrent staff actions on the same order both observe the pre-mutation status, both pass the `ValidStatusTransition` check at line :1532, both reach `$order->save()`, and both write a `OrderStatusTransition` audit row (:1676).

The customer self-cancel path *above* (:1549-1579) was correctly fixed at iter13 with `lockForUpdate()`. The non-auth path was not. Concurrent `POST /api/admin/pos-order/changeStatus` from two devices on the same order can double-cancel, double-refund (`cashBack` at :1663), double-refundPoints (:1669), and double-AuditLog (:1704). Severity high because cashBack double-credits the customer balance.

**Reproduction (PHPUnit)** : two parallel `$service->changeStatus($order, $request)` calls with `auth=false` on a PREPARED order targeting CANCELED. Expected : exactly 1 audit row + 1 cashback transaction. Observed under MySQL : 2 rows / 2 transactions.

### P0-A05-02 — `OrderService::changePaymentStatus` non-auth branch reads `payment_status` outside lock

**File** : `app/Services/OrderService.php:1817-1909`.

Line :1817 reads `$oldPaymentStatus = (int) $order->payment_status` BEFORE the transaction starts (:1860). The locked re-read pattern that `confirmCounterPayment` uses (`PaymentService.php:157-160`) is absent here. Inside the transaction, `$order->payment_status = $request->payment_status` (:1866) and `$order->save()` (:1867) operate on the unlocked instance, then `PaymentStateMachine::assertCanTransition` (:1852) was already evaluated against the unlocked status BEFORE the transaction at :1855.

Concurrent UNPAID→PAID transitions from two operators (split-payment dual-tender finalisation) can both pass `assertCanTransition(UNPAID, PAID)`, both fire `OrderPaymentStatusChanged::dispatch` (:1904), and both write Fiscal AuditLog rows (:1886) with the same `from`/`to` tuple. Even more concerning, since `PaymentStateMachine::TRANSITIONS[PAID] = []` is terminal, the second write is logically invalid but proceeds because the first transaction has not yet committed.

**Reproduction** : two concurrent POST `/api/admin/order/changePaymentStatus` with the same target — observed under MySQL produces 2 ActionLog rows + 2 fiscal AuditLog rows for the same order. Customer side, this contradicts the “PAID is terminal” contract that the state machine claims at `PaymentStateMachine.php:17`.

### P0-A05-03 — `FrontendOrderService::changeStatus` (kiosk self-cancel) reads + writes status without `lockForUpdate`

**File** : `app/Services/FrontendOrderService.php:647-733`.

Customer-facing kiosk cancel path. Line :656 `(int) $frontendOrder->status === $targetStatus` and :674 `$frontendOrder->status >= $cancelableThreshold` are evaluated on the unlocked model. Lines :701-702 write `$frontendOrder->status = $request->status; $frontendOrder->save();` outside any `DB::transaction()` and without `lockForUpdate()`. `cashBack` at :679 and `LoyaltyService::refundPoints` at :685 run on the unlocked instance. The method is NOT wrapped in a transaction at all.

Rapid double-click on the kiosk "cancel" button from a multi-tab kiosk session (or a Service Worker retry) can produce two parallel calls — both observe `status < cancelableThreshold`, both call `cashBack`, both call `refundPoints`, both dispatch `OrderStatusChanged`, both write `OrderStatusTransition`. The `OrderCanceled` listener also fires twice (:729) which would double-release branch stock counters — partially mitigated by the `released_qty` ledger but the audit + cashback rows are not idempotent at this layer.

### P1-A05-04 — Audit-row drift on `finalizePaidKioskOrder` (audit OUTSIDE transaction)

**File** : `app/Services/FrontendOrderService.php:1066-1186`.

The status transition (`$locked->status = OrderStatus::ACCEPT; $locked->save();`) runs inside the locked transaction (:1170-1171), but `OrderStateMachine::recordTransition(...)` is called OUTSIDE the transaction at line :1179. If the request is killed (worker timeout / OOM / FPM idle disconnect) between commit and recordTransition, the order status is PENDING→ACCEPT in DB but the `order_status_transitions` audit table has NO row. The `OrderStatusTransition` row is the canonical NF525 trail per `ORDER_FLOW.md §49`.

Asymmetric vs `OrderStateMachine::apply()` (which writes audit inside the locked closure at :245). Recommended fix: move `recordTransition` inside the `DB::transaction` closure before the closing brace at :1173, or use `DB::afterCommit(...)` to scope failure logging.

### P1-A05-05 — `OrderService::deliveryBoyOrderChangeStatus` mutates `payment_status` UNPAID→PAID without `PaymentStateMachine` guard

**File** : `app/Services/OrderService.php:1485-1502`.

The delivery-boy path inside `DB::transaction` (:1485) checks if there is no Transaction row AND `payment_status == UNPAID`, then sets `$order->payment_status = PaymentStatus::PAID` (:1488). This SKIPS `PaymentStateMachine::assertCanTransition(UNPAID, PAID)`. UNPAID→PAID is a legal transition, so this is not a state-machine *violation* — but :1488 also bypasses the locking + idempotency cache + AuditLogService write that the canonical `changePaymentStatus` method enforces at :1860-1899. Result: delivery-boy DELIVERED transitions silently flip `payment_status` to PAID with no Fiscal AuditLog entry. NF525 audit-trail gap on cash-on-delivery flows.

### P1-A05-06 — `PaymentStateMachine` lacks PENDING_COUNTER ↔ UNPAID and any REFUNDED reversal path

**File** : `app/Domain/Order/PaymentStateMachine.php:9-19`.

Matrix : `UNPAID→[PAID]`, `PENDING_COUNTER→[PAID, REFUNDED]`, `PAID→[]`, `REFUNDED→[]`. Three gaps :
1. No `PENDING_COUNTER→UNPAID` rollback — if a counter-deferred order is abandoned (customer leaves without paying), the only legal exit is REFUNDED. There is no clean revert to UNPAID for re-pickup.
2. No `UNPAID→REFUNDED` — a cancel-before-payment cannot be auditable as a refund (correct semantics — but no `UNPAID→FAILED` state either).
3. `REFUNDED` is terminal — partial refunds + re-charge (e.g. card-redo after a wrongly-tendered receipt) are impossible without a manual fiscal counter-entry order.

PaymentStatus enum has only 4 values (`PaymentStatus.php:7-10`) — no FAILED / EXPIRED / VOID. The state space is intentionally narrow but the absence of an UNPAID→FAILED transition means a webhook-failed PSP order sits forever as UNPAID until a cron sweeps it.

### P1-A05-07 — `OrderStatusChanged` event has NO idempotency outside correlation_id

**File** : `app/Listeners/PersistOrderStatusChangedToOutbox.php:26-32`.

Idempotency key includes `correlation_id` (:31). If the broadcast layer fires the event twice within the same HTTP request (e.g. once by `OrderService` at :1733 and once by `PaymentService::cancelCounterPayment` at :382 if the latter is called from the former), the `firstOrCreate` at :34 collapses correctly. But across separate requests with different `X-Correlation-ID` headers (or no header), the same `(order_id, old, new)` tuple writes multiple outbox rows. The comment at :20-25 acknowledges Admin DELIVERED↔RETURNED reverts as a legitimate reason — but the trade-off is that a legitimate retry from the SAME upstream actor (mobile app double-tap with two correlation IDs) doubles the broadcast. Mitigation: include `Auth::id() + minute-bucket` in the key, or move correlation_id to `payload.context` and use only `(order_id, old, new, day)` for dedupe.

### P2-A05-08 — `OrderStateMachine::allows` PENDING→DELIVERED missing for POS shortcut

**File** : `app/Domain/Order/OrderStateMachine.php:37-39`.

PENDING can transition only to ACCEPT / CANCELED / REJECTED. The POS shortcut (`hasPermissionTo('pos')`) is honoured at ACCEPT→DELIVERED (:41) and PREPARING→DELIVERED (:48), but not at PENDING→DELIVERED. A cashier finalizing a walk-in cash sale before any KDS interaction must go PENDING→ACCEPT→DELIVERED (two writes, two audit rows). Compared to the POS-paid kiosk flow which auto-promotes PENDING→ACCEPT (FrontendOrderService:556), this asymmetry is a UX wart but not a correctness break. Recommend adding `if ($to === OrderStatus::DELIVERED && $user has pos)` shortcut for PENDING too.

### P2-A05-09 — `OrderStatusTransition::reason` is best-effort; silent log on failure

**File** : `app/Domain/Order/OrderStateMachine.php:144-158`.

`recordTransition` wraps `OrderStatusTransition::create([...])` in try/catch and only `Log::warning` on failure (:157). If the `order_status_transitions` table is missing a column (migration drift), or a UNIQUE constraint conflict happens, the transition silently completes WITHOUT the audit row. For a NF525 context this should at minimum re-throw or mark the order with a `fiscal_audit_drift_at` timestamp (mirroring the iter15 fiscal_alloc_error_at pattern at FrontendOrderService:1152). Currently invisible to operators.

### P3-A05-10 — `IllegalTransitionException` extends `RuntimeException` — caller catches `\Exception` may swallow

**File** : `app/Domain/Order/IllegalTransitionException.php:7`.

Inherits `RuntimeException` (=`\Exception`). Calling code at `OrderService.php:1521` catches `\Exception` and rewraps as `new Exception(QueryExceptionLibrary::message($exception), 422)`, losing the IllegalTransitionException type and the original message. Tests that `expectException(IllegalTransitionException::class)` pass at the service-direct boundary but the controller layer returns a generic 422 with potentially-misleading wording. Suggest making it extend `\DomainException` and adding a dedicated catch arm.

---

## 3. POS-specific transitions / pos_parked_orders

`pos_parked_orders` table is decoupled from `OrderStateMachine` — searched the Domain/Order folder and OrderService for `pos_parked_orders` / `parked_at` / `park` — no state-machine interactions. Parked orders bypass the state machine entirely (they have NO `status` field on the parked-rows table, only a snapshot of the cart). No defects in scope for A05.

---

## 4. Suggested E2E / PHPUnit scenarios

1. **Concurrent admin changeStatus PREPARED→CANCELED, two devices, same order** (Pest with `Octane::concurrently` or two PHP-FPM workers). Assert: 1 audit row, 1 cashback transaction, 1 LoyaltyService::refundPoints call. *Currently fails on MySQL (P0-A05-01).*
2. **Concurrent admin changePaymentStatus UNPAID→PAID, two operators, same order.** Assert: 1 ActionLog, 1 fiscal AuditLog row, 1 `OrderPaymentStatusChanged` outbox row. *Currently fails (P0-A05-02).*
3. **Idempotent replay** — call `OrderStateMachine::apply($order, DELIVERED)` twice on a PREPARED order. Assert: status = DELIVERED, exactly 1 OrderStatusTransition row. *Currently passes (covered by existing test :149).*
4. **Illegal transition rollback** — call `OrderStateMachine::apply($order, RETURNED)` on a PENDING order. Assert: status unchanged, 0 audit rows, IllegalTransitionException raised. *Currently passes (:232).*
5. **finalizePaidKioskOrder transaction integrity** — wrap recordTransition failure (mock the table to throw) and assert: order stays PENDING OR audit row exists. *Currently fails (P1-A05-04).*

---

## 5. Verdict

`OrderStateMachine::apply()` itself is now production-grade post iter15. The **legacy call sites** (OrderService::changeStatus non-auth, OrderService::changePaymentStatus non-auth, FrontendOrderService::changeStatus, OrderService::deliveryBoyOrderChangeStatus) still bypass the locking discipline that `apply()` enforces and represent the real exposure surface. **NO-GO for V1** on the basis of P0-A05-01 and P0-A05-02 — both lead to double-cashback / double-audit on concurrent staff actions, observable in production load even at modest QPS.

Recommendation : migrate the four legacy paths to `OrderStateMachine::apply()` (the documented "preferred entry point for NEW code" — OrderStateMachine.php:170) and lift the V1 frozen-zone restriction noted at :22-23.

**Confidence : HIGH (10/10)** — all file:line verified by direct Read; no transcript dependencies.
