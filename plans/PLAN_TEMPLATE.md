# Plan – [TASK_ID] – [DATE]

## TASK_ID
[ID]

## PRIMARY_EXECUTION_MODEL
gpt-5.5-pro

## REASONING_EFFORT
xhigh

## PLAN_REVIEW
PLAN_REVIEW_CHANNEL: [codex-extension | foodking-complex-implementer (codex-extension-fallback)]
PLAN_REVIEW_MODEL: gpt-5.5-pro
PLAN_REVIEW_REASONING_EFFORT: xhigh
PLAN_REVIEW_VERDICT: [PENDING | PASS | REWORK | ESCALATE]

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| [e.g. OrderService] | [e.g. refund logic] | Write | No | No |

## SUBSYSTEMS_OFF_LIMITS
- [subsystem — reason]

## INVARIANTS_AT_RISK
- [Invariant name — why it is at risk this cycle, or: None]

## GATE_CONDITIONS
- [Anticipated gate trigger, or: None anticipated]

## Execution Steps
1. [Step 1]
2. [Step 2]

## SUBTASKS (optional — for multi-task cycles, see `docs/orchestration/TEAM_WORKFLOW.md`)
> Use when a cycle has 2+ implementable steps that benefit from per-task dual audit.
> SUBTASK_ID is stable: `${TASK_ID}-S01`, `-S02`, …
> Status machine: `TODO → PLAN_REVIEWED → CLAIMED → EXECUTED_BY_GPT → GPT_SELF_AUDITED → CLAUDE_MINI_PASS|CLAUDE_MINI_REWORK → GPT_FINAL_PASS|GPT_FINAL_REWORK → DONE | RETRY (≤3) | HUMAN_GATE`
> A subtask cannot be marked `DONE` before Claude mini-audit and GPT final/pass for that subtask or batch.

| SUBTASK_ID | Description | Difficulty | Owner (planned) | Invariants at risk | Mini-audit policy | Status | Retry |
|---|---|---|---|---|---|---|---|
| ${TASK_ID}-S01 | [scope précis] | small \| complex | codex-extension \| foodking-complex-implementer fallback | None \| [list] | 1:1 \| batch-eligible | TODO | 0 |
| ${TASK_ID}-S02 | … | … | … | … | … | TODO | 0 |

### Batching rule (mini-audit Claude)
- `complex` OR `Invariants at risk ≠ None` → **mandatory 1:1** mini-audit (`CLAUDE_MINI_AUDIT_${TASK}_S0N.md`).
- `routine` AND `Invariants at risk = None` → **batch-eligible** (lots de 2 à 4) → `CLAUDE_MINI_AUDIT_${TASK}_BATCH_${N}.md` listant les SUBTASK_ID couverts.

## SYMMETRY_NOTE
[Required if OrderService or FrontendOrderService is in SUBSYSTEMS_TOUCHED. Otherwise: N/A]

## SCOPE_PRESSURE
[Populated mid-cycle only. Leave blank at plan time.]

## ESCALATION
[Populated mid-cycle only. Leave blank at plan time.] 


## Audit Status
[ ] Pending
[ ] PLAN_REVIEW_VERDICT: PASS
[ ] AUDIT_VERDICT: PASS
[ ] GPT_FINAL_AUDIT_VERDICT: PASS
[ ] Passed — cycle closed
[ ] Gate opened — `docs/gates/GATE_[TASK_ID]_[DATE].md`
