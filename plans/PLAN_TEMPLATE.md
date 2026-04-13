# Plan – [TASK_ID] – [DATE]

## TASK_ID
[ID]

## PRIMARY_MODEL
[GPT-5.4 | Composer]

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

## SYMMETRY_NOTE
[Required if OrderService or FrontendOrderService is in SUBSYSTEMS_TOUCHED. Otherwise: N/A]

## SCOPE_PRESSURE
[Populated mid-cycle only. Leave blank at plan time.]

## ESCALATION
[Populated mid-cycle only. Leave blank at plan time.] 


## Audit Status
[ ] Pending
[ ] Passed — cycle closed
[ ] Gate opened — `docs/gates/GATE_[TASK_ID]_[DATE].md`
