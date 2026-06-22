# Audit Plan — Kiosk · KDS · OSS Cross-Surface Sync

**Run ID**: `kiosk-kds-sync-2026-05-11`
**Branch**: `feature/mobile-app-le-cayenne-2026-05-10`
**Working dir**: `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`
**Mission (verbatim)**: « là on fera la meme mission de test pour kiosk + kds + cross-surface sync ! max discipline et finir par tout valide raisonnemennt logique affichage et backend sync »
**Translation**: same E2E discipline as the just-converged `pos-kds-sync-2026-05-10` audit, now applied to **kiosk + KDS + OSS** with full cross-surface sync tracking. Owner mandate (CLAUDE.md): *"Numeric integrity is non-negotiable — same fact must equal across every surface"* and *"Silent errors are P0"*. Loop until truly green — no caveats, no companion-spec attribution.

**Surfaces in scope**
- Kiosk borne SPA — `http://127.0.0.1:8000/kiosk/idle` and `/kiosk/login` — Vue SPA (`KioskAppComponent.vue` shell + `KioskWizardComponent.vue` OR `KioskPosWizardComponent.vue` wrapper depending on `kioskUsePosWizard` flag); auto-login via `window.foodkingConfig.kioskAutoLogin = {username: 'kiosk-lecayenne', password: 'kiosk123'}`
- KDS — `http://127.0.0.1:8000/admin/kitchen-display-system` — `KitchenDisplaySystemComponent.vue` (kiosk-source cards rendered via `data-kds-order-card="kiosk"` lane, verified line 703)
- OSS (customer-facing) — `http://127.0.0.1:8000/admin/order-status-screen` — by-design only renders PREPARING + PREPARED orders (per pos-kds-sync D-002 reclassification — measurement at PREPARED bump, NOT at pay)

**Login helpers** (`tests/e2e/helpers/login.js`, all wipe rate-limit buckets first)
- `loginAsKiosk(page, 'kiosk-lecayenne', 'kiosk123')` → posts to `/kiosk/login`, SPA mounts, auto-login config injected
- `loginAsChefOperator(page)` → chef@lecayenne.fr / 123456 → `/admin/kitchen-display-system`
- `loginAsAdmin(page)` → admin@lecayenne.fr / 123456 → for OSS (admin-only route)

**Recorder helper** (mandatory per state)
- `tests/e2e/helpers/mega-audit-snap.js` → `attachMegaAuditRecorder(page, dir)` → `{ snap, dispose }`. Each `snap('XX-name')` writes the artifact quartet: `.png` + `.dom.html` + `.console.json` + `.network.json`. **DOM cap = 2 MB** (post pos-kds-sync R2 fix B-004 — earlier 1 MB cap truncated several Wave-D pos-suivi snapshots).

**Other helpers**
- `tests/e2e/helpers/sync-journey-trace.js` — DB seed/cleanup + cross-surface lifecycle assertions (`AUDIT-SYNC-JOURNEY-KIOSK-*` prefixes for this run)
- `tests/e2e/helpers/kiosk-order.js` — `getKioskApiToken`, `placeKioskOrder`, `placeKioskOrderTwice`, `placeKioskOrderTwiceDifferentPayload`, `cleanupKioskAuditOrders`. Reused as-is from pos-kds-sync R2 expansion.
- `tests/e2e/helpers/rate-limit.js` — `clearFoodKingRateLimits()`
- `tests/e2e/helpers/process-audit.js` — orchestration helpers

**Environment context (locked for this run)**
- `QUEUE_CONNECTION=sync` — jobs run inline; **no need** for `queue:work --once` between phases. Different from pos-kds-sync R1 (`database`); aligns with R2.
- `BROADCAST_DRIVER=pusher` BUT WS port 6001 unreachable in dev → frontend uses polling fallback. KDS degraded interval 2000 ms, disconnected 4000 ms; OSS disconnected 2000 ms (per D-002 fix). Wave-D timing budgets must accommodate the polling tick, not the (faster) Echo path.
- `kioskUsePosWizard=true` (env override possible) — kiosk SPA renders the **vanilla pos-wizard.js** via `KioskPosWizardComponent.vue` instead of `KioskWizardComponent.vue`. **Critical** — see Wave B + Risk register. Pre-flight MUST verify which wizard renders for the live config; Wave B captures whichever is active and FREEZES if pos-wizard.js.
- `bypassMode.payment=true` — TPE bypass active in dev (no real card terminal); receipt prints `🔧 MODE TEST — IMPRESSION BYPASSÉE` marker. Wave B / D specs MUST assert this banner is visible during paid flows so we never silently ship "test-mode receipt" UX into prod.
- `kioskConfirmationAutoReturnSeconds=30` — confirmation page auto-returns to idle. Wave A state `09-kiosk-confirmation` must capture BEFORE the 30 s timer fires; Wave A state `10-kiosk-auto-return-to-idle` must wait the timer + assert idle restored.
- Concurrent audit `borne-cats-309-318-2026-05-10` (visual catalog) is converged — kiosk catalog visual tour is OUT-OF-SCOPE here; we cover only the page-level kiosk states needed for the order journey.
- Concurrent audit `pos-kds-sync-2026-05-10` is converged — Wave D may inherit several lessons (axios interceptor for 4xx, palette sweep, KDS sticky banner, OSS polling tightened to 2 s, version-gating, idempotency middleware behavior).

**FROZEN ZONES (capture-only — NEVER patch)**
1. **POS Vanilla JS wizard** — `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php`. Owner declared "design parfait". **Even if rendered inside the kiosk via `kioskUsePosWizard=true`**, these files remain frozen. Wave B captures the rendered UI for audit but ZERO LINE patched.
2. NF525 backend — `app/Services/Fiscal/*`, `app/Models/Scopes/BranchScope.php`, `app/Http/Middleware/IdempotencyKeyMiddleware.php`, `app/Services/Pricing/PricingService.php`, `app/Domain/Order/OrderStateMachine.php` — read-only audit.
3. Kiosk Vue components (`KioskWizardComponent.vue`, `KioskAppComponent.vue`, `KioskUpsellComponent.vue`) — V1.x production-ready BUT auditable + patchable per memory `feedback_kiosk_wizard_not_protected`. Different from POS wizard: tests + bug fixes allowed; design changes require owner gate.

**State budget target**: ~58 PNGs across 4 waves (A=14, B=15, C=14, D=18; Wave D triple-quartet so capture-count is 3× per chronological state but state-count is 18 chronologically).

---

## Round-1 scope

This is a brand-new audit — no prior round to inherit. All four waves capture from scratch in round 1. Convergence rule (per CLAUDE.md §10 + pos-kds-sync precedent): two consecutive rounds with **set-equality on findings** AND `open_P0 == 0` AND `open_P1 == 0` on technical/sync findings. Visual-only P2/P3 backlog does NOT gate convergence but is logged for the `kiosk-visual-polish` follow-up.

