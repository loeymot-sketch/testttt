# Audit Plan — POS · KDS · OSS Cross-Surface Sync

**Run ID**: `pos-kds-sync-2026-05-10`
**Branch**: `feature/mobile-app-le-cayenne-2026-05-10`
**Working dir**: `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`
**Mission (verbatim)**: « fait le test E2E pour caisse et kds massive visuelle et backeds et track tout les syncronisation »
**Translation**: massive visual + backend audit of POS (caisse) + KDS, with full cross-surface synchronization tracking. Owner mandate (CLAUDE.md): *"Numeric integrity is non-negotiable — same fact must equal across every surface"* and *"Silent errors are P0"*.

**Surfaces in scope**
- POS V4 SPA — `http://127.0.0.1:8000/admin/pos` (alias `/admin/pos-v4`) — Le Cayenne shell + vanilla JS wizard `pos-wizard.js?v=9-...`
- KDS — `http://127.0.0.1:8000/admin/kitchen-display-system` (alias `/kds`) — `KitchenDisplaySystemComponent.vue`
- OSS (customer-facing) — `http://127.0.0.1:8000/admin/order-status-screen` — used as third surface for cross-sync verification

**Login helpers** (`tests/e2e/helpers/login.js`, all wipe rate-limit buckets first)
- `loginAsPosOperator(page)` → pos@lecayenne.fr / 123456 → `/admin/pos`
- `loginAsChefOperator(page)` → chef@lecayenne.fr / 123456 → `/admin/kitchen-display-system`
- `loginAsAdmin(page)` → admin@lecayenne.fr / 123456 → `/admin/dashboard`

**Recorder helper** (mandatory per state)
- `tests/e2e/helpers/mega-audit-snap.js` → `attachMegaAuditRecorder(page, dir)` returning `{ snap, dispose }`. Each `snap('XX-name')` writes the artifact quartet: `.png` + `.dom.html` + `.console.json` + `.network.json`.

**Other helpers**
- `tests/e2e/helpers/sync-journey-trace.js` — DB seed/cleanup + cross-surface lifecycle assertions (`AUDIT-SYNC-JOURNEY` prefix)
- `tests/e2e/helpers/rate-limit.js` — `clearFoodKingRateLimits()`
- `tests/e2e/helpers/process-audit.js` — orchestration helpers

**FROZEN ZONES (capture-only — NEVER patch)**
1. POS Vanilla JS wizard — `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php`. Owner declared "design parfait". Capture every visual state for audit but ZERO LINE patched.
2. NF525 backend — `app/Services/Fiscal/*`, `app/Models/Scopes/BranchScope.php`, `app/Http/Middleware/IdempotencyKeyMiddleware.php`, `app/Services/Pricing/PricingService.php`, `app/Domain/Order/OrderStateMachine.php` — read-only audit.

**State budget target** (extended for round-2): ~88-90 PNGs total across 6 waves (Waves A–F). Round-2 captures only C, D, E, F (visual A/B already-fixed verified via snapshot diff).

---

## Round-2 scope

Round-1 cluster fixes already landed (history, immutable):
- **A-001 / D-001 / C-001** — silent 4xx/5xx visibility (commit `95c2fd799`)
- **D-002** — OSS realtime sync fallback hardening (commit `248fced17`)
- **A-002 / A-003** — POS palette + login i18n FOUC (commit `c08575207`)
- **B-003** — POS wizard toast contrast (commit `d8ddabef8`)
- **C-002 / D-003 / D-004 / B-004** — cluster-4 spec hardening (commit `10456234c`)

Round-2 fix wave (in flight): closes the remaining technical P1 cluster — **D-003, D-004, A-005, C-002** (cluster spec hardening — landed in `10456234c`).

Round-2 capture scope: **Waves C, D, E, F** only. Per owner directive "que côté technique", Waves A and B are dropped from round-2 captures; their already-fixed visual findings (A-002/A-003/B-003) are verified by snapshot diff at end-of-round against round-1 baselines.

**Visual-only round-1 findings (non-blocking for round-2 convergence per owner scope)**: A-006, A-007, B-004, B-005, B-006, B-007, B-008, C-003, C-004, C-005, C-006, C-008, D-005, D-007, D-008 — documented in `round-1/wave-{A,B,C,D}-findings.json` and remain open as visual-polish backlog. They do NOT gate round-2 GREEN. Round-2 RED/AMBER verdict is computed from C/D/E/F technical assertions only.

---

## Round-2 test environment hardening

Round-2 introduces broadcast-pipeline depth (Waves E + F) requiring controlled queue + Pusher behavior. Pre-round setup:

1. **`QUEUE_CONNECTION=sync`** for the dev server during round-2 capture so `OrderCreated` → `PersistOrderCreatedToOutbox` → `DispatchDomainEventsJob` chain fires inline. `PosSyncService` / `OssSyncService` / `KdsSyncService` Echo subscriptions still exercise but receive the broadcast immediately (no out-of-band worker tick needed).
   - Alternative: keep `QUEUE_CONNECTION=database` and run `php artisan queue:work --once --queue=high` in a `beforeAll` hook + after each pay/state transition. Either path is acceptable — pick one per spec and document the choice in the spec header comment.
2. **`FK_CATALOG_POS_FALLBACK_POLLING_ENABLED=true`** in test env so the POS suivi tab's `PosSyncService` falls back to polling if Echo is degraded. NOTE: `PosSyncService` covers catalog availability, NOT order suivi realtime — so this only validates catalog sync. Order suivi realtime relies on Echo + the same outbox pipeline as KDS. This separation must be documented in spec header comments and surfaced as architectural finding if any state assertion fails on the POS suivi side because of missing order-suivi polling fallback.
3. **Kiosk auth pre-step**: any Wave E spec MUST call `getKioskApiToken()` from `tests/e2e/helpers/kiosk-order.js` (token + `kiosk:order` ability) BEFORE any `POST /api/frontend/order`. Token is scoped to a kiosk machine row — `beforeAll` must verify a kiosk machine seed exists for the test branch; if absent, fail-fast with a clear error (the spec must not silently bypass kiosk auth).
4. **Broadcast driver flexibility** for Wave F states 05, 07, 09: spec may toggle `BROADCAST_DRIVER` via `php artisan tinker --execute="config(['broadcasting.default' => 'null']); ..."` or by rewriting `.env` + `php artisan config:clear`. Cleanup MUST restore the previous driver in `afterAll` regardless of test outcome.
5. **Idempotency cache reset**: Wave F uses `clearFoodKingRateLimits()` (from `rate-limit.js`) plus a tinker-based `Cache::tags(['idempotency'])->flush()` (or `Cache::store('redis')->flush()` if no tag support) at `beforeEach` to guarantee clean cache buckets per scenario. Replay assertions are meaningless if a stale cache key from a prior run shortcuts the second request.
6. **Correlation ID injection (state F-10)**: client requests must emit `X-Correlation-ID: <uuid>` header — helper `placeKioskOrder` should accept an optional `correlationId` arg; default to `crypto.randomUUID()`. Backend must propagate to `domain_events.metadata` (verify via tinker query after the POST).
7. **Multi-branch fixture for state F-11**: dev DB typically has 1 branch (Le Cayenne). State F-11 (cross-branch channel isolation) is hard-deterministic only with 2 seeded branches. Mitigation in risk register below — if only one branch, F-11 degrades to "tinker-only assertion" verifying broadcast channel naming convention `private-branch.{branch_id}` carries the correct branch id.

