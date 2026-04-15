# Task: SIM_001

## Description
Create a new markdown file `docs/orchestration/AGENT_ROLES.md` that documents the role of each agent in the FoodKing Cursor-local multi-agent system.

## Target file
`docs/orchestration/AGENT_ROLES.md` (new file)

## Change
Create a new markdown file with the following sections:
- Title and one-line purpose
- Table: Agent | Model | Phase | Responsibility (4 rows: Planner, Implementer, Validator, Auditor)
- One-line note on RUNNER_MODE behavior

No business logic. No source code. Documentation only.

## Acceptance criterion
`docs/orchestration/AGENT_ROLES.md` exists and contains the required sections.
No other files are created or modified.

## Constraints
- No schema
- No auth
- No pricing
- No frozen zones
- No external APIs
- No OrderService / FrontendOrderService
- No app/, resources/, routes/, database/ changes

## PRIMARY_MODEL
Composer

## Test strategy
static-inspection
