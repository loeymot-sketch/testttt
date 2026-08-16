# AUDIT_PLAN.md — goal-4chantiers-2026-08-16

Base URL: `http://127.0.0.1:8000` (confirmed HTTP 200 at pre-flight).
Branch under audit: `pos/category-first-caisse-2026-06-23`, commits `69b10f0aa..10cff6e76`
(6 commits: `b7e5240ba` web-order alert, `f1433a6b9` wait-tiers backend, `4b7574598`
stock badge, `1410105e4` tracking_token fix, `51d72fc15` /suivi page + kiosk
enrichment, `10cff6e76` brain doc-only).

**Correction to mission brief**: the POS cart-item edit button
(`.pos-v5-cart-item__edit`, `editCartLine(index)`) and the web-order red panel
(`.pos-shortcuts__panel--web`) both live in
`resources/js/components/admin/pos/PosComponent.vue`, NOT
`resources/js/components/admin/pos/ItemComponent.vue` — grep confirmed zero
matches for the edit-button class/handler in `ItemComponent.vue`. Waves A/B
below use the corrected file. `resources/css/pos-v5.css` (`.pos-v5-cart-item__edit`
around line 852) is correct as given.

Existing helpers confirmed present and reusable (no new helpers to write):
`tests/e2e/helpers/login.js` (`loginAsAdmin`, `loginAsPosOperator`,
`loginAsChefOperator`, `loginAsKiosk`), `tests/e2e/helpers/kiosk-auth.js`
(`loginKiosk` — API-level Sanctum `kiosk:order` token),
`tests/e2e/helpers/kiosk-order.js` (`placeKioskOrder(page, opts)` → returns
`{ orderId, orderSerialNo, queueNumber, ... }` — already does the full
kiosk-order-placement dance, use it verbatim for Wave D instead of driving the
kiosk wizard UI by hand), `tests/e2e/helpers/mega-audit-snap.js` (artifact
quartet recorder), `tests/e2e/helpers/rate-limit.js`
(`clearFoodKingRateLimits()`).

`tracking_token` is auto-generated on both `Order::create()` and
`FrontendOrder::create()` (mirrored `booted()` hook, confirmed in
`tests/Feature/Order/OrderTrackingTest.php`) — GStack agents do **not** need
a bespoke seeder for Wave C/D fixture orders; any order created via
**tinker against the real dev DB** (existing `branch_id=1`, existing items —
NOT `Order::factory()`, which is PHPUnit-`RefreshDatabase`-only and would not
exist against the live dev DB this audit runs against) will carry a valid
48-char token immediately.

---

## Wave structure (5 waves, max 6 respected)

| Wave | Concern | Surfaces | Spec file |
|---|---|---|---|
| A | POS cart-item edit button + wizard sauce/garniture restore bug | POS (single context) | `tests/e2e/test-e2e-goal-4chantiers-wave-A.spec.js` |
| B | Web-order alert: triple beep + red panel | POS (single context) | `tests/e2e/test-e2e-goal-4chantiers-wave-B.spec.js` |
| C | Public tracking page `/suivi/:token` — all states + admin-cookie regression | Public + Admin-cookie (2 contexts) | `tests/e2e/test-e2e-goal-4chantiers-wave-C.spec.js` |
| D | Kiosk order → waiting screen → QR round trip | Kiosk (1-2 contexts) | `tests/e2e/test-e2e-goal-4chantiers-wave-D.spec.js` |
| E | Stock intelligence — dashboard widget + POS badge (empty vs populated) | Admin + POS Operator (2 contexts) | `tests/e2e/test-e2e-goal-4chantiers-wave-E.spec.js` |

Each wave's spec is runnable standalone (`npx playwright test <spec>`),
`workers: 1` respected (already set in `playwright.config.js:55`). Each wave
owns `tests/e2e/__screenshots__/test-e2e-goal-4chantiers-wave-<W>/`.

