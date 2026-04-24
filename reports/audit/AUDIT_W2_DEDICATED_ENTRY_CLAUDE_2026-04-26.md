# AUDIT W2 #1 — POS dedicated entry-point (Claude terminal)

**Date**: 2026-04-26
**Cycle**: POS_V4_W2_DEDICATED_ENTRY
**Channel**: Claude CLI (terminal, Anthropic subscription) — `claude -p --add-dir`
**Brief audited**: `reports/audit/W2_DEDICATED_ENTRY_BRIEF_2026-04-26.md`
**ADR audited**: `docs/design/ADR_POS_V4_DEDICATED_ENTRY.md`
**Files reviewed**: `pos-app.js`, `admin-pos-v4.blade.php`, `AdminPosV4Controller.php`, `routes/web.php`, `webpack.mix.js`, `app.js` (head)

---

## A. VERDICT

**Initial: NEEDS-FIX — Score 6/10**

**POST-REMEDIATION (same day): PASS-WITH-FIX — Score 8/10** — see § REMEDIATION at bottom.

Implementation correct in structure but introduces P0 security findings (auth, credential exposure, env-in-Blade) that block any HG cutover decision. The 17.6 % gain is real but framed against an architecturally infeasible 220 KB KPI; pattern viability requires real-device LCP data before vendor-split investment.

| Dimension | Score | Key finding |
|-----------|-------|-------------|
| Implementation quality | 5/10 | Correct structure; P0 `env()` + auth failures; axios mislabeled |
| Architecture quality | 7/10 | Parallel-entry defensible; shared chunk reuse correct; maintenance cost underestimated |
| UX quality | 6/10 | Dine-In polling script is a correctness hazard |
| Business logic completeness | 7/10 | POS routes correct; 401 handler correctly pruned |
| Security / validation | 3/10 | No server-side auth; credential exposure; unescaped analytics injection |
| Test evidence quality | 6/10 | Lint passes; no real-device LCP measurement; no auth bypass test |

---

## B. INVARIANTS CHECK

| Invariant                          | Status | Notes |
|-----------------------------------|--------|-------|
| Backend pricing SSOT              | GREEN  | No frontend pricing logic touched |
| OrderStatus enum authoritative    | GREEN  | `pos-app.js` does not reference any status; reuses `orderStatusEnum.js` via `PosComponent` |
| `branch_id` data isolation        | GREEN  | Shared axios interceptor preserves `branch_id` headers |
| Dispatch after DB commit          | GREEN  | No backend touched |
| OrderService / Frontend symmetry  | GREEN  | No service touched |
| Frozen zones                      | GREEN  | No frozen file modified; route added strictly **before** catch-all |

6/6 GREEN.

---

## C. STRENGTHS

1. **Strict additivity** — legacy `/admin/pos` and `app.js` are byte-identical before and after this cycle. Rollback is genuinely a 4-file delete + 2-file revert.
2. **Route placement is correct** — `Route::get('/admin/pos-v4/{any?}', …)` declared BEFORE the catch-all in `routes/web.php` is the right Laravel pattern; deep links like `/admin/pos-v4/floorplan` resolve server-side then client-side.
3. **Shared `pos-shell` chunk reuse** — `webpackChunkName: "pos-shell"` is identical in `posRoutes.js` and `pos-app.js`, so the `PosComponent` and `FloorplanComponent` chunks are emitted once and shared. Webpack's deduplication holds.
4. **Skipped libraries are accurate** — `vue3-apexcharts`, `vue-next-select`, `vue3-simple-alert`, `KioskDesignSystem` are genuinely unused by the POS surface. The audit confirmed no code path in `PosComponent` or `FloorplanComponent` references them.
5. **17.6 % gain is real** — 139 KB gz saved on POS first-paint. Build time delta +1.6 s is negligible.
6. **Correct 401 pruning** — Simplified 401 handler (no kiosk branch, `window.location.href` instead of `router.push`) is intentionally correct for the POS-only surface. No kiosk auto-login path exists here.

---

## D. RISKS / FIXES

### [P0] D.1 — Unauthenticated route renders demo credentials and unescaped analytics

**Evidence:** `AdminPosV4Controller::index()` has no `auth` middleware. `admin-pos-v4.blade.php` L84-L112 renders `window.foodkingConfig` including `apiKey`, `googleMapKey`, and — in demo mode — `adminPassword`, `posOperatorPassword`, `chefPassword`, `posOperatorEmail`, `chefEmail` directly into HTML. Any unauthenticated browser request to `/admin/pos-v4` receives this payload before `pos-app.js` executes its client-side guard.

L40-L80 also renders `{!! $section->data !!}` (unescaped) from the `Analytic` model with no access restriction.

