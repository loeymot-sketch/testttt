# S4 — OSS (Order Status Screen) MAIN audit
Date: 2026-05-17
Auditor: OSS MAIN (Claude Code)
Mode: READ-ONLY · Anti-drift · file:line
Scope: customer-facing wall display (`/admin/order-status-screen`) + dual-mode (auth admin widget + unauth public TV)

## Surface map (verified line counts)

| Layer | File | LOC | Role |
|---|---|---|---|
| Shell | `resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue` | 61 | Thin wrapper, mounts ConnectionStatusBanner + 2 columns + popular panel |
| Column | `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue` | 352 | Preparing/Ready columns, Echo + OssSyncService wiring, audio chime |
| Panel | `resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue` | 63 | Popular items grid |
| Service-FE | `resources/js/services/OssSyncService.js` | 427 | State machine (IDLE/POLLING/BACKOFF/STOPPED), WS-aware fallback polling |
| Store | `resources/js/store/modules/orderStatusScreenOrder.js` | 70 | Vuex actions w/ auth-aware URL branching |
| Router | `resources/js/router/modules/orderStatusScreenRoutes.js` | 17 | Lazy-loaded SPA route |
| Service-BE | `app/Services/OrderStatusScreenOrderService.php` | 172 | `list()` + `listForBranch()` (dup) + `mostPopularItems()` |
| Controller | `app/Http/Controllers/Admin/OrderStatusScreenController.php` | 121 | 4 endpoints (auth: index, mostPopularItems · public: publicIndex, publicMostPopularItems) |
| Resource | `app/Http/Resources/CDSOrderDetailsResource.php` | 26 | 6 fields exposed (incl. `token`) |
| Resource | `app/Http/Resources/CDSPopularItemResource.php` | 27 | 4 fields exposed |

Tests: `tests/Feature/OSSReadOnlyTest.php` (33 lines, only verifies 405 on POST/PUT — does NOT exercise `publicIndex` payload, branch resolution, or RBAC), `tests/js/orderStatusScreenOssSync.spec.js` (22 lines, regex-grep of source — not an actual mount test), `tests/js/ossSyncFallback.spec.js` (125 lines, exercises STATE/BACKOFF/cadence).

## Scoring

| Dimension | Score | Notes |
|---|---|---|
| 1. Architecture | 75/100 | Thin shell appropriate, but L7 controller dup (`list` vs `listForBranch`) duplicates 30 lines of query logic; risk of drift |
| 2. Business | 60/100 | `token` exposed in public payload (PII risk, see F-S4-001); `mostPopularItems` query doesn't filter by branch (cross-branch leak, see F-S4-002); FIFO ordering correctly added (Z4-P1-02) |
| 3. UX | 70/100 | Large 40px font good for readability; column flash + sound on new-ready good; popular column hidden on mobile (`md:block hidden`) reduces utility; no order count badge; no "—" empty-state translation |
| 4. i18n | 35/100 | **CRITICAL:** EN `popular_menu_items` = "Articles à préparer" (FR text, wrong meaning); FR `ready` = "Prêt" (sing.) inconsistent with EN "Prêtes" (plural FR in EN file); `oss_main_aria` + `oss_popular_region_aria` missing in `bn.json` and `de.json` (will render raw keys for those locales) |
| 5. Integration | 55/100 | **CRITICAL:** Public wall (unauth) cannot use Echo private-channel (auth-gated `branch.{id}` channel); realtime updates depend ENTIRELY on 2s polling (see F-S4-003); audio chime + flash never fire on public wall because Echo handler is gated on `branchId>0` from auth store |
| 6. Tests | 30/100 | 1 superficial PHPUnit (POST/PUT 405 only), 1 trivial Vitest grep, 1 unit Vitest for service; ZERO PHPUnit coverage of `publicIndex`/`publicMostPopularItems`; no integration test verifying KDS bump → OSS visible <1s |
| 7. Performance | 70/100 | Composite indexes `(branch_id, status)` + `order_datetime` exist; `whereDate()` may not use functional index (full-day scan acceptable for single restaurant); 60s/2s polling cadence reasonable; **leak risk:** AudioContext + visibility listener can leak if `beforeUnmount` not called on TV reload |
| 8. Privacy | 45/100 | `order.token` (line 20 of CDSOrderDetailsResource) is a customer-facing identifier that could correlate orders cross-session; `order_serial_no` (line 19) is the human-readable order number — exposing it publicly leaks daily order volume to anyone on local network; absence of `branch_id` query validation means scanning `?branch_id=1..N` exposes multi-tenant operational data |

