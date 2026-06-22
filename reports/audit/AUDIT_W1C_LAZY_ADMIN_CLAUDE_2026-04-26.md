# AUDIT W1-C — Lazy admin classique routes + perf historization

**Auditor**: Claude (terminal, Anthropic subscription)
**Date**: 2026-04-26
**Brief reviewed**: `reports/audit/W1C_LAZY_ADMIN_BRIEF_2026-04-26.md`
**Verdict**: **PASS-WITH-FIX — 8/10**
**Channel**: terminal audit (PRIMARY) — `AUDIT_CHANNEL: terminal-anthropic`, `TERMINAL_AUDIT_OK: 1`

---

## A. Verdict

**PASS-WITH-FIX — 8/10**.

W1-C delivers a real, mechanically-verifiable bundle reduction (`app.js` 752 → 456 KB gz, -39 % on top of W1-B; -53 % vs W0 baseline) with a **clean invariants surface** (routing-only change, no business logic touched). The reproducible refactor script and the `POS_V4_PERF_HISTORY.md` SSOT are net positives for orchestration discipline. Two P1 fixes are required before the refactor script can be promoted as a reusable pattern in `AGENTS.md`.

---

## B. Invariants check (6 / 6 GREEN)

| Invariant                          | Status | Evidence                                                                  |
|-----------------------------------|--------|---------------------------------------------------------------------------|
| Backend pricing SSOT              | GREEN  | No frontend pricing logic touched; `pos:lint:pricing` clean (HG-2 WARN).  |
| OrderStatus enum authoritative    | GREEN  | `pos:lint:status` clean.                                                  |
| `branch_id` data isolation        | GREEN  | Routing-only; no data layer or query touched.                             |
| Dispatch after DB commit          | GREEN  | No backend service touched.                                               |
| OrderService / Frontend symmetry  | GREEN  | Neither service touched.                                                  |
| Frozen zones                      | GREEN  | `router/modules/*.js` not in any frozen registry.                         |

---

## C. Strengths

1. **Reproducible mechanical transform.** The `tools/refactor/lazy_router_modules.mjs` script makes the 114-import migration auditable via a single regex contract instead of 25 hand-edits with risk of inconsistency. The dry-run / `--apply` discipline is correct.
2. **Chunk grouping is well-reasoned.** Isolating `admin-kds` (26 KB) and `admin-oss` (6 KB) means kitchen displays and customer-facing OSS screens never download the admin classique payload (279 KB). For dedicated KDS terminals running 24/7, this materially reduces cold-start cost.
3. **Perf history SSOT (`POS_V4_PERF_HISTORY.md`) is real, not narrative.** Cycle-by-cycle table with raw measurements + investigation explanation = future cycles inherit context without re-deriving it. Aligned with `MEMORY_MATRIX.md` store D.
4. **Boot critical / SEO exclusions are correct.** `authRoutes.js` + `frontendRoutes.js` + Dashboard / NotFound / Exception kept static = no first-paint regression for visitors and login flow.
5. **Untraced +53 KB delta from W1-A is genuinely resolved**, not papered over. Root cause (webpack SplitChunksPlugin behavior on partial split topology) is documented and the resolution mechanism is verifiable in the next build.

---

## D. Risks / Fixes

### R1 — `shouldSkipImport` is a dead stub (P1)
**Evidence**: `tools/refactor/lazy_router_modules.mjs:67-74` defines `shouldSkipImport` that always returns `false` and a comment claims it could filter "helpers, services, utils accidentally located under components/". The function is called but never blocks. Either:
- **Remove** the dead code (the regex `\.\.\/\.\.\/components\/[^"']+?` is the actual safety net, and it has worked correctly across 25 modules).
- **OR** implement a real allowlist of suffixes (`Component`, `Component.vue`) and document it.

**Fix proposal**: remove the dead function and inline a comment explaining that the regex path constraint is the safety contract. Keeps the audit story honest.

### R2 — Injected banner references stale baseline (P1)
**Evidence**: `tools/refactor/lazy_router_modules.mjs:79-83` injects this header into every modified router module:

> `// Goal: reduce app.js first-paint (see reports/baseline/POS_V4_PERF_BASELINE_W0.md).`

This file is the **W0 snapshot**, frozen on 2026-04-26 W0 morning. The new SSOT for cross-cycle perf is `POS_V4_PERF_HISTORY.md`. The 25 router modules now point future readers to a stale snapshot.

**Fix proposal**: change the banner's referenced doc to `POS_V4_PERF_HISTORY.md`. Re-run the script in `--apply` mode to update all 25 banners (they'll be skipped by the `HEADER_TAG` guard, so the banner update needs a one-time forced update or a sed pass).

### R3 — `admin-shell.js` at 279 KB is large but acceptable for W1 (P2 — informational)
**Evidence**: Single chunk holds settings, employees, customers, orders, items, offers, coupons, dining tables, transactions, messages, push notifications. First admin sub-route navigation costs +279 KB.

