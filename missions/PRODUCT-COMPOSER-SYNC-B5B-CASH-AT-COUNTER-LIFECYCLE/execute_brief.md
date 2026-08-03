# Execute Brief - PRODUCT-COMPOSER-SYNC-B5B-CASH-AT-COUNTER-LIFECYCLE

## Scope

Implement B5b from the final Claude execution plan:

- add `PaymentStatus::PENDING_COUNTER` and `PaymentStatus::REFUNDED`;
- add `PosPaymentMethod::COUNTER_DEFERRED`;
- keep kiosk cash orders non-fiscal at creation;
- send kiosk cash orders to KDS immediately with a visible pending-counter badge;
- collect/cancel payment from POS only;
- allocate `fiscal_sequence_no` only when POS confirms collection;
- publish `OrderPaidAtCounter` through the existing `domain_events` outbox.

## Verification

Required validation is local PHPUnit/Vitest/build plus bundle lockdown scans.

## Decision Notes

Cross-branch POS access returns 404 through `Order` route-model binding and branch scope before the service sees the row. This is stricter than 403 because it does not reveal order existence across branches.
