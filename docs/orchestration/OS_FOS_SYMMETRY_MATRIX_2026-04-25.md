# OS / FOS Symmetry Matrix - 2026-04-25

Mission: `CV1-M10-OS-FOS-SYMMETRY`

Scope: documentation and contract tests only. Product code is unchanged.

SYMMETRY_NOTE: `OrderService` and `FrontendOrderService` share the same physical `orders` table, the same `OrderStatus` constants, and the same branch isolation invariant. They are intentionally asymmetric on creation and payment completion: POS/admin/table flows are operational and cashier/admin controlled, while frontend/kiosk flows are machine/customer controlled and must confirm deferred kiosk payments through `paymentConfirm` followed by `finalizePaidKioskOrder`. `OrderService::changePaymentStatus` remains POS/admin-only; there is no matching `FrontendOrderService::changePaymentStatus`.

## Current-State Method Matrix

| Capability | OrderService / OS | FrontendOrderService / FOS | Contract classification | Evidence |
| --- | --- | --- | --- | --- |
| Order table | `App\Models\Order` uses `orders`. | `App\Models\FrontendOrder` also uses `orders`. | Symmetric storage, asymmetric models. | `app/Models/Order.php`; `app/Models/FrontendOrder.php` |
| Create - POS/admin | `posOrderStore` creates accepted and paid POS orders, rejects cross-branch cashier creation, recalculates totals server-side. | No POS create method. | Intentional asymmetry: POS/admin only. | `app/Services/OrderService.php:570`, `app/Services/OrderService.php:611` |
| Create - web/app | `myOrderStore` exists for non-POS customer orders. | `myOrderStore` exists for kiosk/web frontend orders. | Similar surface, different actor and branch derivation. | `app/Services/OrderService.php:295`, `app/Services/FrontendOrderService.php:127` |
| Create - table | `tableOrderStore` is OS-owned. | No table-create method. | Intentional asymmetry: table/admin flow only. | `app/Services/OrderService.php:1057` |
| Pricing | OS create paths unset submitted totals and recalculate through backend pricing. | FOS create path unsets submitted totals and recalculates through backend pricing. | Symmetric invariant: backend pricing SSOT. | `app/Services/OrderService.php:599`, `app/Services/FrontendOrderService.php:199` |
| Status change | `changeStatus` handles admin/POS and self-cancel paths. Uses `OrderStatus` constants and no-ops when current status equals target. | `changeStatus` allows owner cancel only. Uses `OrderStatus` constants and no-ops when current status equals target. | Symmetric no-op side-effect guard; intentionally narrower frontend authority. | `app/Services/OrderService.php:1517`, `app/Services/OrderService.php:1529`, `app/Services/OrderService.php:1591`, `app/Services/FrontendOrderService.php:674`, `app/Services/FrontendOrderService.php:683` |
| Cancel side effects | On actual cancel/reject/return, OS may call `PaymentService::cashBack`, `LoyaltyService::refundPoints`, status transition audit, notifications, and stock release. | On actual cancel, FOS may call `cashBack`, `refundPoints`, status transition audit, notifications, and stock release. | Similar side-effect classes, different actor limits. Both must skip on no-op. | `app/Services/OrderService.php:1596`, `app/Services/FrontendOrderService.php:691` |
| Payment status update | `changePaymentStatus` updates `payment_status`, no-ops when unchanged, branch-guards staff, and writes audit on real change. | No `changePaymentStatus` method and no frontend route for it. | Intentional asymmetry: POS/admin-only. | `app/Services/OrderService.php:1702`, `app/Services/OrderService.php:1719`, `app/Services/OrderService.php:1728`, `routes/api.php:682`, `routes/api.php:697` |
| Deferred kiosk payment | POS cash collection can promote kiosk cash orders through `collectKioskCash`. | Card / ticket kiosk payment is confirmed by `paymentConfirm`, then operationally promoted by `finalizePaidKioskOrder`. | Intentional asymmetry by payment rail. | `app/Services/OrderService.php:1799`, `routes/api.php:638`, `app/Http/Controllers/Frontend/OrderController.php:88`, `app/Services/FrontendOrderService.php:812`, `routes/api.php:910` |
| Branch isolation - listing | `OrderService::applyOrderFilter` applies exact `branch_id = (int) value`. | `FrontendOrderService::myOrder` applies exact `branch_id = (int) value` inside owner query. | Symmetric exact branch filtering where branch filter is accepted. | `app/Services/OrderService.php:2084`, `app/Services/FrontendOrderService.php:100` |
| Branch isolation - mutation | OS status/payment/destroy paths guard non-admin staff against foreign branch orders. | FOS status uses owner check; `paymentConfirm` binds authenticated kiosk user to `KioskMachine.branch_id` and rejects order branch mismatch. | Symmetric branch isolation invariant, actor-specific enforcement. | `app/Services/OrderService.php:1584`, `app/Services/OrderService.php:1721`, `app/Services/OrderService.php:1917`, `app/Services/FrontendOrderService.php:680`, `app/Http/Controllers/Frontend/OrderController.php:100`, `app/Http/Controllers/Frontend/OrderController.php:126` |
| Dispatch timing | OS admin status dispatches status notifications only after the DB transaction closes. `collectKioskCash` dispatches only if collection occurred after transaction. | FOS `myOrderStore`, `changeStatus`, and `finalizePaidKioskOrder` dispatch after DB mutation; payment confirmation calls finalization after its payment transaction. | Symmetric invariant: side effects after DB mutation/commit for touched paths. | `app/Services/OrderService.php:1581`, `app/Services/OrderService.php:1669`, `app/Services/OrderService.php:1804`, `app/Services/OrderService.php:1859`, `app/Http/Controllers/Frontend/OrderController.php:116`, `app/Http/Controllers/Frontend/OrderController.php:199`, `app/Services/FrontendOrderService.php:832`, `app/Services/FrontendOrderService.php:878` |

