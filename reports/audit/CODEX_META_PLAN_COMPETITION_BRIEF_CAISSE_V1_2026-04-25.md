# CODEX META-PLAN COMPETITION BRIEF — CAISSE V1

Date: 2026-04-25  
Purpose: give Claude a strong adversarial starting point to produce a more robust “plan of plans” for Caisse V1.  
Scope: planning/audit only; no product code changes.

## 0. Codex Position

The existing master plan is good enough for Phase 0, but it is not yet the final “maximum intelligence” orchestration artifact. It should now be attacked and expanded by Claude into a plan-of-plans with traceability from every audit finding to every implementation task, test, gate, owner, dependency, rollback, and acceptance proof.

Current plan:

`plans/PLAN_CAISSE_V1_MEGA_CORRECTION_2026-04-25.md`

My current verdict:

`CODEX_META_VERDICT: PLAN_READY_BUT_CAN_BE_HARDENED_BY_PLAN_OF_PLANS`

## 1. What The Current Plan Already Gets Right

| Strength | Why it matters |
| --- | --- |
| Separates planning readiness from implementation readiness | Prevents premature product edits in frozen/payment/fiscal zones. |
| Keeps Phase 0 gates before code | Correct for fiscal, payment, migrations, frozen zones, and KDS authority. |
| Uses Claude’s sequence over Codex R1 | Security/revenue/branch P0 before broad domain refactor. |
| Preserves Codex concepts | `OrderQuote`, `PaymentProof`, `KitchenRelease`, `OrderIntent` remain useful mental models. |
| Provides 9 execution phases | Gives a coherent correction path from gates to go/no-go. |
| Includes sentinel matrix | Converts audit into executable proof. |
| Includes Codex/Claude protocol | Maintains `codex-extension` implementation and `claude-terminal` audit. |
| Avoids product code edits | Correct for current pre-cycle state. |

## 2. Weaknesses Claude Should Attack

These are deliberate self-critiques. Claude should validate, reject, or expand them.

| Weakness | Why it matters | Expected Claude improvement |
| --- | --- | --- |
| Plan is still too linear | Real implementation will need parallel lanes with dependency gates. | Convert phases into multiple plans/subplans with dependency graph. |
| Traceability from findings to tasks is incomplete | A finding can be lost during execution. | Create matrix: source report/finding -> risk -> phase -> TASK_ID -> tests -> gate. |
| Per-task steps are too high-level | Codex implementers need scoped steps and file boundaries. | Break every plan into tasks, every task into execution steps. |
| No explicit “do not touch” matrix per task | Frozen or unrelated areas may be accidentally edited. | Add allowlist/denylist per task group. |
| No rollback/canary strategy | Payment/fiscal/KDS changes need safe rollout. | Add feature flags, rollback points, staged deployment, canary branch. |
| Ops/runtime is not deep enough | Queue, scheduler, broadcast, workers, cache, TPE, printers need proof. | Create dedicated runtime readiness plan. |
| Fiscal plan is under-specified | Z, refunds, voids, kiosk fiscal policy, HMAC chain need exact proofs. | Create fiscal/reconciliation subplan with scenarios. |
| Payment plan depends too much on a gate | Need both option branches fully planned. | Produce Option A ledger plan and Option B restricted-pilot plan. |
| Branch isolation needs a complete surface map | Orders/transactions/KDS/fiscal/devices/channels may leak differently. | Create branch isolation threat model and test matrix. |
| Offline policy needs failure injection | Offline replay/duplicate/reconnect is hard to reason about statically. | Add offline chaos/replay scenarios. |
| Legacy cutover may miss hidden imports | Static grep is not enough if routes/views dynamically include files. | Add bundle/build/route-list/config scan plan. |
| KDS release contract may be too loose | “Formal rule” can drift unless encoded in tests or service API. | Define exact release predicate and test coverage. |
| Hardware smoke is too late | Hardware availability can invalidate scope. | Pull hardware proof into Phase 0/parallel Plan OPS-0. |
| No data migration dry-run plan | Quotes/payment/queue migrations can break production data. | Add schema migration dry-run/rehearsal plan. |
| No acceptance metrics | “PASS” needs measurable criteria. | Add success metrics per plan: fraud, leak, latency, test coverage, ops. |
| No reporting cadence | Long 6-10 week plan needs control loops. | Add daily/weekly artifacts and replan triggers. |
| No explicit risk of stale reports | Some referenced reports are future-dated 2026-04-26 relative to current date. | Claude should mark how to treat future-dated handoffs already present in repo. |

