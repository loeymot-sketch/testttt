# D-M13 Queue Number DB Unique — Execution Report

TASK_ID: D-M13-QUEUE-NUMBER-DB-UNIQUE-2026-04-28
PHASE: EXECUTE
EXECUTE_DELEGATION: codex-extension
AUDIT_OVERRIDE_PHASE_A: 1
OVERRIDE_REASON: human_authorization_2026-04-26 by user kossayelbenna8

## Objective

Make `(branch_id, business_date, queue_number)` unique at the DB layer and remove unsafe queue-number fallback behavior while preserving branch isolation and daily queue reset.

2026-04-27 update: the earlier full-history uniqueness draft was replaced by the human-approved business-day model for Train A.

## Implemented

- Added migration `2026_04_26_213800_add_unique_branch_queue_number_to_orders.php`.
- Added `orders.business_date`, backfilled from `order_datetime`/`created_at`.
- Added duplicate preflight inside the migration before index creation, scoped to branch + business date + queue number.
- Added SQLite/MySQL index detection and rollback support.
- Replaced POS/web queue allocation with a branch + business-date scoped save helper.
- Replaced kiosk/frontend queue allocation with a symmetric branch + business-date scoped save helper.
- Removed microtime fallback queue generation.
- Added retry-once handling for DB unique collisions during queue allocation.
- Added `QueueNumberConcurrencyTest` for same-branch same-day duplicate rejection, same-branch different-day allowance, cross-branch allowance, and null legacy rows.
- Documented the signed local/test implementation decision and production rollout runbook.

## Validation

Static:

- `php -l app/Services/OrderService.php`: PASS
- `php -l app/Services/FrontendOrderService.php`: PASS
- `php -l database/migrations/2026_04_26_213800_add_unique_branch_queue_number_to_orders.php`: PASS
- `php -l tests/Feature/QueueNumberConcurrencyTest.php`: PASS
- Unsafe fallback grep: no matches
- Scoped `git diff --check` on touched Train A files: PASS

Targeted:

- `php artisan test tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php`: 1 passed
- `php artisan test tests/Feature/QueueNumberConcurrencyTest.php`: 4 passed

Regression:

- `php artisan test --filter='QueueNumber|Kiosk|POS|Order'`: 643 passed, 4 skipped
- `php artisan test`: 1097 passed, 8 skipped
- `npx vitest run`: 126 files passed, 867 tests passed
- `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 npx playwright test`: 35 passed
- `bash scripts/lint-fk-bundle-legacy.sh strict`: exit 0 with known kiosk legacy bundle warning

Validation notes:

- Local browser E2E initially failed because the local development database had not applied the D-M13 migration yet (`orders.business_date` missing). Applied only the D-M13 migration locally, then reran the failed POS tacos flow and the full Playwright suite successfully.
- `database/factories/ItemFactory.php` was aligned to `Status::ACTIVE` after regression tests exposed that factory-created items were considered inactive by `AvailabilityService`.
- `tests/js/kioskPerfChunks.spec.js` was aligned with the human-approved kiosk lock decision: the customer kiosk must not load a staff admin component, and `/kiosk/admin` redirects to idle.
- `tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts` was aligned with the actual French fiscal receipt label (`N° ticket NF525`).

## Risks

- Production migration must not run until production duplicate preflight is clean and backup/cutover/rollback are prepared.
- The selected invariant is business-day uniqueness per branch. If the business later wants night-shift cutoffs that are not calendar days, `business_date` must be derived from a branch business-day calendar service before production rollout.
- The local D-M13 migration was applied for E2E only. Production rollout still requires the runbook preflight, backup, and signoff.

## Verdict

D13_EXECUTION_VERDICT: PASS
