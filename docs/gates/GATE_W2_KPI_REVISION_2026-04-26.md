# Gate Brief — HG-W2-3 — KPI revision for POS V4 first-paint — 2026-04-26

**Cycle source**: POS_V4_W2_DEDICATED_ENTRY (W2 #1, parent ADR `docs/design/ADR_POS_V4_DEDICATED_ENTRY.md`)
**Status**: PENDING_HUMAN_GATE
**Author**: cursor-claude (per `.cursor/rules/human-gates.mdc` — Claude only)
**Type**: Soft gate (planning ambiguity — original KPI is architecturally infeasible; needs revision before further W2 work proceeds)
**Blocking**: HG-W2-2 (vendor split authorization) and HG-W2-1 (cutover strategy) both depend on this decision.

---

## Trigger

The original W1/W2 acceptance criterion **POS first-paint ≤ 220 KB gzipped** — inherited verbatim from the POS V4 design package — was identified as **architecturally infeasible** during the W2 #1 audit (`reports/audit/AUDIT_W2_DEDICATED_ENTRY_CLAUDE_2026-04-26.md` § E Q2).

Concrete evidence:
- After W1-A (POS code split), W1-B (vendor chunking), W1-C (lazy admin routes), W2 #1 (dedicated entry), the POS first-paint stands at **652 KB gz** (-17.6 % vs the 791 KB legacy baseline, but still ~3× the 220 KB target).
- Reaching 220 KB requires dropping `laravel-echo` + `pusher-js` from the bundle. These libraries are **the transport for live KDS sync**, real-time order updates, and operator presence — removing them breaks core POS functionality.
- Even with maximum vendor splitting (W2 #2 `vendor-pos.js` proposal), the floor is approximately **520 KB gz** because Vue + Vuex + Vue Router + axios + Echo + Pusher + DOMPurify + i18n alone weigh ~280 KB gz, and `pos-app.js` itself contributes 318 KB gz of POS-only application logic.

A bundle-size proxy (KB gz) is also not the right contract: real perceived performance is measured by **LCP** (Largest Contentful Paint) and **TTI** (Time to Interactive) on actual POS terminal hardware. No real-device measurement exists today; all our numbers are theoretical bundle weights.

The cycle cannot reasonably authorize further investment (vendor split, additional refactors, cutover decisions) while the target itself is unachievable.

---

## Affected Subsystems

- **Frontend bundle topology**: `webpack.mix.js`, `resources/js/pos-app.js`, `resources/js/app.js`, `resources/js/shared/axios-setup.js` — any subsequent split decisions ride on the chosen KPI.
- **POS UX contract**: `resources/views/admin-pos-v4.blade.php` and the `PosComponent` lifecycle — LCP/TTI targets dictate whether further lazy-loading is needed inside POS itself (e.g., deferring `bootstrap.js` Echo init until after first paint).
- **W2 backlog**: HG-W2-2 (`vendor-pos.js` split) and HG-W2-1 (cutover) both reference whichever KPI this gate sets.
- **Product / brand commitments**: any external commitment (sales decks, customer-facing docs) referencing "<1.2 s LCP" or "220 KB" must be updated to match the revised contract.

Read-only here: no source file is modified by this gate. Only the **target metric** is changed.

---

## Invariants at Risk

None. Pricing SSOT, OrderStatus enum, branch_id isolation, dispatch ordering, OrderService/FrontendOrderService symmetry, frozen zones — all unaffected by a KPI revision (the target is a measurement contract, not implementation).

The only "invariant-adjacent" concern: if Option C below (preserve 220 KB) were chosen, removing real-time would weaken the synchronization invariant (`docs/orchestration/MEMORY_MATRIX.md` and store B episodes). That option therefore **does** carry invariant risk and is documented as such.

---

## Decision Required

**Which performance contract should govern the POS V4 surface from W2 #2 onward?**

The decision must produce:
1. A **bundle-size ceiling** (gzipped) that CI can assert.
2. A **real-device LCP/TTI target** (with the device profile defined: e.g., Chrome on a fanless industrial PC, 4G fallback) that QA can validate.
3. An **acceptance protocol** stating which measurement (bundle size proxy OR real-device LCP) is the authoritative pass/fail signal.

---

## Options

### Option A — Pragmatic baseline (recommended by Claude audit)

| Metric | Target |
|--------|--------|
| POS first-paint gz ceiling | **≤ 600 KB** (current 652 KB → -52 KB via deferred Echo init in W2 #3) |
| LCP on Wi-Fi (Chrome, fanless industrial PC) | **< 1.5 s** |
| TTI on Wi-Fi (same profile) | **< 2.0 s** |
| Authoritative signal | LCP/TTI (bundle size = secondary CI guard) |

**Action consequence**:
- Authorizes W2 #3 (CI guards + Echo deferred init) to chase the 600 KB ceiling.
- Does **not** authorize W2 #2 vendor split — current 652 KB is within striking distance of 600 KB without the split.
- Cutover (HG-W2-1) becomes possible after one real-device LCP measurement campaign confirms < 1.5 s.

**Investment**: ~1 cycle (W2 #3) + 1 LCP measurement session.

### Option B — Stretch: vendor split + tighter LCP

| Metric | Target |
|--------|--------|
| POS first-paint gz ceiling | **≤ 520 KB** (after W2 #2 `vendor-pos.js` split) |
| LCP on Wi-Fi | **< 1.2 s** |
| TTI on Wi-Fi | **< 1.6 s** |
| Authoritative signal | LCP/TTI |

**Action consequence**:
- Authorizes both W2 #2 (vendor split) AND W2 #3 (CI guards + Echo deferred init).
- Higher engineering cost (~2-3 cycles) and ongoing maintenance (two vendor chunks to keep in sync with two entries).
- Closer to the original design spirit (sub-1.2 s LCP).

**Investment**: ~3 cycles total + LCP measurement.

### Option C — Preserve original 220 KB target (NOT recommended)

| Metric | Target |
|--------|--------|
| POS first-paint gz ceiling | **≤ 220 KB** (forces removal of `laravel-echo` + `pusher-js` + possibly `vue3-apexcharts`) |
| Real-time sync | **REPLACED** by adaptive polling (3-10 s interval) |
| LCP on Wi-Fi | < 1.0 s (likely achievable after real-time removal) |

**Action consequence — INVARIANT IMPACT**:
- Removes the WebSocket/Echo path that backs KDS live sync, OSS live order list, and operator presence indicators.
- Falls back to polling — increases server load (~20-100×), increases p95 latency (3-10 s vs <1 s), regresses operator UX.
- Triggers an **additional** invariant gate (Synchronization invariant — see `memory/INDEX.md` store B episodes on real-time sync).
- Likely violates synchronization commitments documented in past cycles (`docs/orchestration/MEGA_AUDIT_SYSTEM_OPERATION_AGENTIC_2026-04-23.md`).

**Investment**: ~2 cycles to refactor sync layer + retraining ops; high regression risk.

**Recommendation**: only consider if Product explicitly chooses lower interactivity over real-time fidelity.

### Option D — Defer; collect 2 weeks of real-device LCP first

Do not set any new KPI yet. Run W2 #1's `/admin/pos-v4` POC against 2-3 real POS terminals (1 branch volunteer), collect LCP/TTI data daily for 2 weeks via Lighthouse + the existing `MetricsBatcher` pipeline, then return to this gate with empirical data.

**Action consequence**:
- Zero engineering investment in the meantime.
- HG-W2-1 (cutover) and HG-W2-2 (vendor split) remain blocked for the duration.
- Decision in 2-3 weeks is data-driven instead of theoretical.

**Investment**: 1 measurement setup (small), 2 weeks elapsed, then re-decision.

### Option E — Cancel POS V4 performance work; accept current 652 KB

Close the W2 line. POS V4 stays accessible at `/admin/pos-v4` but no further perf investment is funded. HG-W2-1 cutover would still be possible if Product judges 652 KB acceptable on its own merit.

**Investment**: zero.

---

## Approval

[ ] Approved — option selected: ___
[ ] Approved with constraint: ___________________________________________
[ ] Cancelled
Approved by: ___
Date: ___

---

## Attachments

- Bundle measurements: `reports/baseline/POS_V4_PERF_HISTORY.md` (cross-cycle SSOT).
- Audit identifying the infeasibility: `reports/audit/AUDIT_W2_DEDICATED_ENTRY_CLAUDE_2026-04-26.md` § E Q2.
- ADR with revised KPI proposal: `docs/design/ADR_POS_V4_DEDICATED_ENTRY.md` § "KPI revision proposal".

## Resumption protocol

Per `.cursor/rules/human-gates.mdc` § Resumption Protocol, the loop resumes only after all three conditions are met:
1. The Approval block above is filled by a human.
2. A line is added to `docs/gates/GATE_LOG.md` Trail courant referencing the chosen option (date + approver + commit/cycle).
3. cursor-claude reads the cleared brief and **updates the plan file** to reflect the resolution.

**Plan-file substitute declaration**: this gate originated outside any single bounded `plans/PLAN_*.md` file (the W2 line is tracked across `plans/PLAN_POS_V4_IMPL_EXEC_FINAL_2026-04-26.md` and `plans/PLAN_POS_V4_IMPL_MASTER_2026-04-26.md`, plus the audit `reports/audit/AUDIT_W2_DEDICATED_ENTRY_CLAUDE_2026-04-26.md`). The "plan file" required by step 3 is therefore satisfied by updating BOTH:
- `docs/design/ADR_POS_V4_DEDICATED_ENTRY.md` § "KPI revision proposal" — mark the chosen option as the binding KPI; AND
- The active `plans/PLAN_POS_V4_*.md` cycle file (whichever is current per `.cursor/ACTIVE_CYCLE.md`) — append a `KPI_DECISION:` line under the W2 section referencing this gate's resolution.

This dual update is required so future cycles inherit the binding KPI from both the architectural record (ADR) and the cycle plan (SSOT for execution).