## Intentional Asymmetries

1. POS/admin payment mutation is not exposed in FOS.
   - OS: `OrderService::changePaymentStatus`.
   - FOS: `paymentConfirm` validates kiosk ownership, branch, original payment rail, duplicate transaction id, and pending status; `finalizePaidKioskOrder` promotes paid deferred kiosk orders to `ACCEPT`.

2. POS/table order creation is not mirrored in FOS.
   - POS creation sets POS-specific payment defaults, cashier branch guards, fiscal sequence, and optional floorplan release.
   - Kiosk/frontend creation derives branch from the authenticated `KioskMachine`, handles kiosk idempotency by `(branch_id, idempotency_key)`, and keeps deferred card/ticket orders pending until payment confirmation.

3. Cancel authority is intentionally narrower in FOS.
   - OS admin/POS may process broader status transitions through the state machine and permissions.
   - FOS only allows owner cancellation and rejects non-cancel transitions.

## Contract Tests

`tests/Feature/Symmetry/OrderServicesContractTest.php` covers:

- method/route presence and intentional absence of FOS `changePaymentStatus`;
- exact branch filtering and kiosk branch guard evidence;
- no-op status/payment side-effect guards for OS and FOS;
- deferred kiosk payment golden response/idempotency;
- dispatch-after-mutation ordering by source anchor checks.

Mandatory related tests for this mission:

- `php artisan test --filter=OrderServicesContractTest`
- `php artisan test --filter=OrderStatusNoopSideEffectsTest`
- `php artisan test --filter=PaymentNoopIdempotencyTest`
- `php artisan test --filter=PaymentConfirmCrossBranchTest`

## Wave 2 D-07 Verification Addendum

Mission: `CV1-LOT-D07-FOS-SYMMETRY-CONTRACT`

Verification focus:

- keep POS/admin `OrderService::changePaymentStatus` intentionally absent from `FrontendOrderService`;
- keep frontend/kiosk payment mutation constrained to `paymentConfirm` and `finalizePaidKioskOrder`;
- keep OS/FOS no-op status/payment side-effect guards explicit;
- keep branch isolation evidence exact, not pattern/LIKE based.

Mandatory Wave 2 command:

- `php artisan test --filter=OrderServicesContractTest`

## Product Gaps

No product patch was applied in M-10. The current code supports the documented contract. Any future drift that introduces FOS payment-status mutation, removes exact branch equality filters, or moves lifecycle dispatch inside a transaction should fail the contract tests before product code is changed.
