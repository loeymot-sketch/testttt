# NVA — Logic edge / refund / void / composition / state-machine
Slug: `nva-logic-edge-refund` · HEAD `cfc23966a` · 2026-07-03 (nuit, read-only)

## Surface
Business correctness at the limits: `OrderStateMachine`, `PaymentStateMachine`,
refund/void (pre-Z RETURNED + post-Z counter-entry mirror + cashBack), pricing
edges (coupon/manual discount, rounding, negative/zero total), composition
min/max/qty. Read-only; live repro via read-only `tinker` + grep/Read.

## Verdict: IMPROVABLE
The transactional core is genuinely hardened. Refund/void has multiple layered
guards (three twin `pos-refund` gates on RETURNED, DB `UNIQUE(parent_order_id)`,
`SealedOrderGuard`, parent-already-RETURNED check, per-order idempotent
`cashBack`). Pricing clamps totals to `max(0,…)` and rejects over-discount.
Composition clamps every quantity `max(1,…)` and enforces min/max. **But** I
found one asymmetric authorization gap (P2), one confirmed state-machine
dead-end (P3), and two hardening/durability items (P3).

---

## Findings

### P2 — `change-payment-status → REFUNDED` bypasses the `pos-refund` gate (deferred-order void)
`app/Http/Requests/PaymentStatusRequest.php:14-20` + `app/Services/OrderService.php:2358-2497`
+ `app/Http/Controllers/Admin/PosOrderController.php:348-357`

The team explicitly hardened the **order-status** refund axis: `RETURNED` (22)
is gated on `can('pos-refund')` in all three siblings
(`PosOrderController::changeStatus:328`, `OnlineOrderController::changeStatus:101`,
`TableOrderController::changeStatus:72`). The **payment-status** axis was NOT
given the twin gate. `PaymentStatusRequest::authorize()` admits `POS Operator`,
`rules()` whitelists `REFUNDED`, and `PaymentStateMachine` allows
`PENDING_COUNTER → REFUNDED` (`app/Domain/Order/PaymentStateMachine.php:13-16`).
`OrderService::changePaymentStatus` only applies the sealed-Z guard for REFUNDED
(`:2366`) — **no permission check**.

Live-confirmed reachability:
- `tinker`: `POS Operator` (user#3) → `pos-refund=N`, `pos-orders=Y`.
- `tinker`: `PaymentStateMachine::canTransition(PENDING_COUNTER, REFUNDED) = Y`;
  `PAID→REFUNDED = N` (so the surface is scoped to deferred/PENDING_COUNTER orders).
- The counter-collect queue (`routes/api.php:819`) filters
  `payment_status = PENDING_COUNTER`. Flipping a served borne/walk-in order to
  REFUNDED silently **removes it from the "à encaisser" queue** — no cashBack
  fires (no money out), **no `reason` required** (unlike CANCELED), and **no
  `pos-refund` permission required**.

Impact (long-term / insider): a cashier without refund rights can void any
fulfilled deferred order out of the payable queue, disguised as a "refund",
so the counter never collects the cash. It is audited on the HMAC chain
(`order.payment_status_changed`, `:2463`) which aids *detection* but not
*prevention*. This is the exact vulnerability class the `RETURNED` twin-guards
were written to close, left open on the payment_status axis.

**Live repro (read-only, reachability):**
```
php artisan tinker --execute='
$op=App\Models\User::role("POS Operator")->first();
echo $op->can("pos-refund")?"HAS":"NO-pos-refund"; // → NO-pos-refund
echo App\Domain\Order\PaymentStateMachine::canTransition(15,20)?"PENDING_COUNTER->REFUNDED OK":""; // → OK
'
```
(Full exploit = POST `/api/.../pos-order/change-payment-status/{order}` with
`payment_status=20` as a POS-Operator token on a PENDING_COUNTER order; not
executed — no DB writes per mandate.)

**Fix proposal:** mirror the RETURNED twin-guard. In `PaymentStatusRequest`
(or `PosOrderController::changePaymentStatus`, non-frozen), when
`payment_status === PaymentStatus::REFUNDED`, `abort_unless(auth()->user()?->can('pos-refund'), 403)`.
Optionally require a `reason` for REFUNDED like RETURNED does.

---

### P3 — `OUT_FOR_DELIVERY` is a state-machine dead-end (failed / refused delivery)
`app/Domain/Order/OrderStateMachine.php:73-74`

`OUT_FOR_DELIVERY (10)` has exactly one exit — `DELIVERED (13)` — and the case
ignores the `$user` param, so **not even Admin** can Cancel/Return/Re-prepare an
order once it's out for delivery. A driver whose delivery fails (customer
absent/refuses) has no clean terminal transition: the order is stuck until it is
marked `DELIVERED` (a false state), after which `DELIVERED→RETURNED` allows the
refund. The operational workaround forces a fake DELIVERED.