**TOTAL: 55/100** (lower-stake surface but multiple correctness issues)

## Findings

### F-S4-001 [P1 — Privacy/Compliance]
**File:** `app/Http/Resources/CDSOrderDetailsResource.php:20`
`'token' => $this->token` is exposed unauthenticated through `frontend/oss-order`. Even if token is the customer's order token (used for `/order-confirmed/{token}` link), surfacing it on a publicly readable wall payload allows screen-scrapers behind the same NAT to harvest tokens and then potentially open the customer's confirmation page (depending on `order-confirmed` route gating). Public wall display does NOT need `token` — only `queue_number` is shown in the UI (`PreparingAndReadyComponent.vue:26,47`); the `token` fallback only renders if `queue_number` is null (legacy delivery orders). Restrict `token` in the public path (`publicIndex` branch) or short-hash it. The "no PII" claim in controller comment (line 62-63) is wrong — `token` IS a session-equivalent identifier.

### F-S4-002 [P1 — Cross-tenant data leak]
**File:** `app/Services/OrderStatusScreenOrderService.php:88-96`
`mostPopularItems()` is NOT branch-filtered. It queries `Item::withCount('orders')` globally (Item model has no BranchScope; verified in `app/Models/Item.php`). On a multi-branch deploy, every wall (and the public unauth endpoint) shows TOP items across ALL branches mixed together — leaks competitive operational data (which branch sells what most). For V1 single-restaurant Le Cayenne it doesn't matter, but the audit comment in `publicMostPopularItems` doc (`OrderStatusScreenController.php:108-110`) is misleading because it implies branch-scoped semantics that don't exist.

### F-S4-003 [P1 — Realtime claim broken on public wall]
**File:** `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:190-193`
`subscribeEcho()` bails out when `branchId <= 0`. The PUBLIC wall display has no auth, no store auth state, and `authBranchId()` returns 0 — so `subscribeEcho` returns silently. Confirmed by channel auth in `routes/channels.php:25-39` which requires `$user` (unauth Echo private subscribe fails authorization). **Consequence:** on the actual customer-facing TV, realtime "ready" flash + audio chime NEVER fire from a KDS bump — only the 2s polling fallback updates the screen. SYNC-2 budget (POS pay → OSS visible 8s) is only met because the polling fallback was tightened to 2s (`OssSyncService.js:16`). The architecture claims realtime via Echo but the actually-deployed customer wall never receives Echo events. This contradicts master plan and needs either (a) a public Channel (non-private branch.{id}.public) or (b) honest documentation that public wall is poll-only.

### F-S4-004 [P1 — i18n broken/missing]
**File:** `resources/js/languages/en.json:958`
`"popular_menu_items": "Articles à préparer"` — French text in English file AND wrong semantic (says "Items to prepare" instead of "Popular items"). This will render French to English-locale users.
**Files:** `resources/js/languages/bn.json`, `resources/js/languages/de.json` — missing `oss_main_aria` + `oss_popular_region_aria` keys (verified: zero matches in both). German/Bengali ARIA labels will fall back to raw key or empty.
**Files:** `resources/js/languages/bn.json:1011-1012`, `de.json:1011-1012` — `"preparing": "En préparation"` + `"ready": "Prêt"` (French text leaked into BN and DE files; duplicate keys at different scopes).

### F-S4-005 [P2 — Service duplication]
**File:** `app/Services/OrderStatusScreenOrderService.php:45-65` vs `:107-127`
`list()` and `listForBranch()` duplicate the entire WHERE clause (token/KIOSK/TAKEAWAY+queue_number, status, today+advance) and ORDER BY logic. Two parallel maintenance points; future change to OSS filter must touch both. Should extract to `private function buildBaseQuery(): Builder` returning the unconfigured query, then both public methods chain `where('branch_id',$scope)`.

### F-S4-006 [P2 — Test coverage thin]
**File:** `tests/Feature/OSSReadOnlyTest.php`
Only 33 lines. Only checks that arbitrary POST/PUT returns 405. NO test that:
- `publicIndex` returns expected payload shape
- `publicIndex` resolves branch from `?branch_id=` query
- `publicIndex` falls back to "first active branch" when no query
- `publicIndex` payload excludes PII (per assertion in controller doc)
- `publicMostPopularItems` returns sane response when no orders exist
- KDS bump → OSS list reflects within polling interval
- 403 on `?branch_id=X` for branch staff scoping mismatch (auth path)

