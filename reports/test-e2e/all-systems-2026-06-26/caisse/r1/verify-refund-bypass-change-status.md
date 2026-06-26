# [P1] CONFIRMED — POS Operator refund-bypass via change-status → RETURNED

**Verdict:** REAL — severity **P1** (NF525/argent/sécurité ; insider-staff money-control bypass, audit-trailed/traceable).
**Heal target NON-frozen:** YES (`PosOrderController::changeStatus`). Root cause (`OrderStateMachine` DELIVERED→RETURNED unconditional) is FROZEN → close at controller authz layer, do NOT touch the state machine.

## file:line (re-verified by Read/grep)
- `app/Http/Controllers/Admin/PosOrderController.php:28-37` — `$this->middleware(['permission:pos-orders'])->only('destroy','export','changeStatus','changePaymentStatus','selectDeliveryBoy','reorderItems','refundWithCounterEntry')`. `changeStatus` is gated ONLY by `permission:pos-orders`.
- `app/Http/Controllers/Admin/PosOrderController.php:58-62` — `refundWithCounterEntry` adds `abort_unless(Auth::user()?->can('pos-refund') ?? false, 403, ...)`. This `pos-refund` gate exists ONLY on the dedicated endpoint, NOT on `changeStatus` (l.312-321).
- `app/Domain/Order/OrderStateMachine.php:76-77` (FROZEN) — `case OrderStatus::DELIVERED: return $to === OrderStatus::RETURNED;` — unconditional, ignores `$user`. The ACCEPT/PREPARING/PREPARED→RETURNED edges (l.48/59/67) DO gate `pos-refund`; the DELIVERED edge does not (this is INTENTIONAL per sentinel, not an oversight — see below).
- `app/Rules/ValidStatusTransition.php:30-36` — `passes()` delegates to `OrderStateMachine::allows($from, $to, auth()->user())`; for DELIVERED→RETURNED it returns true regardless of permission.
- `app/Http/Requests/OrderStatusRequest.php:25` — `authorize()` returns `true` for `'POS Operator'` ("can change any order status") → FormRequest layer does NOT block either.
- `app/Services/OrderService.php:2150-2195` — non-`$auth` branch: for RETURNED it validates reason then l.2188-2194 `PaymentService::cashBack(...)` + l.2195 `LoyaltyService::refundPoints(...)`.
- `app/Services/PaymentService.php:91-165` — `cashBack()` creates a `cash_back` Transaction (sign `-`, amount=`order.total`), increments `User->balance`, writes NF525 audit (`payment.cash_back_issued`), dispatches `RefundCreated` (stock release).
- `database/seeders/RolePermissionTableSeeder.php:87-89,98-110` — POS Operator gets `pos`+`pos-orders` (NOT `pos-refund`); comment l.87-88: "POS Operator does NOT get it by default (mass-refund vector mitigation)". Branch Manager/Admin get `pos-refund`.
- Route: `routes/api.php:983-985` POST `/api/admin/pos-order/change-status/{order}` → `PosOrderController::changeStatus`.

## Repro (independent — probe written + run on phpunit sqlite :memory:, live foodking_e2e untouched/read-only)
Actor = `POS Operator` (assigned role; granted `pos`+`pos-orders`, `pos-refund` explicitly revoked). Two DELIVERED(13)+PAID(5), order_type=POS, pre-Z orders with a prior `payment` Transaction.

Probe output:
```
[PROBE] dedicated refund-with-counter-entry status = 403
[PROBE] orderA status_after = 13 | dedicated cash_back = NO
[PROBE] change-status->RETURNED status = 200
[PROBE] orderB status_after = 22 | cash_back_txn = YES amount=30.000000
OK (1 test, 8 assertions)
```
→ Dedicated endpoint correctly 403s (no money). The SAME operator drives DELIVERED→RETURNED via `change-status` (HTTP 200), order ends status=22 (RETURNED), a `cash_back` Transaction of 30.00€ (sign `-`) is created. Same actor, same money effect, one door gated and one wide open.

## Evidence (live DB, read-only SELECT)
- Split permissions: `POS Operator` → pos-orders=1, pos-refund=0 ; Admin/Branch Manager → pos-refund=1.
- Exploitable surface: `status=13 AND payment_status=5 AND order_type=5 AND deleted_at IS NULL AND (not inside a closed Z window)` = **10** rows immediately exploitable. DELIVERED+PAID total = **2153**. Z #26 opened 2026-06-25 14:09, status=open, nothing closed since → all recent DELIVERED orders are pre-Z (SealedOrderGuard only blocks post-Z, so changeStatus→RETURNED passes `assertMutable`).

## Why the original "the edge was forgotten" framing is partly wrong (but finding still holds)
`tests/Feature/Order/OrderStateMachinePreZRefundLockSentinelTest.php:163-165` PINS `allows(DELIVERED, RETURNED, null) === true` ("unconditional — the always-legal refund edge"), and `tests/Feature/Refund/PreZRefundViaEndpointTest.php:278-280` documents "DELIVERED→RETURNED at line 77 is unconditional, so case (a) never reaches the pos-refund check." So the state-machine edge is permissive **BY DESIGN** — the dedicated endpoint relies on the controller-level `abort_unless(can('pos-refund'))` to gate operators. The bug is that `changeStatus` (the OTHER door into the same cashBack machinery) was never given that gate. The bypass is real; the root cause is "controller authz gap on changeStatus", not "state-machine omission".

## Lentille
Jumeau-systémique / autorisation-asymétrique : deux endpoints atteignent le MÊME effet money (`OrderService::changeStatus → RETURNED → cashBack`) ; un seul porte le gate `pos-refund`.

## Reco (NON-frozen, TDD)
In `PosOrderController::changeStatus`, BEFORE delegating to `OrderService::changeStatus`, mirror the `refundWithCounterEntry` gate:
```php
if ((int) $request->input('status') === \App\Enums\OrderStatus::RETURNED) {
    abort_unless(
        \Illuminate\Support\Facades\Auth::user()?->can('pos-refund') ?? false,
        403, 'Insufficient permission to issue refund.'
    );
}
```
(`OrderStatusRequest::rules()` makes `status` required+numeric, so the check is reliable; the gate must run before the generic try/catch so the 403 HttpException isn't masked as 422 — place it ahead of the `try` or rethrow HttpException like `selectDeliveryBoy`/`destroy` already do.)

TDD: new `tests/Feature/Pos/RefundBypassGuardTest.php`:
- POS Operator (no pos-refund) + DELIVERED+PAID → POST change-status status=22 ⇒ 403, status stays 13, 0 `cash_back` Transaction.
- Branch Manager / Admin (pos-refund) → 200 + RETURNED + cash_back present (legit path preserved).
- Operator + non-refund transition (e.g. PREPARED→DELIVERED with `pos`) ⇒ still 200 (guard scoped to RETURNED only, no collateral).

**Do NOT touch** `OrderStateMachine.php` (FROZEN + sentinel `OrderStateMachinePreZRefundLockSentinelTest`). If owner prefers to close at the state-machine root (gate the DELIVERED edge on `pos-refund`) → ESCALADE: frozen edit + LOCK + sentinel update (l.163-165 would have to flip) + owner gate.

## Next round (jumeau)
Audit `OnlineOrderController::changeStatus` (gated `permission:online-orders`, l.34) and `FrontendOrderController::changeStatus` — same `->RETURNED` triggers `cashBack`. Same asymmetry likely applies; out of scope of this single finding but flagged.