---

## Wave A — POS visual page-by-page (caisse, NO wizard)

**Spec file**: `tests/e2e/test-e2e-pos-kds-sync-2026-05-10-wave-A.spec.js`
**Screenshots dir**: `tests/e2e/__screenshots__/test-e2e-pos-kds-sync-A/`
**Surfaces / contexts**: 1 context — POS operator. Visual tour of every POS page reachable WITHOUT opening the wizard popup. Wave B owns the wizard.
**Estimated states**: 15

### Numbered states

1. `01-login-form` — `/login` baseline; verify no dark mode flash, Le Cayenne palette
2. `02-pos-landing` — first paint after `loginAsPosOperator`; URL `/admin/pos`; verify shell, sidebar, header, branch indicator
3. `03-pos-catalog-grid-default` — default category grid render; assert `≥4` item tiles, no broken thumbnails
4. `04-pos-category-switch` — click second category; grid swaps, no console error, no skeleton frozen
5. `05-pos-item-tile-states` — hover/focus/disabled states on a representative tile (capture default + qty badge if any)
6. `06-pos-cart-empty` — sidebar/cart panel empty state; verify CTA copy + i18n resolved (no `Label.X`)
7. `07-pos-cart-after-direct-add` — add a NO-WIZARD item (drink/supplement); cart shows 1 line, line total rendered, grand total rendered
8. `08-pos-cart-qty-stepper` — +/- on the line; line subtotal AND grand total update lock-step (P0 numeric_integrity)
9. `09-pos-cart-remove-line` — remove the line; cart returns to empty state (state 06 parity)
10. `10-pos-orders-tracker-baseline` — open `PosOrdersTrackerComponent` (suivi tab); empty or pre-existing orders rendered
11. `11-pos-payment-method-modal` — re-add a line, open payment modal; verify CB / Espèces / TPE buttons visible, palette correct
12. `12-pos-payment-cancel` — close modal; assert focus returns, no orphan overlay
13. `13-pos-receipt-baseline` — open `PosOrderReceiptComponent` for a prior order if any; verify layout intact, no NaN totals
14. `14-pos-parked-orders` — open `ParkedOrdersComponent`; verify list / empty state quality
15. `15-pos-logout` — logout; assert redirect to `/login`, session cleared

### Acceptance criteria

| MUST PASS (P0/P1) | BEST EFFORT (P2/P3) |
|---|---|
| Every state console.json has 0 `level=error` (allowlist: vendor/pusher/wss noise) | Hover/focus visuals |
| Network.json has 0 unexpected 4xx/5xx (allowlist: 401 logout, 422 form, 304 cache) | Skeleton timing |
| Cart line subtotal + grand total update in lock-step at state 08 (P0 numeric_integrity) | Layout shift on cat switch |
| No raw i18n key visible in DOM (regex `^[a-z]+(\.[a-z_]+){1,4}$`) | Image lazy-load shimmer |
| Le Cayenne palette respected (no pink drift, no dark-mode flash) | |
| Empty states pass §5 quality bar (illustration OR copy ≥20 chars OR CTA) | |

---

## Wave B — POS wizard popup (FROZEN — visual capture only)

**Spec file**: `tests/e2e/test-e2e-pos-kds-sync-2026-05-10-wave-B.spec.js`
**Screenshots dir**: `tests/e2e/__screenshots__/test-e2e-pos-kds-sync-B/`
**Surfaces / contexts**: 1 context — POS operator. Open the vanilla-JS wizard popup for 3 representative items spanning the typology. Capture every step + recap modal. Item add to cart is performed via the wizard's own validate button (not by direct cart manipulation). Wizard is FROZEN — assertions are read-only.
**Estimated states**: 17

> **Item triplet selection (orchestrator pre-pick during pre-flight)**: pick one item per type from the live catalog by querying `php artisan tinker`:
> - Item-1 (no options) — simplest direct config (e.g. a fixed plat with single CTA)
> - Item-2 (with options/extras) — multi-step wizard exercising extras + supplements
> - Item-3 (composite/menu) — wizard exercising menu/upsell branch if available
> If catalog topology differs, fall back to: any 3 distinct catalog tiles that each open a wizard popup (DOM marker `[data-pos-wizard]` or `.pos-wizard` overlay).

### Numbered states

**Item-1 (simple, no options)**
1. `01-b-tile-tap-item-1` — pre-tap baseline (catalog grid + selected tile highlight)
2. `02-b-wizard-open-item-1` — wizard overlay open; verify backdrop + modal centered + close affordance
3. `03-b-wizard-recap-item-1` — recap step; price line matches catalog price (P0)
4. `04-b-cart-after-validate-item-1` — wizard validate → cart shows 1 line; price = recap price (P0 numeric_integrity)

**Item-2 (with options + extras)**
5. `05-b-tile-tap-item-2`
6. `06-b-wizard-step-1-item-2` — first option/extras step
7. `07-b-wizard-step-2-item-2` — second step (whatever the wizard pipeline renders)
8. `08-b-wizard-step-extras-item-2` — extras / supplements step; pick at least 1 paid extra
9. `09-b-wizard-recap-item-2` — recap; verify base + extras line items + delta price visible
10. `10-b-cart-after-validate-item-2` — cart line total = base + Σ(extras) (P0)

**Item-3 (composite / menu)**
11. `11-b-tile-tap-item-3`
12. `12-b-wizard-step-1-item-3`
13. `13-b-wizard-step-menu-or-upsell-item-3` — menu branch or upsell step if present
14. `14-b-wizard-recap-item-3`
15. `15-b-cart-after-validate-item-3`

**Cart aggregate + edge**
16. `16-b-cart-three-lines-aggregate` — cart with all 3 items; grand total = Σ lines (P0 numeric_integrity); also dump cart payload via `page.evaluate(...)` to `*.payload.json` sidecar capturing line items JSON for adversarial review
17. `17-b-wizard-cancel-edge` — open wizard for any item, click cancel/backdrop; verify NO line added, NO orphan overlay, focus restored

### Acceptance criteria

| MUST PASS | BEST EFFORT |
|---|---|
| Wizard recap price === cart line price for each item (P0 numeric_integrity) | Step transition smoothness |
| Cart grand total at state 16 === Σ(line × qty) (P0) | Backdrop click vs explicit close parity |
| Wizard overlay is keyboard-dismissible (ESC) — captured in console/keyboard event | Animation jank |
| No console error during wizard open/step/validate flow | |
| Wizard cancel (state 17) leaves zero residual DOM (`[data-pos-wizard]` absent post-cancel) | |
| Sidecar `16-b-cart-three-lines-aggregate.payload.json` captures cart store JSON for adversarial review | |
| **ZERO line of patch** in `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php` (frozen-zone diff check at end of round) | |

---

## Wave C — KDS visual + lifecycle (single surface)

