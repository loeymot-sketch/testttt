# Orchestrator Responsible Decision POS + Kiosk - 2026-04-26

TASK_ID: ORCHESTRATOR_RESPONSIBLE_DECISION_POS_KIOSK
ROLE: orchestrator-auditor
EXECUTE_DELEGATION: codex-extension
AUDIT_OVERRIDE_PHASE_A: 1
OVERRIDE_REASON: human_authorization_2026-04-26 by user kossayelbenna8

DECISION_VERDICT: STOP_RANDOM_PATCHING_AND_LOCK_THE_RELEASE_PATH
BEST_CHOICE_FOR_US: D13_THEN_GOVERNANCE_PERSISTENCE_THEN_FINAL_RELEASE_VALIDATION

## 1. Verification

Current verified state:

- Latest backend full suite: `php artisan test` => 1080 passed, 8 skipped, 1 failed.
- Only failing backend test: `Tests\Feature\Sentinels\QueueNumberUniquenessSentinelTest`.
- The failing sentinel asserts the missing database uniqueness guard for `(branch_id, queue_number)`.
- Latest no-D13 follow-up fixed the POS reorder residual and proved historical prices stay display/re-import data only.
- Latest no-D13 follow-up report: `reports/audit/ORCHESTRATOR_NO_D13_FOLLOWUP_EXECUTION_2026-04-26.md`.

No additional no-gate product bug remains proven by the latest audit chain.

## 2. Responsible Decision

The best choice is not to keep implementing small code changes.

The best choice is:

1. Freeze product-code expansion now.
2. Treat the current local code as technically stabilized except D13.
3. Make D13 the next real engineering decision.
4. After D13, close governance/persistence so CI and a clean clone can reproduce the local system.
5. Run final validation gates.

Reason:

- Adding more no-gate patches now increases dirty-worktree risk more than it reduces product risk.
- The only red backend signal is a deliberate schema sentinel.
- The app-level queue locks still have fallback paths using microtime-style queue numbers. That is not a final fiscal/operational guarantee.
- A real V1 needs the database to enforce queue-number uniqueness, not just application code.

## 3. D13 Recommendation

Recommended D13 option:

- Unique database constraint on `(branch_id, queue_number)` where `queue_number IS NOT NULL`, if the target DB supports partial unique indexes.
- If the production DB is MySQL and partial unique indexes are unavailable in the project version, use a generated/functional guard or a full unique index after backfill, depending on confirmed DB capability.
- Remove unsafe random/microtime fallback behavior from queue allocation after the DB constraint exists.
- On duplicate-key collision, retry deterministic allocation under lock or return an explicit 409 after bounded retries.

Recommended rollout:

1. Preflight query for existing duplicates grouped by `(branch_id, queue_number)`.
2. Backfill/deduplicate historical duplicates before adding the unique constraint.
3. Add index in a migration with rollback.
4. Update queue allocation collision handling.
5. Run `QueueNumberUniquenessSentinelTest`.
6. Run full backend suite.

This is the responsible technical path. It is also the path with the least hidden risk for POS + Kiosk + KDS.

## 4. What Not To Do

Do not:

- Disable or weaken `QueueNumberUniquenessSentinelTest`.
- Declare release-ready with the sentinel red.
- Add unrelated frontend/UI patches before D13 and governance.
- Commit broad untracked files without bucket triage.
- Mix D13 migration with unrelated Phase A cleanup in one commit.

## 5. Release Path

Required order:

1. D13 decision and implementation.
2. Phase A persistence/untracked cleanup.
3. Quote subsystem persistence decision.
4. Active primary cleanup.
5. Memory policy cleanup.
6. Final validation:
   - `php artisan test`
   - `npx vitest run`
   - `npx playwright test`
   - `bash scripts/lint-fk-bundle-legacy.sh strict`

## 6. Final Position

Responsible orchestrator decision:

- No more no-gate implementation is justified before D13.
- D13 is the correct next mission.
- If D13 remains deferred, the correct status is `TECHNICAL_PASS_WITH_D13_HOLD`, not `RELEASE_READY`.

DECISION_STATUS: RECORDED
