# Plan Phase – Load Instructions (Claude)

## Load — in this order only
1. `.cursor/ACTIVE_CYCLE.md` — confirm PHASE is PLAN, TASK_ID is set
2. `tasks/[TASK_ID].md` — full task file
3. `.cursor/routing.md` — PRIMARY_EXECUTION_MODEL assignment and mandatory review checkpoints

Do not load previous plans, reports, or gate files.
alwaysApply rules are expected to already be in context. Do not manually reload them unless the active Cursor session clearly did not load them.

## Graphiti query (Phase 2 — si MCP actif)
Before producing the plan, query Graphiti for prior decisions on the subsystems
declared in the task file, using `group_id: foodking`.
Ask: "What decisions, invariants, or risks have been recorded for [SUBSYSTEM]?"
If Graphiti returns context: include a `## PRIOR_CONTEXT` section in the plan file
summarizing what was found (2–5 lines max). Do not re-litigate past decisions — use
them as constraints.
If Graphiti is unavailable or returns nothing: proceed without it. Never block PLAN on Graphiti.

## Produce plan file
Write `plans/PLAN_[TASK_ID]_[DATE].md` from `plans/PLAN_TEMPLATE.md`.

Plan is invalid without:
- TASK_ID, PRIMARY_EXECUTION_MODEL, REASONING_EFFORT, PLAN_REVIEW, SUBSYSTEMS_TOUCHED, SUBSYSTEMS_OFF_LIMITS
- INVARIANTS_AT_RISK, GATE_CONDITIONS, Execution Steps
- SYMMETRY_NOTE (required if OrderService or FrontendOrderService is in scope)

## Stratégie de test — décision obligatoire dans chaque plan

Le plan DOIT déclarer une stratégie de test. Utiliser le vocabulaire actif :

| Stratégie | Quand |
|---|---|
| `no-test` | Docs, commentaires, config cosmétique |
| `local-validation` | CRUD, wiring, logique localisée (PHPUnit / Jest) |
| `playwright-mcp` | Flows UI critiques via Playwright MCP tools (Phase 3) |
| `playwright-critical-flow` | Un ou deux flows ciblés (POS, KDS, Kiosk, Auth) |
| `playwright-full-e2e` | Suite complète des 5 flows critiques FoodKing |
| `human-verification` | Gate humain explicite requis |

**Playwright MCP (Phase 3)** : utiliser `playwright-mcp` ou `playwright-critical-flow`
si le changement touche : POS, KDS, Kiosk, OSS, auth guards, routing surfaces,
pricing display, ou transitions de statut ordre.
Le Planner-Orchestrator décide — jamais auto-déclenché.

## Before marking PLAN complete
- [ ] Scope is unambiguous — if not, write clarification in task file and stop
- [ ] Frozen zone in scope → gate brief written to docs/gates/ before proceeding
- [ ] PRIMARY_EXECUTION_MODEL assigned per routing.md (`gpt-5.5-pro`, `REASONING_EFFORT: xhigh`)
- [ ] PLAN_REVIEW block initialized; `run-cycle.md` Step 1 must obtain `PLAN_REVIEW_VERDICT: PASS` before EXECUTE

## Update ACTIVE_CYCLE.md
Set PHASE → EXECUTE
Set PLAN_FILE → path to plan just written
Set PRIMARY_EXECUTION_MODEL → as declared in plan
Check PLAN row in Phase Completion

## Handoff
- If `RUNNER_MODE: manual` — stop here. Developer invokes the implementation agent manually.
- If `RUNNER_MODE: single-session` — do not stop. Proceed immediately to the EXECUTE phase using the active cycle protocol in `.cursor/commands/run-cycle.md`.