**Spec file**: `tests/e2e/test-e2e-pos-kds-sync-2026-05-10-wave-C.spec.js`
**Screenshots dir**: `tests/e2e/__screenshots__/test-e2e-pos-kds-sync-C/`
**Surfaces / contexts**: 1 context — chef operator. Visual tour of KDS surface + lifecycle transitions PENDING → IN_PROGRESS → READY → SERVED → ARCHIVED. To get cards on the pile without coupling to Wave D, this wave SEEDS orders directly via `php artisan tinker` (using `sync-journey-trace.js` patterns with `AUDIT-KDS-WAVE-C` prefix) at `beforeAll`, then drives transitions through the KDS UI.
**Estimated states**: 14

### Pre-test seeding (in `beforeAll`)
- Seed 3 orders via tinker: order-1 (1 line), order-2 (3 lines mixed), order-3 (1 line + 1 extra). All status PENDING, source POS, branch matches `chef@lecayenne.fr`.
- Cleanup in `afterAll` via prefix purge (mirror `cleanupTraceAudit` pattern).

### Numbered states

1. `01-kds-empty-state` — pre-seed snap (or post-cleanup at end of test); verify empty-state quality (illustration / copy / no broken icons)
2. `02-kds-after-seed-three-pending` — post-seed; 3 cards in PENDING column; assert each card shows order #, line items, total amount
3. `03-kds-card-detail-order-1` — focus / hover / expand interaction on order-1 card; verify line breakdown, options visible
4. `04-kds-mark-in-progress-order-1` — drive UI transition PENDING → IN_PROGRESS for order-1; capture column move
5. `05-kds-after-in-progress-order-1` — column re-layout; order-1 in IN_PROGRESS column, others still PENDING
6. `06-kds-mark-ready-order-1` — IN_PROGRESS → READY transition
7. `07-kds-after-ready-order-1` — READY column populated; verify visual differentiation (color / pulse / countdown if any)
8. `08-kds-mark-served-order-1` — READY → SERVED (archives card from active view)
9. `09-kds-after-served-order-1` — order-1 absent from active columns; archived list incremented (if visible)
10. `10-kds-bulk-progress-orders-2-3` — drive orders 2 + 3 to IN_PROGRESS; verify both move
11. `11-kds-polling-fallback-tick` — wait `~6s` (polling tick); capture; verify network.json shows recurring poll request to `/api/kds/...` or `kitchen-display-system` endpoint with 200 (no silent 4xx/5xx)
12. `12-kds-numeric-integrity-card-totals` — for each visible card, assert displayed `total_amount` === seeded `expected_total` (P0)
13. `13-kds-keyboard-aria-spot-check` — keyboard nav across cards; verify `:focus-visible` styles, `role=status` on column headers
14. `14-kds-error-resilience` — simulate transient endpoint failure (route mock to 503 once via `page.route`), verify UI shows error toast / retry indicator (NOT silent failure — P0 if silent)

### Acceptance criteria

| MUST PASS | BEST EFFORT |
|---|---|
| Status transitions reflected in DOM within 5s of click (no stuck cards) | Card animation polish |
| Each card total === seeded expected total (P0 numeric_integrity) | Hover effects |
| Polling tick at state 11 returns 200 (network.json clean) | Column-header copy quality |
| State 14 surfaces error UI visibly (no silent failure on 503) — P0 if silent | Aria-live region polish |
| Empty state at state 01 passes quality bar | |
| No console error throughout lifecycle (vendor/pusher noise allowlisted) | |

---

## Wave D — POS ↔ KDS ↔ OSS sync end-to-end (multi-context)

**Spec file**: `tests/e2e/test-e2e-pos-kds-sync-2026-05-10-wave-D.spec.js`
**Screenshots dir**: `tests/e2e/__screenshots__/test-e2e-pos-kds-sync-D/`
**Surfaces / contexts**: **3 contexts in parallel**:
- `ctxPOS` — pos@lecayenne.fr at `/admin/pos`
- `ctxKDS` — chef@lecayenne.fr at `/admin/kitchen-display-system`
- `ctxOSS` — admin@lecayenne.fr at `/admin/order-status-screen`

Each context attaches its own `attachMegaAuditRecorder` to its own subdir prefix (`d-pos-XX`, `d-kds-XX`, `d-oss-XX`) so triple-side captures are decoupled but timestamped together.

**Estimated states**: 18 (6 per surface × 3 surfaces, ordered chronologically by scenario)

### Numbered states (chronological — same scenario, captured on all 3 surfaces)

**Scenario timeline**: place ONE order through POS wizard → assert it propagates to KDS → assert it propagates to OSS → drive KDS through PENDING→IN_PROGRESS→READY → assert POS suivi reflects → drive POS to SERVED → assert KDS removes from active.

1. `01-d-pos-baseline` — POS catalog ready
2. `02-d-kds-baseline` — KDS empty (or pre-existing pile snapshot)
3. `03-d-oss-baseline` — OSS empty
4. `04-d-pos-wizard-validate` — POS wizard validated → cart has 1 line; capture cart total `T_cart`
5. `05-d-pos-payment-cash-confirm` — POS pay cash → confirmation modal; capture receipt total `T_receipt`; assert `T_cart === T_receipt` (P0)
6. `06-d-kds-after-pay-within-8s` — KDS context capture ≤8s after step 05; assert order card present (P0 SYNC-1); capture KDS card total `T_kds`; assert `T_kds === T_receipt` (P0 numeric_integrity cross-surface)
7. `07-d-oss-after-pay-within-8s` — OSS context capture ≤8s after step 05; assert order present (P0 SYNC-2); capture OSS displayed amount `T_oss` if shown; assert `T_oss === T_receipt` if rendered (P0)
8. `08-d-kds-mark-in-progress` — KDS chef drives PENDING → IN_PROGRESS
9. `09-d-pos-suivi-reflects-in-progress-within-5s` — POS suivi tab capture ≤5s after step 08; assert order card status badge updated (P0 SYNC-3)
10. `10-d-oss-status-after-in-progress` — OSS reflects new status if rendered
11. `11-d-kds-mark-ready` — KDS chef drives IN_PROGRESS → READY
12. `12-d-pos-suivi-reflects-ready-within-5s` — POS suivi reflects READY (P0 SYNC-3 continuation)
13. `13-d-oss-status-after-ready` — OSS reflects READY (typically the surface customers stare at)
14. `14-d-pos-mark-served` — POS suivi → action SERVED on the order
15. `15-d-kds-removes-from-active-within-5s` — KDS card disappears from active columns (P0 SYNC-4)
16. `16-d-oss-status-after-served` — OSS removes / archives the order
17. `17-d-pos-double-tap-idempotency` — separate sub-scenario (clean cart, new wizard line, fire 2× rapid clicks on pay button); capture POS state + assert backend created EXACTLY 1 order (query via `page.evaluate` against orders endpoint or via tinker count delta) (P0 SYNC-6 idempotency)
18. `18-d-network-silent-error-sweep` — sweep all 3 surfaces' `network.json` files at end; assert ZERO unallowlisted 4xx/5xx without a corresponding visible alert/toast in matching DOM snap (P0 silent_error)

### Acceptance criteria

