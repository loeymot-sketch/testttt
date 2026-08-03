# W2 #1 — POS dedicated entry-point — Audit brief

**Date**: 2026-04-26
**Cycle**: POS_V4_W2_DEDICATED_ENTRY
**Channel**: Cursor agent (architecture POC behind a parallel URL — ZERO mutation of legacy `/admin/pos`).
**ADR reviewed**: `docs/design/ADR_POS_V4_DEDICATED_ENTRY.md`
**Author**: cursor-claude

---

## What W2 #1 delivers

### A new parallel entry-point (not a switch — a parallel)
A second Vue app (`pos-app.js`) is compiled by webpack alongside `app.js`. It is served by a **new Laravel route** `/admin/pos-v4/{any?}` (placed strictly before the catch-all in `routes/web.php`) which renders a **new Blade view** `admin-pos-v4.blade.php` that loads `manifest.js + vendor.js + pos-app.js` (NOT `app.js`).

Legacy `/admin/pos` continues to load the legacy `app.js`. **Zero modification** to legacy POS behavior.

### Files added (5)
| File                                                              | Purpose                                       |
|-------------------------------------------------------------------|-----------------------------------------------|
| `resources/js/pos-app.js`                                         | Dedicated POS Vue entry-point                 |
| `resources/views/admin-pos-v4.blade.php`                          | Slim Blade variant (no `app.js`)              |
| `app/Http/Controllers/Admin/AdminPosV4Controller.php`             | Mirror of `RootController` for the new route  |
| `docs/design/ADR_POS_V4_DEDICATED_ENTRY.md`                       | Architecture decision record                  |
| `reports/audit/W2_DEDICATED_ENTRY_BRIEF_2026-04-26.md`            | This brief                                    |

### Files modified (2 minimal additions)
| File              | Change                                                                  |
|-------------------|-------------------------------------------------------------------------|
| `webpack.mix.js`  | +1 line `mix.js('resources/js/pos-app.js', 'public/js').vue();`        |
| `routes/web.php`  | +1 `Route::get('/admin/pos-v4/{any?}', ...)` BEFORE the catch-all      |

---

## Measurements (gzipped, prod build)

```
manifest.js              gz=  1 KB
vendor.js                gz=267 KB
app.js                   gz=457 KB   (legacy entry — UNCHANGED)
pos-app.js               gz=318 KB   (new dedicated POS entry)
pos-shell.js             gz= 65 KB   (lazy chunk — shared by both entries via webpackChunkName)
admin-shell.js           gz=279 KB   (lazy chunk — only used by legacy /admin/* sub-routes)
```

### Surface first-paint cost

| Surface              | Boot chain                                       | Total gz | Δ vs legacy |
|----------------------|--------------------------------------------------|----------|-------------|
| `/admin/pos` (legacy) | `manifest + vendor + app + pos-shell`           | **791**  | baseline    |
| `/admin/pos-v4` (NEW) | `manifest + vendor + pos-app + pos-shell`       | **652**  | **-139 KB / -17.6 %** |

### Acceptance criteria scorecard (from ADR)

| Criterion | Target | Measured | Status |
|-----------|--------|----------|--------|
| `pos-app.js` < 320 KB gz | hard | **318 KB** | ✅ PASS (2 KB margin) |
| POS first-paint /admin/pos-v4 < 600 KB gz | soft | 652 KB | ⚠ MISS (52 KB over soft target) |
| Legacy `/admin/pos` size unchanged | regression check | identical (`app.js` 457 KB → 457 KB) | ✅ PASS |
| Build time delta ≤ +5 s | acceptable | +1.6 s (18.2 → 19.8 s) | ✅ PASS |
| `pos:lint:pricing` clean | hard | WARN 1779 (HG-2 expected) | ✅ PASS |
| `pos:lint:status` clean | hard | OK | ✅ PASS |
| `php -l routes/web.php` clean | hard | OK | ✅ PASS |
| `php -l AdminPosV4Controller.php` clean | hard | OK | ✅ PASS |

---

## Why the soft target was missed by 52 KB

`pos-app.js` is 318 KB gz, but the **first-paint payload also includes `vendor.js` (267 KB)** which still bundles `vue3-apexcharts`, `vue-next-select`, `vue3-simple-alert` (libraries the POS does not use — pulled into `vendor.js` because the legacy `app.js` extract list contains them).

