# Audit Phase – Load Instructions (Claude)

## Load — in this order only
1. `.cursor/ACTIVE_CYCLE.md` — confirm PHASE is AUDIT, PLAN_FILE and REPORT_FILE are set
2. `[REPORT_FILE]` — full validation report
3. `[PLAN_FILE]` — full active plan file

Do not load other reports, previous plans, or gate files unless the report explicitly references one.
alwaysApply rules are expected to already be in context. Do not manually reload them unless the active Cursor session clearly did not load them.

## Audit checklist
**Scope**
- [ ] All SUBSYSTEMS_TOUCHED were the only subsystems touched
- [ ] SCOPE_PRESSURE entries present and resolved, or absent

**Invariants**
- [ ] Every invariant listed in INVARIANTS_AT_RISK was respected
- [ ] No ESCALATION entry is unresolved

**Symmetry and dispatch**
- [ ] SYMMETRY_NOTE resolved if OrderService or FrontendOrderService was touched
- [ ] Dispatch-after-commit confirmed if dispatch was in scope

**Validation**
- [ ] Report shows PASSED, or failure is escalated
- [ ] `EXECUTE_DELEGATION:` line present in `reports/post_execute_latest.log` and/or `REPORT_FILE` when product code changed (per `run-cycle.md` Step 2); absent only if EXECUTE made **zero** product edits

## If all items pass
Append to report: `Audit: PASSED`
Update ACTIVE_CYCLE.md: PHASE → CLOSED, check AUDIT row
Archive the completed cycle record according to the active project archive convention, then clear ACTIVE_CYCLE.md for the next cycle.

## If any item fails
Write gate brief to `docs/gates/GATE_[TASK_ID]_[DATE].md`
Update ACTIVE_CYCLE.md: PHASE → GATE, set GATE_FILE
Do not close. Do not self-resolve.

## Graphiti write (Phase 2 — si MCP actif)
After archiving the cycle (CLOSED only — not on GATE), write key decisions to Graphiti.
Use `group_id: foodking`. Write one episode per major decision or finding — not the full report.

For each significant decision in this cycle, record:
- **Entity** : subsystem(s) touched (ex : `"OrderService"`, `"config/app.php"`)
- **Fact** : what changed, why, what invariant was verified or at risk
- **Reference** : PLAN_FILE path and REPORT_FILE path as source links

Do not write trivial cycles (comment-only, docs, formatting) to Graphiti — only cycles
that produced an architectural decision, an invariant confirmation, or a gate event.
If Graphiti is unavailable: log `GRAPHITI_WRITE: skipped — unavailable` in the report and close normally.

## Handoff
CLOSED → inform developer, cycle complete.
GATE → inform developer, action required at GATE_FILE.
