# Train A / D-M13 Business-Day Queue Final Report

Date: 2026-04-27
Task: D-M13-QUEUE-NUMBER-DB-UNIQUE-2026-04-28
Verdict: PASS

## Scope Closed

- Queue number uniqueness is now enforced by database invariant `(branch_id, business_date, queue_number)`.
- POS `OrderService` and kiosk/frontend `FrontendOrderService` allocate queue numbers with the same branch + business-date scope.
- `orders.business_date` is filled on new orders and backfilled by migration from `order_datetime` or `created_at`.
- Legacy microtime fallback behavior is removed from the active queue allocation path.
- The customer kiosk admin sentinel now matches the human decision: no staff admin component is loaded from the customer kiosk, and `/kiosk/admin` redirects to idle.

## Validation

- `php artisan test tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php`: PASS, 1 passed.
- `php artisan test tests/Feature/QueueNumberConcurrencyTest.php`: PASS, 4 passed.
- `php artisan test --filter='QueueNumber|Kiosk|POS|Order'`: PASS, 643 passed, 4 skipped.
- `php artisan test`: PASS, 1097 passed, 8 skipped.
- `npx vitest run`: PASS, 126 files passed, 867 tests passed.
- `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 npx playwright test`: PASS, 35 passed.
- `bash scripts/lint-fk-bundle-legacy.sh strict`: PASS with known warning on `public/js/kiosk.js`.
- Scoped `git diff --check` on touched files: PASS.

## Notes

- The local browser database had not run D-M13 yet, causing Playwright to fail first with `orders.business_date` missing. Applied only the D-M13 migration locally and reran the full E2E suite successfully.
- Full `git diff --check` still reports an unrelated trailing whitespace in `reports/audit/_TERMINAL_CONTEXT_BRIEF.md`; touched files are clean.
- Production rollout is not automatic: run the D-M13 preflight, backup, migration window, and rollback checks from `docs/runbooks/D-M13-QUEUE-NUMBER-ROLLOUT.md`.

## Remaining Risk For Train 2

- If FoodKing needs a business day that crosses midnight, `business_date` must move from calendar-date derivation to a branch business-calendar service before production rollout.
- The kiosk/POS connection-loss banner remains a separate Train 2 issue; it was not caused by D-M13.