### F-S4-007 [P3 — UX: empty state token]
**File:** `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:29,50`
Empty-state placeholder is the literal `—` character. No translation, no helpful CTA. For a 24/7 TV display when no orders are active (lunch lull), the wall shows two columns with just `—`. Owner should consider branding/visual filler or "Aucune commande en cours" text.

### F-S4-008 [P3 — Mobile responsiveness]
**File:** `resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue:4`
`<div class="col-span-2 md:block hidden">` — popular items panel HIDDEN on `<md` viewports. If the wall display is a portrait kiosk or smaller TV, popular column disappears entirely without explanation. Wall is meant to be public displays — hiding panels by viewport is questionable design.

### F-S4-009 [P3 — Hardcoded color palette]
**File:** `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:18,25,38,46`
Magenta `#B0004D`, dark text `#1F1F39`, `#991B1B`, green `#1AB759`, `#2AC769` are all hardcoded Tailwind arbitrary values. Bypasses theme system; if owner changes brand palette per S25 Le Cayenne refresh (mobile app palette noir/rouge/jaune/blanc cf. memory), wall stays magenta.

### F-S4-010 [P3 — Audio gesture trap on customer wall]
**File:** `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:96-107`
AudioContext is created on first `pointerdown`/`keydown`. A TV wall in a restaurant NEVER receives user gestures, so `_audioCtx` stays null forever and the chime is silent. The code is correct (no spam warnings — that was the fix) but the "audio cue when ready" feature is effectively dead on the actually-deployed wall display. Either rely on visual flash only and remove sound code, or pre-authorize audio via a setup screen.

### F-S4-011 [P3 — Sustained-disconnect dev warn never seen on prod]
**File:** `resources/js/services/OssSyncService.js:243-263`
`_maybeWarnDisconnect` only fires in dev/testing env. On a production TV wall where Pusher/Echo is misconfigured, OPERATORS GET NO SIGNAL that realtime is broken — silent degradation. Should fire a single `console.warn` regardless of env (or surface in ConnectionStatusBanner) so an in-store techician can spot a broken WS during open hours.

### F-S4-012 [P2 — `_audioCtx` close in beforeUnmount during pointer event leaks]
**File:** `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:98-105,118-125`
Listeners use `{ once: true, passive: true }`. The `_audioInitListener` reference is captured by both listeners; when the FIRST fires (e.g. `pointerdown`), the second (`keydown`) is still attached and the reference still held. The `removeEventListener` in `beforeUnmount` is correct but the unused `keydown` listener stays attached when `pointerdown` fires first and vice-versa — minor leak, not catastrophic.

### F-S4-013 [P3 — `mostPopularItems` no caching, no debounce]
**File:** `resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue:45-47`
`mounted() { this.popularItems(); }` — fetched ONCE on mount, never refreshed. If wall stays open 24/7, popular items never update until SPA reload. No `setInterval`, no Echo trigger. Could be intentional (low-priority data), but contrasts with the rest of the wall being live-updated.

### F-S4-014 [P2 — Branch resolution silently falls back to first active]
**File:** `app/Http/Controllers/Admin/OrderStatusScreenController.php:78-84`
If client omits `branch_id` (or sends 0), controller uses `Branch::where('status', Status::ACTIVE)->orderBy('id')->first()`. For multi-branch deploys, this means every "default" wall shows branch with lowest ID regardless of physical location. Operators MUST hardcode `?branch_id=N` per wall — not documented, not validated, no error. Recommend: require explicit `branch_id` and 422 if missing in multi-branch deploys, or read from a host/location header.

### F-S4-015 [P3 — No CSRF/IDOR protection on public branch_id input]
**File:** `app/Http/Controllers/Admin/OrderStatusScreenController.php:78`
`(int) $request->query('branch_id', 0)` — no validation that the branch exists, is active, or is in the operator's tenant scope (multi-tenant). A scraper can enumerate `?branch_id=1..1000` and harvest queue numbers + tokens from every branch. For V1 single-restaurant this is moot; for SaaS multi-tenant V2 it's a blocker.

## What I cited but couldn't fully verify

- Agent-1 cited `OssSyncService.js:260-331` "sync state machine"; I read those lines (`_maybeWarnDisconnect`, `_scheduleNormalCadence`). The actual state-machine surface is `STATE.{IDLE,POLLING,BACKOFF,STOPPED}` defined at line 1-6. Range 260-331 is dev-warn + cadence scheduling, not the state machine per se.
- `eventContract.js` for KDS_BUMPED→OSS: there is NO event named `KDS_BUMPED`. The bump from KDS to OSS uses `OrderStatusChanged` (KDS calls `OrderStatusUpdate(status=PREPARED)` → `OrderStatusChanged` event → `PersistOrderStatusChangedToOutbox.php:53` → broadcast as `OrderStatusChanged` on `private-branch.{id}`). The OSS Vue listens via `eventContract.js` line 18.