---

## Pre-round environment hardening

Run BEFORE every capture round:

1. **`QUEUE_CONNECTION=sync`** — verify `php artisan tinker --execute="echo config('queue.default');"` prints `sync`. If `database`, either restart with `QUEUE_CONNECTION=sync` or schedule `queue:work --once --queue=high` after every state that POSTs (kiosk pay, KDS transition). Spec headers MUST self-declare which mode they assume and fail-fast on mismatch.
2. **`KIOSK_USE_POS_WIZARD`** — read live value via `php artisan tinker --execute="echo (int) env('KIOSK_USE_POS_WIZARD', 0);"`. Spec Wave B reads the same value via `await page.evaluate(() => window.foodkingConfig.kioskUsePosWizard)` and branches accordingly:
   - **`true`** → captures pos-wizard.js inside kiosk; FROZEN-ZONE diff check at end of round on `public/js/pos-wizard.js`, `public/css/pos-wizard.css` MUST be empty
   - **`false`** → captures `KioskWizardComponent.vue`; patches allowed via owner-gated heal
3. **Kiosk machine seed** — `KioskMachine::query()->where('username','kiosk-lecayenne')->where('status', 5)->first()` must return id=1, branch_id=1. Fail-fast if absent (do NOT auto-create — would mask deploy gap).
4. **Auto-login config** — verify `window.foodkingConfig.kioskAutoLogin === { username: 'kiosk-lecayenne', password: 'kiosk123' }` after `loginAsKiosk(page)`. If absent, the SPA stays on the login screen and Wave A states 02+ silently capture the wrong surface.
5. **Idle-screen state** — Wave A state `01-kiosk-idle` must start from a clean `/kiosk/idle`. Pre-flight purges any `AUDIT-SYNC-JOURNEY-KIOSK-*` orders + clears the kiosk session via `loginAsKiosk` re-issue.
6. **`kioskConfirmationAutoReturnSeconds`** — verify the live config value. Wave A state 09 capture window MUST be < this value − 5 s buffer. If config is mutated mid-run, spec must re-fetch.
7. **Bypass markers** — verify `window.foodkingConfig.bypassMode.payment === true` and `bypassMode.printingScreenMarker` non-empty. If false in dev, Wave B payment captures will hit a real TPE timeout (or 500) — spec MUST detect and either skip pay-leg or swap to cash.
8. **Idempotency cache** — `Cache::flush()` (or `Cache::tags(['idempotency'])->flush()` if tag-supported) at `beforeEach` for any wave that exercises double-tap or replay (Wave D state 17). Stale cache key from a prior run would mask the second request as a replay when in fact the test wanted to verify the live dedup path.
9. **Cleanup** — `php artisan iter15:cleanup-test-orders --apply` + helper-level `cleanupKioskAuditOrders('AUDIT-SYNC-JOURNEY-KIOSK')` at `afterAll` regardless of test outcome.

---

## Wave A — Kiosk visual page-by-page (idle → confirmation → auto-return)

**Spec file**: `tests/e2e/test-e2e-kiosk-kds-sync-2026-05-11-wave-A.spec.js`
**Screenshots dir**: `tests/e2e/__screenshots__/test-e2e-kiosk-kds-sync-A/`
**Surfaces / contexts**: 1 context — kiosk machine. Visual tour of every kiosk page reachable WITHOUT exercising the wizard popup deeply (Wave B owns that). Sample 3-4 categories covering simple items + items-with-options + (if catalog has it) sandwich split.
**Estimated states**: 14

### Numbered states

1. `01-kiosk-idle` — `/kiosk/idle` baseline; verify branding (Le Cayenne logo, palette noir/rouge/jaune/blanc — see memory `project_kiosk_design_refresh_2026-05-10`), tap-to-start CTA visible, language picker if rendered, no FOUC, no `Label.X` raw keys
2. `02-kiosk-tap-to-start` — tap on idle CTA; SPA transitions to categories welcome / order-type prompt; capture intermediate state if any
3. `03-kiosk-categories-welcome` — categories view first paint; assert ≥4 category tiles, no broken thumbnails, palette respected, cart panel empty / collapsed
4. `04-kiosk-category-1-simple` — open a simple-item category (e.g. drinks / supplements where add-to-cart is direct); assert grid render, no skeleton frozen
5. `05-kiosk-direct-add-no-wizard` — tap a simple item that adds to cart **without opening a wizard** (if such items exist in this catalog — verify in pre-flight); cart panel updates; cart line subtotal + total render. If no direct-add items exist in catalog, skip + log P3 finding "all kiosk items go through wizard".
6. `06-kiosk-category-2-with-options` — open a category whose items open a wizard (we capture only the tap → wizard-opens transition here; full wizard flow = Wave B); capture grid + wizard-opens-overlay first frame
7. `07-kiosk-cart-panel-bottom-sheet` — cart panel expanded as bottom-sheet (per memory `project_kiosk_design_refresh_2026-05-10`); verify line items grouped, qty steppers visible, grand total visible, recap chips if any
8. `08-kiosk-checkout-button` — tap checkout; capture transition (loading? confirmation modal? payment selector?)
9. `09-kiosk-payment-method-picker` — payment selector visible; capture CB / Espèces / TPE buttons; verify bypass-mode marker `🔧 MODE TEST — IMPRESSION BYPASSÉE` is rendered SOMEWHERE in DOM (banner or footer or modal — locate and assert)
10. `10-kiosk-payment-tpe-bypass-flow` — choose TPE; bypass kicks in; capture intermediate processing state(s); should NOT hang
11. `11-kiosk-confirmation-page` — confirmation page renders with order #, queue number, total; **capture WITHIN 5 s of payment confirm** (auto-return at 30 s); assert no NaN totals, no raw i18n keys, palette respected
12. `12-kiosk-confirmation-content-detail` — focus capture on the receipt content block (line items, taxes, total, queue ticket / QR if present)
13. `13-kiosk-auto-return-to-idle` — wait `kioskConfirmationAutoReturnSeconds + 2 s` (default 32 s); assert SPA returned to `/kiosk/idle` state (state-01 parity), no orphan modals, no console error during the timer
14. `14-kiosk-network-silent-error-sweep` — sweep the wave's `network.json` files; assert ZERO unallowlisted 4xx/5xx without paired DOM alert/toast (P0 silent_error per pos-kds-sync A-001 baseline)

### Acceptance criteria

