# GPT_AUDIT — CV1-M07-KDS-RELEASE REWORK FIX

FOODKING_GPT_ONLY: 1
AUDIT_CHANNEL: gpt-codex
AUDIT_VERDICT: PASS

## Corrections

- Added `OrderStateMachine::isReleasedToKitchen()` for the M07 KitchenRelease predicate: status must be at least `OrderStatus::ACCEPT` and either `PaymentStatus::PAID`, or POS cash via `OrderType::POS` + `PosPaymentMethod::CASH`.
- Added dedicated predicate tests for paid/unpaid orders and the POS cash exception in `KitchenReleaseRuleTest`.
- Updated `KitchenDisplaySystemOrderService::list()` so KDS visibility mirrors the release policy while keeping the KDS-visible status set.
- Added feature coverage in `KdsPaginationOverflowTest` proving paid orders and POS cash are visible, while unpaid non-POS cash and pending POS cash are not.
- Added M07 GPT-only trace in `reports/post_execute_latest.log`.

## Validation

- `php -l app/Domain/Order/OrderStateMachine.php app/Services/KitchenDisplaySystemOrderService.php tests/Feature/KitchenReleaseRuleTest.php tests/Feature/KdsPaginationOverflowTest.php` — PASS.
- `php artisan test --filter=KitchenReleaseRuleTest` — 4 passed.
- `php artisan test --filter=KdsPaginationOverflowTest` — 2 passed.
- `php artisan test --filter='KdsTransitionWhitelistSentinelTest|KdsExpectedStatusConflictSentinelTest|KdsTransitionWhitelistTest|KdsExpectedStatusConflictTest|KitchenReleaseRuleTest|KdsPaginationOverflowTest|KdsChangeStatusConcurrencyTest|KDSFlowTest'` — 15 passed.
- `npx playwright test -c tests/Playwright KdsMultiScreenPlaywrightTest.spec.js` — 1 passed.
- `bash scripts/lint-fk-branch-isolation.sh` — PASS.
- Scoped `git diff --check` — PASS.

## Verdict

PASS. M07 now implements the missing KitchenRelease predicate, tests the payment/cash rule explicitly, preserves branch isolation, uses `OrderStatus::*`, and keeps dispatch after the DB transaction returns.
