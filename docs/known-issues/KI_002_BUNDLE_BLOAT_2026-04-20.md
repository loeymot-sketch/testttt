# KI-002 — POS/Kiosk/Admin frontend bundle bloat (4.4 MB → cible 1.5 MB)

**Status** : OPEN — no immediate remediation planned
**Severity** : P1 (UX latency, network cost, low-end hardware risk, no data integrity impact)
**Discovered** : 2026-04-20 by `VERIFY_15_OBSERVABILITY_PERF` (F-VERIFY-15-03)
**Tracking** : `tasks/execute-2026-04-20/` — `P12_BUNDLE_POS_SPLIT` (created in PLAN_POST_VERIFY but not yet scheduled)
**Related** : F-VERIFY-15-03 (VERIFY_15), F-VERIFY-17-01 (build pipeline V17), C12 convergence (TRACKER §3)

---

## TL;DR

The compiled `public/js/app.js` weighs ~**4.4 MB** uncompressed against an architectural target of ~1.5 MB. The bundle ships POS, Kiosk, Admin, KDS, OSS, Pos-Wizard, charts, and 3rd-party libs in a single file, with no route-level code splitting and no dynamic imports for heavy widgets (Swiper, vue-select, vue-chartjs). Initial load on a 3G-equivalent connection or low-end Android kiosk takes ~6-10 s vs a target ≤ 3 s.

## Measured surface (2026-04-20)

| Metric | Current | Target | Gap |
|---|---|---|---|
| `public/js/app.js` (uncompressed) | ~4.4 MB | ≤ 1.5 MB | **+193%** |
| `public/js/app.js` (gzip) | ~1.0 MB (estimated) | ≤ 400 KB | +150% |
| Initial parse time (mid-range device) | ~600 ms | ≤ 200 ms | +200% |
| Routes shipped in main bundle | All (POS/Kiosk/Admin/KDS/OSS) | Per-route lazy | — |

(Update these numbers with `npm run prod` followed by `ls -lh public/js/app.js` if not measured today.)

## Root cause analysis

1. **No route-level code splitting** : `webpack.mix.js` produces a single `app.js` for all surfaces. Vue Router lazy imports (`() => import('./...')`) are not used.
2. **3rd party libs eagerly loaded** : `swiper`, `vue-select`, `vue-chartjs`, `chart.js`, `dompurify`, `pusher-js`, `axios`, `firebase`, etc. are all in the main entry.
3. **Mix manifest stale** (related to F-VERIFY-17-01) : `pos-wizard.js` is built separately but `webpack.mix.js` doesn't declare it, so the build pipeline is brittle.
4. **No tree-shaking analysis** : likely large unused exports from lodash, moment, firebase SDK.
5. **No bundle analyzer in CI** : drift is invisible until manual check.

## Production impact

- **Kiosk** : booting a kiosk with empty cache on Wi-Fi 3G takes ≥ 8 s before first interactive. Operators report perceived lag at chain restaurants opening for breakfast.
- **POS** : back-office reload after deployment costs 3-5 s of staring at a white screen for cashiers.
- **Network cost** : on multi-branch SaaS rollouts, ~3 MB × N tablets × M deploys/day = significant CDN bill.
- **Lighthouse** : Total Blocking Time and Largest Contentful Paint scores degraded.
- **Low-end Android kiosks** : risk of OOM on 1 GB RAM devices when bundle parses in main thread (Chrome ~50 MB heap for 4 MB bundle).

## What is NOT impacted

- **No data integrity** : it's purely a performance/UX issue.
- **No NF525 fiscal** : the bundle ships and runs correctly; just slowly.
- **No security** : no extra attack surface vs a smaller bundle.
- **No correctness regression** : all features work, just slower to start.

## Active sentinels

- `npm run prod` is the primary detection — bundle size is visible at every build.
- No CI alert on bundle size drift (recommended : `bundlesize` package or webpack-bundle-analyzer JSON output).
- No production RUM (Real User Monitoring) telemetry for First Contentful Paint per-surface.

## Remediation plan (P12_BUNDLE_POS_SPLIT, not scheduled)

1. **Quick wins** (~1 day) :
   - `import()` lazy on heavy admin sub-routes (KDS, OSS, charts dashboards)
   - Tree-shake lodash → lodash-es per-method imports
   - Verify gzip/brotli enabled in nginx (if not already)
2. **Mid term** (~1 week) :
   - Split entry per surface : `pos.js`, `kiosk.js`, `admin.js`, `kds.js`
   - Vue Router lazy imports across the app
   - Move Pusher/Firebase to dedicated chunk loaded after auth
3. **Long term** (~ 2-3 weeks) :
   - Migrate to Vite (R-RES-04 backlog) — auto code-splitting + ESM-native + faster HMR
   - Add `bundlesize` CI check with per-surface budgets

Estimated post-remediation : `pos.js` ≤ 800 KB, `kiosk.js` ≤ 600 KB, `admin.js` ≤ 1.2 MB, `kds.js` ≤ 400 KB.

## Workarounds (production today)

- ✅ Enable gzip/brotli at nginx layer (likely already in place — verify)
- ✅ Set long Cache-Control headers on `public/js/app.js?id=<hash>` (mix manifest provides hash) so repeat visits don't re-download
- ✅ Pre-warm CDN before kiosk floor opens (manual)
- ⚠️ Avoid mid-day deployments that invalidate the cache for all kiosks simultaneously
- ❌ DO NOT disable mix versioning — it would break cache busting

## Detection in production

If a customer reports slow kiosk boot or POS reload after deployment :
1. Verify the bundle hash changed : `curl -sI <site>/js/app.js | grep -i etag`
2. Verify gzip is active : `curl -sH 'Accept-Encoding: gzip' -I <site>/js/app.js | grep -i content-encoding`
3. Check nginx access logs for `app.js` response time and size
4. Compare against this KI's measured baseline (~4.4 MB uncompressed, ~1 MB gzipped)

## Closure criteria

KI-002 will be marked CLOSED when ALL of the following are true :
- [ ] `public/js/pos.js` ≤ 800 KB uncompressed
- [ ] `public/js/kiosk.js` ≤ 600 KB uncompressed
- [ ] `public/js/admin.js` ≤ 1.2 MB uncompressed
- [ ] CI bundle size check enforced (`bundlesize` or equivalent)
- [ ] At least 1 production RUM data point confirming First Contentful Paint ≤ 3 s on mid-range device, ≤ 5 s on low-end kiosk
- [ ] `webpack.mix.js` (or successor `vite.config.js`) declares all entries in version control (no manual mix-manifest edits)

## Cross-references

- `reports/review/VERIFY_15_OBSERVABILITY_PERF_2026-04-20.md` — F-VERIFY-15-03 (source)
- `reports/review/VERIFY_17_I18N_DEPLOY_2026-04-20.md` — F-VERIFY-17-01 (related : build pipeline drift)
- `reports/review/VERIFY_TRACKER_2026-04-20.md` — convergence C12
- `plans/PLAN_POST_VERIFY_2026-04-20.md` — `P12_BUNDLE_POS_SPLIT` cycle definition (deferred)
- `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md` — companion KI for the dispatch bug (different domain, same governance pattern)

## Revision history

- **2026-04-20** : Created (V11 #2, foodking-routine-implementer Composer cycle). Documents F-VERIFY-15-03 + workarounds + closure criteria.
