# FoodKing – Agent Roles

Defines each agent's model, phase ownership, and responsibility in the Cursor-local multi-agent system.

**Primer complet** (lecture obligatoire multi-agents, Graphiti, terminal allies, tokens) : **[GLOBAL_SYSTEM_PRIMER.md](./GLOBAL_SYSTEM_PRIMER.md)**.

## Agent Roster

| Agent | Model | Phase | Responsibility |
|---|---|---|---|
| FoodKing-Planner | Claude | PLAN, AUDIT | Produce plan files, run full Claude audit checklist, write gate briefs, close or gate cycles |
| Codex Plan Reviewer | GPT-5.5-pro / xhigh | PLAN_REVIEW | Challenge Claude's plan before implementation; no EXECUTE without `PLAN_REVIEW_VERDICT: PASS` |
| FoodKing Codex Implementer | GPT-5.5-pro / xhigh via `codex-extension` | EXECUTE, SELF_AUDIT | Implement all product changes per active plan only; routine product implementation is disabled |
| FoodKing GPT Final Auditor | GPT-5.5-pro / xhigh via `codex-extension` | GPT_FINAL_AUDIT | Provide the second final audit after Claude; close requires `GPT_FINAL_AUDIT_VERDICT: PASS` |
| FoodKing-Validator | Composer / local tooling | VALIDATE, REPORT | Test execution, diff summary, anomaly flagging, validation report; no product-code fixes |
| Orchestrator | Claude | All phases | Drive single-session autonomous cycle via `.cursor/commands/run-cycle.md`; fall back from terminal Claude to Cursor Claude on quota/rate-limit/terminal failure |

## RUNNER_MODE

- `manual` — developer invokes each agent at each phase transition
- `single-session` — Claude orchestrates all phases autonomously within one session, halting only on hard gate conditions