| MUST PASS | BEST EFFORT |
|---|---|
| **SYNC-1**: KDS card present within 8s of POS pay (P0) | Polling tick visible in network.json |
| **SYNC-2**: OSS shows order within 8s of POS pay (P0) | OSS "estimated time" display polish |
| **SYNC-3**: POS suivi reflects KDS status change within 5s in BOTH directions (P0) | Status badge color contrast |
| **SYNC-4**: POS mark SERVED removes KDS card from active within 5s (P0) | Archive transition animation |
| **SYNC-5**: `T_cart === T_receipt === T_kds === T_oss` (numeric integrity, P0) — assert in spec, NOT just visually | Decimal formatting consistency (e.g., `12,50 €` vs `12.50€`) |
| **SYNC-6**: Double-tap pay creates EXACTLY 1 order (P0 idempotency); spec must query backend (axios or tinker) to verify count delta = 1 | UI debounce visual feedback |
| **SILENT-ERROR**: state 18 sweep finds 0 unallowlisted 4xx/5xx without paired DOM alert (P0) | Toast copy quality |
| Branch isolation respected: pos+chef+admin all on same branch — no cross-branch leakage in seeded orders | |

---

## Wave E — Kiosk ↔ Backend ↔ KDS ↔ POS suivi sync (NEW round-2)

**Spec file**: `tests/e2e/test-e2e-pos-kds-sync-2026-05-10-wave-E.spec.js`
**Screenshots dir**: `tests/e2e/__screenshots__/test-e2e-pos-kds-sync-E/`
**Surfaces / contexts**: **3 contexts in parallel**:
- `ctxKiosk` — kiosk machine context. Order placement is API-driven via `tests/e2e/helpers/kiosk-order.js` (`getKioskApiToken`, `placeKioskOrder`, etc.). The kiosk UI on `/kiosk/idle` is captured at order-placement boundary for visual sanity (confirmation screen / queue ticket display) but the wizard flow itself is not driven — this wave is sync-focused, not UX.
- `ctxKDS` — chef@lecayenne.fr at `/admin/kitchen-display-system`.
- `ctxPOS` — pos@lecayenne.fr at `/admin/pos-orders-tracker` (suivi tab). Kiosk orders MUST also surface in POS suivi as cross-surface coverage — staff must see borne orders alongside POS orders.

Each context attaches its own `attachMegaAuditRecorder` to `e-kiosk-XX`, `e-kds-XX`, `e-pos-XX` prefixes.

**Estimated states**: 14

### Pre-test setup (in `beforeAll`)
- Verify `KIOSK_ORDER_RATE_LIMIT` config (default 5/min) — increase to ≥30 for the test session via env or `Config::set('kiosk.order_rate_limit', 30)` to avoid 429 mid-spec (separate from F-08 which intentionally exercises the limit).
- `getKioskApiToken()` once and reuse across the wave (token TTL 480min covers spec runtime).
- Verify a kiosk machine row exists for the test branch; fail-fast if absent (do NOT silently fall back to no-auth POST).
- Clean up any prior `AUDIT-KIOSK-WAVE-E` orders.
- Set `QUEUE_CONNECTION=sync` (or schedule `queue:work --once` after each POST).

### Numbered states

1. `01-e-kiosk-baseline` — kiosk idle screen pre-order on `/kiosk/idle`; verify branding + language picker
2. `02-e-kds-baseline` — KDS pile snapshot pre-order (empty or pre-existing)
3. `03-e-pos-suivi-baseline` — POS suivi tab snapshot pre-order
4. `04-e-kiosk-order-placed` — after `placeKioskOrder(...)` resolves: capture kiosk UI confirmation screen + record `order_id`, `queue_number`, `total_amount`, `idempotency_key` to a sidecar `04-e-kiosk-order-placed.payload.json`. Assert HTTP 201, no 4xx/5xx in response chain.
5. `05-e-kds-after-kiosk-pay-within-8s` — KDS context capture ≤8s of state 04; assert order card present (P0 SYNC-E-1); assert source badge visually distinct = "Borne" (or equivalent kiosk indicator) — capture card DOM as evidence.
6. `06-e-pos-suivi-after-kiosk-pay-within-8s` — POS suivi capture ≤8s of state 04; assert kiosk order visible (P0 SYNC-E-2); assert source badge "Borne" or kiosk-source indicator distinct from POS-source rows.
7. `07-e-kds-mark-preparing` — chef drives PENDING → PREPARING (KDS UI click). Capture KDS post-click.
8. `08-e-pos-suivi-reflects-preparing` — POS suivi context capture ≤5s after state 07; assert status badge updated on kiosk-source order. Per round-1 D-004 fix: use in-place mutation observation (NOT `page.goto` reload — that would mask realtime regressions).
9. `09-e-kds-mark-prepared` — PREPARING → PREPARED.
10. `10-e-pos-suivi-reflects-prepared` — POS suivi reflects ≤5s.
11. `11-e-kds-mark-served-or-pos-deliver` — terminal transition. If KDS owns "served" action: click in KDS; else drive via POS suivi "marquer comme livré".
12. `12-e-kds-removes-from-active` — KDS card absent from active columns ≤5s; archived list incremented if visible.
13. `13-e-source-isolation-borne-vs-pos` — concurrent state: place a POS order via POS context (wizard flow) WHILE a kiosk order is already on the pile. Assert KDS shows BOTH cards, source badges distinct (Borne vs POS lane / different badge color), idempotency keys land in different cache buckets (verify via tinker query on `idempotency:v1:{branch}:{user}:{hash}` — kiosk user_id ≠ POS user_id → different cache rows).
14. `14-e-kiosk-order-cancel-edge` — IF cancellation flow exists for kiosk orders (verify in pre-flight; if absent, mark state as SKIPPED with note in spec): place a kiosk order, then cancel via the documented cancellation pathway. Assert KDS removes the card ≤5s (P0 SYNC-E-CANCEL). If no cancellation pathway exists, downgrade to P3 documentation gap finding.

### Acceptance criteria

| MUST PASS (P0/P1) | BEST EFFORT (P2/P3) |
|---|---|
| **SYNC-E-1**: KDS card present ≤8s of kiosk POST (P0) | Badge color contrast quality |
| **SYNC-E-2**: POS suivi shows kiosk order ≤8s (P0) | Confirmation-screen i18n polish |
| **SYNC-E-3**: KDS status change → POS suivi ≤5s for kiosk-source order (P0; same realtime path as D) | Queue-number visual treatment |
| **SYNC-E-4**: kiosk + POS concurrent orders to same branch — BOTH reach KDS independently (P0) — no merge, no loss, no broadcast race | KDS card source-badge a11y label |
| **SYNC-E-5**: numeric integrity end-to-end (`T_kiosk_paid === T_db === T_kds_card === T_pos_suivi`) (P0); assert in spec, not just visually | Decimal formatting parity |
| **SYNC-E-CANCEL**: kiosk cancel → KDS removes ≤5s (P0 if cancellation pathway exists; P3 doc gap if absent) | Cancellation confirmation UX |
| Source badge "Borne" visually distinct from POS source on KDS card (P1) | Cancellation toast copy |
| Zero silent 4xx/5xx on kiosk POSTs (P0) | Polling vs Echo tick visible in network.json |

