# GPT Self Audit - CV1-LOT-P08-KDS-RELEASE-RULE

## Scope

- TASK_ID: `CV1-LOT-P08-KDS-RELEASE-RULE`
- Lot: P-08 POS/KDS
- Delegation: `codex-extension`
- Gate: `GATE_KDS_BUMP_AUTHORITY_V1_2026-04-25` Approved Option B - server authority with `expected_status`.

## Changes

- Added `app/Domain/Kds/KitchenReleaseRule.php` as the explicit KDS release predicate.
- Added `app/Listeners/DispatchKdsTicket.php` as the direct KDS status-event dispatcher with no-op dedupe.
- Updated `KitchenDisplaySystemOrderService` to consume `KitchenReleaseRule` for KDS visibility and transition checks, and `DispatchKdsTicket` for post-transition `OrderStatusChanged` dispatch.
- Strengthened `KdsExpectedStatusConflictTest` and `KitchenReleaseRuleTest`.

## Invariants

- Pricing backend SSOT: PASS. No price, total, quote, or payment calculation changed.
- OrderStatus enum: PASS. KDS transitions are still expressed with `OrderStatus::*` constants and locked by `KitchenReleaseRule` plus `OrderStateMachine::allows`.
- branch_id isolation: PASS. Existing branch lock in `KitchenDisplaySystemOrderService::changeStatus` and branch-scoped list filtering were preserved.
- Dispatch after commit: PASS. `DispatchKdsTicket` dispatches `OrderStatusChanged`, which uses `DispatchableAfterCommit`; no direct broadcast was introduced.
- OS/FOS symmetry: PASS. Neither `OrderService.php` nor `FrontendOrderService.php` was modified.
- Frozen zones/gates: PASS. Required KDS gate was verified before edits; no schema or route/provider scope was touched.
- Payment Ledger Option B: PASS. No M-04A/full ledger work.

## Validation

- `php -l app/Domain/Kds/KitchenReleaseRule.php` - PASS.
- `php -l app/Listeners/DispatchKdsTicket.php` - PASS.
- `php -l app/Services/KitchenDisplaySystemOrderService.php` - PASS.
- `php -l tests/Feature/KitchenReleaseRuleTest.php` - PASS.
- `php -l tests/Feature/KdsExpectedStatusConflictTest.php` - PASS.
- `git diff --check -- app/Domain/Kds/KitchenReleaseRule.php app/Listeners/DispatchKdsTicket.php app/Services/KitchenDisplaySystemOrderService.php tests/Feature/KdsTransitionWhitelistTest.php tests/Feature/KdsExpectedStatusConflictTest.php tests/Feature/KitchenReleaseRuleTest.php tests/Feature/KdsPaginationOverflowTest.php` - PASS.
- `php artisan test --filter='KdsTransitionWhitelistTest|KdsExpectedStatusConflictTest|KitchenReleaseRuleTest|KdsPaginationOverflowTest'` - PASS, 11 tests.

## Residual Risk

- `EventServiceProvider.php` is outside the P-08 allowlist, so `DispatchKdsTicket` is used as a direct service collaborator rather than a globally registered Laravel listener.
- `KitchenDisplaySystemOrderService.php` already carried W2 KDS hardening changes before this run; they were preserved and validated rather than reverted.

VERDICT: PASS