## Verdict

OSS surface is functionally correct for V1 single-restaurant Le Cayenne and has decent fallback engineering (polling, backoff, visibility burst). However:
- **Realtime claim is partially false on the actual public wall** (F-S4-003)
- **PII exposure of `token` in public payload** (F-S4-001)
- **i18n broken** with French text leaking into EN/DE/BN files (F-S4-004)
- **Tests are skin-deep** (F-S4-006)
- **Service code duplication** (F-S4-005)

For Le Cayenne V1, OSS ships with caveats (audio dead on wall, popular items stale 24/7, branch_id leaks to scrapers). For multi-branch V2 SaaS, F-S4-002 + F-S4-014 + F-S4-015 are blockers.

---

## SUMMARY (≤ 500 words)

The OSS surface (FoodKing's customer-facing wall display) is a thin Vue shell of 61 lines wrapping two columns (Preparing/Ready) plus a popular-items panel, backed by a 172-line PHP service and a 427-line front-end OssSyncService state machine. The architecture is intentionally lightweight — a TV bolted to the wall that polls the same payload behind the counter. Score: 55/100.

The strongest engineering is the OssSyncService: clean state machine (IDLE/POLLING/BACKOFF/STOPPED) with WS-aware cadence (60s when Echo healthy, 2s when WS down), exponential backoff capped at 30s on 5xx, visibility burst-poll on tab focus, and jittered scheduling. Tests cover the state machine reasonably (`tests/js/ossSyncFallback.spec.js`). The dual-endpoint pattern (auth `/admin/oss-order` for the admin dashboard widget, public `/frontend/oss-order` for the wall display) is a sensible mitigation of the 401-empty-columns trap.

The weaknesses are severe enough to flag:

**F-S4-001 (P1 privacy)** — `CDSOrderDetailsResource.php:20` exposes `order.token` (the customer's confirmation token) on the unauth public endpoint. The wall UI never displays it (only `queue_number` is rendered). A scraper on local Wi-Fi could harvest tokens and potentially access `/order-confirmed/{token}`. The controller doc claims "no PII" — wrong.

**F-S4-002 (P1 leak)** — `mostPopularItems()` is NOT branch-filtered (Item model has no BranchScope). Multi-branch deploys mix all branches' top sellers into a single anonymous result — operational data leak.

**F-S4-003 (P1 realtime broken on public wall)** — `subscribeEcho()` bails out when `branchId<=0` (line 193). The actual customer-facing TV has no auth context, so Echo is never subscribed; channel auth in `routes/channels.php:25-39` requires `$user`. Result: real-time "Ready" chime + flash NEVER fire on the wall — only the 2s polling fallback updates it. The "KDS bump → OSS update <1s p95" claim is false for the deployed surface; it's 2-3s p95 via polling.

**F-S4-004 (P1 i18n)** — EN file has `"popular_menu_items": "Articles à préparer"` (French text, wrong meaning); BN + DE files have FR text leaked into them at line 1011-1012; `oss_main_aria` ARIA keys missing from BN + DE entirely. The wall in English locale will display French.

**F-S4-005 (P2)** — Service `list()` + `listForBranch()` duplicate 30 lines of WHERE clause across two methods (deliberate per comment, but creates drift surface).

**F-S4-006 (P2)** — Only 33 lines of PHPUnit (POST 405 sanity), no test of `publicIndex` payload, branch resolution, RBAC. Vitest is grep-based not mount-based.

Secondary findings (P2/P3): branch_id falls back silently to first-active (F-S4-014), branch_id input unvalidated (F-S4-015), audio chime dead on TV (no user gesture, F-S4-010), popular items never refresh (F-S4-013), hardcoded color palette (F-S4-009), empty-state `—` placeholder not translated (F-S4-007), popular column hidden on `<md` viewports (F-S4-008), `_audioInitListener` minor leak (F-S4-012), dev-only WS-disconnect warn invisible to prod operators (F-S4-011).

V1 ship-ok for Le Cayenne with caveats; V2 SaaS NO-GO until F-S4-001 + F-S4-002 + F-S4-014 + F-S4-015 are healed.
