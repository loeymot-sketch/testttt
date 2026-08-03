# Plan — Caisse V1 Wave 1/2 Rework After Global Tests

Date: 2026-04-26
Basis: post-correction full validations from `reports/audit/GPT_CORRECTION_EXECUTION_W1_W2_2026-04-26.md`.

## Verdict

READY_FOR_NEXT_REWORK_WAVE: YES
READY_FOR_RELEASE: NO

Targeted K-09B / Playwright / tacos / staff-routing corrections are green. The global suites expose remaining red families that must be handled as bounded missions.

## Non-Negotiables

- Do not reopen M-04A under Option B.
- Do not add database migrations without the M-13/schema gate or explicit human approval.
- Do not bypass POS quote binding to satisfy legacy tests; update tests or flow to the new contract.
- Do not relax `EventContract::REQUIRED_PAYLOAD_KEYS` for order realtime events.
- Keep one mission per allowlist and commit/review between missions.

## Rework Order

### R0 — Git Persistence / Closed-vs-Git

Objective: make the worktree trustworthy before further execution.

Done when:

- untracked bucket review is human signed
- `reports/audit/MISSIONS_CLOSED_VS_GIT_2026-04-26.md` has no unhandled `REWORK_NOT_PERSISTED`
- each persisted bucket has an atomic commit or explicit discard note

Routing: human-led, Codex can regenerate reports only.

### R1 — POS Quote-Binding Test Contract

Objective: update legacy POS feature tests that still post `/api/admin/pos` without quote token/signature.

Affected red families:

- `AntiGravityFinalTest`
- `AntiGravityTest`
- `POSComprehensiveTest`
- `PosDiscountTest`
- `PosKioskPricingParityTest`
- `PosOrderRequestNullableTotalTest`
- `PosOrderTaxTest`
- `PosPricingSsotProofTest`
- `PosPriorityApiTest`
- `PosTicketRestaurantPaymentTest`
- `PosUITest`
- fiscal BL POS helper tests
- `SyncComprehensiveTest` POS path

Done when:

- tests generate/consume server quotes through the same helper contract used by green quote-binding tests
- no product code weakens quote binding
- focused PHP filter for the listed tests is PASS

Routing: Codex mission, allowlist limited to tests and shared test helper only.

### R2 — Outbox Fixture Contract Update

Objective: update outbox tests/fixtures that create manual `order.created` payloads without `_origin`, `payment_method`, and `queue_number`.

Affected red families:

- `OutboxConcurrentWorkerDedupeTest`
- `OutboxTest`

Done when:

- manual domain event fixtures include the full required payload
- failure-path tests still assert broadcaster failures after payload validation succeeds
- `php artisan test --filter='OutboxConcurrentWorkerDedupeTest|OutboxTest|EventContractTest|KioskRealtimeBroadcastTest'` is PASS

Routing: Codex mission, allowlist limited to tests unless product bug is proven.

### R3 — KDS Branch Visibility Regression

Objective: resolve KDS own-branch visibility failures without weakening branch isolation.

Affected red families:

- `BranchIsolationTest::chef_kds_does_not_leak_other_branch_orders`
- `SyncComprehensiveTest::kiosk_order_appears_in_kds`

Done when:

- own branch orders are visible to chef/KDS
- foreign branch orders remain excluded
- existing exact-branch sentinels remain PASS

Routing: Codex mission, likely service/controller/test allowlist after inspection.

### R4 — Kiosk Offline Queue V1/V2 Key Preservation

Objective: fix Vitest failures around offline queue idempotency/local keys.

Affected red families:

- `tests/js/kioskOfflineQueue.spec.js`
- `tests/js/kioskOfflineQueueMigration.spec.js`
- `tests/js/kioskOfflineQueueV2.spec.js`

Done when:

- replay uses the original idempotency key
- migration preserves legacy local keys
- stale cancellation and force retry clear persisted entries as expected
- `npx vitest run tests/js/kioskOfflineQueue.spec.js tests/js/kioskOfflineQueueMigration.spec.js tests/js/kioskOfflineQueueV2.spec.js` is PASS

Routing: Codex mission, allowlist limited to offline queue helper/store/tests.

### R5 — Queue Number Unique Guard

Objective: satisfy the database uniqueness sentinel for `(branch_id, queue_number)`.

Affected red family:

- `QueueNumberUniquenessSentinelTest`

Done when:

- schema has a unique guard containing `branch_id` and `queue_number`
- concurrent queue-number behavior is still safe

Routing: BLOCKED_HUMAN_SCHEMA_GATE. This needs migration/schema approval before code.

### R6 — Kiosk Branch Forced From Machine

Objective: resolve `KioskSecurityTest::kiosk_branch_id_is_forced_from_machine` returning 403.

Done when:

- forged branch payload is ignored
- machine branch wins
- unauthorized kiosk tokens are still rejected

Routing: Codex mission after gate check; inspect auth/ability path first.

### R7 — Final Closure Validation

Run after R0-R6:

```bash
php artisan test
npx vitest run
npx playwright test
bash scripts/lint-fk-bundle-legacy.sh strict
```

Done when:

- PHPUnit full: 0 failed
- Vitest full: 0 failed
- Playwright full: 0 failed, 0 flaky
- legacy strict gate is signed or references removed

Routing: Codex validation + independent audit.
