# Execute Phase – Load Instructions (GPT-5.5-pro/xhigh via `codex-extension`)

## Mandatory reads — in this order only

1. `.cursor/ACTIVE_CYCLE.md` — confirm PHASE is EXECUTE, PLAN_FILE is set, PRIMARY_EXECUTION_MODEL matches your model
2. `[PLAN_FILE]` — full active plan file

**Policy sources** (`AGENTS.md`, `.cursor/routing.md`, `.cursor/rules/*.mdc`): do **not** re-open during EXECUTE if their content is visible in your current context (check for the header text, e.g. "Global Rules – FoodKing" for `global.mdc`). Open a specific missing artifact **only** if the session demonstrably lacks it — one file at a time.

`codex-extension` and fallback `foodking-complex-implementer` use **only** the mandatory reads above for governance. All other file reads are **implementation targets** from `SUBSYSTEMS_TOUCHED` (plus direct imports/interfaces of target files — one level, not transitive). `foodking-routine-implementer` is validation/report only in finishing cycles.

## Before writing any code

- [ ] PRIMARY_EXECUTION_MODEL in ACTIVE_CYCLE.md matches your model — if not, **stop** and tell the developer
- [ ] `PLAN_REVIEW_VERDICT: PASS` exists in the active plan or report — if not, **stop** before code
- [ ] The plan file contains no unresolved `ESCALATION` or `SCOPE_PRESSURE` markers — if any exist, **stop** and report them
- [ ] Every file you intend to edit is listed in `SUBSYSTEMS_TOUCHED` — if not, log `ESCALATION` and **stop**
- [ ] No file in `SUBSYSTEMS_TOUCHED` is a frozen zone without a cleared gate — if one is, log `ESCALATION` and **stop**
- [ ] `.cursor/hooks/safety-check.sh` has been confirmed by the developer this session — if not, prompt: "Please run `.cursor/hooks/safety-check.sh` and confirm before I begin implementation."

## Execute

Implement exactly the Execution Steps in the plan file. Nothing more.

If a step requires a subsystem not in `SUBSYSTEMS_TOUCHED`:
1. Stop
2. Log under `ESCALATION` in plan file: subsystem affected, what blocks, why
3. End your response — the developer will invoke the planner to re-plan or gate

If you modify `OrderService` or `FrontendOrderService`, log `SYMMETRY_NOTE` in the plan file with symmetry verification result — regardless of whether the plan mentions symmetry.

## After execution completes

1. Write this line to the report file (`reports/post_execute_latest.log` or `REPORT_FILE` from `ACTIVE_CYCLE.md`):
   `EXECUTE_DELEGATION: [your subagent name]`
   This is required by `run-cycle.md` for audit traceability.

2. Run `.cursor/hooks/post-execute.sh`. If shell execution is unavailable, note it in your output — the developer will run it manually before proceeding.

Results are written to:
- `reports/post_execute_latest.log` — stable path, read by Composer validate phase
- `reports/test_[TASK_ID]_[DATE].log` — timestamped archive

## Update ACTIVE_CYCLE.md on completion

Set PHASE → VALIDATE
Check EXECUTE row in Phase Completion

## Handoff

Developer invokes validation.
Composer reads `ACTIVE_CYCLE.md`, loads `PLAN_FILE`, then reads `reports/post_execute_latest.log` as validation input.

`VALIDATE` (tests/CI verts) ne **clôture** **pas** le cycle : [run-cycle.md](mdc:.cursor/commands/run-cycle.md) Step 5 exige `AUDIT_VERDICT: PASS` (Claude) puis `GPT_FINAL_AUDIT_VERDICT: PASS` avant `CLOSED`. Sur `REWORK`, re-boucle orchestration + EXECUTE avec plafond 5.