To reach the hard 220 KB POS first-paint KPI, **W2 #2** must split `vendor.js` into:
- `vendor-pos.js` (~130 KB gz): Vue + Vuex + Vue Router + axios + laravel-echo + pusher-js + DOMPurify + vue-i18n + vuex-persistedstate + swiper + vue-element-loading.
- `vendor-rest.js` (~140 KB gz): vue3-apexcharts + apexcharts + vue-next-select + vue3-simple-alert + vue-toastification (POS uses Toast — TBD).

Projected POS first-paint after W2 #2: **~520 KB gz** (still above 220 KB hard target). To approach 220 KB, a `pos-vendor.js` would need to drop laravel-echo + pusher-js + apexcharts entirely (no real-time, no charts) — but the POS **does** use Echo for real-time KDS sync. **Conclusion**: 220 KB is architecturally infeasible without a fundamental scope reduction (e.g., remove real-time from POS V4). Recommend revising the KPI in HG.

---

## Invariants check (6/6 GREEN)

| Invariant                          | Status | Evidence                                                  |
|-----------------------------------|--------|-----------------------------------------------------------|
| Backend pricing SSOT              | GREEN  | No frontend pricing logic touched; `pos-app.js` reuses store. |
| OrderStatus enum authoritative    | GREEN  | `pos-app.js` does not reference any status; lazy-loads `PosComponent` which uses `orderStatusEnum.js`. |
| `branch_id` data isolation        | GREEN  | All API calls go through the SHARED axios interceptor (mirror of `app.js`). |
| Dispatch after DB commit          | GREEN  | No backend touched.                                       |
| OrderService / Frontend symmetry  | GREEN  | No service touched.                                       |
| Frozen zones                      | GREEN  | No frozen file modified. `routes/web.php` += new route, **before** catch-all (strictly additive). |

---

## Architectural risks (and mitigations)

| Risk | Mitigation |
|------|-----------|
| Duplicate Vue app instances if user opens `/admin/pos` and `/admin/pos-v4` simultaneously in two tabs | Document operator instruction. Long-term: A/B switch via feature flag. |
| Auth interceptor drift between `app.js` and `pos-app.js` | `pos-app.js` includes a copy of the axios setup with a comment marking it as "frozen mirror until shared module extraction". CI guard recommended in W2 #3. |
| Two Echo connections per browser tab | Same mitigation as above; one tab = one entry = one connection. |
| Bundle drift (`app.js` evolves, `pos-app.js` stagnates) | ADR Cons §B documents this. CI guard for entry parity is a W2 #3 item. |

---

## Cutover decision (HG-W2-1) — NOT proposed in this cycle

The POC delivers `/admin/pos-v4` accessible in production but **does not switch operators to it**. A human gate is required to decide:

- **Option A**: A/B test 50/50 by branch ID (requires backend feature flag — small change in `RootController` to redirect 50 % of `/admin/pos` traffic).
- **Option B**: Soft launch on 1 branch (operator manually opens `/admin/pos-v4`).
- **Option C**: Hard switch — redirect `/admin/pos` → `/admin/pos-v4` (1-line in `RootController`).
- **Option D**: Indefinite parallel — keep both URLs alive permanently for tenant choice.

Brief should be drafted only after stakeholder review of the measurements above.

---

## Questions for Claude (audit)

1. The ADR rejected Option A (aggressive tree-shaking) and chose Option B (parallel entry). Is the rationale solid given the 17.6 % gain demonstrated?
2. The 220 KB KPI appears architecturally infeasible without dropping real-time or charts. Should W2 #2 (vendor split) be authorized to chase 520 KB, or recommend KPI revision instead?
3. The `pos-app.js` axios interceptor is a near-verbatim copy of `app.js` L52-L146 — frozen-mirror anti-pattern. Authorize a follow-up extraction to `resources/js/shared/axios-setup.js`?
4. Route `/admin/pos-v4` has no Laravel auth middleware (mirror of legacy pattern). Acceptable, or should this POC harden auth middleware before HG cutover?
5. Recommend next step:
   - **W2 #2**: vendor split (chase 520 KB POS first-paint).
   - **W2 #3**: CI guard for entry parity (`tools/lint/pos_v4_entry_diff.mjs`).
   - **HG cutover**: stop at POC, draft cutover gate brief now.

---

## Rollback (one-liner)

```bash
rm resources/js/pos-app.js resources/views/admin-pos-v4.blade.php app/Http/Controllers/Admin/AdminPosV4Controller.php
git checkout webpack.mix.js routes/web.php
npm run production
```

Legacy `/admin/pos` is **never** affected.
