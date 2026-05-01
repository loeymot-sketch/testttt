# GPT Self-Audit — D-M13 Queue Number DB Unique

Date: 2026-04-26
TASK_ID: D-M13-QUEUE-NUMBER-DB-UNIQUE-2026-04-28
EXECUTE_DELEGATION: codex-extension

## Scope Reviewed

Files materially involved:

- `database/migrations/2026_04_26_213800_add_unique_branch_queue_number_to_orders.php`
- `app/Services/OrderService.php`
- `app/Services/FrontendOrderService.php`
- `tests/Feature/QueueNumberConcurrencyTest.php`
- `docs/decisions/D-M13-QUEUE-NUMBER-UNIQUE.md`
- `docs/runbooks/D-M13-QUEUE-NUMBER-ROLLOUT.md`
- `docs/gates/GATE_LOG.md`
- `docs/PHASE_A_CLOSED.md`
- `reports/audit/PHASE2_TRAIN_A_VALIDATION_REPORT_2026-04-26.md`
- `missions/D-M13-QUEUE-NUMBER-DB-UNIQUE-2026-04-28/report.md`

## Invariants

Pricing SSOT:

- PASS. No frontend pricing logic was added. Queue number allocation does not compute or accept client totals.

OrderStatus enum:

- PASS. D-M13 changes do not introduce status string comparisons.

Branch isolation:

- PASS. The unique constraint is `(branch_id, queue_number)`, not global queue number uniqueness. Cross-branch same queue number is tested as allowed.

Dispatch after commit:

- PASS for D-M13 scope. Queue number changes do not introduce new event dispatch inside the allocation helpers.

OrderService / FrontendOrderService symmetry:

- PASS. Both services use branch-scoped allocation, fail closed on lock timeout, parse existing `A\d+` queue numbers, and retry once on unique collisions.

Frozen zones:

- PASS with documented gate. D-M13 migration was executed as local/test implementation after human authorization and explicit gate log entry; production rollout remains pending.

## Technical Audit

The previous implementation had two unsafe properties:

- lock timeout generated a new queue number from microtime, which could still collide;
- allocation used daily max semantics while D-M13 requires full `(branch_id, queue_number)` uniqueness.

The new implementation removes both:

- no fallback queue number is generated after lock timeout;
- allocation scans existing branch queue numbers and increments the maximum numeric `A####` suffix;
- DB uniqueness is the final guard if cache locking fails or if a race occurs.

During validation, the broad regression suite exposed a DB portability defect in the first allocation implementation. SQLite did not behave like MySQL for the SQL `REGEXP`/`SUBSTRING` max expression, causing repeated `A0001` allocation. This was corrected by parsing candidate queue numbers in PHP after a simple `LIKE 'A%'` query.

## Validation Evidence

- `php artisan test tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php`: 1 passed
- `php artisan test tests/Feature/QueueNumberConcurrencyTest.php`: 3 passed
- `php artisan test --filter='QueueNumber|Kiosk|POS|Order'`: 634 passed, 4 skipped
- `php artisan test`: 1086 passed, 8 skipped
- `npx vitest run`: 126 files passed, 853 tests passed
- `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 npx playwright test`: exit 0, 34 passed, 1 flaky retry passed
- `bash scripts/lint-fk-bundle-legacy.sh strict`: exit 0 with known kiosk legacy bundle warning

## Residual Risks

- Production DB may contain duplicate `(branch_id, queue_number)` values. The migration blocks if a duplicate exists, and the rollout runbook requires a production preflight before execution.
- The business decision is not daily reset. If daily reset is required later, the unique key must change and historical data must be modeled accordingly.
- Hardware UAT is still not simulated by automated tests: cash drawer, receipt printer, kiosk screen, and KDS physical screen must be tested in the lab.
- Live payment providers are not configured; V1 validation assumes manual/simulated external terminal payment confirmation.

## Verdict

SELF_AUDIT_VERDICT: PASS
