# FoodKing – Model Routing Policy

Auto/Premium routing: DISABLED
One PRIMARY_MODEL per cycle. Assignment is explicit in every plan file.

---

## Routing Table

| Phase | Model | Permitted scope |
|---|---|---|
| PLAN | Claude | Read task, write scoped plan, flag invariant risks and gate conditions |
| EXECUTE — complex | GPT-5.4 | Backend logic, sync, data layer, API contracts, non-trivial algorithms |
| EXECUTE — routine | Composer | CRUD, config, UI copy, boilerplate, scaffold-only migrations |
| VALIDATE | Composer | Diff summary, test results, anomaly flags, report draft |
| AUDIT | Claude | Plan adherence, invariant check, drift assessment, gate brief or close |
| GATE BRIEF | Claude → Human | Claude writes, human decides, loop blocked until resolved |
| REPORT | Composer | Cycle summary aligned to `reports/` discipline |

---

## Hard Boundaries

**Claude**
- No implementation code
- No direct file edits
- Sole author of plan files, audit records, and gate briefs

**GPT-5.4**
- No planning, no self-routing, no auditing
- Executes within plan scope only — does not redefine it
- No schema migrations, auth changes, or external service wiring unless explicitly scoped
- No frozen zone edits without gate clearance

**Composer**
- No schema, auth, sync, pricing, dispatch, or `branch_id` filtering logic
- No frozen zone edits
- No architectural decisions
- No gate briefs

---

## FoodKing Routing Triggers

| Condition | Routing consequence |
|---|---|
| `OrderService` or `FrontendOrderService` in scope | GPT-5.4 + symmetry check required in plan |
| Pricing logic in scope | Claude confirms backend-first in plan before routing to GPT-5.4 |
| `OrderStatus` reference in scope | GPT-5.4 must reference enum from code — no strings |
| Dispatch logic in scope | GPT-5.4 + post-commit constraint explicit in plan |
| `branch_id` filtering or scoping in scope | GPT-5.4 + isolation logic declared in plan |
| Frozen zone file in scope | Gate brief required before any implementation begins |

---

## Escalation Protocol
If Composer or GPT-5.4 discovers a scope gap or invariant conflict mid-cycle:
1. Stop execution
2. Log under `ESCALATION` in the active plan file
3. Do not self-resolve — Claude reviews and decides: re-plan or gate

Mid-cycle model switch requires Claude confirmation logged in the plan file.

---

## Routing Integrity
This file is version-controlled and may not be modified during an active cycle.
Routing changes require a plan-phase Claude decision recorded in `docs/gates/GATE_LOG.md`.
