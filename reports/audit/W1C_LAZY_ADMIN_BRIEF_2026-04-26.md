# W1-C — Lazy admin classique routes + perf historization — Audit brief

**Date**: 2026-04-26
**Scope**: POS V4 implementation cycle, micro-exit W1-C (#1 + #2 combined).
**Channel**: Cursor agent (routine refactor, mechanical pattern propagation).
**Author**: cursor-claude.

---

## What W1-C delivered

### W1-C #2 — Route lazy-loading propagation (114 SFC imports)
Converted 25 router modules from static SFC imports to `webpackChunkName` lazy imports.
Pattern is identical to `posRoutes.js` (W1-A) and pre-existing `kioskRoutes.js`.

**Implementation**: a single reproducible Node script — `tools/refactor/lazy_router_modules.mjs` — performs the mechanical transform with safe regex matching, dry-run by default, `--apply` to write. The script is preserved in-tree as the auditable transform record.

**Excluded (intentionally static — boot or SEO critical)**:
- `authRoutes.js` (login = boot critical)
- `frontendRoutes.js` (vitrine = SEO + first-paint critical for visitors)
- `posRoutes.js` (already lazy at W1-A → `pos-shell`)
- `kioskRoutes.js` (already lazy pre-W1 → `kiosk-shell`/`kiosk-wizard`/etc.)
- `cdsRoutes.js` (orphaned, not referenced in `router/index.js`)

**Chunk mapping**:
| Chunk          | Files                                                                 | SFCs |
|----------------|----------------------------------------------------------------------|------|
| `admin-shell`  | settings, employees, customers, orders, items, offers, coupons, etc. | 108  |
| `admin-reports`| salesReport, itemsReport, creditBalanceReport                        | 5    |
| `admin-kds`    | kitchenDisplaySystem                                                 | 1    |
| `admin-oss`    | orderStatusScreen                                                    | 1    |

### W1-C #1 — Bundle delta investigation + perf historization
- Created `reports/baseline/POS_V4_PERF_HISTORY.md` — single source of truth for cross-cycle bundle size evolution.
- Investigated and **resolved** the +53 KB `app.js` delta flagged at W1-A. Root cause: webpack defaults pushed shared modules between `pos-shell` and the still-static admin chunks back into `app.js`. After W1-C completes the lazy topology, `app.js` drops 752 → 456 KB gz, fully absorbing the delta plus -296 KB on top. Documented in the perf history.

---

## Measurements (gzipped, prod build)

```
manifest.js               gz=  1 KB
vendor.js                 gz=267 KB
app.js                    gz=456 KB   (was 965 KB at W0 baseline → -53 %)
admin-shell.js            gz=279 KB   (lazy, loaded on first admin classique nav)
admin-reports.js          gz=  9 KB   (lazy)
admin-kds.js              gz= 26 KB   (lazy)
admin-oss.js              gz=  6 KB   (lazy)
pos-shell.js              gz= 60 KB   (lazy, W1-A)
```

### Surface first-paint cost (gz)

| Surface             | Boot chain                          | Total gz |
|---------------------|-------------------------------------|----------|
| Any boot            | `manifest + vendor + app`           | **725**  |
| `/admin/pos`        | boot + `pos-shell`                  | **785**  |
| `/admin/kds`        | boot + `admin-kds`                  | **752**  |
| `/admin/oss`        | boot + `admin-oss`                  | **731**  |
| `/admin/employees`  | boot + `admin-shell`                | 1004     |

---

## What W1-C did NOT deliver (deferred / out of scope)

1. **POS first-paint < 220 KB KPI** — still **not met** (785 KB). Closing it requires a dedicated `pos-app.js` entry-point with its own Vue root. **Recorded as W2 architecture decision**.
2. **Frontend (vitrine) lazy split** — kept static for SEO and first-paint reasons. Re-evaluate after Lighthouse audit.
3. **Bundle analyzer report** — not generated (would have been 5+ MB). Acceptable trade-off; raw chunk sizes already prove the gain.
4. **E2E test for lazy chunk loading** — pending W1-C #3 + W1-C #4 follow-up cycles per Claude's W1-A/W1-B audit recommendations.

---

## Invariants check

| Invariant                          | Status | Evidence                                                  |
|-----------------------------------|--------|-----------------------------------------------------------|
| Backend pricing SSOT              | OK     | No frontend pricing logic added/touched.                  |
| OrderStatus enum authoritative    | OK     | `pos:lint:status` clean.                                  |
| `branch_id` data isolation        | OK     | Routing-only change; no data layer touched.               |
| Dispatch after DB commit          | OK     | No backend touched.                                       |
| OrderService/Frontend symmetry    | OK     | No service touched.                                       |
| Frozen zones                      | OK     | `router/modules/*.js` not in any frozen registry.         |

## Pricing guard

```
[pos:lint:pricing] WARN 1779: signoff-pending until 2026-05-10 — block tolerated, fails after that date.
[pos:lint:pricing] OK — scanned 53 files in 3 dirs.
```

Expected: HG-2 still pending human sign-off. WARN, not FAIL.

---

## Files touched

| File                                                         | Change                                              |
|--------------------------------------------------------------|-----------------------------------------------------|
| `tools/refactor/lazy_router_modules.mjs`                     | NEW — reproducible refactor script (kept in-tree)   |
| `resources/js/router/modules/*.js` (25 files)                | 114 imports converted from static to lazy           |
| `reports/baseline/POS_V4_PERF_HISTORY.md`                    | NEW — perf history SSOT                              |
| `reports/audit/W1C_LAZY_ADMIN_BRIEF_2026-04-26.md`           | NEW — this brief                                     |

`router/index.js` **not modified** — `DashboardComponent`, `NotFoundComponent`, `ExceptionComponent` kept static (boot/fallback critical).

---

## Questions for Claude (audit)

1. Approve the chunk grouping strategy (4 admin chunks: `admin-shell` / `admin-reports` / `admin-kds` / `admin-oss`)? Any case for finer granularity?
2. Approve keeping `frontendRoutes.js` static (SEO consideration)? Or W1-C+ follow-up to lazy-split it with prefetch?
3. The reproducible refactor script is kept in `tools/refactor/`. Should it be documented in `AGENTS.md` as a reusable pattern, or moved to one-shot/archived after this cycle?
4. The +53 KB W0→W1-A delta is closed. Should the residual risk in `AUDIT_W1A_CODESPLIT_CLAUDE_2026-04-26.md` be marked RESOLVED in `AUDIT_FINAL_W0PLUS_CLAUDE_2026-04-26.md` ST-* tracker?
5. Confirm POS-first-paint <220 KB is **W2 architecture work** (dedicated `pos-app.js` entry), not a W1 micro-exit.
6. Any next W-level (W1-C #3 KDS-only deeper split, W1-C #4 Kiosk magic ints, or jump to W2)?

---

## Rollback

```bash
cp -r /tmp/router-modules-bak.w1c/* resources/js/router/modules/
git checkout webpack.mix.js  # if you want to also revert vendor chunking
npm run production
```

(Backup at `/tmp/router-modules-bak.w1c` — make a permanent copy if intervention is delayed.)