## 3. Proposed Plan-Of-Plans Structure For Claude To Improve

Claude should not be constrained to this, but this is the structure Codex recommends as a starting point.

| Plan ID | Name | Goal |
| --- | --- | --- |
| `PLAN-00` | Governance, Gates, Memory, Scope | Human decisions, Graphiti/fallback, active cycle discipline. |
| `PLAN-01` | Evidence Baseline And Finding Traceability | Map every report finding to task/test/gate. |
| `PLAN-02` | Direct P0 Security And Revenue Closure | payment-confirm, branch leaks, POS forged totals, offline unsafe methods. |
| `PLAN-03` | Pricing And OrderQuote Contract | Backend quote, taxes, discount, expiration, replay protection. |
| `PLAN-04A` | Payment Ledger Option A | Full/minimal ledger if V1 requires broad payment scope. |
| `PLAN-04B` | Restricted Payment Pilot Option B | Safer pilot if scope can restrict payment methods. |
| `PLAN-05` | Fiscal, Z, Refund, Void, Reconciliation | Kiosk/POS fiscal policy and audit proofs. |
| `PLAN-06` | Kiosk Runtime And Offline | Menu, price display, offline replay, admin PIN, machine binding. |
| `PLAN-07` | KDS Release, Concurrency, Realtime | expected_status, release predicate, branch-scoped realtime. |
| `PLAN-08` | Legacy Cutover And Compatibility | old POS, web/table, public payment, archives, feature flags. |
| `PLAN-09` | Runtime Operations | queue, scheduler, workers, broadcast, cache, logs, metrics. |
| `PLAN-10` | Test Architecture And Red-Team Matrix | sentinel, PHP, JS, Playwright, hardware, chaos. |
| `PLAN-11` | Migration And Data Safety | schema dry-runs, backfills, rollback, data reconciliation. |
| `PLAN-12` | Go-Live, Canary, Rollback, Post-Launch | staged rollout, monitoring, incident playbooks. |

## 4. Required Depth Per Subplan

Each subplan should include:

1. Objective.
2. Business risk.
3. Technical risk.
4. Invariants touched.
5. Source reports/finding references.
6. Files likely touched.
7. Files explicitly off-limits.
8. Gates required.
9. TASK_ID list.
10. Task steps.
11. Tests.
12. Rollback.
13. Observability.
14. Owner: Codex/human/Claude.
15. Audit prompt to run after the task.
16. Done criteria.
17. Rework trigger.

## 5. Specific Questions Claude Must Answer

1. Is the 9-phase plan enough, or should it be replaced by a multi-plan hierarchy?
2. Which findings are still not mapped to a task?
3. Which tasks in the plan are too broad and must be split?
4. Which gates are missing or merged too aggressively?
5. Which tests are missing for hidden/indirect paths?
6. Which plan should run first if Phase 0 gates take several days?
7. Can any no-code/test-only work proceed before gates?
8. What is the safest V1 payment option?
9. What is the safest kiosk fiscal option?
10. What runtime/ops evidence is mandatory before go-live?
11. What should be the canonical “ready for implementation” checklist?
12. What should be the canonical “ready for go-live” checklist?

## 6. Suggested Output From Claude

Claude should produce one master report:

`reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md`

And it should be strong enough to generate a future final plan:

`plans/PLAN_CAISSE_V1_SUPER_MASTER_CORRECTION_2026-04-25.md`

Claude should not modify product code. It may recommend additional plan files, but the initial output should be a single consolidated report.

## 7. Codex Challenge To Claude

Do not validate my plan unless it survives attack. Assume that:

- I under-specified at least 10 hidden risks.
- I merged at least 5 tasks that should be separated.
- I missed at least 5 no-code preparatory tasks that can happen before gates.
- I missed at least 5 tests around indirect surfaces.
- I did not fully map every source report finding.
- I did not go deep enough on runtime ops, fiscal, migration safety, and rollout.

Claude should find these gaps and produce a stronger orchestration.

`CODEX_TO_CLAUDE_CHALLENGE: BREAK_AND_REBUILD_THE_PLAN`