---

## Wave F — Idempotency · Outbox · Race Conditions · Channel Isolation DEEP (NEW round-2)

**Spec file**: `tests/e2e/test-e2e-pos-kds-sync-2026-05-10-wave-F.spec.js`
**Screenshots dir**: `tests/e2e/__screenshots__/test-e2e-pos-kds-sync-F/`
**Surfaces / contexts**: 1-2 contexts depending on scenario; this wave is primarily **API + assertion-driven** via `kiosk-order.js` helpers + `php artisan tinker` queries. Visual captures (`mega-audit-snap`) act as evidence anchors, NOT as visual-judgement targets. Each scenario produces a `*.assertion.json` sidecar with the proof tuple `{request, response, db_query_result, cache_key_state}`.

**Estimated states**: 12

### Pre-test setup (in `beforeAll`)
- `getKioskApiToken()`.
- `clearFoodKingRateLimits()` + `Cache::tags(['idempotency'])->flush()` (or fallback to `Cache::flush()` if no tag support).
- Confirm `BROADCAST_DRIVER` default (snapshot for restore in `afterAll`).
- Confirm 2-branch fixture availability for state 11; if only 1 branch, mark state 11 as tinker-only assertion.

### Numbered states (each is a SCENARIO with assertion-heavy proof)

1. `01-f-idempotency-replay-same-payload` — `placeKioskOrderTwice(key=K1, payload=P1, payload=P1)`. Assert: response 1 = 201 (or 200) with `Idempotency-Replayed: false`, response 2 = same status with `Idempotency-Replayed: true` header AND identical response body. Tinker query: `Order::where('idempotency_key', 'K1')->count() === 1`. Capture POS suivi + KDS post-call: exactly 1 order visible across surfaces.
2. `02-f-idempotency-conflict-different-payload` — `placeKioskOrderTwiceDifferentPayload(key=K2, payload=P1, payload=P2)`. Assert: response 2 returns HTTP 409 Conflict. Tinker query: still only 1 order with `idempotency_key=K2`. Capture `network.json` showing 409 response body for adversarial review.
3. `03-f-idempotency-pos-double-tap-actually-exercised` — POS wizard double-tap on the "Payer" button after `clearFoodKingRateLimits()`. Assert via `network.json`: both POST requests share the same `X-Idempotency-Key` header OR are gated by frontend debounce. If different keys, finding = "frontend should reuse key on rapid retap" (reclassify as P1 frontend bug, NOT idempotency middleware failure). If same key: assert one response carries `Idempotency-Replayed: true` OR is queued behind the cache lock. Exactly 1 order in DB.
4. `04-f-concurrent-kiosk-pos-same-branch` — `Promise.all([placeKioskOrder(...), placePosOrder(...)])` to the same branch. Assert: 2 distinct orders in `orders` table (different ids, different idempotency cache buckets due to different `user_id` scope), 2 distinct cards on KDS post-state, no broadcast collision. Capture KDS DOM showing both cards.
5. `05-f-outbox-retry-curve` — disable broadcast: tinker `Config::set('broadcasting.default', 'null')` + reset cached connection. Place a kiosk order. Assert: `domain_events` row exists for `OrderCreated` with `dispatched_at IS NULL`. Re-enable broadcast (restore driver) + flush: run `php artisan queue:work --once --queue=high`. Assert: KDS receives the order ≤30s. Capture KDS card post-flush as evidence.
6. `06-f-version-gating-replay` — via tinker: manually `Event::dispatch(new OrderUpdated($order, 'status_change', $payload))` TWICE in rapid succession with the same `version` field. Assert in browser console (via `page.on('console')`): `KdsSyncService` logs the gating decision (drop second event because `_versionMap.get(orderId) >= version`). Assert: `kdsErrorBanner` never appears; KDS DOM does NOT mutate twice (verify by tracking DOM mutation count via `MutationObserver` injected before dispatch).
7. `07-f-broadcast-after-commit-only` — wrap order creation in a transaction that rolls back: tinker `DB::beginTransaction(); $order = Order::create(...); DB::rollBack();`. Assert: NO `OrderCreated` broadcast fires (verified by spying on the Pusher / log channel via `Event::fake([OrderCreated::class])->dispatched()` — empty array). DB row count delta = 0. This validates the `DispatchableAfterCommit` invariant.
8. `08-f-rate-limit-kiosk-orders-feedback` — temporarily set `Config::set('kiosk.order_rate_limit', 5)` (or use the default if not overridden in pre-flight). Fire 7 kiosk orders in 60s via `placeKioskOrder()`. Assert: requests 6 and 7 return HTTP 429 with response body `{"message": "Trop de commandes...", "retry_after": 60}`. Assert via kiosk UI capture: IF the kiosk SPA also has a 429-toast surface (verify in source), it shows a visible rate-limit message; ELSE flag as new finding "kiosk SPA does not surface 429 to user" (P1 silent_error).
9. `09-f-poll-fallback-when-broadcast-down` — disable broadcast (`Config::set('broadcasting.default', 'null')`). Place an order via kiosk POST. Assert: KDS picks up via `KdsSyncService` polling tick within 10s (disconnected interval ≈10s). Assert: OSS picks up within 4s (2s interval + jitter, per D-002 fix). Capture both KDS + OSS post-tick as evidence. Restore driver in `afterAll`.
10. `10-f-source-correlation-id-trace` — pass `placeKioskOrder({ correlationId: 'corr-F10-001' })`. Assert chain: same correlation-id present in (a) outgoing request `X-Correlation-ID` header (via network.json), (b) `domain_events` row `metadata` JSON (tinker query), (c) `audit_logs` row `event_id_external` field if applicable for this order type, (d) KDS console payload if surfaced (`page.on('console')`). If any leg is missing the correlation id, finding = "correlation id not propagated through layer X" (P1 observability gap).
11. `11-f-broadcast-channel-isolation-cross-branch` — IF dev DB has 2+ branches: place a kiosk order in branch A. Open a KDS context bound to branch B (different chef user). Assert: KDS-B does NOT receive the broadcast (channel `private-branch.{A_id}` is not subscribed by branch B). IF dev DB has only 1 branch (typical): degrade to tinker-only assertion verifying the `OrderCreated` event's `broadcastOn()` returns `new PrivateChannel("branch.{branch_id}")` with the correct id, and that `BroadcastEvent` queue payload's `channels` field contains only that one channel.
12. `12-f-kds-version-map-lru-eviction` — fire 260 `OrderUpdated` events via tinker for 260 distinct fabricated order ids in rapid succession (`for ($i=0; $i<260; $i++) Event::dispatch(...)`). Assert via browser console: after the 260 dispatches, `_versionMap.size` is capped at 256 (LRU eviction). Then re-dispatch one of the oldest evicted ids — assert: the event is NOT gated (no entry in `_versionMap` → baseline reset). This is a stretch deterministic test — flag as P3 if assertion proves flaky across runs.

### Acceptance criteria

