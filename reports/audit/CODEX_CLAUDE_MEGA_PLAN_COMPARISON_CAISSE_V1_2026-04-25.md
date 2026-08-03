# CODEX / CLAUDE COMPARISON — MEGA PLAN CAISSE V1

Date: 2026-04-25  
Scope: audit arbitration, no product code change  
Primary plan: `plans/PLAN_CAISSE_V1_MEGA_CORRECTION_2026-04-25.md`

## 0. Executive Verdict

`COMPARISON_VERDICT: MERGE_WITH_CLAUDE_PHASE_ORDER`

Codex and Claude converge on the same final readiness decision: the project is ready for a mega plan, not ready for product implementation until human gates are signed.

The main disagreement is sequencing. Codex emphasized the domain primitives first: `OrderIntent`, `OrderQuote`, `PaymentProof/PaymentLedger`, `KitchenRelease`. Claude accepts those primitives as useful but contests starting implementation there. Claude’s order is safer for V1: close direct security/revenue/branch P0 first, then implement targeted contracts.

Final arbitration: use Codex’s conceptual map, Claude’s execution order.

## 1. Source Files Compared

| Source | Role |
| --- | --- |
| `reports/audit/MEGA_DISPUTE_CODEX_R1_CAISSE_POS_KIOSK_KDS_2026-04-25.md` | Codex R1 architecture and risks. |
| `reports/audit/MEGA_RAPPORT_FINAL_DISPUTE_CAISSE_POS_KIOSK_KDS_2026-04-25.md` | Codex consolidation and dispute report. |
| `reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md` | Codex readiness/gap diagnosis. |
| `reports/audit/CLAUDE_MAX_ORCHESTRATION_CAISSE_V1_2026-04-25.md` | Claude terminal Opus 4.7 max orchestration. |
| `plans/PLAN_CAISSE_V1_MEGA_CORRECTION_2026-04-25.md` | Merged executable master plan. |

## 2. Dispute Table

| Theme | Codex | Claude | Final Arbitration |
| --- | --- | --- | --- |
| Readiness | Ready to plan, blocked before execution by gates. | Same. | Accepted. |
| System diagnosis | Lifecycle compressed into status/payment fields; primitives needed. | Accepted as diagnosis. | Keep primitives as conceptual map. |
| Execution order | Domain contracts first, then direct fixes. | Security/revenue/branch P0 first, then contracts. | Claude order wins. |
| OrderIntent | Central primitive across POS/kiosk/web/table. | Useful but full centralization too heavy for V1. | Contract tests now, centralization later. |
| OrderQuote | Backend signed quote required. | P0 mandatory. | Phase 3. |
| PaymentLedger | Full ledger or explicit scoped restriction. | Human split; recommends restricted pilot if mono-branch. | Gate decides; default restricted pilot. |
| KitchenRelease | Explicit release/event primitive. | Formal rule may be enough for V1. | Phase 5 formalizes rule; event only if scope demands. |
| payment-confirm | Must be hardened. | P0 immediate. | Phase 2. |
| branch_id | Must be exact everywhere. | P0 immediate. | Phase 2. |
| Kiosk fiscal | Needs hard decision. | P0 human gate. | Phase 0 gate blocks paid kiosk. |
| Offline CB/TR | Unsafe without ledger. | Disable by default for V1. | Phase 2/6 disable unless gate says otherwise. |
| KDS expected_status | Required. | Required, but rollout with flag. | Phase 5. |
| Legacy paths | Must be quarantined. | P0 process / Phase 7. | Phase 7 plus guards. |
| Graphiti | Not exposed in current session. | Must be used if available in official cycle. | Phase 0 requires Graphiti read or documented fallback. |
| Tests | Sentinels required. | Sentinels accepted, then targeted fixes. | Phase 1. |

## 3. What Codex Adds That Must Not Be Lost

| Contribution | Why it matters | Where preserved |
| --- | --- | --- |
| Domain primitive map | Prevents patch-only thinking and clarifies lifecycle. | Plan sections 2, 4, 7. |
| Readiness gap analysis | Separates planning readiness from implementation readiness. | Plan sections 0, 5, 17. |
| Hidden surface coverage | Kiosk legacy, web/table, scheduler, cache, fiscal, outbox. | Plan sections 6, 7, 12. |
| Single-file audit consolidation | Useful for handoff and future agents. | File index and prior context. |
| Strong “no execution before gates” stance | Protects frozen zones and fiscal/payment decisions. | Plan sections 0, 5, 17. |

## 4. What Claude Adds That Must Govern Execution

| Contribution | Why it matters | Where preserved |
| --- | --- | --- |
| Security-first phase order | Closes immediate fraud/leak risk before abstraction. | Phase 2 before Phase 3/4/5. |
| Restricted pilot recommendation | Avoids overbuilding full ledger if V1 can be scoped. | Gate `GATE_PAYMENT_LEDGER_V1`. |
| Gate list tightened to seven blockers | Gives human decision surface. | Plan section 5. |
| Detailed P0/P1/P2 matrix | Enables bounded `TASK_ID` cycles. | Plan section 6. |
| Concrete sentinel matrix | Converts audit into tests. | Plan section 8. |
| Overbuild/underbuild risk split | Prevents both architecture paralysis and unsafe patches. | Plan sections 13 and 14. |

## 5. Final Merged Doctrine

1. Do not implement product code until Phase 0 gates are signed.
2. Do not start with a full domain rewrite.
3. Start with sentinels and direct P0 security/revenue/branch fixes.
4. Add `OrderQuote` before real POS payment flow is considered safe.
5. Gate payment scope before ledger work.
6. Gate kiosk fiscal policy before paid kiosk autonomy.
7. Gate KDS bump before forcing expected status across clients.
8. Use Codex for bounded implementation, not final arbitration.
9. Use Claude terminal for audit and REWORK decisions.
10. Keep Graphiti/memory discipline if available; otherwise record fallback.

## 6. Final Verdict

`CODEX_CLAUDE_FINAL_ARBITRATION: CODEX_CONCEPTS_CLAUDE_SEQUENCE`

`READY_FOR_PHASE_0: YES`

`READY_FOR_PRODUCT_CODE: NO`

