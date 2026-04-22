# FoodKing – Agent Roles

Defines each agent's model, phase ownership, and responsibility in the Cursor-local multi-agent system.

**Primer complet** (lecture obligatoire multi-agents, Graphiti, terminal allies, tokens) : **[GLOBAL_SYSTEM_PRIMER.md](./GLOBAL_SYSTEM_PRIMER.md)**.

## Agent Roster

| Agent | Model | Phase | Responsibility |
|---|---|---|---|
| FoodKing-Planner | Claude | PLAN, AUDIT | Produce plan files, run full audit checklist, write gate briefs, close or gate cycles |
| FoodKing-Implementer | GPT-5.4 | EXECUTE (complex) | Implement complex backend logic, sync, data layer, API contracts per active plan only |
| FoodKing-Validator | Composer | EXECUTE (routine), VALIDATE | Routine edits, config, boilerplate, diff summary, anomaly flagging, validation report |
| Orchestrator | Claude | All phases | Drive single-session autonomous cycle via `.cursor/commands/run-cycle.md` |

## RUNNER_MODE

- `manual` — developer invokes each agent at each phase transition
- `single-session` — Claude orchestrates all phases autonomously within one session, halting only on hard gate conditions
