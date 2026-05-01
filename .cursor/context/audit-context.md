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

**Verdict audit Claude (binaire)**
- [ ] Ligne `AUDIT_VERDICT: PASS` ou `AUDIT_VERDICT: REWORK` présente dans le même `REPORT_FILE` (décision **Claude** ; canal terminal PRIMARY, fallback session avec `AUDIT_FALLBACK_REASON:`) — exigence [run-cycle.md](mdc:.cursor/commands/run-cycle.md) Step 5
- [ ] Si `REWORK` : `REMEDIATION_AUDIT_CYCLE` noté (1..5) et boucle `replan → EXECUTE → …` tant que N < 5 ; **pas** de `PHASE: CLOSED`
- [ ] Après `AUDIT_VERDICT: PASS`, lancer / vérifier `npm run codex:final-audit -- <TASK_ID>` et obtenir `GPT_FINAL_AUDIT_VERDICT: PASS`
- [ ] **Clôture** = checklist ci-dessus + **`AUDIT_VERDICT: PASS`** + **`GPT_FINAL_AUDIT_VERDICT: PASS`** (les tests seuls ne suffisent pas)

## If all items pass **and** dual PASS exists
Append to report: `Audit: PASSED` (cohérent avec `AUDIT_VERDICT: PASS`)
Update ACTIVE_CYCLE.md: PHASE → CLOSED, check AUDIT row
Archive the completed cycle record according to the active project archive convention, then clear ACTIVE_CYCLE.md for the next cycle.

## If any checklist item fails, **or** `AUDIT_VERDICT: REWORK`, **or** `GPT_FINAL_AUDIT_VERDICT: REWORK|ESCALATE`

**Branche `REWORK` (audit de fond) —** prioritaire sur une simple re-marque : incrémenter / consigner `REMEDIATION_AUDIT_CYCLE` (voir [run-cycle.md](mdc:.cursor/commands/run-cycle.md) Step 5). Tant que N < 5 : **Claude** replanifie, **EXECUTE** (souvent `codex-extension` si correction complexe), re-VALIDATE, re-audit. À N = 5 et encore `REWORK` : **GATE** humain (orchestrateur s’arrête seul ici).

Apply the triage below (per `.cursor/rules/auto-remediation.mdc`):

### Triage on failure

1. **Critical zone touched?** — DB schema, auth, frozen zone, invariant logic (OrderService/FrontendOrderService symmetry, branch_id isolation, OrderStatus enum, dispatch-after-commit, pricing backend SSOT) → **GATE** (see "GATE branch" below). No auto-remediation allowed here.
2. **Same bug for the 3rd consecutive attempt?** (compare `bug_signature` across `REMEDIATION_ATTEMPT_*` entries in `REPORT_FILE`) → **GATE** with "bug irrésolu" template from `auto-remediation.mdc`.
3. **Otherwise (KO normal, attempt 1 or 2 sur le même bug_signature)** → **REMEDIATION** branch (auto, no human gate — distinct du plafond `REWORK`×5) :
   - Append `REMEDIATION_ATTEMPT_N` block to `REPORT_FILE` with `bug_signature`, `root_cause`, `correction_plan`
   - Re-route to **codex-extension** per `.cursor/routing.md` for any product correction; fallback `foodking-complex-implementer` only if `codex` is unavailable
   - Re-run EXECUTE → post-hook → VALIDATE → AUDIT
   - Stay in PHASE: AUDIT (do not transition to CLOSED) until `AUDIT_VERDICT: PASS` or one of the **GATE** conditions above (critique, bug×3, ou `REWORK`×5) is met

### GATE branch
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