**Live repro (read-only):**
```
php artisan tinker --execute='
use App\Domain\Order\OrderStateMachine as SM; use App\Enums\OrderStatus as S;
$a=App\Models\User::role("Admin")->first();
foreach([S::CANCELED,S::REJECTED,S::RETURNED,S::PREPARED] as $t)
  echo "OFD->$t admin=".(SM::allows(S::OUT_FOR_DELIVERY,$t,$a)?"Y":"N")." ";'
# → all N ; only OFD->DELIVERED = Y
```

**Fix proposal:** add `OUT_FOR_DELIVERY → CANCELED` (reason-required) and/or
`OUT_FOR_DELIVERY → RETURNED` for a `pos-refund`/Admin actor, so a failed
delivery has an honest terminal path without faking DELIVERED. Frozen-zone /
owner-gated edit (`OrderStateMachine` is under `LOCK_ORDERSTATEMACHINE_PREZ_REFUND`).

---

### P3 (durability) — counter-entry mirror does not check for an existing pre-Z `cash_back` on the parent
`app/Services/Order/RefundWithCounterEntryService.php:86-91` + `app/Domain/Order/OrderStateMachine.php:79-86`

Terminal states `CANCELED/REJECTED/RETURNED` grant Admin a blanket override
(`allows()` returns true for any target when `hasRole('Admin')` — live-confirmed
`RETURNED→DELIVERED admin=Y`). The pre-Z `cashBack` is per-order idempotent
(`PaymentService::cashBack:97-103`), so a *same-window* double-refund is blocked.
The residual: an Admin can (1) pre-Z refund an order → RETURNED + real cashBack,
(2) bounce it `RETURNED→DELIVERED`, (3) after the Z closes, refund again →
`RefundWithCounterEntryService::execute` creates a mirror **plus a second
`CashMovement` DIRECTION_OUT** (`:291-299`). `execute()` guards on
`parent.status === RETURNED` (`:86`) and DB `UNIQUE(parent_order_id)`, but does
**not** check whether the parent already carries a `type='cash_back'`
Transaction — so the status-bounce across a Z boundary yields two cash-outs for
one sale. Highly contrived (requires the most-trusted actor + a deliberate
un-return + a Z close), audited on-chain; static-analysis only (no live repro
without forbidden DB writes).

**Fix proposal:** in `execute()`, before allocating the mirror sequence,
`if (Transaction::where('order_id',$parent->id)->where('type','cash_back')->exists()) throw new InvalidArgumentException('Parent already refunded (cash_back exists).',422);`
and/or narrow the terminal-state Admin escape so `RETURNED` cannot be reopened
to a payable state.

---

### P3 (improvement) — no partial-refund capability
`app/Services/Order/RefundWithCounterEntryService.php:108-170` + `PaymentService::cashBack:136-143`

Both refund paths are all-or-nothing: the mirror negates the full
total/subtotal/tax + every line, and `cashBack` refunds `$order->total`. There is
no way to refund a single line or a partial amount (e.g. one missing item from a
delivered order). For V1 LOCAL mono-poste this is an acceptable scope choice, but
it is a real long-term limitation for the delivery/borne flows (partial-refund is
a routine restaurant operation). Noted per the mission's explicit "remboursement
partiel" angle. No code defect — a missing capability.

**Fix proposal (backlog):** parameterize `execute()`/`cashBack` with an optional
`amount` + item subset, negating only the selected lines and writing a partial
CashMovement; keep the `UNIQUE(parent_order_id)` replaced by a
sum-of-mirrors ≤ parent-total invariant.

---

## Attacks run (and refuted — proof of robustness)
- **Negative / zero order total via discount** → `PricingService:355`
  `max(0.0,$rawTotal)`; `DiscountCalculator::manualDiscount:28` returns `0.0`
  when requested > subtotal (over-discount rejected, not applied). REFUTED.
- **Composition 0-viande / negative extra qty** → every qty coerced
  `max(1,…)` (`PricingService:158,188,213,728`); `min_select` enforced
  (`:618`), `max_select` enforced (`:625`), non-repeat enforced (`:632`).
  REFUTED.
- **Double-refund pre-Z (RETURNED→RETURNED)** → idempotent guard
  `changeStatus` `:2139` + `cashBack:97-103` per-order short-circuit. REFUTED.
- **Double mirror post-Z** → DB `UNIQUE(parent_order_id)` → 409
  `MIRROR_ALREADY_EXISTS` (`PosOrderController:170`) + `execute:86` parent-RETURNED. REFUTED.
- **Refund-bypass via sibling change-status routes (POS/online/table)** → all
  three carry the `pos-refund` twin-guard on RETURNED. REFUTED.
- **Refund an unpaid order for money** → `cashBack` no-ops without a prior
  `type='payment'` Transaction (`:132-134`); `UNPAID→REFUNDED` blocked by
  PaymentStateMachine. REFUTED.
- **ValidStatusTransition vs OrderStateMachine divergence** → the Rule delegates
  to `OrderStateMachine::allows` (single SSOT). REFUTED.
- **Discount over-reports TVA in Z** → `ZReportService` scales per-line tax by
  `orderDiscountRatio`; mirror pre-scales by `parentRatio`
  (`RefundWithCounterEntryService:132-144`). REFUTED.