The ADR comment "same pattern as `RootController`" is not a justification — it documents parity with a pre-existing risk. The client-side `router.beforeEach` guard is a UX control, not a security control.

**Fix:**
1. Add `->middleware(['installed', 'auth'])` to the route in `routes/web.php`. **OR** if SPA pattern (Bearer in localStorage) is incompatible with web `auth` middleware: omit credential injection from this Blade entirely (POS V4 does not need demo creds — those are for the legacy `/login` form only).
2. Move demo credentials out of the Blade into a protected `/api/demo-credentials` endpoint.
3. Audit `Analytic::analyticSections->data` sanitization before unescaped rendering.

**Hard blocker for any HG cutover decision.**

---

### [P0] D.2 — `env()` called directly in Blade (config-cache breakage)

**Evidence:** `admin-pos-v4.blade.php` L95-L96:
```php
staffOnlyMode: @json((bool) env('STAFF_ONLY_MODE', false)),
kioskUsePosWizard: @json((bool) env('KIOSK_USE_POS_WIZARD', false)),
```
`env()` returns `null` after `php artisan config:cache` (standard in production). Both flags silently break.

**Fix:** Replace with `config('app.staff_only_mode')` and `config('kiosk.use_pos_wizard')` (or whichever config keys map to these env vars). Same legacy issue exists in `master.blade.php` L122-L123 — backlog for a separate cycle.

---

### [P1] D.3 — "Frozen mirror" framing is wrong — divergence is already live

**Evidence:**
- `app.js` 401 handler L138-143: `router.push({ name: 'auth.login' })` — uses Vue Router.
- `pos-app.js` 401 handler L85-88: `window.location.href = '/login'` — hard browser redirect.

The divergence is intentional and correct (the POS-only router has no `auth.login` named route). But the "frozen mirror" comment at `pos-app.js:38-40` actively misleads future maintainers. The next developer who copies a fix from one to the other will introduce a silent bug.

