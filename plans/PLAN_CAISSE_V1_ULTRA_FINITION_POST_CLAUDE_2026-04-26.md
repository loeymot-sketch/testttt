# PLAN_CAISSE_V1_ULTRA_FINITION_POST_CLAUDE_2026-04-26

Status: PLAN_ONLY_AWAITING_HUMAN_VALIDATION
Source audit: Claude ultra-plan POS post-audit profond 2026-04-26, pasted by human.
Local evidence integrated:
- `reports/audit/CODEX_BRIEF_EXECUTION_NOW_2026-04-26.md`
- `reports/audit/GPT_SELF_AUDIT_CV1-BRIEF-AFTER-CLAUDE-ULTRA-REVIEW-V2.md`
- `plans/PLAN_CAISSE_V1_W1W2_REWORK_AFTER_GLOBAL_TESTS_2026-04-26.md`
- `plans/masterplay/MASTERPLAY_QUEUE.md`
- `.cursor/ACTIVE_CYCLE.md`

## Executive Decision

This plan converts Claude's audit into a bounded implementation stack. It does not start implementation.

`MASTERPLAY_FROZEN=1` remains authoritative until Phase A is signed. No B/C/D/E/F/G product task may start before Phase A is closed or explicitly overridden by a human gate.

The plan is intentionally split into phase files under `plans/caisse-v1-ultra-finition/` so each future run can become one `TASK_ID`, one allowlist, one validation suite, one GPT self-audit, then Claude/GPT final review if the project mode requires it.

## Non-Negotiables

- Backend pricing is the only source of truth.
- No frontend authoritative total or price logic.
- `OrderStatus` enum only; no magic status literals.
- `branch_id` isolation must hold across POS, KDS, kiosk, fiscal, payment, transaction, floorplan, and reports.
- Dispatch and broadcast after commit only.
- Frozen zones require an approved gate in `docs/gates/GATE_LOG.md`.
- Option B payment ledger remains in force; `CV1-M04A-PAYMENT-LEDGER-FULL` is excluded.
- No migration runs without schema/M-13 human gate.
- One task = one allowlist. No mixed patches.

## Current Hard Blockers

| Blocker | Evidence | Effect |
| --- | --- | --- |
| Phase A unsigned | `.cursor/ACTIVE_CYCLE.md` still has W10 and Caisse V1 active; no signed A.1-A.6 packet | B+ tasks blocked |
| Dirty worktree governance | `111` modified tracked + `664` full-file untracked after latest brief run | no reliable close/release claim |
| Global tests red | PHPUnit 44 failed; Vitest 6 failed; Playwright 35 passed | implementation needed after governance |
| Queue-number unique requires gate | schema migration required | B.6/C schema work blocked |
| Legacy bundle cutover unresolved | `HG-W2_CUTOVER_DECISION_OR_POS_WIZARD_SHIM_ACCEPTANCE` not closed | release hold |

## Phase Stack

| Phase | File | Start Condition | Exit Condition |
| --- | --- | --- | --- |
| A | `PHASE_A_GOVERNANCE_2026-04-26.md` | now | human signed, dirty worktree classified, single active primary decided |
| B | `PHASE_B_TEST_STABILIZATION_2026-04-26.md` | Phase A closed | PHPUnit/Vitest red families corrected or gated |
| C | `PHASE_C_BACKEND_SECURITY_2026-04-26.md` | B.1-B.5 closed; schema gates for migration items | backend invariant fixes closed |
| D | `PHASE_D_POS_FRONTEND_REFACTOR_2026-04-26.md` | C.1, C.2, C.4, C.5 stable | POS monolith reduced safely, no pricing drift |
| E | `PHASE_E_CATALOG_POS_LINK_2026-04-26.md` | C.6 + B.2 closed | POS catalogue gaps closed or V1.5 backlog |
| F | `PHASE_F_SYNC_RESILIENCE_2026-04-26.md` | B.2 + C closed | KDS/OSS realtime and dedup improvements verified |
| G | `PHASE_G_CLOSURE_PROOFS_2026-04-26.md` | A-F closed | release decision packet ready for human GO/HOLD/NO-GO |

## Strict Execution DAG

```text
A.1 A.2 A.3 A.4 A.5 A.6
  -> B.1 -> B.2 -> B.3 -> B.4 -> B.5
       -> B.6 only after schema gate
  -> C.1 -> C.2 -> C.3 -> C.4 -> C.5 -> C.6 -> C.7
  -> D.1 -> D.2 -> D.3 -> D.4 -> D.5 -> D.6
  -> E.1 -> E.2 -> E.3 -> E.4 -> E.5 -> E.6
  -> F.1 -> F.2 -> F.3 -> F.4
  -> G.1 -> G.2 -> G.3 -> G.4 -> G.5 -> G.6 -> G.7
```

Allowed parallelism after the blocking condition is satisfied:

- E.1, E.2, E.4 may prepare after D.1 interface boundaries are stable.
- F.1 and F.2 may prepare once B.2 proves KDS visibility and K09B payload contract.
- Documentation/report-only G tasks may draft early, but no proof can be marked PASS before A-F close.

Forbidden parallelism:

- B.4 before C.2 design is frozen; quote tests must not lock in silent re-quote behavior.
- D.1 before C.5 decision; the frontend split must not carry two pricing paths.
- B.6 before schema/M-13 gate.
- D.6 before human cutover decision.

## Implementation Protocol For Each Future Task

1. Create or refresh `missions/<TASK_ID>/input.json` and `execute_brief.md` from `TASK_REGISTRY_2026-04-26.md`.
2. Verify `status` is not blocked and dependencies are closed.
3. Run activity log start with exactly the allowlist files.
4. Run plan review if required by `run-cycle`.
5. Execute with `codex-extension`; no Claude or sub-agent unless human explicitly changes mode.
6. Run mandatory tests only plus the minimal nearby regression set.
7. Write `missions/<TASK_ID>/output_codex.json`.
8. Write `reports/audit/GPT_SELF_AUDIT_<TASK_ID>.md`.
9. Record `EXECUTE_DELEGATION: codex-extension`.
10. Do not close without validation and audit protocol required by the current operating mode.

## Phase Validation Rule

A phase is closed only when every task in that phase is one of:

- `CLOSED_PASS`
- `BLOCKED_HUMAN_GATE` with exact gate path and no product workaround
- `DEFERRED_V15` with explicit V1 non-blocking rationale

No phase can be closed with `UNKNOWN`, `TODO`, or `ASSUMED`.

## Generated Plan Files

The complete plan stack is listed and verified in:

- `plans/caisse-v1-ultra-finition/README.md`
- `plans/caisse-v1-ultra-finition/TASK_REGISTRY_2026-04-26.md`
- `reports/audit/CAISSE_V1_ULTRA_PLAN_VERIFICATION_2026-04-26.md`

## Human Validation Needed Before Implementation

Validate this plan with one explicit instruction such as:

`GO PLAN_CAISSE_V1_ULTRA_FINITION_POST_CLAUDE_2026-04-26 Phase A only`

Until then: no implementation wave starts.
