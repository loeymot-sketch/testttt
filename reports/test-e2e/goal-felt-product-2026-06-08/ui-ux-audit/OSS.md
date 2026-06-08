# OSS (Order Status Screen — customer "preparing / ready" wall) — UI/UX Audit

**Date:** 2026-06-08
**Surface:** Order Status Screen (the customer-facing wall: `EN PRÉPARATION` | `PRÊT`)
**Harness:** disposable clone `http://127.0.0.1:8766` / DB `foodking_e2e` (read-only audit; main thread applies fixes)
**Served-bundle currency GATE:** PASSED — `curl :8766/js/admin-oss.js | grep -c "oss-conn-slow|oss_no_orders|oss-scroll-shift"` = 8, and `lsof` confirms `:8766` was launched from this worktree. Every screenshot reflects current worktree code (the worktree component is 562 lines; the main-repo shadow at the same logical path is the OLD 505-line version with none of the heals — do NOT audit against it).
**Scratch spec:** `tests/e2e/zz-uiux-oss-2026-06-08.spec.js`
**Screenshots:** `tests/e2e/__screenshots__/uiux-oss-2026-06-08/`
**Component under audit (NON-frozen, fixable):**
- `resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue` (68 lines — shell, 2-col grid)
- `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue` (562 lines — the two columns, marquee, pill, chime)
- `resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue` (present but NOT mounted on OSS per Wave Q-3 owner directive)

---

## VARIANTS AUDITED

| Variant | Route | Auth | Chrome | Status |
|---|---|---|---|---|
| Authed wall (admin) | `/admin/order-status-screen` (alias `/order-status-screen`) | `loginAsAdmin` (branch_id=0) | Navbar present (fullscreen btn, branch picker, profile) | OK |
| Public wall (unauth) | same path, no session | none | **Chromeless** (no navbar — correct for a TV wall) | OK |
| Fullscreen mode | toggled from authed navbar | authed-only control | Chrome class-swapped away | OK |
| Empty state | wall with 0 qualifying orders | both | per variant | OK |
| Degraded (poll 5xx) | forced via route 503 | both | pill + (P2) red toast | issues |

Note `/order-status` appears as a `publicFriendlyPaths` entry in `app.js:165` but the router module (`orderStatusScreenRoutes.js`) only registers `/admin/order-status-screen` + alias `/order-status-screen`. `/order-status` is a documented friendly-path alias, not a distinct UI.

---

## PAGE-BY-PAGE / CONTROL-BY-CONTROL BREAKDOWN

### 1. `EN PRÉPARATION` column (left, magenta `#B0004D` header)
- Renders the right rows: `whereIn('status',[PREPARING=7, PREPARED=8])` split client-side; preparing list filtered to status 7. Token shown as `N°{queue_number}` or `{token}` (deep red `#991B1B`). **Verified live** (seeded 14 fresh TODAY orders → 14 li, `screenshot 01`).
- Big readable tokens: `text-[56px] font-extrabold` — readable at ≥3m (owner TV mandate met). **PASS.**
- FR copy correct: header `En préparation` (`label.preparing`, fr.json:670). No raw labels (`rawLabels: []` in probe). **PASS.**

### 2. `PRÊT` column (right, green `#1AB759` header)
- Status-8 rows, green `#0E7C3A` tokens. New-ready orders get `oss-new-ready oss-pulse-ready` (bounce + 1.6s pulse, ~10s window) + whole-column green flash (`oss-ready-flash`). **Verified** (`screenshot 01/02`).
- `aria-live="polite"` + `role="status"` on the ready `<ul>` — SR announces newly-ready orders. **PASS.**

### 3. Auto-scroll marquee (overflow tail reveal) — **FP-04 heal VERIFIED**
- Trigger: `items.length > 8` toggles `.oss-autoscroll` (pure-CSS 30s keyframe). For ≤8 orders, no scroll. **PASS.**
- Tail reveal: `updateOssScrollShift()` (PreparingAndReadyComponent.vue:446-458) sets `--oss-scroll-shift = -(scrollHeight − clientHeight)` per column; keyframe peaks at `translateY(var(--oss-scroll-shift,-50%))` (CSS line 549). **DOM-verified:** at 1920×1080, `scrollHeight 1280 − clientH 963 = 317`, `--oss-scroll-shift = -317px` (exact). At 1280×720, overflow 490 → shift `-489px` (1px rounding, harmless). **Visual-verified:** `screenshot 02` (t=27s, near 90% marquee peak) shows the END of both queues revealed (`P0008→P0014`, `R0004→R0013`) — a near-end customer DOES see their N°. The old fixed `-50%` would have hidden the tail; the heal is real and correct. **PASS.**
- Recompute hooks: on `_hydrateFromRows` (`$nextTick`) and on `window resize` (TV rotation). **PASS.**

