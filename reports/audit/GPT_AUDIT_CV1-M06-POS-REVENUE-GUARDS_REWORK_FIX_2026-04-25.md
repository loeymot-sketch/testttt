# GPT Audit — CV1-M06-POS-REVENUE-GUARDS Rework Fix

GPT_AUDIT_CHANNEL: codex-session
FOODKING_GPT_ONLY: 1
TASK_ID: CV1-M06-POS-REVENUE-GUARDS
VERDICT: PASS

## Scope

Rework after `GPT_SELF_AUDIT_CV1-M06-POS-REVENUE-GUARDS.md` reported `NEEDS_FIX`.

Resolved items:
- `PosOrderRequest` no longer decides discount percentage authority from client `subtotal`; it only keeps shape/UX checks and discount reason validation.
- POS manual discount authority is enforced in `OrderService` against backend-computed subtotal and returns `ValidationException` on `discount`.
- `paymentConfirm` rejects unpaid non-`PENDING` orders instead of returning false success.
- Already paid idempotence compares existing `transaction_id`; blank legacy paid/pending rows may attach the first TPE reference and finalize.
- TPE duplicate transaction references remain rejected cross-order.

## Invariants

- pricing_ssot: PASS — POS discount percentage authority is backend subtotal based.
- OrderStatus enum: PASS — status checks use `OrderStatus::*`.
- branch_id isolation: PASS — kiosk payment confirm uses `KioskMachine.branch_id`; POS collection uses branch visibility.
- dispatch after commit: PASS — cleanup and collect-cash dispatch after DB mutation transaction.
- frozen zones: PASS — gates frozen Option C and payment_prop Option A are approved.
- OrderService/FrontendOrderService symmetry: PASS — both have no-op status guards where status side effects can duplicate.

## Validation

- `php -l` on modified PHP files: PASS.
- `git diff --check` on M06 touched files: PASS.
- `php artisan test --filter='PaymentConfirmAbilityTest|PaymentConfirmMachineResolverTest|PaymentConfirmCrossBranchTest|OrderStatusNoopSideEffectsTest|PaymentNoopIdempotencyTest|CleanupVsConfirmRaceTest|PosCollectKioskCashRouteTest|PosDiscountForgeryTest|KioskPaymentStateMachineTest|PosCashEndpointSentinelTest|PosDiscountPermissionTest'`: PASS, 26 tests.

## Residual Risk

No blocking residual risk found for M06. Broader sentinel failures outside M06 remain gated by later Wave B missions.