**Why P2**: splitting further now would create 7+ admin chunks, each <50 KB, increasing HTTP request count without meaningful UX gain (admin users typically navigate within the same surface). For W1 KPI **(admin first-paint < 1.1 MB gz)**, current 1004 KB is comfortable.

**Recommendation**: Keep as-is for W1. Re-evaluate if `admin-shell` grows past 400 KB gz in future cycles.

### R4 — No bundle analyzer report generated (P2)
**Evidence**: Brief explicitly skips it ("would have been 5+ MB"). True, but `webpack-bundle-analyzer --mode static` produces an HTML artifact that compresses well and would let future audits verify chunk membership without re-reading 25 router modules.

**Recommendation**: generate one analyzer snapshot per major W cycle, store under `reports/baseline/bundle-analyzer/W1C-2026-04-26.html`. Optional for W1-C close, mandatory before W2 entry-point split.

### R5 — `frontendRoutes.js` 13 imports stays unaudited (P2)
**Evidence**: Excluded from W1-C (correct for SEO), but no Lighthouse measurement has been done to confirm SEO/LCP would actually regress under lazy split with `<link rel="prefetch">`.

**Recommendation**: defer to W2 with a Lighthouse-driven decision, NOT a guess. Do not lazy-split `frontendRoutes.js` until measurement justifies it.

---

## E. Answers to brief questions

### Q1 — Approve 4-chunk grouping?
**APPROVED**. KDS/OSS isolation is high-value (24/7 terminals, single-purpose). `admin-shell` granularity is correct for W1. Finer split is **not** justified at this stage.

### Q2 — Keep `frontendRoutes.js` static?
**APPROVED for W1.** Defer split-with-prefetch decision to a W2 cycle gated on Lighthouse measurement (do not lazy-split blind).

### Q3 — Promote refactor script to `AGENTS.md` reusable pattern?
**Conditional approval.** Promote **after** fixing R1 (dead `shouldSkipImport`) and R2 (stale banner reference). Then add a section to `AGENTS.md` § "Reusable refactors" pointing to `tools/refactor/lazy_router_modules.mjs` as the template for future router lazy migrations.

### Q4 — Mark +53 KB W0→W1-A residual risk as RESOLVED?
**YES, RESOLVED**. Update `AUDIT_W1A_CODESPLIT_CLAUDE_2026-04-26.md` § Residual risks accordingly. The investigation in `POS_V4_PERF_HISTORY.md` is the closure evidence.

### Q5 — Confirm POS first-paint < 220 KB gz is W2 architecture work?
**CONFIRMED.** Closing the 565 KB gap requires a dedicated `pos-app.js` Vue entry (separate from `app.js`), NOT route lazy-loading. This is W2 scope. Document the decision in a forthcoming `ADR_POS_V4_DEDICATED_ENTRY.md`.

### Q6 — Next W-level recommendation
**Jump to W2.**
- W1-C #3 (KDS-only deeper split) — **skip**: KDS is already isolated as `admin-kds` (26 KB), no further ROI.
- W1-C #4 (Kiosk magic ints) — **defer**: the brief notes "after semantic verification of `status !== 10`" which itself requires a small verification cycle; not a blocker for W2.
- **W2 priorities**:
  1. ADR + implementation of dedicated `pos-app.js` entry (target POS first-paint ≤ 220 KB gz).
  2. PaymentComponent prop mutation refactor (gated on HG-4 sign-off).
  3. Color identity finalization (HG-3 ADR sign-off).

---

## F. ST-* tracker update (W0+ STOP triggers)

| ST  | Description                                            | Status      | Notes                                                  |
|-----|--------------------------------------------------------|-------------|--------------------------------------------------------|
| ST-1| HG-1: CI lint guards cabling confirmed                 | **OPEN**    | No human confirmation logged yet.                      |
| ST-2| HG-2: PosComponent:1779 pricing sign-off              | **OPEN**    | `signoff-pending` until 2026-05-10.                    |
| ST-3| HG-3: ADR_POS_V4_COULEUR.md product+design approval   | **OPEN**    | Pending.                                               |
| ST-4| HG-4: PaymentComponent prop mutation gate             | **OPEN**    | Gate brief amended at W1-B+; awaiting sign-off.        |
| ST-5| Untraced +53 KB W0→W1-A delta                         | **RESOLVED**| Closed at W1-C; documented in `POS_V4_PERF_HISTORY.md`.|

**New tracking item**: W1-B `mix.version()` enabled — verified via `manifest.js` presence in build output → **RESOLVED**.

---

## G. Operational notes

- Backup at `/tmp/router-modules-bak.w1c` is **volatile** (will be lost on machine reboot). If rollback may be needed beyond 24 h, copy to `reports/baseline/router-modules-pre-w1c-bak/`.
- Activity log: this audit was conducted while reservation `POS_V4_W1C_LAZY_ADMIN` is held by `cursor-claude`. Release after fix application (R1 + R2).

---

**Audit complete.** Approve W1-C with the two P1 fixes applied before W2 entry.
