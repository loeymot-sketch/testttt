# Report - PRODUCT-COMPOSER-SYNC-B5B-CASH-AT-COUNTER-LIFECYCLE

REPORT_VERDICT: PASS

## Implemented

- Kiosk cash orders now persist as `payment_status=PENDING_COUNTER`, `pos_payment_method=COUNTER_DEFERRED`, `status=ACCEPT`, `fiscal_sequence_no=NULL`.
- POS collection endpoints were added under `/api/admin/pos/counter-collect/*` for pending list, confirm, and cancel.
- POS confirm allocates `fiscal_sequence_no` in the payment transaction and emits `OrderPaidAtCounter`.
- POS cancel transitions payment to `REFUNDED`, order to `CANCELED`, and dispatches `OrderCanceled` for B5a stock release without fiscal allocation.
- KDS includes pending-counter orders and shows `PAIEMENT COMPTOIR - NON REGLE`.
- Kiosk cash UX routes to `kiosk.cash-instruction` and no longer opens the kiosk cash drawer.
- Event contract/outbox/frontend subscription maps now include `order.payment_confirmed` / `OrderPaidAtCounter`.

## Validation

- `php -l` on B5b PHP files: PASS.
- `php artisan test tests/Feature/Payment`: PASS, 14 tests.
- `php artisan test tests/Feature/Payment/CounterDeferredPaymentLifecycleTest.php`: PASS, 5 tests.
- `php artisan test tests/Feature/PosCollectKioskCashRouteTest.php`: PASS.
- `php artisan test tests/Feature/KioskPaymentStateMachineTest.php`: PASS, 5 tests.
- `php artisan test tests/Feature/AfterCommitDispatchTest.php`: PASS, 14 tests.
- `php artisan test tests/Feature/EventContractTest.php`: PASS, 9 tests.
- `php artisan test tests/Unit/Domain/Events/EventContractUnitTest.php`: PASS, 12 tests.
- `php artisan test tests/Feature/DispatchAfterCommitTest.php`: PASS, 8 tests.
- `php artisan test tests/Feature/Stock`: PASS, 17 tests.
- Payment/KDS/kiosk/sync/catalog targeted regressions: PASS.
- `npx vitest run tests/js/kioskCounterPaymentFlow.spec.js tests/js/eventContractDedupe.spec.js tests/js/userReportedBlockersRuntime.spec.js tests/js/kioskRouterLockdown.spec.js tests/js/posComponentA11y.spec.js`: PASS, 17 tests.
- `npm run production`: PASS.
- `bash tools/lint/forbidden_bundles.sh`: PASS.
- `node tools/lint/scan_kiosk_bundles.mjs`: PASS.
- `bash scripts/scan-bundle-legacy.sh`: PASS.
- `git diff --check`: PASS.

## Residual Notes

- Cross-branch counter confirm returns 404 because scoped route model binding hides the foreign order. This preserves branch isolation and avoids existence leakage.
- No frontend pricing logic was added.
