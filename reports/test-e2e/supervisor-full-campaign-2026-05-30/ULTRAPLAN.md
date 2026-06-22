# ULTRAPLAN — Supervisor Full-Project Test-E2E Campaign (2026-05-30)

> Owner mandate (verbatim spirit): *act as supervisor/director/developer/brain.
> Test the WHOLE project to the maximum. POS ≥100 distinct orders, abuse-style.
> Same for Kiosk → flow → screenshot analyzed → kitchen screen → management →
> history → synchronization. Main reasoning agents analyze each screenshot +
> adversarial agents dispute for robustness. Take BOTH the client seat and the
> cook seat — psychologically, visually, technically. Loop+heal until genuinely
> production-perfect. Auto-audit at the end (no manual re-ask). If weak points
> found, heal them — staying in V1 LOCAL scope, no useless complexity. Then give
> a ship verdict so owner can run only the cloud `/code-review ultra` pass.*

**Scope guard**: V1 LOCAL Le Cayenne (single box, branch_id=1, FR). No SaaS/cloud
preconditions surfaced as blockers. No useless complexity.

**Architecture decision (advisor-confirmed)**:
- MAIN LOOP owns the single shared Playwright browser → all visual capture serial.
- Fan out ONLY read-only work (code audit, DB/log analysis, fiscal reasoning,
  adversarial dispute) to sub-agents / Workflow.
- "100 commandes" = scripted VOLUME (`foodking:e2e:stress`, `kiosk:simulate-orders`)
  + invariant verification, PLUS ~6–10 curated deep visual flows (client + cook).
  Report states plainly which orders were scripted vs visually-analyzed. NO fake
  "100 screenshots analyzed".

**Hard guardrails**:
- Frozen zones (pos-wizard.js, kiosk wizard, Fiscal/ZReport/AuditLog, BranchScope,
  IdempotencyKeyMiddleware, PricingService, OrderStateMachine) → if a real issue
  lands here, SURFACE + recommend `/lock-plan`. NEVER silent patch.
- Every adversarial finding passes `verify-before-report` (file:line + reproduction)
  before any heal. Documented #1 failure mode = hallucinated P0s.
- 3-loop max PER problem → escalate that one. Loop freely across different problems.
- Don't re-litigate documented dormant items as new V1 blockers: O-1 worker-death
  silent-degrade-60s (monitored), F1 TVA/HT 0%-VAT, O-2/O-3 P2, any cloud precond.
- Checkpoint-commit per wave. Update BRAIN per wave. This doc = resume anchor.

**Environment confirmed (Wave 0 pre)**:
- Server 200 on login/kiosk/kds · 45 items · 1 branch · queue:work redis running
- WS: soketi LISTEN on 127.0.0.1:6001 (real WS push available, not just polling)
- Accounts: admin@lecayenne.fr (b0), pos@lecayenne.fr (b1), chef@lecayenne.fr (b1, KDS)
- POS_SIMULATION_HARDWARE=true (dev-acceptable, NF525 bypass HARDWARE only)

---

## WAVE TRACKER

| Wave | Title | Status | Evidence / Notes |
|------|-------|--------|------------------|
| W0 | Baseline gates re-confirm | ⏳ | vitest / PHP / NF525 CHAIN / frozen diff / sentinels |
| W1 | Volume stress (≥100 orders) + invariant verify | ⏳ | fiscal chain gap-free, pricing SSOT, outbox monotonic |
| W2 | POS visual E2E (client+cook personas, abuse orders) | ⏳ | screenshots analyzed |
| W3 | Kiosk visual E2E → KDS handoff (chef acct) | ⏳ | sync latency measured |
| W4 | KDS visual E2E (accept/bump/recall/ready) | ⏳ | live state transitions |
| W5 | OSS + management + history + sync cross-surface | ⏳ | sync at each handoff |
| W6 | Adversarial dispute (parallel RED army, read-only) | ⏳ | verify-before-report applied |
| W7 | Auto-audit synthesis + heal loop (≤3/problem) | ⏳ | heals scope-minimal |
| W8 | Final convergence + SHIP VERDICT | ⏳ | GREEN/YELLOW/RED + counts |

Legend: ⏳ pending · 🔄 in progress · ✅ done · 🟡 done-with-notes · 🛑 blocked/escalate

---

## FINDINGS LEDGER (append-only; verify-before-report gated)

(none yet)

---

## HEAL LEDGER (append-only)

(none yet)

---

## VERDICT

(pending — written at W8)