| MUST PASS (P0/P1) | BEST EFFORT (P2/P3) |
|---|---|
| Every state console.json has 0 `level=error` (allowlist: vendor/pusher/wss noise) | Bottom-sheet animation polish |
| Network.json has 0 unexpected 4xx/5xx (allowlist: 401 logout, 422 form, 304 cache, intentional WS 1006 to port 6001) | Category transition smoothness |
| No raw i18n key visible (regex `^[a-z]+(\.[a-z_]+){1,4}$` in DOM) | Tap-to-start CTA hover/focus |
| Le Cayenne palette respected (no pink drift; noir/rouge/jaune/blanc per design refresh) | Queue-ticket visual treatment |
| Confirmation page renders within 5 s of payment confirm and auto-returns within configured timer ± 2 s | Receipt formatting consistency |
| Bypass-mode marker `🔧 MODE TEST — IMPRESSION BYPASSÉE` visible in DOM during payment flow (P1 — must never silently ship test mode) | Idle-screen language picker polish |
| Cart line subtotal + grand total update lock-step after qty change (P0 numeric_integrity) | Empty-cart illustration quality |
| Empty states pass quality bar (illustration OR copy ≥20 chars OR CTA) | |
| Silent-error sweep at state 14 finds 0 unallowlisted 4xx/5xx without paired DOM alert (P0) | |

---

## Wave B — Kiosk wizard flows (3 representative items)

**Spec file**: `tests/e2e/test-e2e-kiosk-kds-sync-2026-05-11-wave-B.spec.js`
**Screenshots dir**: `tests/e2e/__screenshots__/test-e2e-kiosk-kds-sync-B/`
**Surfaces / contexts**: 1 context — kiosk machine. Open wizard for at least **3 representative items** spanning the typology (one with options, one menu/extras, one composite or sandwich-split). Capture every wizard step + recap modal + cart line after wizard validates. Numeric integrity at each step (recap = cart line = backend).

