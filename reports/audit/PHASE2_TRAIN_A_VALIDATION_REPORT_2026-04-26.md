# Phase 2 Train A — Validation Report

Date: 2026-04-26
Scope: V1 release preparation after A.1/A.2/A.3 and local/test D-M13 implementation
Mode: audit + implementation validation

## Executive Verdict

TRAIN_A_VALIDATION_VERDICT: PASS_WITH_RELEASE_GATES_PENDING

The V1 technical core is now green on the local validation stack:

- D-M13 queue-number uniqueness is implemented for local/test and covered by sentinels.
- Full backend PHPUnit is green.
- Full Vitest is green.
- Full Playwright is green with one flaky retry that passed.
- Legacy bundle strict lint exits 0, with the known kiosk legacy bundle warning still visible.

This does not mean commercial release is fully signed. Production migration rollout, hardware UAT, and live payment gateway setup remain explicit gates.

## D-M13 Queue Number Uniqueness

Implemented paths:

- `database/migrations/2026_04_26_213800_add_unique_branch_queue_number_to_orders.php`
- `app/Services/OrderService.php`
- `app/Services/FrontendOrderService.php`
- `tests/Feature/QueueNumberConcurrencyTest.php`
- `docs/decisions/D-M13-QUEUE-NUMBER-UNIQUE.md`
- `docs/runbooks/D-M13-QUEUE-NUMBER-ROLLOUT.md`

Technical decisions:

- Unique DB constraint on `(branch_id, queue_number)`.
- `queue_number = null` remains allowed for legacy rows.
- Same queue number remains allowed across different branches.
- POS/web and kiosk/frontend allocation now share the same branch-scoped rule.
- Old `microtime(true) * 10 % 9999` fallback was removed.
- Cache lock timeout now fails closed with HTTP 409 instead of inventing an unsafe queue number.
- Duplicate unique violation retries allocation once for same-branch races.

Production note:

- Local/test implementation is complete.
- Production execution of the migration still requires the rollout runbook: backup, duplicate preflight on the production DB, maintenance/cutover window, and rollback readiness.

## Backend / PHP

### Static Checks

Commands:

```bash
php -l app/Services/OrderService.php
php -l app/Services/FrontendOrderService.php
php -l database/migrations/2026_04_26_213800_add_unique_branch_queue_number_to_orders.php
php -l tests/Feature/QueueNumberConcurrencyTest.php
rg -n "microtime\\(true\\).*9999|fallback queue number|whereDate\\('created_at'.*queue|queue_lock_.*_\\$today" app/Services/OrderService.php app/Services/FrontendOrderService.php
```

Result:

- PHP syntax checks passed.
- Removed unsafe microtime/daily queue fallback patterns: no matches.

### Targeted D-M13 Tests

Commands:

```bash
php artisan test tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php
php artisan test tests/Feature/QueueNumberConcurrencyTest.php
```

Result:

- QueueNumberUniquenessSentinelTest: 1 passed.
- QueueNumberConcurrencyTest: 3 passed.

### Broad Queue/Kiosk/POS/Order Regression

Command:

```bash
php artisan test --filter='QueueNumber|Kiosk|POS|Order'
```

Result:

- 634 passed
- 4 skipped
- 0 failed

Important correction during validation:

- The first broad run exposed SQLite-incompatible allocation logic when using SQL `REGEXP`/`SUBSTRING`.
- Allocation was corrected to read candidate `A%` queue numbers and parse `A\d+` in PHP, keeping the behavior DB-neutral for SQLite tests and MySQL production.

### Full PHP Suite

Command:

```bash
php artisan test
```

Result:

- 1086 passed
- 8 skipped
- 0 failed

Known skips are environment/vendor-scope skips already present in the suite, mostly SQLite/MySQL behavior boundaries.

## Frontend / Vitest

Command:

```bash
npx vitest run
```

Result:

- 126 test files passed
- 853 tests passed
- Exit code 0

Observed warnings, non-blocking for this run:

- `baseline-browser-mapping` data is older than two months.
- Vuex/i18n test harness warnings around kiosk filter init and kiosk promo translation keys.
- Happy DOM network warnings from intentionally unsafe URL tests.

## Legacy Bundle Lint

Command:

```bash
bash scripts/lint-fk-bundle-legacy.sh strict
```

Result:

- Exit code 0.
- Warning remains for legacy kiosk bundle references:
  - `public/js/kiosk.js`
  - `public/js/kiosk-wizard.js`

Release interpretation:

- Not a hard failure in the current strict mode.
- Before commercial release, decide whether W2 keeps a shim or removes/quarantines the old kiosk bundle path.

## Playwright / E2E

Server:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Command:

```bash
PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 npx playwright test
```

Result:

- Exit code 0.
- 34 passed.
- 1 flaky retry passed: `tests/e2e/01-auth-refresh.spec.js` POS login/F5 session persistence timed out once and passed on retry.

Covered flows:

- auth refresh / F5 POS
- POS cash full cycle
- kiosk login and navigation
- KDS login/list load
- POS card surface/no JS crash
- staff-only routing
- tacos cash receipt flow
- static Playwright contract sentinels for KDS, kiosk errors, offline waiting, quote pinning, POS realtime, offline payment refusal

Operational note:

- Playwright config still has no `webServer` block. E2E requires starting Laravel manually until a test server strategy is added.
- The temporary Laravel server was stopped after the run.

## Sync / Architecture Review

Branch isolation:

- The DB constraint scopes uniqueness by `branch_id`, not globally.
- Same queue number across two branches remains valid and tested.

Order lifecycle:

- POS/web and kiosk/frontend both allocate from the same orders table with branch-scoped lock and DB uniqueness.
- Queue allocation no longer resets per day; this is intentional because `(branch_id, queue_number)` is unique for the full table.

Pricing SSOT:

- No frontend pricing logic was introduced.
- Queue-number work does not alter pricing calculation paths.

KDS / realtime:

- Queue number remains a persisted order attribute before broadcast/list consumption.
- Full Playwright and broad Kiosk/POS/Order regression passed after the change.

Remaining risk:

- Production rows must be duplicate-free before applying the migration.
- If production requires daily queue reset semantics, that is a different business model and requires a different unique key such as `(branch_id, business_date, queue_number)`. Current D-M13 decision is full `(branch_id, queue_number)` uniqueness.

## Known Warnings To Track

- Vendor PHP deprecation from `smartisan/laravel-settings` while serving E2E requests: `Using ${var} in strings is deprecated`.
- French/i18n cleanup remains needed for visible technical English and missing kiosk promo keys.
- Legacy kiosk bundle warning remains until W2 cutover/shim cleanup.
- One Playwright auth-refresh retry occurred; it passed on retry but should stay under observation.
- Hardware lab UAT still needs physical execution with cash drawer, receipt printer, kiosk screen, and KDS screen.
- Live payment gateway setup remains outside this local validation; V1 policy is manual/simulated external terminal until configured.

## Final Gate State

- A.1: closed.
- A.2: closed.
- A.3: closed.
- A.4 / D-M13: local/test implementation complete, production rollout pending runbook signoff.
- Hardware UAT: pending human/device execution before commercial release.
- Payment provider live activation: pending later configuration.

RELEASE_READINESS_VERDICT: TECHNICAL_CORE_GREEN__RELEASE_PENDING_PROD_DM13_ROLLOUT_AND_HARDWARE_UAT
