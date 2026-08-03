# GPT Self Audit - PRODUCT-COMPOSER-SYNC-B5B-CASH-AT-COUNTER-LIFECYCLE

AUDIT_VERDICT: PASS

## Invariants

- Pricing/payment authority stays backend-side. The frontend only chooses a payment method and displays instructions.
- Branch isolation is enforced by route model binding plus `PaymentService::assertCounterOrderVisible`.
- Fiscal NF525 guard holds: kiosk cash creation leaves `fiscal_sequence_no` null; POS confirmation allocates it in the payment transaction; cancellation does not allocate.
- Dispatch-after-commit is preserved through `OrderPaidAtCounter` using `DispatchableAfterCommit` and `PersistOrderPaidAtCounterToOutbox`.
- OrderService/FrontendOrderService symmetry impact is explicit: kiosk creation path sets pending counter, POS legacy route delegates to `PaymentService`.

## Risk Review

- Risk: KDS must not hide unpaid counter orders. Mitigation: KDS query includes `PENDING_COUNTER`, resource exposes `payment_pending_counter`, UI badge added, regression tests pass.
- Risk: POS double-collect. Mitigation: confirm is idempotent for already `PAID`; `OrderPaidAtCounter` dispatches only on the first transition.
- Risk: Cross-branch access. Mitigation: foreign scoped order returns 404 and state remains unchanged.
- Risk: Public kiosk admin bundle regression. Mitigation: production build plus bundle lockdown scans pass.

## Validation Summary

All mission PHP lints, PHPUnit targeted suites, Vitest targeted suites, production build, bundle scans, order-service symmetry audit, and `git diff --check` passed on 2026-04-27.
