---

## Audit — HG-W2-3 (KPI Revision) + HG-W2-1 (Cutover) — 2026-04-26

---

**A. STRUCTURE**
**PASS** — Both briefs carry all required sections: Trigger / Affected Subsystems / Invariants at Risk / Decision Required / Options / Approval block / Resumption Protocol. Both add a non-spec "Approved with constraint" checkbox; the spec shows a minimum, not a ceiling — no violation.

---

**B. SELF-APPROVAL**
**PASS** — Approval blocks are blank in both. HG-W2-1's "Recommendation (cursor-claude)" section is explicitly flagged "non-binding" with the human-authoritative disclaimer; this is compliant with the prohibition. HG-W2-3 labels options internally ("recommended / NOT recommended") but leaves the Approval block open; no pre-decision.

---

**C. OPTION REALISM**
**FIX** — HG-W2-3: all 5 options are real and differentiated with costs, dependencies, and a cancel path (Option E). HG-W2-1: Decision Required asks for a quantified rollback trigger ("5xx rate > X %, LCP p95 > Y s") but none of the 6 options define those thresholds — Option D merely says "rollback is a 1-line revert." Cancel is present (Option F). The missing rollback-signal definition is a gap the human cannot fill from the option text alone.

---

**D. INVARIANT COVERAGE**
**PASS** — HG-W2-3 correctly declares "None" (KPI is a measurement contract, not implementation) and explicitly flags Option C's removal of Echo/Pusher as triggering an additional synchronization gate — handled correctly, not silently passed. HG-W2-1 covers branch_id (Option C read path), frozen zones (master.blade.php "verify before modification"), and correctly omits invariants 2/4 (no order-status or dispatch logic touched). Minor: master.blade.php frozen status is "verify" rather than confirmed — defensible but leaves an open question.

---

**E. DEPENDENCY GRAPH**
**PASS** — HG-W2-3 header explicitly blocks HG-W2-2 and HG-W2-1. HG-W2-1 header soft-blocks on HG-W2-3 cleared + LCP campaign; Options B/C/D each restate the pre-requisite. GATE_LOG.md Trail courant matches: HG-W2-1 = soft-blocked, HG-W2-2 = BLOCKED on HG-W2-3, HG-W2-3 = PENDING. Chain is consistent.

---

**F. SCOPE**
**PASS** — HG-W2-3 explicitly states "no source file is modified by this gate." HG-W2-1's "eventually allows deleting legacy app.js" is a noted future possibility, not authorized by this gate. Resumption Protocol for HG-W2-1 creates a new bounded cycle (B/C/D) or closes with no code change (A/E/F) — no implicit scope drag.

---

**G. RESUMPTION PROTOCOL**
**FIX** — Spec §Resumption Protocol step 3 requires "Claude reads the cleared gate brief and **updates the plan file** to reflect the resolution." HG-W2-3 substitutes "updates the ADR"; HG-W2-1 substitutes "opens a new bounded cycle." Both are contextually reasonable but deviate from the literal spec. A plan file update (or explicit note that no plan file exists for this gate) must be added.

---

**FINAL VERDICT: NEEDS-FIX — 8/10**

Two targeted fixes required before these briefs are production-compliant:
1. **HG-W2-1 C**: add quantified rollback thresholds (observable signals) to at least Options B/C/D.
2. **Both G**: align Resumption Protocol step 3 with the spec's "plan file" wording, or explicitly declare a documented substitute and cite why.
**Item C — HG-W2-1 Option rollback thresholds**: PASS
All five default thresholds are defined in § Decision Required (error rate 2%/15 min, LCP p95 2.5 s/30 min, 401 rate 0.5%/15 min, 3 operator incidents/24 h, WebSocket +50%/1 h); Options B, C, D each carry a scoped "Rollback triggers for this option" subsection citing the relevant subset with per-option tightening. Quantification is present and option-specific.

**Item G (HG-W2-3) — Plan-file substitute**: PASS
Step 3 now carries an explicit "Plan-file substitute declaration" requiring BOTH the ADR update AND an active `plans/PLAN_POS_V4_*.md` append (`KPI_DECISION:` line), with rationale for why the ADR acts as the architectural record while the plan file remains the cycle SSOT. The substitution is declared and justified.

**Item G (HG-W2-1) — Plan-file resolution**: PASS
Step 3 retains "updates the plan file" verbatim, then the "Plan-file resolution per chosen option" section defines concretely what that means per option group (B/C/D → new bounded cycle plan opened + active plan annotated; A/E/F → active plan annotated only). No unjustified substitution; the spec requirement is fully honoured.

---

**VERDICT: PASS — 10/10.** Both previously flagged items are resolved with quantified thresholds and explicit, justified plan-file compliance.