### 4. Staleness pill "Connexion lente — affichage en différé" — **FP-24 heal present; P3 root cause confirmed (transient, NOT permanent)**
- Renders `v-if="connectionDegraded"` as a fixed pill `top:64px; right:12px; z-index:40` (amber `rgba(180,83,9,.94)`, white text). **DOM-verified position:** `top:64`, `right:12`, `280×32px` — sits cleanly BELOW the 64px navbar, no overlap with content (`screenshot 07`, and the public chromeless `screenshot 05`). The earlier "pill-below-navbar" heal holds. FR copy correct (`label.oss_connection_slow`, fr.json:933). **PASS on placement & copy.**
- **P3 (confirmed, see FIX P3-OSS-03):** `connectionDegraded = !this.wsConnected || this.pollDegraded` (lines 132-134). The `!wsConnected` half means a **transient** WS transport blip flips the pill on even while the 5s poll keeps the board current. **Reconciled with direct WS probe** (`window._wsService.isConnected()` + `Echo.connector.pusher.connection.state` on both variants): in a steady state on `:8766`, transport = `connected` on BOTH public and authed, and `pillPresent: false` on both — so the pill is NOT permanent. `screenshot 02` caught the pill during a transient WS flap across the 27s capture window (the box's `ws:6001` flaps; browser falls back to polling per MEMORY SYNC-WS-01). So the defect is the prior "transport-loss-not-stale" flag exactly: the pill conflates transport state with display staleness and fires on transient WS loss even though the wall is poll-resilient and current. In production (soketi UP per MEMORY) it should not normally fire; severity P3.

### 5. Connection-degraded RED TOAST — **NEW P2**
- On a `list()`-triggered fetch that 5xx's, a global axios interceptor (`bootstrap.js:250-255`, bucket `srv`) fires `alertService.error('Service indisponible — réessayez dans quelques instants.')` — a **red dismissable toast that overlaps the navbar** on a customer-facing surface (`screenshot 07`). The OSS already has a designed degradation channel (the subtle amber pill); the red toast is redundant + alarming + has an `×` close affordance a customer shouldn't need. (The 5s `OssSyncService` poll does NOT toast — it emits `error`→`pollDegraded`→pill. Only the component's own `list()` path — mount, Echo, `realtime-order-update`, ws-`connected` — funnels through the toasting interceptor.)

### 6. Empty state — **heal VERIFIED**
- 0 qualifying orders → centered `Aucune commande` (`label.oss_no_orders`, fr.json:934) in BOTH columns, grey `#A0A3BD text-[28px]`. **Verified clean** (`screenshot 06`): no stray loading bar, no raw label, headers crisp. The earlier empty→"Aucune commande" heal holds. **PASS.**

### 7. Fullscreen toggle (authed-only control) — **VERIFIED WORKING**
- Button in shared navbar `BackendNavbarComponent.vue:12-16` (`aria-label="Plein écran"`, green maximize icon), shown `v-if path.includes('order-status-screen')`. Click → `fullScreen()` (navbar:~575) swaps `.db-main`→`.db-main-customer customer-display hiddenHeader` and `.db-header`→`active hidden`, then `toggleFullscreen()` (OS requestFullscreen). **DOM-verified:** before `{headerHidden:false}` → after `{headerHidden:true, headerActive:true, mainCustomer:true}`; **visual-verified** `screenshot 08` = chromeless edge-to-edge wall. The class-swap fires even when headless OS-fullscreen no-ops. **PASS.** (The public wall is already chromeless without this button — see variant table.)

### 8. Loading overlay — **NEW (split P3 + P2-inferred)**
- `LoadingContentComponent` = `VueElementLoading spinner="bar-fade-scale" :is-full-screen="false"` overlays the OSS grid while `loading.isActive` is true. `list()` (lines 460-472) sets `isActive=true` then `false`.
- **P3 (screenshot-proven):** on initial load the spinner covers the WHOLE board over still-empty/faded columns (`screenshot 01-old-frame`, `03-old-frame` before the fix; reproducible by capturing before first hydrate). Minor — a wall boots once.
- **P2 (code-inferred, repro limited):** on a **branch_id>0 staff wall**, Echo is subscribed and each `OrderStatusChanged`/`OrderCreated` push calls `list()` (lines ~330, ~335) → `loading.isActive` flips true → the full-board spinner FLASHES over POPULATED content on every order event. Cannot be reproduced under `loginAsAdmin` (branch_id=0 → `isPublicWall` → `subscribeEcho` early-returns, Echo off, 5s poll only via `OssSyncService` which does NOT toggle loading). Repro requires branch>0 staff creds — flagged, not blindly healed.
- **Branding (low):** spinner color `#696cff` (indigo) is off-brand vs Cayenne `#F4501E`. Note only.

### 9. Public error path — info-leak — **FP-22 heal VERIFIED**
- `OrderStatusScreenController::publicIndex` catch (verified file) `report($exception)` server-side and returns generic `{status:false, message:'Service momentanément indisponible.'}` (422) — never `getMessage()`. **Verified:** forced-422 body contains NO `SQLSTATE|select|from \`|table` (`ERROR-PATH BODY contains SQL? false`), generic message only (`screenshot 05`). No SQL/table leak to a LAN device. **PASS.**

### 10. Double-chime / double-flash on hydration — **prior P2, MITIGATED (not a confirmed double-fire)**
- Multiple `_markNewReady` entry points exist (Echo `OrderStatusChanged` line ~328; `_hydrateFromRows` line ~429 fed by `OssSyncService` 'sync', `list()`, `realtime-order-update`, ws-`connected`). The race IS guarded: Echo pre-registers the id in `_echoMarkedReady` (line ~326), and `_hydrateFromRows` skips ids in `prevPreparedIds` OR `_echoMarkedReady` (lines 428-435), clearing the set one-shot after. Residual theoretical race (sync arriving between Echo-mark and list-refresh) is mitigated. **Not reproduced** (Echo off under admin). Report as mitigated, residual-theoretical — NOT a confirmed defect.

### 11. Sidebar / chrome management
- `OrderStatusScreenComponent.mounted()` calls `closeSidebar()` (collapses admin sidebar, hides `.db-header` active), `beforeUnmount()` restores. **PASS** (authed wall hides the admin nav clutter; `screenshot 01` shows only the slim top bar, no left sidebar).

---

## PRIORITIZED FIX LIST (non-frozen)

> All in `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue` unless noted. Two findings touch SHARED files (`bootstrap.js` axios interceptor, `BackendNavbarComponent.vue`) — flagged cross-surface.

### [P2] OSS-FIX-01 — Red error toast over the customer wall on fetch failure (CROSS-SURFACE source)
- **File (real source):** `resources/js/bootstrap.js:250-255` — the GLOBAL `window.axios` response interceptor, `srv` bucket. **Source confirmed by string match:** the toast in `screenshot 07` reads exactly "Service indisponible — réessayez dans quelques instants." = `error.service_unavailable` (bootstrap.js:254). This is NOT the OSS `list()` catch text — `list()` (PreparingAndReadyComponent.vue:470) toasts `err.response.data.message` (the 503 body, `'down'`) **or** `message.something_wrong` ("Une erreur est survenue."), neither of which is the observed string. The store imports bare `axios` but `bootstrap.js:14` sets `window.axios = axios` (same singleton), so the interceptor fires for the OSS fetch independently of and ahead of `list()`'s own catch.
- **Broken:** any 5xx on an OSS fetch shows a dismissable red toast that overlaps the navbar on the customer-facing wall. The OSS already has a designed amber staleness pill; the red `×` toast is redundant + alarming for a customer.
- **Repro:** route `**/api/admin/oss-order` → 503; open `/admin/order-status-screen` authed → red toast top-right over navbar (`screenshot 07`).
- **Screenshot:** `07-staleness-pill.png`.
- **Fix:** make the global interceptor route-aware — skip the `srv`/network toast buckets when `location.pathname.includes('order-status-screen')` (customer surfaces own their own degradation UI: the amber pill). NOTE: swallowing in `PreparingAndReadyComponent.list()` will NOT suppress this toast (wrong source — the interceptor fires first), so the OSS-only option does not solve it.
- **owner_gated:** CROSS-SURFACE — the fix lives in shared `bootstrap.js` (affects every admin/POS/KDS surface's 5xx toasting). Coordinate before editing; flag for owner review of the route-aware suppression.

### [P2] OSS-FIX-02 — Loading spinner flashes over populated board on every order event (staff wall)
- **File:** `PreparingAndReadyComponent.vue` `list()` lines 460-472 (toggles `loading.isActive`) + Echo handlers lines ~330/~335 (call `list()` per push).
- **Broken:** on a branch_id>0 staff wall, every `OrderStatusChanged`/`OrderCreated` push triggers `list()`, flashing the full-board `LoadingContentComponent` overlay over already-rendered orders — a jarring strobe on a busy wall. (`OssSyncService` poll path correctly does NOT toggle loading; only `list()` does.)
- **Repro:** branch>0 staff creds (not available under `loginAsAdmin`); code-inferred. Initial-load spinner is screenshot-proven (`01`/`03` loading frames).
- **Screenshot:** initial-load overlay visible in early `01`/`03` frames (now captured post-hydrate as clean).
- **Fix:** only show the overlay on the FIRST load (e.g. `if (!this._hydratedOnce) loading.isActive = true`), or never toggle `loading` from Echo-triggered refreshes (push refreshes should be silent like the poll path). Keep the overlay for the genuine cold start only.
- **owner_gated:** NO (OSS component only).

### [P3] OSS-FIX-03 — "Connexion lente" pill fires on transient WS transport loss even when the board is current
- **File:** `PreparingAndReadyComponent.vue:132-134` (`connectionDegraded = !this.wsConnected || this.pollDegraded`).
- **Broken:** the `!wsConnected` half conflates transport state with display staleness. A transient WS blip (the box's `ws:6001` flaps; SYNC-WS-01) flips the pill on even though the 5s poll keeps the board current. **NOT permanent** — direct WS probe shows transport `connected` + `pillPresent:false` on both variants in steady state; `screenshot 02` caught a transient flap during the 27s window. The wall is poll-resilient by design, so transport loss alone is not "affichage en différé" → false alarm that trains customers to ignore the cue.
- **Repro:** observe the wall across a WS flap on `:8766` (transient); pill appears while data stays fresh (`screenshot 02`). Steady state = no pill (WS probe).
- **Screenshot:** `02-authed-wall-1920-t27-marquee.png` (pill during transient flap on a current board).
- **Fix:** drive the pill off actual staleness — `pollDegraded` (real 5xx backoff) and/or a "last successful hydrate older than N×cadence" timestamp — NOT `!wsConnected`. Matches the prior falsification flag "connexion lente on transport-loss-not-stale" (verify + anchor, confirmed).
- **owner_gated:** NO (OSS component only).

### [P3] OSS-FIX-04 — Initial-load spinner color off-brand
- **File:** `resources/js/components/admin/components/LoadingContentComponent.vue` (`color="#696cff"`) — shared component; OSS-only override not trivial.
- **Broken:** the boot spinner is indigo `#696cff`, not Cayenne `#F4501E`. Minor branding inconsistency on the customer wall.
- **Repro:** cold-load the wall (`screenshot 01`/`03` initial frames).
- **Fix:** low priority; if pursued, prefer suppressing the overlay on Echo refreshes (OSS-FIX-02) so it shows once; a per-surface color override would touch the shared loader (cross-surface) — defer.
- **owner_gated:** cross-surface (shared loader) — defer / note-only.

---

## TOP MUST-FIX

1. **[P2] OSS-FIX-02** (OSS-scoped, owner_gated: NO) — stop the full-board loading spinner from strobing over populated orders on every Echo push (staff wall). Silent push-refresh; overlay on cold start only. Best ROI: fully inside the OSS component, no cross-surface coordination.
2. **[P2] OSS-FIX-01** (CROSS-SURFACE — shared `bootstrap.js` interceptor) — make the global 5xx toast route-aware so the customer wall shows only its amber pill, not a red `×` toast over the navbar. Most visible to a customer, but the fix touches shared code → owner-review the route-aware suppression.
3. **[P3] OSS-FIX-03** (OSS-scoped, owner_gated: NO) — drive "Connexion lente" off real staleness (`pollDegraded`/last-hydrate age), not `!wsConnected`, so a transient WS flap doesn't false-alarm on a current board.

---

## VERIFIED-HEALED (do not re-litigate)
- **FP-04** marquee real-overflow reveal — DOM `--oss-scroll-shift = -(scrollHeight−clientHeight)` exact; tail revealed (`screenshot 02`).
- **FP-22** public error path — generic message, NO SQL/table leak (body scan clean, `screenshot 05`).
- **FP-24 + pill-below-navbar** — staleness pill renders at `top:64 right:12`, no navbar/content overlap.
- **empty → "Aucune commande"** — both columns, no stray loading bar (`screenshot 06`).
- **Fullscreen toggle** — class-swap to chromeless customer-display verified (`screenshot 08`).
- **Public wall chromeless** — no navbar, immersive, readable (`screenshot 04`).
- **Double-chime** — guarded by `_echoMarkedReady` + `prevPreparedIds`; residual race theoretical, not reproduced.

## REPRO LIMITATIONS (honest scope)
- Echo/chime/staff-wall behaviors (OSS-FIX-02 live repro, double-chime live repro) require **branch_id>0 staff creds**. Under `loginAsAdmin` (branch_id=0), `authBranchId()<=0` → `isPublicWall` true → `subscribeEcho`/`_playReadySound` early-return and the wall polls every 5s. These are anchored by code file:line, not live-fired.
- `:8766` has a flapping WS (browser `ws:6001` intermittently fails → polling fallback; SYNC-WS-01), which is why OSS-FIX-03's transient-pill condition is observable here during flaps (but steady-state transport reconnects → pill clears, confirmed by WS probe).

## TEARDOWN
Seeded 28 fresh TODAY orders to exercise overflow/marquee, then reverted all 28 `order_datetime` back to 2026-05-28 — wall confirmed empty again (`rows=0`). Clone left clean.