---

## Wave A — POS cart-item edit button + wizard restore (free-sauce bug)

**File**: `resources/js/components/admin/pos/PosComponent.vue`
(`.pos-v5-cart-item__edit` template ~L1250-1263, `editCartLine()` ~L5077),
`resources/css/pos-v5.css` (`.pos-v5-cart-item__edit` ~L852-866). Frozen zone
adjacent: the wizard itself (`public/js/pos-wizard.js`,
`public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php`) is
FROZEN — capture around it, do not touch.

**Context**: 1 (`loginAsPosOperator`).

**Product choice**: pick a real Tacos (or equivalent multi-choice product with
a variation-level sauce AND at least one "1ère sauce gratuite"/extra-priced-0
sauce) from the live catalogue — grep item names via
`php artisan tinker --execute="App\Models\Item::where('status',5)->whereNull('deleted_at')->where('name','like','%Tacos%')->pluck('name','id');"`
per CLAUDE.md §3bis SSOT discipline (never invent a product name).

**States to capture** (numbered):
1. `01-cart-empty` — POS baseline before add.
2. `02-wizard-open-add` — wizard opened via "+" for the chosen product, before
   any selection (frozen wizard UI, capture only).
3. `03-wizard-sauce-selected` — free sauce (extra, price 0, name containing
   "sauce") selected in the wizard, plus at least one non-sauce garniture
   (e.g. oignons/tomate) selected in the SAME composition, to prove the two
   categories render in their correct UI sections simultaneously.
4. `04-cart-line-added` — product added to cart; the pencil/edit button is
   visible at 28px with the red-tinted persistent background (not 22px
   transparent) on the cart line.
5. `05-wizard-reopen-edit` — click the edit pencil on that cart line; wizard
   reopens PRE-FILLED. **Critical assertion**: the free sauce chosen in state
   3 must appear in the wizard's SAUCE section (checked/selected sauce pill),
   NOT in the garniture/extras section. The non-sauce garniture from state 3
   must independently appear correctly checked in the garniture section.
6. `06-wizard-edit-confirm` — confirm the edit without changing anything;
   cart line total/composition must be byte-identical to state 4 (no drift
   from the restore round-trip).

**DOM assertions**: `.pos-v5-cart-item__edit` computed size ≥ 28px (not the
old 22px), non-transparent background color present. Wizard DOM (read via
`get_page_text`/`read_page` inside the wizard iframe/overlay, NOT edited) for
state 5: sauce pill has an "active/selected" class AND lives under the sauce
list container, not the garniture list container.

**Cross-surface / numeric integrity**: cart line price after edit-confirm
(state 6) must equal the price at add-time (state 4) — a free sauce
(price 0) misfiled as garniture must not silently add/drop a price delta.

**Acceptance criteria — cannot fail**: state 5's sauce placement (P0 if the
regression the implementer claims to have fixed is still present — this is
the single highest-value assertion in this wave). Price identity state
4 == state 6. Edit button visibly non-transparent/28px.
**Best-effort**: capturing a SECOND multi-choice product family (e.g. a
Sandwich/Galette) if time allows, since only one is required by the mission.

---

## Wave B — Web-order alert: triple beep + red panel

**Files**: `resources/js/components/admin/pos/PosComponent.vue`
(`_playWebOrderAlertSequence()` ~L3970-3984 — fires `_playNewOrderBeep()` at
t=0, t=10000ms, t=20000ms when `normalized.origin === 'web'`;
`.pos-shortcuts__panel--web` ~L580/657/6199-6201 — border-left
`#d32f2f`), `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue`
(pre-existing `.pos-tracker-card-source--online` badge, `#d32f2f` on
`#FDECEA` background, at L2339 — NOT new, do not attribute this to the fix;
the NEW thing is the `pos-shortcuts__panel--web` border and the beep
sequence).