| MUST PASS (P0/P1) | BEST EFFORT (P2/P3) |
|---|---|
| **SYNC-F-IDEM**: same key + same payload = 1 order; same key + different payload = 409 (P0) | LRU eviction determinism (state 12) |
| **SYNC-F-CONCURRENT**: kiosk + POS Promise.all = 2 orders, 2 KDS cards, no race (P0) | Correlation id formatting consistency |
| **SYNC-F-OUTBOX**: broadcast pipeline preserves at-least-once delivery via outbox (state 05) (P0) | Backoff curve visualization |
| **SYNC-F-COMMIT**: rollback transaction = no broadcast fires (P0) | Tinker output sidecar polish |
| **SYNC-F-CHANNEL**: cross-branch broadcast isolation (P0; degrades to tinker-only if 1-branch dev DB) | Rate-limit toast UX (state 08) |
| **SYNC-F-VERSION**: version-gating drops replays (P1) | Console log signal-to-noise |
| **SYNC-F-RATE-LIMIT-UI**: kiosk 429 surfaces visibly OR is documented as silent-error finding (P1) | |
| **SYNC-F-CORR**: correlation id propagates end-to-end through request → outbox → audit log → KDS console (P1 observability) | |
| Zero silent 4xx/5xx on Wave F POSTs (excluding intentional 409 at state 02 and 429 at state 08) (P0) | |
| Assertion sidecars (`*.assertion.json`) present for every state — proof tuples recoverable for adversarial review (process discipline) | |

---

## Cross-surface scenarios — formal registry

Each scenario maps to one or more states above. Spec assertions must reference the scenario ID in code comments for traceability.

| ID | Scenario | Spans | States | P-level |
|---|---|---|---|---|
| **SYNC-1** | POS pay (cash) → KDS pile within 8s | `ctxPOS` + `ctxKDS` | Wave D 05 → 06 | P0 |
| **SYNC-2** | POS pay → OSS within 8s | `ctxPOS` + `ctxOSS` | Wave D 05 → 07 | P0 |
| **SYNC-3** | KDS status → POS suivi within 5s | `ctxKDS` + `ctxPOS` | Wave D 08↔09, 11↔12 | P0 |
| **SYNC-4** | POS marks SERVED → KDS removes from active within 5s | `ctxPOS` + `ctxKDS` | Wave D 14 → 15 | P0 |
| **SYNC-5** | Numeric integrity end-to-end (T_cart = T_receipt = T_kds = T_oss) | All 3 | Wave D 04, 05, 06, 07 | P0 |
| **SYNC-6** | Idempotency — double-tap pay button creates 1 order | `ctxPOS` + backend | Wave D 17 | P0 |
| **SYNC-7** *(implicit)* | OSS reflects status changes (PENDING→IN_PROGRESS→READY→SERVED) | `ctxOSS` | Wave D 10, 13, 16 | P1 (UX, not lifecycle) |
| **SYNC-E-1** | Kiosk pay → KDS pile ≤8s with source badge "Borne" | `ctxKiosk` + `ctxKDS` | Wave E 04 → 05 | P0 |
| **SYNC-E-2** | Kiosk pay → POS suivi ≤8s | `ctxKiosk` + `ctxPOS` | Wave E 04 → 06 | P0 |
| **SYNC-E-3** | KDS status change → POS suivi ≤5s for kiosk-source order | `ctxKDS` + `ctxPOS` | Wave E 07↔08, 09↔10 | P0 |
| **SYNC-E-4** | Kiosk + POS concurrent orders → both reach KDS independently | `ctxKiosk` + `ctxPOS` + `ctxKDS` | Wave E 13 | P0 |
| **SYNC-E-5** | Numeric integrity end-to-end (T_kiosk_paid = T_db = T_kds_card = T_pos_suivi) | All 3 + backend | Wave E 04, 05, 06 | P0 |
| **SYNC-E-CANCEL** | Kiosk cancel → KDS removes ≤5s | `ctxKiosk` + `ctxKDS` | Wave E 14 | P0 (if pathway exists) / P3 doc-gap |
| **SYNC-F-IDEM** | Same key+payload = 1 order; same key+different payload = 409 | `ctxKiosk` + backend | Wave F 01, 02 | P0 |
| **SYNC-F-CONCURRENT** | Kiosk + POS Promise.all = 2 orders, 2 KDS cards, no race | `ctxKiosk` + `ctxPOS` + backend | Wave F 04 | P0 |
| **SYNC-F-OUTBOX** | Outbox preserves at-least-once delivery when broadcast down then restored | `ctxKiosk` + `ctxKDS` + backend | Wave F 05 | P0 |
| **SYNC-F-COMMIT** | Rollback transaction = no broadcast fires (`DispatchableAfterCommit` invariant) | Backend (tinker) | Wave F 07 | P0 |
| **SYNC-F-CHANNEL** | Cross-branch broadcast channel isolation (`private-branch.{id}`) | `ctxKDS` × 2 OR backend | Wave F 11 | P0 (degrades to tinker-only if 1-branch) |
| **SYNC-F-VERSION** | KdsSyncService version-gating drops replays | `ctxKDS` + backend | Wave F 06 | P1 |
| **SYNC-F-RATE-LIMIT-UI** | Kiosk 429 surfaces visibly to user | `ctxKiosk` | Wave F 08 | P1 (silent-error class) |
| **SYNC-F-CORR** | Correlation id propagates end-to-end through request → outbox → audit → console | `ctxKiosk` + backend | Wave F 10 | P1 (observability) |
| **SYNC-F-LRU** | KdsSyncService `_versionMap` LRU caps at 256 with correct eviction | `ctxKDS` + backend | Wave F 12 | P3 (stretch) |
| **SYNC-F-POLL-FALLBACK** | KDS picks up via polling ≤10s when broadcast down; OSS ≤4s | `ctxKiosk` + `ctxKDS` + `ctxOSS` | Wave F 09 | P0 |

Each scenario lists: spans (browser contexts), assertions (DOM + network + numeric + DB row + cache key state), success criteria (timing + value equality + side-effect absence).

---

## Cross-cutting assertions — every critical feature mapped to a wave

