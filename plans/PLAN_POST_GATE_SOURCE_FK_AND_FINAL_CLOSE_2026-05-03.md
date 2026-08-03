# Plan – CV1-V2-POST-GATE-SOURCE-FK-001 – 2026-05-03

## TASK_ID
CV1-V2-POST-GATE-SOURCE-FK-001

## PRIMARY_EXECUTION_MODEL
codex-extension

## REASONING_EFFORT
xhigh

## PLAN_REVIEW
PLAN_REVIEW_CHANNEL: codex-extension  
PLAN_REVIEW_MODEL: gpt-5.5-pro  
PLAN_REVIEW_REASONING_EFFORT: xhigh  
PLAN_REVIEW_VERDICT: PASS

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `docs/gates/` | SOURCE-FK decision finalization | Write | No | No |
| `database/migrations` | SOURCE-FK migration path (only if approved option A/B) | Write | Potentially | No |
| `reports/execution/` | post-gate execution evidence | Write | No | No |
| `reports/audit/` | closeout audit evidence | Write | No | No |

## SUBSYSTEMS_OFF_LIMITS
- Pricing internals
- Auth layer
- Any unrelated UI refactor

## INVARIANTS_AT_RISK
- `branch_id` isolation (migration/query scope only)
- Runtime data integrity during migration rollout

## GATE_CONDITIONS
- No migration execution without human approval filled in gate brief
- Any scope expansion beyond SOURCE-FK path => halt and escalate

## Execution Branches by Gate Option

### Option 1 — Approve now (staging then prod)
1. Implement dedicated SOURCE-FK migration cycle with rollback script.
2. Run integrity checks pre/post migration.
3. Stage soak, then production rollout.
4. Final dual audit and close.

### Option 2 — Approve staging only (recommended risk-balanced path)
1. Execute SOURCE-FK migration in staging only.
2. Capture soak evidence window.
3. Hold prod pending explicit follow-up human GO.
4. Keep cycle in controlled gate/deferred-close state.

### Option 3 — Cancel migration
1. Keep logical checks only (no FK enforcement).
2. Document residual drift risk in audit.
3. Close with accepted residual-risk note.

## Validation Matrix (post-gate)

- DB integrity assertions around `source_ref` paths
- Existing parity suites (POS/Kiosk projection + runtime sentinels)
- No new regression in Studio/wizard/ops flag behavior

## Audit Status
- [x] Pending
- [x] PLAN_REVIEW_VERDICT: PASS
- [ ] AUDIT_VERDICT: PASS
- [ ] GPT_FINAL_AUDIT_VERDICT: PASS
- [ ] Passed — cycle closed
- [ ] Gate opened / resolved
