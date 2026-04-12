# FoodKing — Cursor execution handoff (bot v0)

## Cycle metadata
- **cycle_id**: `ad7fbb3b-ccd6-4c90-b59a-cc522b7a7297`
- **persisted_state**: `waiting_cursor`
- **task_id**: `T-SUP`

## Execution scope
- **Planning file (human-readable)**: `reports/planning/latest.md`
- **Prepared at (packet)**: `2026-04-12T02:45:25+00:00`

## Allowed files (from plan → cursor_execution.json)
- `bot/README.md`

## Requested task type
- Implement / fix per **planning** above and repository rules.
- After edits, run validation commands locally (see below).

## Test strategy (from Claude plan JSON, if present)
`No-test`

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
- *(none)*

## Stop conditions
- Stop if a change would violate pricing, authz, or order-state rules.
- Stop if required context is missing; leave a note in execution report.

## Non-goals (from Claude plan JSON if present)
- *(none listed in plan JSON)*
