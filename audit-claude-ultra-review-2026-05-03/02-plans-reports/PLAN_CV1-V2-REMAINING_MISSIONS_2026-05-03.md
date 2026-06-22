# Plan – CV1-V2-REMAINING-MISSIONS-001 – 2026-05-03

## TASK_ID
CV1-V2-REMAINING-MISSIONS-001

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
| `docs/gates/` | SOURCE-FK decision trace | Write | No | No |
| `plans/` | Remaining missions sequencing | Write | No | No |
| `config/catalog_v15.php` + env rollout docs | OPS flag rollout readiness | Write | No | No |
| `resources/js/services/*SyncService.js` | Polling fallback rollout verification | Read/Write | No | Yes (runtime events only) |
| `resources/js/components/admin/items/wizard/*` | XL runtime refactor prep | Write | No | No |
| `tests/Feature/Menu` + `tests/js` + `tests/e2e` | Sentinel + parity + rollout evidence | Write | Potentially (test assertions only) | No |

## SUBSYSTEMS_OFF_LIMITS
- Pricing engine internals (SSOT untouched)
- Auth middleware/guards/tokens
- Schema migration execution before SOURCE-FK human approval
- Frozen zones without explicit gate clearance

## INVARIANTS_AT_RISK
- `branch_id` isolation — at risk only in parity/rollout checks; must remain strict.
- Dispatch-after-commit — no new dispatch logic allowed in this plan.
- Backend pricing SSOT — no frontend pricing derivation introduced.

## GATE_CONDITIONS
- SOURCE-FK migration requires explicit human approval in gate brief.
- Any discovered need to touch auth/schema/frozen zones outside declared scope => immediate halt/escalation.
- Two consecutive validation failures => stop autonomous retries.

## Execution Steps
1. **SSOT hygiene (P0 procedural):** reconcile `ACTIVE_CYCLE.md` with reality (single authoritative state) and align plan/report pointers.
2. **Gate handling (P0 functional):** obtain human decision on `GATE_CV1-WC-T-WC-SOURCE-FK-01_2026-05-03.md`; no migration work before approval.
3. **OPS rollout readiness (P0):**
   - finalize staging/prod checklist for `T-OPS-POS-POLLING-01` and `T-OPS-POS-WIZARD-COMPOSER-01`;
   - verify runtime flags and rollback path;
   - produce rollout evidence report.
4. **Wizard runtime refactor (P1):**
   - execute `V2-WIZARD-RT-REFACTOR-XL` in bounded sub-batches;
   - keep parity sentinels green after each sub-batch.
5. **Dashboard cleanup phase 2 (P2):**
   - remove only dead/duplicated elements with explicit candidate list;
   - validate no regression on POS/Kiosk/KDS surfaces.
6. **Mega-audit closeout:** produce final global audit with residual risk list and PASS/REWORK decision.

## SUBTASKS
| SUBTASK_ID | Description | Difficulty | Owner (planned) | Invariants at risk | Mini-audit policy | Status | Retry |
|---|---|---|---|---|---|---|---|
| CV1-V2-REMAINING-MISSIONS-001-S01 | SSOT cycle-state reconciliation | small | cursor-session | None | 1:1 | DONE | 0 |
| CV1-V2-REMAINING-MISSIONS-001-S02 | SOURCE-FK gate decision and logging | complex | cursor-session | `branch_id` (schema scope) | 1:1 | DONE | 0 |
| CV1-V2-REMAINING-MISSIONS-001-S03 | OPS rollout readiness batch | complex | codex-extension | `branch_id` | 1:1 | DONE | 0 |
| CV1-V2-REMAINING-MISSIONS-001-S04 | Wizard runtime refactor XL (part A/B/C) | complex | codex-extension | None | 1:1 | DONE | 0 |
| CV1-V2-REMAINING-MISSIONS-001-S05 | Dashboard cleanup phase 2 | small | foodking-routine-implementer | None | batch-eligible | DONE | 0 |
| CV1-V2-REMAINING-MISSIONS-001-S06 | Mega-audit + residual risk matrix | complex | cursor-session | None | 1:1 | DONE | 0 |

### Batching rule (mini-audit Claude)
- Any subtask touching runtime behavior across surfaces => mandatory 1:1 mini-audit.
- Cleanup-only subtasks with no invariant touch => batch-eligible by 2 max.

## SYMMETRY_NOTE
N/A (OrderService/FrontendOrderService not in declared touch list).

## SCOPE_PRESSURE
[To populate only if scope expansion is discovered at execution time]

## ESCALATION
[To populate only on gate or invariant conflict]

## Execution Log (live)
- 2026-05-03 — S01 done: SSOT cycle-state reconciled in `ACTIVE_CYCLE.md` (task/plan/report pointers aligned to current batch).
- 2026-05-03 — S03 done (readiness): OPS rollout runbook produced in `reports/execution/RUN_T_OPS_ROLLOUT_READINESS_2026-05-03.md` with staging/prod/rollback steps and fresh validation evidence.
- 2026-05-03 — S02 pending: SOURCE-FK gate still waiting for human approval.
- 2026-05-03 — S04 batch A done: baseline + split strategy documented in `reports/execution/RUN_V2_WIZARD_RT_REFACTOR_XL_BATCH_A_2026-05-03.md`.
- 2026-05-03 — S04 batch B done: internal extraction (helper visibility), sentinel-safe and green (`reports/execution/RUN_V2_WIZARD_RT_REFACTOR_XL_BATCH_B_2026-05-03.md`).
- 2026-05-03 — S04 batch C done: adapter seam + malformed payload guards (`reports/execution/RUN_V2_WIZARD_RT_REFACTOR_XL_BATCH_C_2026-05-03.md`), sentinels `13/13` PASS.
- 2026-05-03 — S05 done: dashboard/nav cleanup phase 2 (non-destructive hide of delivery/online/table-service modules) with `29/29` sentinel pass (`reports/execution/RUN_CV1_DASHBOARD_CLEANUP_2_2026-05-03.md`).
- 2026-05-03 — S06 done: mega-audit consolidated in `reports/audit/MEGA_AUDIT_V2_REMAINING_MISSIONS_2026-05-03.md`.
- 2026-05-03 — S02 done: gate approved Option 2 (staging-only) in `docs/gates/GATE_CV1-WC-T-WC-SOURCE-FK-01_2026-05-03.md` + `docs/gates/GATE_LOG.md`.
- Next: post-gate execution plan `plans/PLAN_POST_GATE_SOURCE_FK_AND_FINAL_CLOSE_2026-05-03.md` (staging migration path + soak evidence).

## Audit Status
- [x] Pending
- [x] PLAN_REVIEW_VERDICT: PASS
- [ ] AUDIT_VERDICT: PASS
- [ ] GPT_FINAL_AUDIT_VERDICT: PASS
- [ ] Passed — cycle closed
- [ ] Gate opened — `docs/gates/GATE_[TASK_ID]_[DATE].md`