| # | Critical feature | Wave / state(s) |
|---|---|---|
| 1 | POS visual page-by-page (shell, cart, payment modal, suivi, receipt, parked) | Wave A states 02–14 |
| 2 | POS wizard popup visual (3 item types: simple / options / composite) | Wave B states 02–17 |
| 3 | Cart line + grand total numeric integrity | Wave A state 08; Wave B states 04, 10, 15, 16 |
| 4 | Wizard recap = cart line price (no silent overcharge) | Wave B states 03↔04, 09↔10, 14↔15 |
| 5 | KDS lifecycle PENDING → IN_PROGRESS → READY → SERVED | Wave C states 04–09; Wave D states 08, 11, 14 |
| 6 | KDS card total === seeded order total | Wave C state 12; Wave D state 06 |
| 7 | KDS polling/fallback tick (no silent 4xx/5xx) | Wave C state 11; Wave D state 18 |
| 8 | KDS error resilience on 503 (no silent failure) | Wave C state 14 |
| 9 | POS pay → KDS within 8s | Wave D SYNC-1 (states 05→06) |
| 10 | POS pay → OSS within 8s | Wave D SYNC-2 (states 05→07) |
| 11 | KDS status → POS suivi within 5s | Wave D SYNC-3 (08↔09, 11↔12) |
| 12 | POS SERVED → KDS removes within 5s | Wave D SYNC-4 (states 14→15) |
| 13 | Numeric integrity cross-surface (T_cart=T_receipt=T_kds=T_oss) | Wave D SYNC-5 (states 04, 05, 06, 07) |
| 14 | Idempotency double-tap pay (1 order created) | Wave D SYNC-6 (state 17) |
| 15 | Silent-error sweep (network 4xx/5xx without paired DOM alert) | Wave D state 18; Wave A all states; Wave C all states |
| 16 | i18n leak detection (no `^[a-z]+(\.[a-z_]+){1,4}$` in DOM) | All waves, all states |
| 17 | Le Cayenne palette / no dark-mode flash | Wave A state 02; Wave B state 02 |
| 18 | Frozen-zone integrity (zero line of patch) | Wave B end-of-round diff check |
| 19 | Kiosk pay → KDS within 8s with "Borne" source badge | Wave E SYNC-E-1 (states 04→05) |
| 20 | Kiosk pay → POS suivi within 8s | Wave E SYNC-E-2 (states 04→06) |
| 21 | KDS status → POS suivi within 5s for kiosk-source order | Wave E SYNC-E-3 (07↔08, 09↔10) |
| 22 | Kiosk + POS concurrent — both reach KDS, no merge/loss/race | Wave E SYNC-E-4 (state 13); Wave F SYNC-F-CONCURRENT (state 04) |
| 23 | Numeric integrity kiosk leg (T_kiosk_paid=T_db=T_kds=T_pos_suivi) | Wave E SYNC-E-5 (states 04, 05, 06) |
| 24 | Kiosk cancel → KDS removes within 5s | Wave E SYNC-E-CANCEL (state 14) |
| 25 | Idempotency same-key same-payload = 1 order | Wave F SYNC-F-IDEM (state 01) |
| 26 | Idempotency same-key different-payload = 409 conflict | Wave F SYNC-F-IDEM (state 02) |
| 27 | POS double-tap shares idempotency key (or rejected as frontend bug) | Wave F state 03; Wave D SYNC-6 (state 17) |
| 28 | Outbox at-least-once delivery survives broadcast outage | Wave F SYNC-F-OUTBOX (state 05) |
| 29 | KdsSyncService version-gating drops replay events | Wave F SYNC-F-VERSION (state 06) |
| 30 | `DispatchableAfterCommit` invariant — rollback = no broadcast | Wave F SYNC-F-COMMIT (state 07) |
| 31 | Kiosk 429 surfaces visibly to user (no silent rate-limit) | Wave F SYNC-F-RATE-LIMIT-UI (state 08) |
| 32 | KDS polling fallback within 10s when broadcast down; OSS within 4s | Wave F SYNC-F-POLL-FALLBACK (state 09) |
| 33 | Correlation id propagates end-to-end (request → outbox → audit → console) | Wave F SYNC-F-CORR (state 10) |
| 34 | Cross-branch broadcast channel isolation | Wave F SYNC-F-CHANNEL (state 11) |
| 35 | KdsSyncService `_versionMap` LRU cap = 256 (stretch) | Wave F SYNC-F-LRU (state 12) |

No assertion unmapped.

---

## Out-of-scope (explicit)

- **Kiosk borne** is **NOW IN SCOPE** via Wave E (round-2 expansion per owner directive "tout web et technique, ultra deep"). The visual borne audit `borne-cats-309-318-2026-05-10` remains a separate parallel concern — Wave E covers ONLY the kiosk → backend → KDS → POS sync technical path, not the full borne UX page-by-page.
- Mobile app
- Admin item / category management screens (deferred)
- Stock rupture cascade (separate audit concern)
- Customer screen / online ordering frontend (other than OSS)
- NF525 reporting / Z-reports (separate fiscal audit)
- Multi-tenant / multi-branch isolation beyond Wave F state 11 (cross-branch broadcast channel only) — full BranchScope audit on the 11 scoped models remains out-of-scope
- Real TPE hardware (TPE simulation OK; cash branch is the safe canonical path for `T_kds`/`T_oss` validation)
- Payment provider webhooks (Senangpay etc.) — separate latent-bug audit
- Loyalty / customer creation flows
- Receipt printing hardware

---

## Pre-flight (already done by orchestrator — documented for reproducibility)

- ✅ Server health: `/admin/pos`, `/admin/pos-v4`, `/kds`, `/admin/order-status-screen`, `/login` all return 200
- ✅ Login creds verified: `pos@lecayenne.fr`, `chef@lecayenne.fr`, `admin@lecayenne.fr` (all `123456`)
- ✅ Bundles fresh: `app.js` dated 2026-05-10 20:33; `pos-wizard.js?v=9-...`
- ✅ Workers locked: `workers: 1` in `playwright.config.js` (login throttle)
- ✅ 0 pending migrations
- ✅ Reports scaffold present: `reports/test-e2e/pos-kds-sync-2026-05-10/round-1/` with REVIEWER_PROTOCOL + FINDINGS_SCHEMA copies
- ✅ Helpers verified: `login.js`, `mega-audit-snap.js`, `rate-limit.js`, `sync-journey-trace.js`

### Per-round pre-flight (run BEFORE every capture round)

- Run `php artisan iter15:cleanup-test-orders --apply` (also auto-invoked by helpers — defensive)
- Verify no leftover `AUDIT-KDS-WAVE-C` or `AUDIT-SYNC-JOURNEY` orders from prior runs
- Confirm 3 contexts (POS / KDS / OSS) can sit on screen simultaneously (Playwright trace open: ports + memory headroom)
- Wave D specifically: orchestrator should chase `php artisan queue:work --once` if jobs are queued post-pay (or document that the deployment runs sync queue in dev)

---

## Spec runner template (each wave runnable in isolation)

```bash
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 \
  npx playwright test tests/e2e/test-e2e-pos-kds-sync-2026-05-10-wave-<W>.spec.js \
  --project=chromium --workers=1 --retries=0 --reporter=list
```

Each wave OWNS its `__screenshots__/test-e2e-pos-kds-sync-<W>/` dir. Captures via `attachMegaAuditRecorder(page, dir)` → quartet (PNG + DOM + console.json + network.json) per state. Wave B adds a 5th sidecar (`*.payload.json`) at state 16. Wave D writes 3 parallel quartets per chronological state (one per context).

---

## Adversarial review (Wave-Reviewer — out-of-band, per round)

Reviewer wave is NOT a Playwright spec. After each capture round, an adversarial supervisor agent is invoked PER WAVE to inspect that wave's artifact quartet (and Wave-B payload sidecar, Wave-D triple-quartet) and emit:

```
reports/test-e2e/pos-kds-sync-2026-05-10/round-<N>/wave-<W>-findings.json
```

per `FINDINGS_SCHEMA.md`. Round-2 expands to waves C, D, E, F (A + B dropped from round-2 captures per "que côté technique" filter). Loop continues until `verdict === GREEN` for all in-scope waves (`open_P0 == 0` AND `open_P1 == 0` on technical/sync findings) for **two consecutive rounds with set-equality** (no new findings, no regressions). Visual-only findings from round-1 (listed in §Round-2 scope) do NOT gate convergence.

