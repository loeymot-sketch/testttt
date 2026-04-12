# FoodKing — Cursor execution handoff (bot v0)

## Cycle metadata
- **cycle_id**: `ef837272-484e-43a8-8867-4e923b953fd9`
- **persisted_state**: `waiting_cursor`
- **task_id**: `T-SMOKE-01`

## Execution scope
- **Planning file (human-readable)**: `reports/planning/latest.md`
- **Prepared at (packet)**: `2026-04-12T02:22:44+00:00`

## Allowed files (from plan → cursor_execution.json)
- `bot/README.md`

## Requested task type
- Implement / fix per **planning** above and repository rules.
- After edits, run validation commands locally (see below).

## Test strategy (from Claude plan JSON, if present)
`Kimi-test`

## Model routing (metadata only; no auto-invocation)
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
- *(none listed in plan JSON)*
