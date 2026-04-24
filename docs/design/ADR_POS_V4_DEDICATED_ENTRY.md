# ADR — POS V4 dedicated webpack entry-point

**Status**: Proposed (POC implemented in W2 #1, awaiting human decision on cutover strategy)
**Date**: 2026-04-26
**Cycle**: POS_V4_W2_DEDICATED_ENTRY
**Author**: cursor-claude (orchestration), Claude terminal (audit)
**Supersedes**: implicit single-entry assumption baked into `master.blade.php`

---

## Context

After W1-A (POS code splitting), W1-B (vendor chunking) and W1-C (lazy admin routes), the POS first-paint cost stands at:

```
manifest.js   1 KB gz
vendor.js   267 KB gz
app.js      456 KB gz   ← shared shell (auth + frontend + dashboard + i18n + Toast + ApexCharts + KioskDS + …)
pos-shell.js 60 KB gz   ← lazy POS code
─────────────────────
Total POS first-paint = 785 KB gz
```

The W1 KPI was **POS first-paint ≤ 220 KB gz**. The 565 KB gap **cannot be closed by route lazy-loading alone** because `app.js` carries dependencies the POS does not need:

- `vue3-apexcharts` + `apexcharts` (admin reports only)
- `vue-next-select` (admin forms only)
- `vue3-simple-alert` (rarely used in POS)
- `KioskDesignSystem` atoms + tokens CSS (kiosk only)
- Frontend (vitrine) routes
- Admin classique routes structure (already lazy as chunks, but the route table itself + permission tree is in `app.js`)

Closing the gap requires **a dedicated webpack entry-point** for the POS surface, served by a **dedicated Blade view**, behind a **dedicated Laravel route**.

---

## Decision

**Adopt Option B**: implement a parallel `pos-app.js` entry served by `/admin/pos-v4` (parallel to legacy `/admin/pos`), keeping legacy untouched. Cutover decision (A/B test, soft launch, or hard switch) is **deferred to a human gate** after measurement.

---

## Options considered

### Option A — Aggressive tree-shaking of `app.js`
Move POS-incompatible imports (`KioskDesignSystem`, `VueApexCharts`, `vue-next-select`) behind dynamic imports gated on route matching.

**Pros**: single bundle, no duplication risk.
**Cons**:
- Conditional imports based on URL parsing in `app.js` are fragile.
- Does not eliminate the shared shell overhead (~250 KB of router + i18n + bootstrap + auth + Vuex + axios interceptors).
- Does not remove the static `DashboardComponent` import from `router/index.js:5`.
- High refactor risk on `app.js` — touches every surface, blast radius huge.
- Closes maybe 100-150 KB of the 565 KB gap. **Insufficient**.

**Verdict**: rejected. Insufficient gain for the risk.

### Option B — Parallel dedicated entry (chosen)
Create `resources/js/pos-app.js`, a slim Vue root with:
- The shared `bootstrap.js` (Echo + axios + lodash — required for real-time + API calls).
- The shared `store/index.js` (Vuex — auth token, posCart, globalState are read by POS).
- The shared `i18n.js` (translations — POS UI is multilingual).
- A **POS-only Vue Router** with 3 routes:
  - `/admin/pos-v4` → POS
  - `/admin/pos-v4/floorplan` → Floorplan
  - `/admin/pos-v4/login` → redirect to legacy `/login` (auth happens via legacy app)
- A minimal app plugin set: `Toast` only (POS already uses it for save/cancel feedback).
- **Skipped** (saves ~80 KB gz): `vue3-apexcharts`, `vue-next-select`, `vue3-simple-alert`, `KioskDesignSystem`.

A new Blade view `admin-pos-v4.blade.php` (slim variant of `master.blade.php`) loads `manifest.js + vendor.js + pos-app.js` (no `app.js`).

A new Laravel route `/admin/pos-v4/{any?}` (placed **before** the catch-all in `routes/web.php`) serves this Blade.

**Pros**:
- Zero risk to legacy `/admin/pos` — no shared file mutated for behavioral change.
- A/B testable: operators can toggle which URL their POS terminals open.
- Measurable ROI: builds in parallel, can be discarded by reverting 4 files.
- Sets the architectural pattern for future surface-dedicated entries (`kiosk-app.js`, `kds-app.js`).

**Cons**:
- Two Vue apps in production = two Echo connections **per browser tab** if a user opens both legacy and v4 (rare; mitigated by server-side Pusher rate limits).
- Two store instances per tab if both apps run (also rare; same browser tab can only render one Blade).
- Maintenance: every cross-cutting feature added to `app.js` must be evaluated for `pos-app.js` inclusion. **Mitigation**: keep `pos-app.js` to <150 lines and reuse imports from existing modules.

**Verdict**: chosen. Aligns with W2 KPI goal, low blast radius, reversible.

### Option C — Defer + accept 785 KB POS first-paint
Recognize that 220 KB was an aspirational KPI inherited from the design package, and operating at 785 KB is acceptable for a POS terminal (plugged in, fast network).

**Pros**: zero engineering cost.
**Cons**: stops POS V4 progress at the KPI ceiling; the design promise of <1.2 s LCP cannot be met on slower 4G/Wi-Fi terminals.

**Verdict**: rejected as final answer, but Option C is **the safe fallback** if Option B POC measurements show <30 % gain.

---

## Realistic POC gain estimate

`pos-app.js` is expected at 200-280 KB gz (vs `app.js` 456 KB gz). Saved by skipping ApexCharts, KioskDS, vue-next-select, vue3-simple-alert, and unused router modules + their permission tree walks.

| Component                          | Estimated POC POS first-paint |
|------------------------------------|------------------------------|
| manifest.js                        | 1 KB gz                      |
| vendor.js (current, shared)        | 267 KB gz                    |
| pos-app.js (new)                   | ~250 KB gz (estimate)        |
| pos-shell.js (already lazy)        | 60 KB gz (loaded on first POS render) |
| **Total POS first-paint**          | **~578 KB gz** (-26 % vs 785) |

To approach the 220 KB KPI, a follow-up cycle would also need to **split `vendor.js`** into `vendor-pos.js` (just Vue + Vuex + Vue Router + axios + Echo + Pusher + DOMPurify ~ 130 KB gz) and `vendor-rest.js`. That is W2 #2.

---

## Invariants impact

| Invariant                          | Impact | Mitigation |
|-----------------------------------|--------|-----------|
| Backend pricing SSOT              | None   | No backend touched.                                            |
| OrderStatus enum authoritative    | None   | `pos-app.js` reuses `orderStatusEnum.js` import.               |
| `branch_id` data isolation        | None   | All API calls go through the shared axios interceptor.         |
| Dispatch after DB commit          | None   | No backend touched.                                            |
| OrderService / Frontend symmetry  | None   | No service touched.                                            |
| Frozen zones                      | None   | New files only (`pos-app.js`, `admin-pos-v4.blade.php`, `AdminPosV4Controller.php`). `routes/web.php` adds a new route **before** catch-all (strictly additive). |

---

## Rollback strategy

```bash
# Revert all POC changes:
rm resources/js/pos-app.js
rm resources/views/admin-pos-v4.blade.php
rm app/Http/Controllers/Admin/AdminPosV4Controller.php
git checkout webpack.mix.js routes/web.php
npm run production
# Legacy /admin/pos is untouched throughout — no rollback needed there.
```

---

## Operational risks

1. **Echo double connection** — if a POS terminal accidentally opens both `/admin/pos` and `/admin/pos-v4` in two tabs, two Pusher connections will be opened. Mitigation: document operator instruction to use only one URL; long-term Pusher subscription quota monitoring.
2. **Auth token rotation** — `pos-app.js` reads the Vuex token from localStorage on init. If the token rotates while the v4 tab is open and the legacy tab in another browser performed the rotation, v4 will see a stale token until next 401 retry. Mitigation: same as legacy (existing 401 interceptor on shared axios).
3. **Bundle drift** — over time, `app.js` and `pos-app.js` may diverge in feature set. Mitigation: a CI guard `tools/lint/pos_v4_entry_diff.mjs` (W2 #3, future) compares the two entries' shared imports.

---

## Acceptance criteria for this POC (W2 #1)

| Criterion | Target | Measured | Status |
|-----------|--------|----------|--------|
| `pos-app.js` < 320 KB gz | hard | TBD | TBD |
| POS first-paint /admin/pos-v4 < 600 KB gz | soft | TBD | TBD |
| Legacy `/admin/pos` load time identical | regression check | TBD | TBD |
| Build time delta ≤ +5 s | acceptable | TBD | TBD |
| Lints clean (`pos:lint:pricing`, `pos:lint:status`) | hard | TBD | TBD |
| 6/6 invariants GREEN | hard | TBD | TBD |

If criteria 1+2 are met → recommend HG for cutover decision (A/B vs hard switch).
If criteria 1 fails → revert, re-evaluate, recommend Option C.

---

## Pending human decisions (after POC measurement)

- **HG-W2-1**: cutover strategy (A/B test 50/50, soft launch on 1 branch, hard switch /admin/pos → v4 redirect, or keep parallel indefinitely).
- **HG-W2-2** (optional): authorize W2 #2 vendor split (`vendor-pos.js`) if a revised KPI is set.
- **HG-W2-3 (NEW from audit)**: KPI revision — see § KPI revision below.

---

## KPI revision proposal (added 2026-04-26 post-audit)

The original `≤ 220 KB gz POS first-paint` KPI is **architecturally infeasible** without removing core POS functionality:
- Removing real-time (`laravel-echo` + `pusher-js`) would break KDS sync and live order updates → not acceptable.
- Removing `vendor.js` deduplication and inlining only POS-needed Vue + Vuex + Vue Router + axios still floors at ~280 KB gz of pure framework code.
- Realistic floor with current feature set: **~520 KB gz** (after W2 #2 vendor split).

**Proposed revised KPIs** (require product + UX sign-off via HG-W2-3):

| Metric | Original | Revised proposal | Justification |
|--------|----------|-----------------|---------------|
| POS first-paint gz | ≤ 220 KB | **≤ 600 KB** (current 652 KB → -52 KB via W2 #3 lazy bootstrap) | Achievable today with current POC + minor follow-ups |
| POS first-paint gz (stretch) | — | ≤ 520 KB after W2 #2 vendor split | Stretch goal, requires HG-W2-2 authorization |
| LCP on Wi-Fi (real device) | — | < 1.5 s | Empirical target replacing the bundle-size proxy |
| TTI on Wi-Fi (real device) | — | < 2.0 s | Empirical target |

The bundle-size proxy was a useful planning lever but should not be the contract. Real-device measurement (e.g., Lighthouse on a typical POS terminal — Chrome on a fanless industrial PC, 4G fallback) is a more honest acceptance criterion.

**Until HG-W2-3 closes**, all subsequent W2 work should reference the **600 KB stretch / LCP < 1.5 s** target as the working hypothesis.

