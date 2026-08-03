# Gate Brief — HG-W2-1 — POS V4 cutover strategy — 2026-04-26

**Cycle source**: POS_V4_W2_DEDICATED_ENTRY (W2 #1, parent ADR `docs/design/ADR_POS_V4_DEDICATED_ENTRY.md`)
**Status**: PENDING_HUMAN_GATE — drafted; **soft-blocked** until HG-W2-3 (KPI revision) clears + at least one real-device LCP measurement campaign completes.
**Author**: cursor-claude (per `.cursor/rules/human-gates.mdc` — Claude only)
**Type**: Hard gate — affects production traffic routing for the POS surface (operator-facing change).

---

## Trigger

W2 #1 delivered the parallel POS V4 entry-point at the URL `/admin/pos-v4` (Blade `admin-pos-v4.blade.php`, entry `pos-app.js`). It has been audited and remediated to PASS-WITH-FIX 8/10 with all P0 security findings resolved. Bundle measurement: 652 KB gz first-paint (-17.6 % vs the 791 KB legacy baseline at `/admin/pos`).

The new URL is **accessible in production today**, but **no operator is sent to it**. The legacy `/admin/pos` continues to serve all traffic. A human decision is required to choose how — and whether — to migrate operator traffic to the new entry.

This gate is necessary because cutover is the moment performance gains become user-visible (operator UX) AND the moment any new-entry regression becomes user-visible (POS terminals are mission-critical — a degraded POS blocks restaurant operations).

---

## Affected Subsystems

| Subsystem | Read | Write | Notes |
|-----------|------|-------|-------|
| `routes/web.php` | yes | conditional | Hard switch (Option D) requires modifying the legacy `/{any}` catch-all to redirect `/admin/pos` → `/admin/pos-v4`. |
| `resources/views/master.blade.php` | yes | no | Frozen-zone-adjacent — only touched if cutover path requires injecting a feature-flag JS shim. |
| `app/Http/Controllers/Frontend/RootController.php` | yes | conditional | Branch-aware A/B test (Option C) requires server-side branch resolution + redirect logic. |
| Operator workstations / POS terminals | n/a | n/a | Cutover communication required if Options B/C/D chosen. |
| Backend metrics + observability | yes | yes | New cutover requires monitoring (LCP, error rate, 401 rate) on both URLs in parallel for the rollback window. |

---

## Invariants at Risk

| Invariant | Risk | Mitigation in scope of this gate |
|-----------|------|----------------------------------|
| `branch_id` data isolation | None directly. Option C (branch-aware A/B) reads `branch_id` server-side for redirect — must use the existing branch-resolution path, no new query introduced. | Option C plan must cite the existing `branch_id` resolver. |
| OrderService / Frontend symmetry | None. Both `/admin/pos` and `/admin/pos-v4` consume the same `/api/*` endpoints. | n/a |
| Backend pricing SSOT | None. Same backend. | n/a |
| Frozen zones | `master.blade.php` is **not** in any documented frozen-zone registry but is touched by the legacy entry. Verify before any modification. | Audit step before any Option B/C/D Blade modification. |
| Synchronization (real-time) | Both entries use the same shared `bootstrap.js` → same Echo connection topology. No risk introduced by cutover itself. | Already validated in W2 #1. |

---

## Decision Required

**How should operator traffic be migrated from `/admin/pos` (legacy `app.js`) to `/admin/pos-v4` (new `pos-app.js`)?**

The decision must produce:
1. A **traffic-routing strategy** (parallel / opt-in / branch-scoped / hard switch).
2. A **rollback trigger** — observable signals that auto-revert or alarm. Default proposed thresholds (a human can tighten or loosen them when picking an option):
   - **POST /api/orders** error rate (5xx + 4xx-non-validation) > **2 %** sustained over 15 min, vs the legacy `/admin/pos` baseline measured the prior 7 days.
   - **LCP p95** (Lighthouse field data, Chrome on POS terminals) > **2.5 s** sustained over 30 min, OR > **+25 %** vs the legacy `/admin/pos` p95 baseline.
   - **401 rate** > **0.5 %** of POS requests over 15 min (would indicate auth-shim regression specific to `pos-app.js`).
   - **Operator-reported incidents** ≥ **3 distinct branches in 24 h** filed through the support channel referencing the new URL.
   - **WebSocket disconnect rate** (Echo) > **+50 %** vs baseline over 1 h (would indicate `pos-app.js` Echo init regressed real-time sync).
   Any single threshold breached = automatic rollback to the legacy entry; a human approves resumption.
3. A **decision horizon** (when do we re-evaluate to either deepen the cutover or roll back).
4. An **operator-communication plan** if the cutover changes the URL operators bookmark.

---

## Options

### Option A — Indefinite parallel (status quo)

`/admin/pos` and `/admin/pos-v4` both alive in production. No URL operator default change. Operators choose which one to use; ops dashboards monitor adoption organically.

**Pros**:
- Zero operational risk — legacy is untouched.
- Lets early-adopter branches self-select.
- Provides natural A/B data over time without engineering investment.

**Cons**:
- Two entries to maintain forever (CI guard ST-W2-CI-2 mitigates drift).
- Slow validation feedback loop — months before enough adoption data exists to decide cutover.
- The performance gain (-17.6 %) is invisible to most operators who never know `/admin/pos-v4` exists.

**Acceptance**: requires no further engineering work after this gate clears.

### Option B — Soft launch on 1 volunteer branch

Identify 1 branch (Tech Lead + branch manager agreement). Communicate the new URL `/admin/pos-v4` to that branch's operators. All other branches stay on `/admin/pos`. Monitor LCP, error rate, 401 rate, operator feedback for 2-4 weeks.

**Pros**:
- Real-world validation with bounded blast radius (1 branch).
- Empirical data to inform Option C/D decision.
- Easy rollback (operator simply navigates back to `/admin/pos`).

**Cons**:
- Requires operational coordination (training, comms, support during the trial).
- 1 branch is a small sample — geographic / device / network bias possible.

**Pre-requisite**: HG-W2-3 KPI revision must clear first so the trial has a defined success criterion (e.g., "LCP p95 < 1.5 s observed during week 2").

**Rollback triggers for this option** (subset of the 5 default signals — see § Decision Required):
- POST /api/orders error rate > 2 % over 15 min (immediate revert: tell branch operators to switch back to `/admin/pos`).
- LCP p95 > 2.0 s in week 2 (success criterion failure — abandon the soft launch, consider Option E).
- ≥ 2 distinct operator complaints in the trial branch in 48 h (qualitative signal — escalate to TL).

### Option C — Server-side A/B test 50/50 by `branch_id`

Modify `RootController` (or the catch-all logic in `routes/web.php`) so that approximately 50 % of `/admin/pos` requests, deterministically split by `branch_id`, redirect to `/admin/pos-v4`. Requires a feature-flag mechanism (`config/features.php` or DB-stored).

**Pros**:
- Statistical validation across the entire operator population.
- Branch-deterministic split = no operator sees a mid-session URL change.
- Standard A/B testing pattern.

**Cons**:
- Engineering investment (~1 cycle to implement the split logic + dashboard).
- Operators on the v4 side cannot easily revert to legacy without admin intervention.
- Risk of partial-rollout regressions affecting half the fleet at once.

**Pre-requisite**: HG-W2-3 cleared + W2 #3 CI guards in place + observability dashboard wired.

**Rollback triggers for this option** (the 5 default signals applied per-cohort):
- Error rate or LCP p95 breach on the v4 cohort > legacy cohort by **+25 %** sustained 30 min → flip the feature flag to 0 % v4, alarm.
- 401 rate on v4 cohort > 1 % over 15 min → flip flag, alarm.
- Operator-reported incidents ≥ 3 distinct branches in v4 cohort in 24 h → flip flag, escalate.
The flag must support instant 0 % / 50 % / 100 % rotation without redeploy.

### Option D — Hard switch (`/admin/pos` redirects to `/admin/pos-v4`)

Modify `routes/web.php`: serve `/admin/pos` with a 302/301 redirect to `/admin/pos-v4`. Legacy entry remains compiled (rollback safety) but receives no production traffic.

**Pros**:
- Maximum performance gain reach — every operator gets the -17.6 % first-paint immediately.
- Single code path for support to reason about.
- Eventually allows deleting the legacy `app.js` POS routes (pure consolidation).

**Cons**:
- All-or-nothing. Any regression hits 100 % of operators at once.
- Requires URL-bookmark migration (operators who bookmarked `/admin/pos` get the redirect; those who bookmarked the underlying URL pattern need to update).
- Rollback is a 1-line revert but still requires a production deploy.

**Pre-requisite**: HG-W2-3 cleared + W2 #3 CI guards in place + 1 prior soft-launch (Option B) or A/B period (Option C) with documented success criteria met.

**Rollback triggers for this option** (full default signal set — strictest because of 100 % blast radius):
- Any of the 5 default signals from § Decision Required breached → revert the redirect line in `routes/web.php` (1-line revert + deploy ≤ 15 min).
- An automated alarm pipeline must be wired BEFORE the cutover ships so the revert is triggered without waiting for human triage.
- A "rollback dry run" (revert deployed and re-deployed in staging) must be rehearsed in the cycle that implements Option D, before the production cutover.

### Option E — Defer cutover (POC stays accessible, no operator use)

Keep `/admin/pos-v4` available as a developer/QA endpoint. No operator communication. Re-open this gate after the next major perf milestone (post W2 #3 or post W2 #2 if Option B in HG-W2-3 is chosen).

**Pros**:
- Zero risk, zero coordination.
- Lets the POC stabilize while other priorities take precedence.

**Cons**:
- The 139 KB gain is in production but harvests zero user value.
- Maintenance cost of two entries continues without offsetting benefit.

### Option F — Cancel cutover entirely

Decide the W2 entry-point pattern is not worth pursuing. Roll back W2 #1 (4-file delete + 2-file revert per ADR rollback section). Close POS V4 perf line.

**Pros**: removes the dual-entry maintenance cost.
**Cons**: discards a 17.6 % validated gain and the architectural investment.

---

## Approval

[ ] Approved — option selected: ___
[ ] Approved with constraint: ___________________________________________
[ ] Cancelled
Approved by: ___
Date: ___

---

## Attachments

- W2 #1 audit & remediation: `reports/audit/AUDIT_W2_DEDICATED_ENTRY_CLAUDE_2026-04-26.md`
- ADR: `docs/design/ADR_POS_V4_DEDICATED_ENTRY.md`
- Performance history: `reports/baseline/POS_V4_PERF_HISTORY.md`
- Dependent gate: `docs/gates/GATE_W2_KPI_REVISION_2026-04-26.md` (HG-W2-3) — must clear first for Options B/C/D acceptance criteria.

## Resumption protocol

Per `.cursor/rules/human-gates.mdc` § Resumption Protocol, the loop resumes only after all three conditions are met:
1. The Approval block above is filled by a human.
2. A line is added to `docs/gates/GATE_LOG.md` Trail courant referencing the chosen option (date + approver + commit/cycle).
3. cursor-claude reads the cleared brief and **updates the plan file** to reflect the resolution.

**Plan-file resolution per chosen option** (the spec's "plan file" requirement is honoured as follows):
- **Options B / C / D** (any cutover that ships routing code): the active cycle plan (`plans/PLAN_POS_V4_*.md` per `.cursor/ACTIVE_CYCLE.md`) gets a `CUTOVER_DECISION:` line; in addition, a new bounded cycle `POS_V4_W2_CUTOVER_<option>` is opened with its own `plans/PLAN_POS_V4_W2_CUTOVER_<option>_<DATE>.md` (via `run-cycle`) to govern the implementation. The new cycle's plan file is the binding execution record; this brief is then linked from that plan as `INHERITED_GATE: docs/gates/GATE_W2_CUTOVER_2026-04-26.md`.
- **Options A / E / F** (no routing code ships): the active cycle plan gets a `CUTOVER_DECISION: <option> — no code change` line, and HG-W2-1 is marked closed in `GATE_LOG.md`. No new cycle is opened.

This dual update (active plan + downstream plan when applicable) ensures the cycle SSOT records the decision before any new code is written, per the spec's intent.

---

## Recommendation (cursor-claude)

**Sequence: HG-W2-3 first → Option B (soft launch on 1 branch) → after 2-4 weeks of LCP data, re-evaluate to Option C or D.**

Rationale: Option A wastes the gain. Option C/D are too aggressive without empirical data. Option B balances real-world validation against bounded blast radius. Options E/F are defensive postures justified only if HG-W2-3 chooses Option E (cancel) or Option D (defer).

Human is authoritative — this recommendation is non-binding.
