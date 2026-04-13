# FoodKing — Cursor execution handoff (bot v0)

## Cycle metadata
- **cycle_id**: `bfebb694-c71d-4310-9731-4a9e6f7053fd`
- **persisted_state**: `waiting_cursor`
- **task_id**: `REAL-CYCLE-001`

## Execution scope
- **Planning file (human-readable)**: `reports/planning/latest.md`
- **Prepared at (packet)**: `2026-04-12T20:56:11+00:00`

## Allowed files (from plan → cursor_execution.json)
- `app/Enums/OrderStatus.php`
- `docs/BUSINESS_RULES.md`
- `docs/DATABASE_SCHEMA_CORE.md`
- `.cursor/rules/safety.mdc`
- `reports/execution/latest.md`

## Requested task type
- Implement / fix per **planning** above and repository rules.
- After edits, run validation commands locally (see below).

## Test strategy (from Claude plan JSON, if present)
`static-inspection`

## Model routing recommendation (from `bot/config/model_routing.json`)
Resolved deterministically with task key **`cursor_execution`** (same key as cycle controller when preparing execution):
- **model**: `local_agent`
- **notes**: `Cursor IDE — human-driven in v0.`
- **provider**: `cursor`
- **tier**: `executor`

## Model route snapshot on cycle state (`active_model_route`)
Value persisted in `bot/state/cycle_state.json` at last transition (often **orchestrator_intake** from `begin-cycle`, not Cursor):
- **model**: `project_conversation`
- **notes**: `Planning / intake — Claude project (manual paste in v0).`
- **provider**: `claude`
- **tier**: `high_reasoning`

## Expected output artifacts
- Update `reports/execution/latest.md` with commands run + results (per `AGENTS.md`).
- Keep diffs scoped to **allowed files** unless human expands scope.

## Validation commands (from packet; not executed by bot)
- `php artisan test --filter=Order`

## Stop conditions
- Stop if a change would violate pricing, authz, or order-state rules.
- Stop if required context is missing; leave a note in execution report.

## Non-goals (from Claude plan JSON if present)
- Any PHP, test, migration, or route file change
- Inventing new documentation sections beyond correcting wrong values
- Using any doc as source of truth for enum values (only app/Enums/OrderStatus.php)