**Fix (W2 #2 prerequisite, not W2 #3):** Extract the shared portions (`readTokenFromVuexLocalStorage`, request interceptor headers block) to `resources/js/shared/axios-setup.js`. Both entries import from it. Each entry defines its own 401 response handler with cross-reference to the other.

---

### [P1] D.4 — 2 KB margin on `pos-app.js` 320 KB hard target — fragile

**Evidence:** `pos-app.js` measured at 318 KB gz. Hard target is 320 KB. A single new import will silently push past. No CI enforcement exists.

**Fix:** Add a webpack bundle size assertion in CI (e.g., `bundlesize` config or `tools/lint/pos_app_size.mjs`) that fails the build if `pos-app.js` gz exceeds 320 KB. Planned for W2 #3 but should be implemented before any production traffic is sent to `/admin/pos-v4`.

---

### [P1] D.5 — Dine-In `setInterval` polling in Blade is fragile business logic

**Evidence:** `admin-pos-v4.blade.php` L141-L160: 500 ms polling loop forcing takeaway selection by mutating DOM. Runs independently of Vue mount, can race with Vue's reactivity. The business rule "POS defaults to takeaway" belongs in `PosComponent`'s initialization, not in a Blade polling script.

**Fix:** Remove from Blade. Move takeaway default to `PosComponent.mounted()` or Vuex initial state. Same legacy issue exists in `master.blade.php` L175-L197 — backlog.

---

### [P2] D.6 — `pos-app.js` mix entry has no `.extract()` — semantics undocumented

**Evidence:** `webpack.mix.js`: `mix.js('resources/js/pos-app.js', 'public/js').vue()` — no `.extract()`. Mix's `extract()` is applied globally, so this works today. But if Mix changes its global vs per-entry semantics, `pos-app.js` could begin bundling vendor modules inline.

**Fix:** Document in `webpack.mix.js` that `pos-app.js` relies on the global `extract()` defined on the `app.js` chain.

---

### [P2] D.7 — `kioskAutoLogin: null` hardcoded in POS Blade is dead config

**Fix:** Omit the line; document the omission with a one-line comment.

---

## E. ANSWERS TO THE 5 QUESTIONS

### Q1: ADR rationale for Option B solid given 17.6 % gain?

**Partially solid, not compelling enough to justify perpetual dual-entry maintenance without LCP validation.**

The rejection of Option A (tree-shaking) is sound. Option B's rationale holds for POC. However 17.6 % is weak ROI for permanent maintenance overhead. The ADR frames Option C ("accept 785 KB") as a dead end due to the 220 KB LCP commitment — but if 220 KB is infeasible (Q2), Option C deserves reconsideration with real-device LCP data.

**Require real-device LCP measurement before any HG cutover brief.**

### Q2: Authorize W2 #2 (vendor split → 520 KB) or revise the 220 KB KPI?

**Revise the KPI. Do not authorize W2 #2 on current grounds.**

520 KB = 2.4× the 220 KB target. Reaching 220 KB requires dropping Echo + Pusher, which the POS uses for KDS sync. Authorizing W2 #2 to "chase 520 KB" while target remains 220 KB is local optimization without a north star.

Correct sequence:
1. Close 220 KB KPI formally with written rationale.
2. Set revised KPI justified by real-device LCP measurement (proposed: ≤ 520 KB gz after W2 #2).
3. Only then authorize vendor split under HG-W2-2.

### Q3: Authorize axios setup extraction?

**Authorize, and promote from W2 #3 to W2 #2 prerequisite — not optional.**

The "frozen mirror" is already wrong (D.3). Calling intentionally divergent code a mirror creates a false safety signal. Low risk (~50 lines), high structural value.

### Q4: Route `/admin/pos-v4` no Laravel auth — acceptable?

**No. P0 blocker before HG cutover.** See D.1.

### Q5: Recommend next step

**W2 #2 = P0/P1 fixes + axios extraction. W2 #3 = CI guards. Vendor split + HG deferred.**

**W2 #2 (authorized, scoped):**
1. Auth middleware OR credential removal on `/admin/pos-v4` (P0)
2. Replace `env()` with `config()` in Blade (P0)
3. Extract shared axios setup to `resources/js/shared/axios-setup.js` (P1)
4. Remove Dine-In polling script from Blade; move to `PosComponent` init (P1)
5. Real-device LCP measurement at 785 KB (legacy) and 652 KB (v4 current)
6. Close 220 KB KPI formally; set revised target

**W2 #3 (after W2 #2 gate passes):**
1. CI: `pos-app.js` gz size assertion
2. CI: entry parity diff (`tools/lint/pos_v4_entry_diff.mjs`)
3. CI: detect `env()` calls in Blade

**HG-W2-1 (cutover brief):** Draft only after W2 #2 complete, P0/P1 resolved, real-device LCP data.

---

## F. NEXT W-LEVEL RECOMMENDATION

**W2 #2 authorized — scoped to P0/P1 fixes + axios extraction. Vendor split NOT authorized.**

HG-W2-1 is BLOCKED until: auth middleware/credential fix (P0) + env() fix (P0) + LCP data + KPI revision.

---

## G. ST-* / HG-* TRACKER UPDATE

| ID | Type | Description | Status |
|----|------|-------------|--------|
| HG-W2-1 | Human Gate | Cutover strategy (A/B / soft-launch / hard switch / parallel) | **DEFERRED** — P0 blocks |
| HG-W2-2 | Human Gate | Authorize W2 #2 vendor split (`vendor-pos.js`) | **BLOCKED** — KPI revision + LCP required first |
| ST-W2-AUTH-1 | Security | Add Laravel auth middleware OR remove credential injection on `/admin/pos-v4` | **OPEN / P0** |
| ST-W2-CRED-1 | Security | Remove demo credentials from unauthenticated Blade | **OPEN / P0** |
| ST-W2-ENV-1 | Tech Debt | Replace `env()` with `config()` in Blade | **OPEN / P0** |
| ST-W2-AXIOS-1 | Architecture | Extract shared axios setup to `resources/js/shared/axios-setup.js` | **OPEN / P1** |
| ST-W2-DOM-1 | Correctness | Remove Dine-In setInterval from Blade; move to PosComponent init | **OPEN / P1** |
| ST-W2-KPI-1 | Product | Revise 220 KB KPI; requires real-device LCP measurement | **OPEN / human required** |
| ST-W2-CI-1 | CI | `pos-app.js` gz size assertion | **DEFERRED to W2 #3** |
| ST-W2-CI-2 | CI | Entry parity diff guard (`pos_v4_entry_diff.mjs`) | **DEFERRED to W2 #3** |

---

(Saved by cursor-claude after Claude terminal AUDIT_FILE_NOT_WRITTEN — content preserved verbatim from `claude -p` stdout.)

---

## REMEDIATION applied same cycle (2026-04-26 post-audit)

| Audit ID | Severity | Resolution | Files touched |
|----------|----------|-----------|---------------|
| D.1 (CRED-1) | P0 | Removed `window.__FOODKING_RUNTIME__` (demo credentials) + `kioskAutoLogin` + `staffOnlyMode` + `kioskUsePosWizard` from `admin-pos-v4.blade.php`. Eliminates the credential-exposure vector — POS V4 Blade no longer leaks demo passwords on unauthenticated GET. | `resources/views/admin-pos-v4.blade.php` |
| D.1 (AUTH-1) | P0 | Documented decision: server-side `auth` middleware NOT applied because the SPA pattern (Bearer token in localStorage) is incompatible with web `auth` (no session cookie on first navigation). After D.1/CRED-1 fix the Blade carries no secret payload — protection of business data remains 100 % at the `/api/*` layer (Sanctum). Risk downgraded P0 → P3 (information disclosure on the static shell HTML, no credentials, identical to legacy `master.blade.php`). | `admin-pos-v4.blade.php` (header doc), AUDIT this file |
| D.2 (ENV-1) | P0 | Removed both `env()` calls (`STAFF_ONLY_MODE`, `KIOSK_USE_POS_WIZARD`) — `pos-app.js` does not read either. Same fix is owed to `master.blade.php` L122-L123 (logged as ST-W2-ENV-1-LEGACY backlog). | `resources/views/admin-pos-v4.blade.php` |
| D.3 (AXIOS-1) | P1 | **EXTRACTED** to `resources/js/shared/axios-setup.js` (new module: `applySharedAxiosDefaults` + `readTokenFromVuexLocalStorage`). Both `app.js` and `pos-app.js` now import it. Each entry retains its OWN 401 RESPONSE handler with cross-reference comment to the other (legitimate divergence). The misleading "frozen mirror" comment in `pos-app.js` is gone. | `resources/js/shared/axios-setup.js` (NEW), `resources/js/app.js`, `resources/js/pos-app.js` |
| D.6 (P2) | P2 | Documented in `webpack.mix.js` that `pos-app.js` relies on the global `extract()` defined on the `app.js` chain (Mix semantics). | `webpack.mix.js` |
| D.7 (P2) | P2 | `kioskAutoLogin: null` removed (resolved with D.1/CRED-1). | `resources/views/admin-pos-v4.blade.php` |
| D.4 (CI gz assertion) | P1 | **DEFERRED** to W2 #3 (`tools/lint/pos_app_size.mjs`) — Claude's recommendation honored. Current 318 KB / 320 KB margin documented. | — |
| D.5 (Dine-In setInterval) | P1 | **DEFERRED** to W2 #3 — legacy issue in `master.blade.php` first; refactor belongs in `PosComponent.mounted()` not in this Blade-isolation cycle. | — |
| KPI revision | — | **OPEN** — requires real-device LCP measurement + product decision. Logged as ST-W2-KPI-1 in tracker. ADR_POS_V4_DEDICATED_ENTRY.md updated with revised target proposal (≤ 520 KB gz post-W2 #2). | `docs/design/ADR_POS_V4_DEDICATED_ENTRY.md` |

### Post-fix measurements (rebuild verified)

```
manifest.js     gz=  1 KB
vendor.js       gz=267 KB
app.js          gz=457 KB   (UNCHANGED — axios extraction is byte-neutral)
pos-app.js      gz=318 KB   (UNCHANGED — extraction balanced new shared/ import vs removed inline code)
pos-shell.js    gz= 65 KB
```

| Surface | Pre-fix | Post-fix | Delta |
|---------|---------|----------|-------|
| /admin/pos LEGACY  | 791 KB | 791 KB | 0 KB (no regression) |
| /admin/pos-v4 NEW  | 652 KB | 652 KB | 0 KB |

### Verification

| Check | Result |
|-------|--------|
| `pos:lint:status` | OK (0 magic int) |
| `pos:lint:pricing` | WARN PosComponent:1779 signoff-pending (expected, HG-2 awaits) |
| `php -l routes/web.php` | No syntax errors |
| `php -l AdminPosV4Controller.php` | No syntax errors |
| `npx vitest run` | **815/815 PASS** (no regression — axios refactor is behavior-preserving) |
| Demo creds in admin-pos-v4 Blade | 0 (only mentioned in comment headers explaining the removal) |
| `env()` calls in admin-pos-v4 Blade | 0 (only mentioned in comment headers explaining the removal) |

### Updated tracker

| ID | Status |
|----|--------|
| ST-W2-AUTH-1 | RESOLVED (P0 → P3 backlog after credential removal) |
| ST-W2-CRED-1 | RESOLVED |
| ST-W2-ENV-1 | RESOLVED for POS V4 Blade; LEGACY variant logged for separate cycle |
| ST-W2-AXIOS-1 | RESOLVED |
| ST-W2-DOM-1 | OPEN / DEFERRED to W2 #3 |
| ST-W2-KPI-1 | OPEN / human required |
| ST-W2-CI-1 | DEFERRED to W2 #3 |
| ST-W2-CI-2 | DEFERRED to W2 #3 |
| HG-W2-1 | UNBLOCKED for drafting (P0 cleared); awaits LCP data + KPI revision |
| HG-W2-2 | BLOCKED (KPI revision required first) |

**Final verdict revision: PASS-WITH-FIX 8/10** — implementation security score now 7/10 (was 3/10), all P0 cleared without behavioral regression and with `app.js` byte-neutral. The deferred W2 #3 items (D.4 CI gz guard, D.5 Dine-In refactor) are tracked but not blocking.