---

## State-budget summary

| Wave | Surface(s) | States | Sidecar | Round-1 | Round-2 |
|---|---|---|---|---|---|
| A | POS visual (no wizard) | 15 | quartet | ✅ captured | dropped (visual-only fixes verified by diff) |
| B | POS wizard (FROZEN) | 17 | quartet + payload at state 16 | ✅ captured | dropped (visual-only fixes verified by diff) |
| C | KDS visual + lifecycle | 14 | quartet | ✅ captured | re-captured |
| D | POS↔KDS↔OSS sync | 18 (× 3 surfaces tagged chronologically) | triple quartet | ✅ captured | re-captured |
| **E** | Kiosk↔Backend↔KDS↔POS suivi sync | **14** (× 3 surfaces tagged chronologically) | triple quartet + `04-e-kiosk-order-placed.payload.json` | n/a (new) | **captured** |
| **F** | Idempotency · Outbox · Race · Channel isolation | **12** | quartet + `*.assertion.json` per state | n/a (new) | **captured** |
| **TOTAL (full sweep)** | — | **90** PNGs (A+B+C+D+E+F) | — | 64 | — |
| **Round-2 actual** | — | **~58** PNGs (C+D+E+F = 14+18+14+12) | — | — | 58 |

---

## Risk register (orchestrator awareness)

| Risk | Mitigation |
|---|---|
| Wave B wizard popup may have animation race vs `snap()` | Add explicit `await page.waitForSelector('[data-pos-wizard]', { state: 'visible' })` before each wizard-step snap; wait for animation `await page.waitForTimeout(250)` after open |
| Wave D 3 contexts may race on shared DB; KDS may show stale order if seed order ID collision | Use unique order prefix per round (`AUDIT-SYNC-D-R{N}`) and clean up in `afterAll` |
| `T_oss === T_receipt` may fail if OSS does not display amount (only shows order # + status) | Spec must check rendered DOM first; if amount not displayed, downgrade SYNC-5 OSS leg to "order # parity" assertion only |
| Polling vs WebSocket fallback timing — KDS may receive update via push OR poll | Wave D uses 8s budget for SYNC-1 (covers 6s polling fallback + 2s buffer) |
| Idempotency (SYNC-6) — backend dedup may be by header `X-Idempotency-Key`; double-tap may not trigger if frontend generates 2 keys | Spec must verify the 2 clicks share an idempotency key by inspecting outgoing requests in `network.json`; if frontend generates 2 keys, finding is "frontend should reuse key", reclassified as P1 frontend bug |
| Frozen-zone diff check — must be enforced by orchestrator at end of each round (`git diff --name-only public/js/pos-wizard.js public/css/pos-wizard.css resources/views/admin-pos-v4.blade.php` MUST be empty) | Add to per-round CI step before declaring round closed |
| **Wave E** kiosk machine seed may be absent in dev DB — `placeKioskOrder()` will 401/403 if `getKioskApiToken()` finds no machine | `beforeAll` fail-fast with explicit error "no kiosk machine seeded for branch X" — do NOT auto-create (would mask deploy gap); document seed command in spec header |
| **Wave E** kiosk `KIOSK_ORDER_RATE_LIMIT` default = 5/min — would 429 mid-wave on a 14-state run | Pre-flight raises limit to ≥30/min for Wave E session; Wave F state 08 intentionally exercises the default and restores afterward |
| **Wave E** state 13 (kiosk + POS concurrent) — POS context may not be at suivi-tab while kiosk POSTs; KDS card lane labeling may not visually differentiate source if no source-badge feature exists | Spec must verify source-badge DOM presence first; if absent, downgrade SYNC-E-4 to "two cards present with distinct order numbers" + raise P1 finding "KDS lacks visible source distinction Borne vs POS" |
| **Wave E** state 14 (kiosk cancel) — cancellation pathway for kiosk-source orders may not exist | Pre-flight verifies pathway via route discovery; if absent, state 14 = SKIP + raise P3 doc-gap finding "no kiosk cancel UX surfaced — operator-only via POS suivi" |
| **Wave F** state 03 (POS double-tap) — frontend may emit different `X-Idempotency-Key` per click | Spec inspects outgoing requests; reclassify as P1 frontend bug + still asserts backend dedup correctness for the single-key scenario via the helper |
| **Wave F** state 05 (outbox retry) — toggling `BROADCAST_DRIVER` dynamically requires either `Config::set` + binding refresh OR `.env` rewrite + `config:clear`; both have edge cases | Helper `kiosk-order.js` should expose `withBroadcastDriver('null', async () => {...})` wrapper; cleanup MUST restore prior driver in `afterAll` even on test failure (`try/finally`) |
| **Wave F** state 06 (version-gating) — `KdsSyncService._versionMap` is private to the module; cannot inspect from page.evaluate without instrumentation | Inject a `MutationObserver` on `#kds-orders` before dispatching the two events; assert mutation count = 1 not 2; supplement with `page.on('console')` listener for explicit gating log line |
| **Wave F** state 07 (rollback) — `Event::fake` does not capture dispatch-after-commit because the event NEVER fires on rollback; the assertion is "no event fires", not "event was caught" | Use `Bus::fake` + `Bus::assertNotDispatched(PersistOrderCreatedToOutbox::class)` AND `DB::table('domain_events')->where('id', '>', $baselineMaxId)->count() === 0` as positive proof |
| **Wave F** state 11 (cross-branch isolation) — dev DB typically 1-branch only | Degrade gracefully to tinker-only assertion verifying `OrderCreated::broadcastOn()` returns `[new PrivateChannel("branch.{branch_id}")]` with the order's actual branch id; raise P1 if multi-branch fixture is feasible and missing |
| **Wave F** state 12 (LRU eviction) — fabricating 260 distinct order ids requires either real DB rows or a tinker `Order::factory()->count(260)->make()` (without persistence) then manually dispatching events; deterministic eviction order depends on `KdsSyncService` internals | Mark P3, accept flakiness; require 3-of-3 runs to assert convergence; if not deterministic, document as "version-map LRU cap value un-asserted" backlog item |
| **Wave F** state 08 (rate-limit UI) — kiosk SPA 429 handling unknown until source-grepped | Pre-flight check `resources/js/components/kiosk/**` for 429 handlers; if absent, state 08 expected to surface P1 finding rather than P0 |
| **Round-2** parallel contexts (E has 3, D has 3 = up to 6 simultaneous browsers if waves run interleaved) — risk of memory pressure + login throttle | Keep waves sequential within a single round; `workers: 1` in playwright.config.js already enforces no inter-wave parallelism; document expected wall-clock per wave in spec header |
| **Round-2** queue driver switch — if some specs run under `QUEUE_CONNECTION=sync` and some under `database`, behavior diverges; specs must self-declare and verify their assumption | Each spec's `beforeAll` reads `process.env.QUEUE_CONNECTION` (or queries via tinker `config('queue.default')`) and fails-fast if mismatched with the spec's assumed mode |