**CRITICAL pre-step**: spec's `beforeAll` MUST read `await page.evaluate(() => window.foodkingConfig.kioskUsePosWizard)`:
- **`true`** → `KioskPosWizardComponent.vue` mounts the FROZEN `pos-wizard.js`. Wave B captures the pos-wizard.js UI inside the kiosk SPA. **NO PATCHES** to `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, or `resources/views/admin-pos-v4.blade.php` allowed during fix rounds. Frozen-zone diff check at end of every round: `git diff --name-only public/js/pos-wizard.js public/css/pos-wizard.css resources/views/admin-pos-v4.blade.php` MUST be empty. Findings against pos-wizard.js are logged but cannot be fixed in this audit — escalate to owner.
- **`false`** → `KioskWizardComponent.vue` renders. Patches allowed via owner-gated heal (kiosk wizard is NOT frozen per memory `feedback_kiosk_wizard_not_protected`).

**Estimated states**: 15

### Item triplet selection (orchestrator pre-pick during pre-flight)
Pick one item per type from the live catalog by querying `php artisan tinker`:
- **Item-1** (single options layer) — e.g. a drink with size variant or a plat with single options layer
- **Item-2** (multi-step with extras / supplements) — wizard exercising at least 2 steps + paid extras
- **Item-3** (composite / menu / sandwich-split if available) — wizard exercising menu pickers, upsell branch, or sandwich-half split (memory `project_kiosk_design_refresh_2026-05-10` mentions sandwich split as a kiosk-specific UX)

If catalog topology differs, fall back to: any 3 distinct catalog tiles that each open a wizard popup (DOM marker `[data-pos-wizard]` for pos-wizard.js path, OR `.kiosk-wizard` / `[data-kiosk-wizard]` for Vue path).

### Numbered states

**Item-1 (simple options)**
1. `01-b-tile-tap-item-1` — pre-tap baseline (catalog grid + selected tile highlight)
2. `02-b-wizard-open-item-1` — wizard overlay open; verify backdrop + modal centered + close affordance + selected variant default
3. `03-b-wizard-variant-pick-item-1` — pick a variant (or confirm default); recap delta visible
4. `04-b-wizard-recap-item-1` — recap step; price line matches catalog price + variant delta (P0)
5. `05-b-cart-after-validate-item-1` — wizard validate → cart shows 1 line; price = recap price (P0 numeric_integrity)

**Item-2 (multi-step + extras)**
6. `06-b-wizard-open-item-2` — wizard overlay open
7. `07-b-wizard-step-1-item-2` — first step (options layer 1)
8. `08-b-wizard-step-extras-item-2` — extras / supplements step; pick at least 1 paid extra
9. `09-b-wizard-recap-item-2` — recap; verify base + extras line items + delta price visible; recap line === Σ(base + extras)
10. `10-b-cart-after-validate-item-2` — cart line total = base + Σ(extras) (P0)

**Item-3 (composite / menu / sandwich-split)**
11. `11-b-wizard-open-item-3` — wizard open
12. `12-b-wizard-step-menu-or-split-item-3` — composite step (menu picker, sandwich-split halves, or upsell)
13. `13-b-wizard-recap-item-3` — recap
14. `14-b-cart-after-validate-item-3` — cart line total = computed (P0)

**Cart aggregate + edge**
15. `15-b-cart-three-lines-aggregate` — cart with all 3 items; grand total = Σ(line × qty) (P0 numeric_integrity); also dump cart payload via `await page.evaluate(() => window.__kioskCartStateForAudit?.())` to a `*.payload.json` sidecar capturing line items JSON for adversarial review. If `__kioskCartStateForAudit` shim is not exposed, fall back to extracting cart DOM into JSON.

### Acceptance criteria

| MUST PASS | BEST EFFORT |
|---|---|
| Wizard recap price === cart line price for each item (P0 numeric_integrity) | Step transition smoothness |
| Cart grand total at state 15 === Σ(line × qty) (P0) | Backdrop click vs explicit close parity |
| Wizard overlay is keyboard-dismissible (ESC) — captured in console/keyboard event | Animation jank |
| No console error during wizard open/step/validate flow | |
| Sidecar `15-b-cart-three-lines-aggregate.payload.json` captures cart store JSON for adversarial review | |
| **IF `kioskUsePosWizard=true`**: ZERO line of patch in `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php` (frozen-zone diff check at end of round) | |
| **Wizard branch declared** in spec header comment + first console.log of run (so reviewer can dispute the right surface) | |
| Bypass-mode markers visible if any item triggers a payment-adjacent UI (e.g. price warning banner) | |

---

## Wave C — KDS visual + lifecycle from kiosk-source orders

**Spec file**: `tests/e2e/test-e2e-kiosk-kds-sync-2026-05-11-wave-C.spec.js`
**Screenshots dir**: `tests/e2e/__screenshots__/test-e2e-kiosk-kds-sync-C/`
**Surfaces / contexts**: 1 context — chef operator. Visual tour of KDS surface + lifecycle transitions for **kiosk-source** orders only. Status flow: `ACCEPT(4) → PREPARING(7) → PREPARED(8)` — terminal at PREPARED for kiosk per `FIX-54-5` design (no SERVED for kiosk per pos-kds-sync findings). Orders are seeded via `placeKioskOrder()` from `kiosk-order.js` to produce real fiscally-valid kiosk orders, NOT direct DB inserts (we want the full pipeline → cards arrive on KDS via the same broadcast/poll path users see in prod).
**Estimated states**: 14

### Pre-test seeding (in `beforeAll`)
- `getKioskApiToken()` once.
- Seed 3 kiosk orders via `placeKioskOrder({ items: [...], paymentMethod: 1 (cash), idempotencyKey: <uuid> })` × 3 with prefix `AUDIT-SYNC-JOURNEY-KIOSK-WAVE-C-{i}`. All status ACCEPT (4 — paid, on KDS). Each different total + line composition.
- Wait ≤8 s for KDS realtime/poll to surface them (poll fallback ~6 s + buffer).
- Cleanup in `afterAll` via `cleanupKioskAuditOrders('AUDIT-SYNC-JOURNEY-KIOSK-WAVE-C')`.

### Numbered states

1. `01-kds-empty-state` — pre-seed snap (or post-cleanup at end of test); verify empty-state quality (illustration / copy / no broken icons)
2. `02-kds-after-seed-three-kiosk-orders` — post-seed; 3 cards visible in the kiosk lane (`[data-kds-order-card="kiosk"]`); assert each card shows order #, line items count, total amount or order_serial_no parity (KDS is money-blind by design — fall back to order_serial_no if total NOT rendered, per pos-kds-sync C-007)
3. `03-kds-card-detail-order-1` — focus / hover / expand interaction on order-1 card; verify line breakdown, options visible, queue number visible
4. `04-kds-mark-preparing-order-1` — drive UI transition ACCEPT → PREPARING for order-1; capture column move / status change
5. `05-kds-after-preparing-order-1` — column / status re-layout; order-1 in PREPARING state, others still ACCEPT
6. `06-kds-mark-prepared-order-1` — PREPARING → PREPARED transition (terminal for kiosk)
7. `07-kds-after-prepared-order-1` — PREPARED state visible; verify visual differentiation (color / pulse / archived chip if any). Order should NOT auto-archive — kiosk lifecycle ends at PREPARED.
8. `08-kds-bulk-progress-orders-2-3` — drive orders 2 + 3 to PREPARING simultaneously; verify both move
9. `09-kds-polling-cadence-degraded` — wait ~3 s (degraded interval = 2 s + buffer); inspect network.json; verify recurring poll request to KDS endpoint with 200 (P0 — no silent 4xx/5xx). Capture the realtime banner state if any (per pos-kds-sync C-007 sticky banner).
10. `10-kds-polling-cadence-disconnected` — simulate WS-down (already true in dev — port 6001 unreachable); wait ~5 s (disconnected interval = 4 s + buffer); verify polling continues at degraded cadence; banner shows "Realtime dégradé" or equivalent if implemented
11. `11-kds-numeric-integrity-card-vs-db` — for each visible card, query DB via tinker for the seeded order's `total_amount` and `order_serial_no`; assert displayed values match (P0 — fall back to order_serial_no parity if KDS hides money)
12. `12-kds-keyboard-aria-spot-check` — keyboard nav across cards; verify `:focus-visible` styles, `role=status` on column headers, `aria-live` on lane updates
13. `13-kds-error-resilience` — simulate transient endpoint failure (route mock to 503 once via `page.route`), verify UI shows error toast / retry indicator (NOT silent failure — P0 if silent)
14. `14-kds-source-badge-distinct` — assert kiosk-source cards have a visually distinct badge / lane label "Borne" (or kiosk icon); compare to a POS-source card if any pre-existing on the pile (capture for evidence). If no source-distinction visible, raise P1 finding "KDS lacks visible source distinction kiosk vs other"

### Acceptance criteria

| MUST PASS | BEST EFFORT |
|---|---|
| Status transitions reflected in DOM within 5 s of click (no stuck cards) | Card animation polish |
| Each card total === seeded expected total OR order_serial_no parity (P0 numeric_integrity) | Hover effects |
| Polling tick at states 09 + 10 returns 200 (network.json clean) | Lane-header copy quality |
| State 13 surfaces error UI visibly (no silent failure on 503) — P0 if silent | Aria-live region polish |
| Empty state at state 01 passes quality bar | |
| Kiosk-source cards visible in `[data-kds-order-card="kiosk"]` lane (P0) | |
| Source badge "Borne" visually distinct from non-kiosk lanes (P1) | |
| No console error throughout lifecycle (vendor/pusher noise allowlisted) | |
| **No SERVED transition exercised** for kiosk orders — terminal = PREPARED per design (assert via state-machine inspection) | |

---

## Wave D — Kiosk ↔ KDS ↔ OSS sync end-to-end (multi-context)

**Spec file**: `tests/e2e/test-e2e-kiosk-kds-sync-2026-05-11-wave-D.spec.js`
**Screenshots dir**: `tests/e2e/__screenshots__/test-e2e-kiosk-kds-sync-D/`
**Surfaces / contexts**: **3 contexts in parallel**:
- `ctxKiosk` — kiosk machine (auto-login). Customer creates kiosk order through wizard → pays via TPE bypass.
- `ctxKDS` — chef@lecayenne.fr at `/admin/kitchen-display-system`.
- `ctxOSS` — admin@lecayenne.fr at `/admin/order-status-screen` (admin-only route).

Each context attaches its own `attachMegaAuditRecorder` to its own subdir prefix (`d-kiosk-XX`, `d-kds-XX`, `d-oss-XX`) so triple-side captures are decoupled but timestamped together.

**Estimated states**: 18 (chronological — same scenario captured on all 3 surfaces with the appropriate prefix)

**Scenario timeline**: place ONE kiosk order through the wizard (or via `placeKioskOrder()` if the wizard branch is fragile and would mask sync findings — spec MUST declare which mode it's in) → pay via TPE bypass → assert order on KDS within 8 s → KDS marks PREPARING → kiosk confirmation page reflects (if confirmation has live status) → KDS marks PREPARED → assert OSS shows order at PREPARED. Plus idempotency double-tap sub-scenario + silent-error sweep.

### Numbered states (chronological — same scenario, captured on all 3 surfaces where applicable)

1. `01-d-kiosk-baseline` — kiosk idle screen ready
2. `02-d-kds-baseline` — KDS empty (or pre-existing pile snapshot)
3. `03-d-oss-baseline` — OSS empty (OSS only shows PREPARING+PREPARED orders, so it WILL be empty or show only pre-existing PREPARING orders)
4. `04-d-kiosk-wizard-validate-and-pay` — kiosk wizard validated → cart has 1 line → pay via TPE bypass → confirmation page; capture confirmation total `T_kiosk_paid`; record `order_id`, `queue_number`, `idempotency_key` to sidecar `04-d-kiosk-wizard-validate-and-pay.payload.json`
5. `05-d-kds-after-pay-within-8s` — KDS context capture ≤8 s after step 04; assert order card present in `[data-kds-order-card="kiosk"]` lane (P0 SYNC-1); capture KDS card total OR order_serial_no `T_kds`; assert `T_kds === T_kiosk_paid` if KDS renders money, else assert order_serial_no parity (P0 numeric_integrity cross-surface)
6. `06-d-oss-after-pay-not-yet-visible` — OSS capture ≤8 s after step 04; expected behavior: OSS does NOT yet show the order (OSS only renders PREPARING+PREPARED). This state is a **negative-assertion** snap — proves OSS is not leaking PENDING orders. If order IS visible at OSS pre-PREPARING, that's a finding.
7. `07-d-kds-mark-preparing` — KDS chef drives ACCEPT → PREPARING
8. `08-d-kiosk-confirmation-reflects-preparing-within-5s` — IF kiosk confirmation page has live status (verify in pre-flight by source-grepping `KioskConfirmationComponent.vue` for status subscriptions), capture ≤5 s after step 07 and assert status badge updated. IF no live status, skip with P3 finding "kiosk confirmation page is one-shot, no live status updates" — common UX pattern, just document.
9. `09-d-oss-after-preparing-within-8s` — OSS context capture ≤8 s after step 07; assert kiosk order NOW visible at OSS (P0 SYNC-2 — measurement at PREPARING bump per pos-kds-sync D-002); capture OSS displayed order # / queue / amount if rendered; assert numeric/identity parity (P0 SYNC-5)
10. `10-d-kds-mark-prepared` — KDS chef drives PREPARING → PREPARED (terminal for kiosk)
11. `11-d-oss-after-prepared-within-8s` — OSS reflects PREPARED status ≤8 s (P0 SYNC-4 — order remains visible at PREPARED, that's by-design — customer is supposed to see "Ready" on OSS until they collect)
12. `12-d-kiosk-confirmation-after-prepared` — IF kiosk confirmation page has live status, capture; otherwise skip
13. `13-d-numeric-integrity-end-to-end` — assertion-only state (no UI capture beyond a summary console log). Spec asserts `T_kiosk_paid === T_db === T_kds === T_oss` (P0 SYNC-5). Writes a `13-d-numeric-integrity-end-to-end.assertion.json` sidecar with the proof tuple `{T_kiosk_paid, T_db_query_result, T_kds_dom_value, T_oss_dom_value, all_equal: bool}`.
14. `14-d-kiosk-double-tap-pay-idempotency` — separate sub-scenario: clean cart, new wizard line, fire 2× rapid clicks on the kiosk pay button (or 2× rapid `placeKioskOrder()` calls with the SAME idempotency key if UI debounce hides the test); inspect outgoing requests in `network.json` → both must share the same `X-Idempotency-Key` header (or be gated by frontend debounce — if debounced, find a way to bypass for the test); assert backend created EXACTLY 1 order via `fetchOrdersByIdempotencyKey` pattern (per pos-kds-sync R3 fix) (P0 SYNC-6 idempotency)
15. `15-d-kiosk-double-tap-different-payload-conflict` — fire 2× requests with same idempotency key but different payloads; assert HTTP 409 on second; tinker confirm only 1 order created (P0 — extends pos-kds-sync SYNC-F-IDEM into kiosk path)
16. `16-d-kiosk-source-vs-pos-source-no-collision` — IF a POS context can be added briefly (read-only suivi tab, no order placement), confirm kiosk + POS-source orders coexist on KDS without merge/loss/race (mirrors pos-kds-sync SYNC-E-4 from the kiosk angle). If POS context unavailable, skip with note + verify via tinker only (kiosk + tinker-seeded POS-source order both land on KDS).
17. `17-d-kiosk-cancel-via-waiting-screen` — IF the kiosk SPA exposes the cancel pathway (`KioskWaitingComponent.vue` line 90 — `kiosk-waiting-cancel-btn` / cancel modal), drive it: order placed but not yet PREPARING → kiosk customer cancels → assert KDS removes the card ≤5 s (P0 SYNC-CANCEL). If pathway only fires before kitchen accept, document the time-window. If pathway absent in the live kiosk flow (e.g. only operator-cancel via POS suivi), downgrade to P3 doc-gap.
18. `18-d-network-silent-error-sweep` — sweep all 3 surfaces' `network.json` files at end; assert ZERO unallowlisted 4xx/5xx without a corresponding visible alert/toast in matching DOM snap (P0 silent_error). Allowlist: 401 logout, 422 form, 304 cache, intentional WS 1006 to port 6001, intentional 409 at state 15.

### Acceptance criteria

| MUST PASS | BEST EFFORT |
|---|---|
| **SYNC-1**: KDS card present in kiosk lane within 8 s of kiosk pay (P0) | Polling tick visible in network.json |
| **SYNC-2**: OSS shows kiosk order within 8 s of KDS PREPARING bump — NOT at pay (P0; per pos-kds-sync D-002 reclassification) | OSS "estimated time" display polish |
| **SYNC-3**: kiosk confirmation page reflects KDS PREPARING within 5 s — IF live-status subscription exists; else P3 doc | Status badge color contrast |
| **SYNC-4**: OSS reflects PREPARED within 8 s of KDS PREPARED bump (P0) | PREPARED visual treatment |
| **SYNC-5**: `T_kiosk_paid === T_db === T_kds === T_oss` (numeric integrity, P0) — assert in spec via `13-d-numeric-integrity-end-to-end.assertion.json`, NOT just visually; KDS leg falls back to order_serial_no parity if money-blind by design | Decimal formatting consistency (`12,50 €` vs `12.50€`) |
| **SYNC-6**: kiosk double-tap pay creates EXACTLY 1 order (P0 idempotency); spec must verify the 2 requests share the same `X-Idempotency-Key` and use `fetchOrdersByIdempotencyKey` pattern from pos-kds-sync R3 fix | UI debounce visual feedback |
| **SYNC-IDEM-409**: same idempotency key + different payload returns 409 + 1 order in DB (P0 — Wave D state 15) | 409 response body copy quality |
| **SYNC-CANCEL**: kiosk cancel via waiting screen → KDS removes ≤5 s (P0 if pathway exists; P3 doc-gap if absent) | Cancel modal UX polish |
| **SILENT-ERROR**: state 18 sweep finds 0 unallowlisted 4xx/5xx without paired DOM alert (P0) | Toast copy quality |
| **OSS isolation**: state 06 negative-assertion — OSS does NOT show PENDING orders (P1 — would be a privacy/UX leak if it did) | OSS branding consistency |
| Branch isolation respected: kiosk machine + chef + admin all on branch 1; no cross-branch leakage in seeded orders | |
| **Wizard branch declared** in spec header comment (Vue vs pos-wizard.js) — same as Wave B | |

---

## Cross-surface scenarios — formal registry

Each scenario maps to one or more states above. Spec assertions must reference the scenario ID in code comments for traceability.

| ID | Scenario | Spans | States | P-level |
|---|---|---|---|---|
| **SYNC-1** | Kiosk pay → KDS pile within 8 s (kiosk lane) | `ctxKiosk` + `ctxKDS` | Wave D 04 → 05 | P0 |
| **SYNC-2** | Kiosk pay → OSS within 8 s — measured at KDS PREPARING bump (NOT at pay; OSS only shows PREPARING+PREPARED per pos-kds-sync D-002) | `ctxKiosk` + `ctxKDS` + `ctxOSS` | Wave D 04 → 07 → 09 | P0 |
| **SYNC-3** | KDS PREPARING → kiosk confirmation page reflects within 5 s (IF live status subscription exists) | `ctxKDS` + `ctxKiosk` | Wave D 07 → 08 | P0 (or P3 doc-gap) |
| **SYNC-4** | KDS PREPARED → OSS reflects within 8 s | `ctxKDS` + `ctxOSS` | Wave D 10 → 11 | P0 |
| **SYNC-5** | Numeric integrity end-to-end (T_kiosk_paid = T_db = T_kds = T_oss); KDS leg falls back to order_serial_no parity if money-blind | All 3 + backend | Wave D 04, 05, 09, 11, 13 | P0 |
| **SYNC-6** | Idempotency — kiosk double-tap pay creates 1 order (X-Idempotency-Key reused) | `ctxKiosk` + backend | Wave D 14 | P0 |
| **SYNC-IDEM-409** | Same idempotency key + different payload = 409 + 1 order | `ctxKiosk` + backend | Wave D 15 | P0 |
| **SYNC-CONCURRENT-NO-COLLISION** | Kiosk + POS-source orders coexist on KDS, no merge/loss/race | `ctxKiosk` + (optional `ctxPOS` or tinker) + `ctxKDS` | Wave D 16 | P0 (degrades if no POS context) |
| **SYNC-CANCEL** | Kiosk cancel via waiting-screen pathway → KDS removes ≤5 s | `ctxKiosk` + `ctxKDS` | Wave D 17 | P0 (if pathway exists) / P3 doc-gap |
| **SYNC-OSS-ISOLATION** | OSS does NOT render PENDING/ACCEPT orders — only PREPARING+PREPARED (negative assertion) | `ctxOSS` | Wave D 06 | P1 (privacy/UX) |
| **SYNC-KDS-LIFECYCLE-KIOSK** | KDS kiosk-source order lifecycle: ACCEPT → PREPARING → PREPARED (terminal — no SERVED) | `ctxKDS` | Wave C 04, 06; Wave D 07, 10 | P0 (state-machine integrity) |
| **SYNC-KDS-SOURCE-BADGE** | Kiosk-source cards visually distinct (lane or badge) on KDS | `ctxKDS` | Wave C 14; Wave D 05 | P1 (a11y / operator UX) |
| **SYNC-SILENT-ERROR** | No silent 4xx/5xx anywhere across the 3 surfaces (paired with DOM alert/toast) | All 3 | Wave A 14; Wave C 09, 10, 13; Wave D 18 | P0 |
| **SYNC-BYPASS-MARKER** | Test-mode markers (`🔧 MODE TEST — IMPRESSION BYPASSÉE`) visible in DOM during paid kiosk flows | `ctxKiosk` | Wave A 09; Wave B (any payment-adjacent state); Wave D 04 | P1 (would be P0 if missing in prod) |
| **SYNC-WIZARD-NUMERIC** | Wizard recap === cart line === kiosk-paid total === KDS card === OSS display | `ctxKiosk` + `ctxKDS` + `ctxOSS` + backend | Wave B 04, 05, 09, 10, 13, 14, 15; Wave D 04, 05, 09, 13 | P0 (numeric integrity end-to-end) |

Each scenario lists: spans (browser contexts), assertions (DOM + network + numeric + DB row + cache key state), success criteria (timing + value equality + side-effect absence).

---

## Cross-cutting assertions — every critical feature mapped to a wave

| # | Critical feature | Wave / state(s) |
|---|---|---|
| 1 | Kiosk visual page-by-page (idle, categories, cart, checkout, payment, confirmation, auto-return) | Wave A states 01–13 |
| 2 | Bypass-mode markers visible in payment flow | Wave A 09; Wave D 04 |
| 3 | Kiosk wizard popup visual (3 item types: simple-options / multi-step+extras / composite-or-split) | Wave B states 01–14 |
| 4 | Wizard recap === cart line price (no silent overcharge) | Wave B 04↔05, 09↔10, 13↔14 |
| 5 | Cart line + grand total numeric integrity | Wave B 15; Wave A 11 |
| 6 | KDS lifecycle ACCEPT → PREPARING → PREPARED (kiosk terminal) | Wave C 04, 06; Wave D 07, 10 |
| 7 | KDS card total === seeded order total (OR order_serial_no parity) | Wave C 11; Wave D 05 |
| 8 | KDS polling cadence (degraded 2 s, disconnected 4 s) | Wave C 09, 10 |
| 9 | KDS error resilience on 503 (no silent failure) | Wave C 13 |
| 10 | KDS source-distinction (kiosk lane / "Borne" badge) | Wave C 14; Wave D 05 |
| 11 | Kiosk pay → KDS within 8 s | Wave D SYNC-1 (states 04→05) |
| 12 | Kiosk pay → OSS within 8 s — measured at KDS PREPARING (D-002 reclassification) | Wave D SYNC-2 (states 04→07→09) |
| 13 | KDS PREPARING → kiosk confirmation reflects within 5 s | Wave D SYNC-3 (states 07→08) |
| 14 | KDS PREPARED → OSS reflects within 8 s | Wave D SYNC-4 (states 10→11) |
| 15 | Numeric integrity cross-surface (T_kiosk_paid = T_db = T_kds = T_oss) | Wave D SYNC-5 (states 04, 05, 09, 11, 13) |
| 16 | Idempotency double-tap pay (1 order created, key reused) | Wave D SYNC-6 (state 14) |
| 17 | Idempotency same-key different-payload = 409 conflict | Wave D SYNC-IDEM-409 (state 15) |
| 18 | Concurrent kiosk + POS-source — both reach KDS, no race | Wave D SYNC-CONCURRENT-NO-COLLISION (state 16) |
| 19 | Kiosk cancel via waiting screen → KDS removes within 5 s | Wave D SYNC-CANCEL (state 17) |
| 20 | OSS isolation — does not leak PENDING/ACCEPT orders | Wave D SYNC-OSS-ISOLATION (state 06) |
| 21 | Silent-error sweep (network 4xx/5xx without paired DOM alert) | Wave A 14; Wave C 09, 10, 13; Wave D 18 |
| 22 | i18n leak detection (no `^[a-z]+(\.[a-z_]+){1,4}$` in DOM) | All waves, all states |
| 23 | Le Cayenne palette (noir/rouge/jaune/blanc per design refresh) — no pink drift | All waves |
| 24 | Kiosk auto-return to idle after `kioskConfirmationAutoReturnSeconds` | Wave A 13 |
| 25 | **Frozen-zone integrity** — pos-wizard.js + pos-wizard.css + admin-pos-v4.blade.php = ZERO patch (IF `kioskUsePosWizard=true`) | Wave B end-of-round diff check |

No assertion unmapped.

---

## Out-of-scope (explicit)

- POS surface — covered by separate, already-converged `pos-kds-sync-2026-05-10` audit
- Mobile app — separate `mobile-vs-kiosk-2026-05-10` and `mobile-wizard-e2e-2026-05-11` audits
- Admin item / category management screens
- Stock rupture cascade (separate concern)
- Reporting / Z-reports (NF525 separate fiscal audit)
- Kiosk catalog visual page-by-page — covered by `borne-cats-309-318-2026-05-10` (converged); we cover only the page-level kiosk states needed for the order journey, not deep catalog tiling
- Real TPE hardware (TPE simulation OK; cash branch is the safe canonical path for `T_kds`/`T_oss` validation if TPE bypass introduces noise)
- Payment provider webhooks (Senangpay etc.)
- Loyalty / customer creation flows (kiosk-side loyalty UI = separate audit)
- Receipt printing hardware
- Multi-tenant / multi-branch broadcast channel isolation — covered by pos-kds-sync Wave F SYNC-F-CHANNEL; not re-asserted here
- Kiosk-side outbox / DispatchableAfterCommit / version-gating / LRU eviction internals — covered by pos-kds-sync Wave F; the kiosk path uses the SAME backend pipeline and therefore inherits those properties (no need to re-prove)

---

## Pre-flight (already done by orchestrator — documented for reproducibility)

- ✅ Server health: `/kiosk/idle`, `/kiosk/login`, `/admin/kitchen-display-system` all return 200
- ✅ Login creds verified: `kiosk-lecayenne` (auto-injected via `kioskAutoLogin`), `chef@lecayenne.fr` / `123456`, `admin@lecayenne.fr` / `123456`
- ✅ Bundles fresh: `app.js` id=`c6f90ff67ccce6d2caeeba703a196cff` (today 2026-05-11)
- ✅ Workers locked: `workers: 1` in `playwright.config.js`
- ✅ 0 pending migrations (assumed — verify in pre-round if `composer install` was re-run)
- ✅ Reports scaffold present: `reports/test-e2e/kiosk-kds-sync-2026-05-11/round-1/` with REVIEWER_PROTOCOL + FINDINGS_SCHEMA copies
- ✅ Helpers verified: `login.js` (loginAsKiosk line 123), `mega-audit-snap.js` (DOM cap 2 MB), `rate-limit.js`, `sync-journey-trace.js`, `kiosk-order.js`
- ✅ Kiosk machine seeded: `KioskMachine` id=1, username=`kiosk-lecayenne`, branch_id=1, status=ACTIVE(5)
- ✅ Queue: `sync` (jobs inline)

### Per-round pre-flight (run BEFORE every capture round)

- Run `php artisan iter15:cleanup-test-orders --apply` (also auto-invoked by helpers — defensive)
- Verify no leftover `AUDIT-SYNC-JOURNEY-KIOSK-*` or `AUDIT-KIOSK-*` orders
- Confirm 3 contexts (kiosk / KDS / OSS) can sit on screen simultaneously (memory headroom + login throttle)
- Re-read `kioskUsePosWizard` live value; if it changed between rounds, update Wave B branch and re-declare in spec header comment
- Re-read `kioskConfirmationAutoReturnSeconds`; update Wave A state 13 timer accordingly
- Confirm bypass markers configured (`bypassMode.payment`, `bypassMode.printingScreenMarker`)

---

## Spec runner template (each wave runnable in isolation)

```bash
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 \
  npx playwright test tests/e2e/test-e2e-kiosk-kds-sync-2026-05-11-wave-<W>.spec.js \
  --project=chromium --workers=1 --retries=0 --reporter=list
