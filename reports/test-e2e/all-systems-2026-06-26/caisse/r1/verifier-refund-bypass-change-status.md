# CAISSE r1 — Adversarial verification: Refund-bypass via change-status

**Verdict: CONFIRMED — P1 (money-out + privilege escalation). NOT REFUTED.**
Role: adversarial verifier. I tried to refute and could not — every claim reconciles with live code + live DB.

---

## [P1] app/Domain/Order/OrderStateMachine.php:76-77 — POS Operator without `pos-refund` issues a full cash refund (cashBack + RETURNED) through the sibling `change-status` route, bypassing the `pos-refund` gate of the dedicated refund endpoint

### Repro (reproducible, read-only — no mutation placed)
DB live `foodking_e2e`:
```
SELECT u.id,u.email,u.branch_id FROM users u WHERE email='pos@lecayenne.fr';
-- 3 | pos@lecayenne.fr | 1
-- role: POS Operator (id=7); perms = pos, pos-orders, pos.redeem-loyalty, pos-discount-up-to-10, dashboard, kds, oss  (NO pos-refund)
SELECT r.name FROM role_has_permissions rhp JOIN permissions p ON p.id=rhp.permission_id JOIN roles r ON r.id=rhp.role_id WHERE p.name='pos-refund';
-- Admin | Branch Manager   (ONLY these two hold pos-refund)
```
Path (all non-`$auth` staff path, no permission gate):
1. Route `POST /api/admin/pos-order/change-status/{order}` — middleware = `permission:pos-orders` only (PosOrderController.php:28-37). Operator HAS pos-orders.
2. `OrderStatusRequest::authorize()` line 25 → `hasAnyRole([...,'POS Operator',...])` returns `true` for ANY status incl. RETURNED.
3. `OrderService::changeStatus` else-branch (line 2098+): only guard before RETURNED is branch isolation (line 2120). In-branch operator → branch-1 order passes.
4. `ValidStatusTransition::passes` (line 2145) → `OrderStateMachine::allows(DELIVERED=13, RETURNED=22, $operator)` → **line 76-77 returns true unconditionally** (no pos-refund check, unlike lines 48/59/67).
5. RETURNED block (OrderService.php:2150-2196): if `$locked->transaction` exists → `PaymentService::cashBack($locked,'credit',...)` fires. **Cash out. No pos-refund gate anywhere.**

### Evidence
- `OrderStateMachine.php:76-77` `case OrderStatus::DELIVERED: return $to === OrderStatus::RETURNED;` — no `hasPermissionTo('pos-refund')`, contrast lines 48/59/67 which DO require it for ACCEPT/PREPARING/PREPARED→RETURNED.
- `PosOrderController.php:58-62` — the dedicated `refundWithCounterEntry` HAS `abort_unless(Auth::user()?->can('pos-refund'),403)`. The sibling `changeStatus` (line 312-321) has NONE. **Asymmetry is the bug.**
- DB: **39 real POS DELIVERED+PAID targets** (order_type=15, status=13, payment_status=5), e.g. #4464 total=147€.
- DB real-world precedent that the cash-out path executes in prod: orders #4206 and #4875 (order_type=15, status=22 RETURNED, payment_status=20 REFUNDED) each have BOTH a `payment` AND a `cash_back` row in `transactions` (ids 982, 983, type=`cash_back`, amounts 7€/10€).
- Sentinel `OrderStateMachinePreZRefundLockSentinelTest.php:164` pins `assertTrue(allows(DELIVERED,RETURNED,null))` — comment literally: "unconditional — the always-legal refund edge".
- `PosRefundUiPermissionSentinelTest.php:26` locks the gate ONLY on `refund-with-counter-entry`; it never touches `change-status`. `PreZRefundViaEndpointTest` also only POSTs to `refund-with-counter-entry`. **No test asserts Operator→403 on change-status→RETURNED** (grepped all candidates for `change-status.*RETURNED` + `assertForbidden`/`403` → empty).

### Note on finding's prose vs reality (substance holds)
The candidate finding's REPRO text wrote `order_type=4` and `payment_status=1/PAID` — those enum LABELS are wrong (POS=type 15, PAID=5; type 4 has 1 order). But the concrete numbers it cited (39 targets, #4464=147€, #4206/#4875 cash_back precedent) match the live DB exactly. The vulnerability is real regardless of the label typos.

### Lens
Privilege-escalation via sibling-route bypass of a gate enforced only on the dedicated endpoint (twin-route authz drift). Same class as past "endpoint A gated, endpoint B reaches same money path ungated".

### Reco
- Root `OrderStateMachine.php:76-77` is **FROZEN** (CLAUDE.md §7) → do NOT edit without LOCK+gate. `heal_safe_nonfrozen=true` because a precise NON-frozen fix exists upstream of the frozen line.
- Non-frozen heal (TDD): in `PosOrderController::changeStatus` (NOT frozen), when `(int)$request->status === OrderStatus::RETURNED` AND the order is money-bearing (payment_status===PAID or `$order->transaction` exists), `abort_unless(Auth::user()?->can('pos-refund'),403,'...')` BEFORE delegating to the service — mirror of the `refundWithCounterEntry` gate (PosOrderController.php:58-62). Alternatively gate inside `OrderStatusRequest::authorize()` for the RETURNED+money case. Keep CANCELED/REJECTED (operational, pre-payment) ungated.
- Add permanent sentinel `RefundBypassGuardTest`: POS Operator (no pos-refund) POSTing RETURNED to change-status on a PAID order → 403 + zero `cash_back` transaction row written.
- NF525 intact (negative captured in open Z, `order.returned` audit row written, no fiscal gap) ⇒ **P1 money/security, not P0.**