**Context**: 1 (`loginAsPosOperator`), page kept open/foregrounded for the
Web Audio API to actually fire (user-gesture requirement noted in code
comment ~L3993).

**Trigger strategy (owner-recommended, use this)**: seed a real web order
mid-test via tinker/API against `branch_id=1` with `source_surface='web'` (or
equivalent `origin`/`source` field used by `normalized.origin` — verify exact
column via
`grep -n "source_surface\|normalized.origin" resources/js/components/admin/pos/PosComponent.vue`
before writing the spec), then poll the already-open POS page (existing
polling loop, do not shorten below its real interval) until the panel
appears. This is realistic (mirrors the actual production trigger — a web
order landing while the cashier's tab is open) rather than a synthetic
DOM/store injection, per the mission's explicit ask for a "realistic way to
trigger this."

**States to capture**:
1. `01-pos-before-web-order` — baseline, no web orders queued, `.pos-shortcuts__panel--web` in its `--empty` variant.
2. `02-web-order-seeded-panel-red` — after the seeded order surfaces:
   panel border-left is `#d32f2f` (measure computed style, don't eyeball the
   PNG alone), panel populated with the order.
3. `03-beep-t0` / `04-beep-t10s` / `05-beep-t20s` — instrument
   `AudioContext`/`createOscillator` calls via `page.evaluate` injected
   BEFORE the seed (a counter wrapping `_playNewOrderBeep`, or listen for
   oscillator `start()` calls) to get a technical (non-visual) proof of
   exactly 3 beeps at ~0s/10s/20s — screenshots alone cannot prove audio
   fired. Record the counter value + timestamps in the network/console
   artifact, not just a PNG.

**Acceptance criteria — cannot fail**: exactly 3 beep-triggers within a
20s±2s window (not 1, not more) for a web-origin order. Panel border-left
computed color `rgb(211,47,47)` (`#d32f2f`) — not the old blue. No P0 if the
audio-instrumentation approach is infeasible in headless Chromium — in that
case fall back to static verification (source inspection + a screenshot with
a seeded order already in queue at page load, per the mission's explicit
fallback clause) and disclose the downgrade in the wave report rather than
silently skipping.
**Best-effort**: verify a kiosk/POS-origin order still gets exactly 1 beep
(regression check — the mission states "other channels keep the existing
single beep").

---

## Wave C — Public tracking page `/suivi/:trackingToken` — all states + admin-cookie regression

**Files**: `resources/js/components/frontend/tracking/OrderTrackingPageComponent.vue`
(368 lines, `data-testid` values confirmed: `ot-loading`, `ot-not-found`,
`ot-cancelled`, `ot-ready`, `ot-in-progress`, `ot-almost-ready`),
`resources/js/components/DefaultComponent.vue` (`theme === 'tracking'` branch
~L53-55, renders bare `<router-view>` with NO navbar/sidebar/footer — this is
the component the regression fix lives in),
`resources/js/router/modules/orderTrackingRoutes.js` (route
`/suivi/:trackingToken`, `meta: { isTracking: true }`, deliberately no
`meta.auth`/`meta.isFrontend` so it falls through every guard as public),
`app/Services/OrderTrackingService.php`, `app/Http/Controllers/Frontend/OrderController.php` (`track`/`trackQr`).

**Contexts**: 2, both required —
- **C1 anonymous**: fresh `browser.newContext()`, no cookies.
- **C2 admin-session**: `browser.newContext()` → `loginAsAdmin(page)` first
  (establishes staff session cookie on `/admin/*`), **then navigate the SAME
  page/context** to each `/suivi/<token>` URL below. This is the exact
  regression the implementer flagged ("previously inherited the FULL admin
  shell") — testing it via a fresh/anonymous context would NOT catch a
  regression, per the mission brief's explicit warning. Do not skip C2.

**Fixture orders to seed via tinker** (real dev DB, `branch_id=1`, no
`Order::factory()`) — one order per state, capture the `tracking_token` for
each:
1. **in-progress-with-position**: status ACCEPT or PREPARING, several other
   active orders ahead of it in the queue so `position_ahead` is a
   non-trivial number (>2, so `almost_ready` is false).
2. **almost-ready**: status PREPARING/ACCEPT with `position_ahead <= 2`
   (component threshold: `ALMOST_READY_THRESHOLD=2`, confirmed in
   `KioskWaitingComponent.vue` comment ~L60) → almost-ready banner variant.
3. **ready/prepared**: status PREPARED (or DELIVERED, which the component
   treats specially — `status === 13` drives `readyHint()`) → `ot-ready`
   state with the ✓ checkmark.
4. **cancelled**: status CANCELLED → `ot-cancelled` state.
5. **not-found**: a syntactically-valid-but-nonexistent 48-char alnum token
   (route regex `[A-Za-z0-9]{48}`, confirmed `routes/api.php:1764`) → `ot-not-found`.
   Also capture a malformed token (wrong length, e.g. 10 chars) to confirm it
   404s cleanly at the route layer rather than reaching the Vue component in
   a broken loading state.

**States to capture** (× both contexts C1 and C2 = 12 total, plus the 2
malformed-token checks × 2 contexts = 4 more, 16 total):
`01-in-progress` .. `05-not-found`, `06-malformed-token`, each suffixed
`-anon` and `-admin-cookie`.

**DOM/visual assertions per state**:
- Zero admin chrome present in EITHER context: no `BackendNavbarComponent`,
  no `BackendMenuComponent`/sidebar DOM nodes, no kiosk-locked-shell wrapper.
  This is the single most important assertion of the whole audit (owner's
  top-priority feature) — check it structurally (query selector absence),
  not just visually.
- Status steps / queue position / wait estimate render with real numbers,
  no raw i18n keys, no `undefined`/`NaN` in the copy.
- `ot-not-found` and malformed-token states show a clean, non-technical
  "not found" card — no stack trace, no raw JSON, no blank white screen.
- `ot-almost-ready` banner text is present and distinct from the plain
  in-progress state (not just the same template with a number changed).

**Acceptance criteria — cannot fail (P0 if violated)**: admin chrome absent
in C2 for all 6 sub-states. Not-found and malformed-token never throw an
unhandled JS exception (check console artifact). Position/wait numbers are
internally consistent (an order with fewer orders ahead never shows a WIDER
wait range than one with more orders ahead, when compared across the fixture
set).
**Out-of-scope for this wave**: exact wait-minute-tier boundary math
(`WaitEstimateService` unit correctness) — that's backend logic, not a
visual/E2E concern; PHPUnit (`WaitEstimateEndpointTest.php`, already exists)
covers it.

---

## Wave D — Kiosk order → waiting screen → QR round trip

**Files**: `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`
(enriched: `data-testid="kiosk-almost-ready"`, `kiosk-position-ahead`,
`kiosk-track-qr` with `<img :src="trackQrUrl">` at ~L82-91,
`trackQrUrl` computed ~L274-277 → `GET /api/frontend/order/track-qr/{token}`).
`KioskWaitingComponent.vue` is enriched but not itself in the frozen list;
`KioskWizardComponent.vue`/`KioskAppComponent.vue`/`KioskUpsellComponent.vue`
ARE frozen — this wave should not need to touch the ordering wizard beyond
using `placeKioskOrder()`, which drives it via API, not DOM.

**Context**: 1, kiosk-authenticated (`loginAsKiosk` for the UI session,
OR use `tests/e2e/helpers/kiosk-order.js#placeKioskOrder(page, opts)`
directly — it already handles kiosk API auth + idempotency internally per
its JSDoc).

**Flow**:
1. `placeKioskOrder(page, { items: [...], paymentMethod: <cash|card|prepaid> })`
   with a real catalogue item (SSOT discipline — no invented product) →
   capture `{ orderId, orderSerialNo, queueNumber }`.
2. Navigate to `/kiosk/waiting/${orderId}`.
3. **Known local-environment gotcha (per mission brief)**: the QR/tracking
   data requires the kiosk machine's own authenticated session — if the
   waiting screen loads under a DIFFERENT/unauthenticated kiosk context than
   the one that placed the order, the QR/position data will legitimately be
   absent. Keep the SAME `page`/context across steps 1-3; do not split them
   across two Playwright contexts.
4. Advance the order server-side (tinker or existing status-change API) to
   ACCEPT then PREPARING to reach a state where `position_ahead`/QR render
   (the component gates some of this — check `kiosk-waiting-track` /
   `kiosk-waiting-meta` `v-if` conditions before assuming PENDING alone is
   enough).

**States to capture**:
1. `01-waiting-screen-initial` — immediately after order placed.
2. `02-waiting-screen-accept-status` — after status → ACCEPT/PREPARING,
   `kiosk-position-ahead` or `kiosk-almost-ready` banner visible with a real
   number/text (not a raw testid, not blank).
3. `03-qr-image-loaded` — `kiosk-track-qr img` must actually load (check
   `naturalWidth > 0` via `page.evaluate`, not just DOM presence — a broken
   `<img>` still exists in the DOM) pointing at
   `/api/frontend/order/track-qr/{token}`.
4. `04-qr-links-to-tracking-page` — fetch the QR endpoint's underlying token
   (from `trackQrUrl` or by decoding the QR image content if the harness
   supports it, otherwise read the `src` attribute's token segment) and
   confirm `/suivi/<that-token>` (from Wave C's route) renders the SAME
   order's data — this ties Wave D to Wave C, the only intentional
   cross-wave assertion in this audit.

**Acceptance criteria — cannot fail (P0)**: QR `<img>` `naturalWidth > 0`
(not a broken-image icon). Position/wait text renders with real data once
status is ACCEPT/PREPARING, for THIS kiosk-placed order specifically (not a
stale/other order's data — cross-check `orderId`/`orderSerialNo` against
what step 1 returned).
**Best-effort**: full "almost ready" banner state (requires other orders
queued ahead at a specific count — may need 1-2 additional filler kiosk
orders placed first via the same helper to engineer `position_ahead <= 2`
then not, unless time-boxed against Wave C already proving that banner
visually).

---

## Wave E — Stock intelligence: dashboard widget + POS badge

**Files**: `resources/js/components/admin/dashboard/DashboardComponent.vue`
(now mounts `StockLowAlertsWidget.vue`), `resources/js/components/admin/dashboard/StockLowAlertsWidget.vue`
(count badge next to title, uncapped count vs capped-5-row table),
`resources/js/components/admin/pos/PosComponent.vue`
(`lowStockCount` ~L2178-2198, badge `:badge="lowStockCount"` ~L198, GET
`admin/stock/low-alerts` ~L4078).

**Contexts**: 2 —
- **E1 POS Operator** (`loginAsPosOperator`): confirms the KNOWN, documented
  403-degrade case. Per the mission brief this is NOT a bug — the standard
  POS-operator test role lacks the `items` Spatie permission on
  `GET /api/admin/stock/low-alerts` (confirmed via curl in prior session).
  **What this wave DOES need to verify** (the part that's genuinely open):
  the empty/hidden state must be a CLEAN silent degrade — no raw error toast,
  no `403`/stack text visible in the DOM, no repeating console error spam
  from the polling loop (~L3668 "même tick pour le badge stock faible").
  Capture console artifact across at least 2 poll cycles to catch spam that
  a single snapshot would miss.
- **E2 Admin** (`loginAsAdmin`): the populated-state proof. Pre-flight for
  this context only: via tinker, pick one real low-stock-eligible item and
  either lower its `stock_levels.on_hand` at or below `threshold_low`, or
  raise `threshold_low` above current `on_hand` — whichever is the smaller,
  more reversible mutation — creating exactly one deterministic low-stock
  row. Capture. **Then revert the mutation immediately after capture** (this
  wave's teardown step, not deferred to a later cleanup pass) so the dev DB
  is not left in a false low-stock state for other waves/users.

**States to capture**:
- E1: `01-pos-badge-hidden-operator` (badge absent, count=0 or 403), console
  artifact spanning ≥2 poll intervals.
- E1: `02-dashboard-widget-absent-or-empty-operator` (if operator role can
  even reach `/admin/dashboard` — if not, note as N/A, don't force it).
- E2: `03-dashboard-widget-populated-admin` — widget visible with count
  badge next to title showing the real number, table showing the seeded row
  (and correctly still capping the TABLE at 5 rows if more exist, while the
  badge itself shows the true uncapped count — verify these two numbers can
  legitimately differ and that's by design, not a bug).
- E2: `04-pos-badge-visible-admin` — "Stock faible" badge visible in POS
  header toolbar with correct count, not `null`/`undefined`.
- E2: `05-badge-click-navigates` — click the badge, confirm navigation to
  `/admin/stock/rupture` and that the SAME item appears there (numeric/
  identity consistency across widget → badge → destination page).
- E2 teardown: `06-post-cleanup-verify` — after reverting the stock mutation,
  re-load dashboard/POS and confirm count returns to pre-seed baseline (own
  regression check on the wave's own cleanup, not just a report claim).

**Acceptance criteria — cannot fail (P0)**: E1 shows zero console error
spam and zero raw error text over the polling window. E2 badge count ==
widget badge count == actual `stock_levels` row count meeting the threshold
(numeric integrity across the two surfaces, per REVIEWER_PROTOCOL). E2
teardown genuinely restores prior state (verified, not assumed).

---

## Cross-surface scenarios (named, span multiple waves' fixtures)

- **SYNC-TRACK-1** (Wave D↔C): kiosk-placed order's QR token, decoded, opens
  `/suivi/<token>` and shows data consistent with what the kiosk waiting
  screen itself displayed at the same moment (position/status match within
  one poll cycle).
- **SYNC-STOCK-1** (Wave E only, single-surface but two components): the
  seeded low-stock item's count must be identical between
  `StockLowAlertsWidget.vue` (dashboard) and the POS toolbar badge — both
  read the same `admin/stock/low-alerts` endpoint per the code comment at
  PosComponent.vue ~L4072-4078 ("même endpoint, même sémantique... SSOT").
  A mismatch here is a P0 numeric-integrity violation per REVIEWER_PROTOCOL.
- **SYNC-WEB-ALERT-1** (Wave B only): the red panel's populated order list
  and the beep-triggering order must be the SAME order (panel shows N web
  orders, exactly one alert sequence fired per NEW arrival, not per poll
  tick — verify no beep-per-poll spam if the seeded order stays in queue
  across multiple poll cycles).

No scenario intentionally spans Wave A (POS cart edit) with any other wave —
it is the most self-contained of the four features.

---

## Out-of-scope for this audit

- Exact minute-tier boundaries of `WaitEstimateService` (backend unit-level
  correctness — covered by existing `WaitEstimateEndpointTest.php`
  PHPUnit, not re-derived here).
- Load/stress testing of the `admin/stock/low-alerts` or `track`/`trackQr`
  endpoints (this is a functional+visual audit, not a perf audit).
- Any change to the frozen wizard files
  (`public/js/pos-wizard.js`, `public/css/pos-wizard.css`,
  `resources/views/admin-pos-v4.blade.php`) beyond capturing around them.
- Fiscal/NF525 chain re-verification — none of the 4 features touch
  `app/Services/Fiscal/*`; `git diff --stat` confirms zero frozen-zone files
  changed in `69b10f0aa..10cff6e76` (see Pre-flight §4 below).
- Mobile RN / web-standalone codebases (out of this backend's mandate per
  CLAUDE.md §3bis — not touched by these 6 commits anyway).
- Re-auditing the pre-existing `.pos-tracker-card-source--online` badge
  (Wave B note) — it predates this goal and is not part of the shipped
  diff.
- Multi-branch / multi-tenant tracking-token collision testing — V1 LOCAL is
  single-branch (`branch_id=1`); token uniqueness across branches is not
  applicable until V2.

---

## Pre-flight setup (orchestrator, before spawning GStack capture wave)

1. **Server health**: `curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8000/login` → confirmed `200` already.
2. **Migrations**: `php artisan migrate:status` → confirmed 0 truly-pending
   (an earlier naive `grep -i pending` false-matched table names like
   `pending_payment_confirmations` — re-verified with a status-column-only
   grep, genuinely 0 pending).
3. **Frozen-zone diff baseline** (must stay 0 for the whole audit —
   re-check after any fix wave):
   ```
   git diff --stat 69b10f0aa..10cff6e76 -- \
     resources/js/components/frontend/kiosk/KioskWizardComponent.vue \
     resources/js/components/frontend/kiosk/KioskAppComponent.vue \
     resources/js/components/frontend/kiosk/KioskUpsellComponent.vue \
     resources/js/components/admin/pos/PaymentComponent.vue \
     resources/js/components/admin/pos/v5/PosV5TrancheRow.vue \
     public/js/pos-wizard.js public/css/pos-wizard.css \
     resources/views/admin-pos-v4.blade.php \
     app/Services/Fiscal/
   ```
   Already confirmed empty for the shipped commit range. Any GStack fix wave
   touching these requires an owner LOCK per CLAUDE.md — should not be
   needed for this audit's expected findings.
4. **Login credentials**: `loginAsAdmin`/`loginAsPosOperator`/
   `loginAsChefOperator`/`loginAsKiosk` all already validated by existing
   suites — no re-verification needed unless a wave reports auth failures.
5. **Test-data orphan cleanup**: run before Wave B/C/D/E fixture seeding —
   `cleanupOrphanTestOrders` (exported from `login.js`) or equivalent — so
   prior audit runs' orders don't pollute `position_ahead` counts in Wave C
   or the web-order panel in Wave B.
6. **Bundles fresh**: `ls -lt public/js/*.js public/mix-manifest.json | head -5`
   — confirm timestamps postdate `51d72fc15`/`4b7574598`/`b7e5240ba`; if
   stale, `npm run dev` before any capture (a stale bundle would make Waves
   A/B/C/D/E all capture the PRE-fix UI silently).
7. **Reports scaffold**: `reports/test-e2e/goal-4chantiers-2026-08-16/round-1/`
   already created; `REVIEWER_PROTOCOL.md` already copied into the run dir.
8. **Branch/stash hygiene**: `git status --short` shows only pre-existing
   unrelated dirty files (worktree lock, vendor.js, unrelated report JSONs)
   — none touch the 4 features' source files. `git stash list` — confirm
   empty before starting (re-check, not assumed from this planning pass).
9. **Wave C/D fixture data**: seed via tinker AFTER orphan cleanup, using
   real `branch_id=1` + real catalogue items, immediately before each wave
   runs (so `position_ahead` counts reflect a known, deliberately-constructed
   queue rather than whatever else is in the dev DB at that moment).
10. **Wave E stock mutation**: explicitly scoped to E2 only, explicitly
    reverted in-wave (state `06-post-cleanup-verify`) — do not leave a
    dangling low-stock row for later waves or the human owner to trip over.

---

## Constraints honored

- 5 waves (≤ 6 max).
- Each spec runnable standalone.
- `workers: 1` (already configured).
- Each wave owns its own screenshots directory.
- Adversarial review is a separate out-of-band Agent step per wave, not a
  Playwright spec — not planned here.