```

Each wave OWNS its `__screenshots__/test-e2e-kiosk-kds-sync-<W>/` dir. Captures via `attachMegaAuditRecorder(page, dir)` → quartet (PNG + DOM + console.json + network.json) per state. Wave B adds a 5th sidecar (`*.payload.json`) at state 15. Wave D writes 3 parallel quartets per chronological state (one per context) plus `*.payload.json` at state 04 and `*.assertion.json` at state 13.

---

## Adversarial review (Wave-Reviewer — out-of-band, per round)

Reviewer wave is NOT a Playwright spec. After each capture round, an adversarial supervisor agent is invoked PER WAVE to inspect that wave's artifact quartet (and Wave-B / Wave-D sidecars) and emit:

```
reports/test-e2e/kiosk-kds-sync-2026-05-11/round-<N>/wave-<W>-findings.json
```

per `FINDINGS_SCHEMA.md`. Loop continues until `verdict === GREEN` for all 4 waves (`open_P0 == 0` AND `open_P1 == 0` on technical/sync findings) for **two consecutive rounds with set-equality** (no new findings, no regressions). Visual-only P2/P3 findings do NOT gate convergence and roll over to a `kiosk-visual-polish` follow-up backlog.

---

## State-budget summary

| Wave | Surface(s) | States | Sidecar | Round-1 |
|---|---|---|---|---|
| A | Kiosk visual (idle → confirmation → auto-return) | 14 | quartet | captured |
| B | Kiosk wizard (3 items; FROZEN if `kioskUsePosWizard=true`) | 15 | quartet + payload at state 15 | captured |
| C | KDS visual + lifecycle from kiosk-source orders | 14 | quartet | captured |
| D | Kiosk↔KDS↔OSS sync end-to-end | 18 chronological (× ≤3 surfaces tagged) | triple quartet + payload (04) + assertion (13) | captured |
| **TOTAL (single-surface count)** | — | **61** chronological states | — | — |
| **TOTAL (PNG count, accounting for Wave-D triple)** | — | **~95** PNGs (14+15+14 + 18×3 = 97; minus skipped negative-assertion or doc-gap states) | — | — |

---

## Risk register (orchestrator awareness)

| Risk | Mitigation |
|---|---|
| **`kioskUsePosWizard=true` means kiosk renders the FROZEN pos-wizard.js inside `KioskPosWizardComponent.vue`**. Wave B captures it but cannot patch it. Findings against the kiosk-wizard UI when it's actually pos-wizard.js must be triaged: `kiosk-frame issue` (patchable in `KioskPosWizardComponent.vue` wrapper / kiosk store) vs `pos-wizard issue` (escalate to owner — not patchable in this audit). | Spec Wave B reads the live `kioskUsePosWizard` value at `beforeAll`, declares branch in spec header + first console.log. Reviewer must classify findings using the same branch info. End-of-round frozen-zone diff check on `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php` MUST be empty regardless of fix actions taken elsewhere. |
| Wave A state 13 (auto-return timer) — `kioskConfirmationAutoReturnSeconds` may differ in dev (default 30 s) from production tuning; spec hard-coding 30 s would mask config drift. | Spec re-reads `await page.evaluate(() => window.foodkingConfig.kioskConfirmationAutoReturnSeconds)` at runtime; uses `value + 2 s` buffer; logs the value to console for reviewer trace. |
| Wave A state 09 — bypass-mode marker `🔧 MODE TEST — IMPRESSION BYPASSÉE` may render in a confirmation footer rather than payment overlay; spec selector hard-coding would miss it and falsely raise P1. | Spec scans full `document.body.innerText` for the marker substring (or its localized equivalent — verify per locale); if absent, confirms via DOM screenshot review before raising finding. |
| Wave B wizard popup may have animation race vs `snap()` (kiosk variant likely heavier than POS due to mobile-app design refresh). | `await page.waitForSelector('[data-pos-wizard], [data-kiosk-wizard], .kiosk-wizard', { state: 'visible' })` before each wizard-step snap; `await page.waitForTimeout(250)` after open / step transition. |
| Wave B item-3 may not actually exist — sandwich-split / composite catalog topology depends on live data. | Pre-flight tinker query: `Item::with('itemOptions')->whereHas('itemOptions', fn($q) => $q->where('type','split'))->limit(3)->get()` — if 0 rows, item-3 falls back to "any item with a multi-step wizard" + raises P3 doc-gap "no sandwich-split items in catalog". |
| Wave C seed via `placeKioskOrder()` requires the same `getKioskApiToken()` as Wave D — if rate-limited mid-seed, wave aborts. | `clearFoodKingRateLimits()` + `Cache::flush()` in `beforeAll`; raise `kiosk.order_rate_limit` to ≥30 in test session if needed (mirror pos-kds-sync R2 hardening). |
| Wave C state 11 (`T_kds === seeded`) — KDS may render `order_serial_no` only, no money. | Spec inspects `[data-kds-order-card="kiosk"]` DOM first; if no money displayed, falls back to `order_serial_no` parity assertion (per pos-kds-sync C-007); does NOT raise P0 if money intentionally hidden. |
| Wave D state 06 (negative assertion — OSS does NOT show PENDING) — false-positive if a PRIOR pre-existing PREPARING order from another test run lingers. | Pre-round cleanup must be exhaustive (`cleanupKioskAuditOrders` + `iter15:cleanup-test-orders --apply`); state 06 sweep validates that the SPECIFIC kiosk order id from state 04 is NOT in OSS DOM (selector by order_id, not generic "any order"). |
| Wave D state 08 (kiosk confirmation live status) — `KioskConfirmationComponent.vue` may NOT subscribe to live status updates; this is a UX choice, not necessarily a bug. | Spec source-greps `KioskConfirmationComponent.vue` at pre-flight for `kioskOrders/subscribe`, `Echo.private`, polling intervals; if absent, state 08 = SKIP + P3 doc-gap, NOT P0. Document the by-design behavior so reviewer doesn't dispute it as a sync regression. |
| Wave D state 14 (idempotency double-tap) — kiosk SPA may debounce the pay button at the click level, hiding the test scenario. | Two-pronged approach: (a) attempt UI double-tap with `page.dblclick` + `delay: 50ms`, inspect network for 1 vs 2 requests; (b) if only 1 request fires, switch to `placeKioskOrderTwice(page, { idempotencyKey: K1, payload: P1, payload: P1 })` from `kiosk-order.js` to exercise the backend path directly. Document which mode in state-14 sidecar. |
| Wave D state 17 (cancel via waiting screen) — the `KioskWaitingComponent.vue` cancel pathway may only be available in a window between order placement and KDS ACCEPT (e.g. only if the order is still in PENDING/CART state); after pay, cancel may not be exposed at all. | Pre-flight source-grep `KioskWaitingComponent.vue` for cancel endpoint + state guards; if cancel only available pre-pay, state 17 documents the time-window + tests within it (or marks as "not exercisable post-pay"). Downgrade to P3 doc-gap if no cancel exists post-pay. |
| Wave D triple-context — 3 simultaneous browsers may exhaust memory on dev machine; OSS context is admin auth-required and Sanctum cookie may collide with KDS context cookies if reused. | Use `browser.newContext()` per surface (separate cookie jars); workers=1 enforces sequential test files but parallel contexts are still 3 within one file. Memory: `--memory-limit` not configurable in Playwright; rely on M-class machine specs + restart Playwright runner between rounds if OOM observed. |
| Polling vs WebSocket fallback timing — KDS may receive update via push OR poll; budget must accommodate the SLOWER of the two. | Wave D timing budgets are 8 s for SYNC-1/2/4, 5 s for SYNC-3; these accommodate the 4 s disconnected polling + 4 s buffer. If WS were live, captures would be near-instant — those captures ALSO pass the budget. |
| `BROADCAST_DRIVER=pusher` + WS port 6001 unreachable — frontend downgrades to polling silently; Wave D should NOT re-prove this (out-of-scope per pos-kds-sync Wave F coverage), only assert observable outcomes. | Spec asserts polling cadence in Wave C 09/10; Wave D simply trusts the polling fallback works (proven in pos-kds-sync). Reviewer must not double-count this as a "missing test" — it's covered upstream. |
| Frozen-zone diff check timing — must run AFTER the fix-and-recapture loop within a round, BEFORE the round is declared closed. | Add `git diff --name-only public/js/pos-wizard.js public/css/pos-wizard.css resources/views/admin-pos-v4.blade.php` to the round-closure checklist; output MUST be empty. If non-empty, round CANNOT be GREEN regardless of finding count. |
| Bypass-mode markers — if `bypassMode.payment` or `bypassMode.printingScreenMarker` is silently flipped in dev (env override), audit could miss real-world payment bugs. | Pre-flight echoes `window.foodkingConfig.bypassMode` to console; spec asserts both fields present + non-empty BEFORE running Wave A/B/D paid flows. If bypass is OFF in dev, swap to cash for paid flows + raise P1 finding "Wave failed to exercise TPE bypass — env config drift". |
| OSS admin auth — `loginAsAdmin` may collide with prior session cookies if Wave D reuses the same browser. | `browser.newContext()` for `ctxOSS` + fresh cookie jar; verify in spec header that no shared cookies leak from `ctxKiosk` or `ctxKDS`. |
| Wave D state 13 numeric-integrity assertion — `T_oss` may not render money (OSS could be order # + status only). | Spec inspects OSS DOM for amount selectors; if money not rendered, falls back to order_id / queue_number parity for the OSS leg of SYNC-5. Sidecar `13-d-numeric-integrity-end-to-end.assertion.json` records which fields were used per surface. |
