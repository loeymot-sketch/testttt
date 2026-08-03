# Plan – CV1-SOURCE-FK-STAGING-MODEL-CORRECTION-001 – 2026-05-03

## TASK_ID
CV1-SOURCE-FK-STAGING-MODEL-CORRECTION-001

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
| `database/migrations` | add typed source columns (non-breaking) | Write | No | No |
| `app/Http/Requests/Composer*` | typed-source validation additions | Write | No | No |
| `app/Services/Composer/*` | dual-write adapter | Write | No | No |
| `tests/Feature/Composer/*` | staging integrity and backfill tests | Write | No | No |
| `reports/execution/` | staging evidence report | Write | No | No |

## SUBSYSTEMS_OFF_LIMITS
- Pricing services
- Auth
- OrderService/FrontendOrderService
- Production data migration execution

## INVARIANTS_AT_RISK
- Data integrity only (composer source references)
- No pricing/branch/dispatch invariants touched directly

## GATE_CONDITIONS
- No destructive migration in this cycle.
- If canonical FK target table for extra_group/addon is ambiguous -> stop and escalate.

## Execution Steps
1. Add non-breaking columns for typed source references.
2. Implement adapter: resolve `source_ref` into typed columns where deterministic.
3. Add tests for mixed legacy values + typed values co-existence.
4. Produce staging soak checklist and expected metrics.
5. Do not remove `source_ref` in this cycle.

## Audit Status
- [x] Pending
- [x] PLAN_REVIEW_VERDICT: PASS
- [ ] AUDIT_VERDICT: PASS
- [ ] GPT_FINAL_AUDIT_VERDICT: PASS
- [ ] Passed — cycle closed
