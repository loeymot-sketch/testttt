# GOAL — FELT-PRODUCT PERFECTION (Le Cayenne V1, the "other angle")
**Created:** 2026-06-08 · **Status:** PLAN-ONLY (written this turn; execution awaits owner GO) · **Author:** Claude (central supervisor) · **Branch:** `heal/pre-cloud-exec-2026-06-05` (worktree `pre-cloud-exec`)
**Pipeline per task:** `~/.claude/skills/ultra-audit-profond/` (14-step LOOP) — not re-described here.
**Evidence base:** read-only 12-agent fan-out (1.82M tokens, 505 tool-uses) over **1,824 past report files + 331 e2e specs + the live code of 369 Vue components**. Raw: `reports/test-e2e/goal-100pct-2026-06-07/CONVERGENCE_VERDICT.md` (prior campaign) + this fan-out's matrix.

---

## §0 — PREAMBLE (read first)

### 0.1 Why this GOAL exists (the owner's actual ask)
40+ past campaigns drove FoodKing to **fiscal/NF525 + sync + raw-label perfection**. The owner's words: *"we have already abused the tests normally, but I feel that we have not always done the perfect thing… I try to see from another perspective, from another angle."* This GOAL **deliberately changes the lens** from *"is the DB/fiscal chain correct"* (exhausted, GREEN) to **the FELT PRODUCT** — what the operator and client actually **see, feel, and read on every page**:
- **the rendered number is right on every page** ("bien calculé sur chaque page") — totals/counts/badges/AOV painted on screen, not just the DB row;
- **every page survives hostile UX** — overflow, tiny/huge viewport, offline mid-flow, empty/error/loading states;
- **the interface is optimized** — bundle weight, N+1-behind-a-page, heavy re-render;
- **synchronization as perceived** — staleness/flicker the user sees, not the chain;
- **client-facing security** — UI-reachable actions, PII in the DOM;
- **a11y / i18n on the populated, interactive state**, not just first paint.

### 0.2 Scope & non-goals
- **IN:** the 5 systems' felt-product quality (BORNE, CAISSE, KDS+OSS, WEB storefront, CENTRAL), weighted to the **daily operational path**.
- **OUT (already exhausted / out of mandate):** fiscal/NF525 correctness (GREEN across 40 campaigns + round-5 abuse), cloud/scale/multi-tenant/concurrency-at-scale (owner mandate: *"JAMAIS un blocker V1"*), the standalone web/`mobile/` (NO V1 wireup — included in the matrix for completeness only, `daily_path=false`).
- **This turn = PLAN ONLY.** No code touched, no live browser, no healing. Execution (test → correct → re-verify, as before) is a separate owner-GO'd phase.

### 0.3 Working-tree decision
Plan is **docs-only** (`plans/` + `reports/`). The repo working tree carries pre-existing unrelated noise (`.playwright-mcp/*.yml` deletions) — untouched. No app/frozen file is edited in the planning turn. Execution will follow the §X waves on this branch (or a fresh `heal/felt-product-*` branch at owner discretion).

### 0.4 Convergence criteria (for the LATER execution phase)
A surface-cell is DONE only when **all** hold (rejection rules, per `ultra-architect-planify` Axis 6):
- the **rendered** number == the source-of-truth number (data-correctness asserted on the DOM, not just the API);
- the surface survives **every abuse-set condition** (§2.2) with no layout break, no raw label, no console error;
- empty / error / loading states are **forced and verified** (screenshot Read + analyzed);
- frozen-zone diff = 0 (or an owner LOCK exists);
- 2 consecutive clean cycles (P1=0, identical findings set) — the `test-e2e` convergence rule.
**Evidence is owner-perceptible:** per-surface screenshot galleries + a per-page "is the math right" assertion. Test-green alone is explicitly **not** acceptance (it's what left the owner unsatisfied after 40 green campaigns).

### 0.5 Hard constraints (Constitution + CLAUDE.md, non-negotiable)
- **Frozen zones** (fix here = owner LOCK + countersign, NEVER a free task): `KioskWizardComponent/KioskAppComponent/KioskUpsellComponent.vue`, `admin/pos/PaymentComponent.vue`, `pos/v5/PosV5TrancheRow.vue`, `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `admin-pos-v4.blade.php`, `app/Services/Fiscal/*`, `PricingService`, `BranchScope`, `IdempotencyKeyMiddleware`, `OrderStateMachine`. Every finding below is **pre-classified frozen/non-frozen**.
- **FR locale canonical** (ADR-007). **DEVDB-GUARD:** never `php artisan test` (use `vendor/bin/phpunit --filter`); E2E only on disposable clone `foodking_e2e` :8766, NEVER operating `foodking` :8765. Never `git add -A`. Never push without owner.

---

## §1 — COVERAGE MATRIX (the keystone — "the other angle", evidence we didn't re-run the same lens)
17 operational surfaces × 10 felt-product dimensions, graded from the 40-campaign corpus.
**● covered** (genuinely driven + verified) · **◐ partial** (touched, shallow/incidental) · **○ never** (no campaign targeted this cell). `▶`=daily-path.

| Surface | VL | i18n | a11y | EMPTY | ERROR | LOAD | **DATA** | **PERF** | SYNC | SEC |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| ▶ kiosk idle | ● | ● | ● | ● | ● | ◐ | ● | ◐ | ● | ● |
| ▶ kiosk wizard (composer) *frozen* | ● | ● | ◐ | ◐ | ● | ◐ | ◐ | ○ | ● | ● |
| ▶ kiosk cart / confirmation | ● | ● | ◐ | ◐ | ● | ◐ | ◐ | ○ | ● | ● |
| ▶ POS order / cart *frozen wizard* | ● | ● | ◐ | ◐ | ● | ● | ◐ | ○ | ● | ● |
| ▶ POS payment modal *frozen* | ● | ● | ◐ | ● | ● | ◐ | ◐ | ○ | ● | ● |
| ▶ encaissement modal (CounterCollect) | ● | ◐ | ◐ | ◐ | **○** | ◐ | ◐ | ○ | ● | ● |
| ▶ KDS board | ● | ● | ● | ● | ● | ◐ | ◐ | ○ | ● | ● |
| ▶ KDS history drawer | ● | ● | ◐ | ● | ● | ● | ● | ● | ● | ● |
| ▶ OSS client screen | ● | ● | ◐ | ◐ | ◐ | **○** | ◐ | ○ | ● | ● |
| ▶ Z-close / X-report screen | ◐ | ◐ | **○** | **○** | ◐ | **○** | ● | ○ | ● | ◐ |
| ▶ dashboard day numbers | ● | ● | ● | ◐ | ◐ | **○** | ◐ | ○ | ● | ◐ |
| ▶ order history (/historique) | ● | ◐ | **○** | **○** | **○** | **○** | ◐ | ○ | ● | ◐ |
| ▶ stock rupture | ● | ● | ● | ◐ | ◐ | **○** | ◐ | ○ | ● | ◐ |
| sales / items reports | ◐ | ◐ | **○** | **○** | ◐ | **○** | ◐ | ○ | ● | ◐ |
| catalogue items / studio | ● | ● | ● | ◐ | ● | ◐ | ◐ | ○ | ● | ● |
| login | ● | ● | ● | ● | ◐ | ◐ | ● | ● | ● | ● |
| customer storefront (standalone, no wireup) | ● | ● | ◐ | ◐ | ◐ | ○ | ◐ | ○ | ● | ◐ |

**Corpus shape:** very strong on fiscal/NF525, **sync-perceived**, **visual-layout**, **i18n raw-label** (repeated sweeps — re-running wastes the plan). The blind columns are unmistakable: **DATA-correctness-of-the-rendered-number, PERF-optimization, and the EMPTY/ERROR/LOADING states on operator surfaces** — exactly the owner's "bien calculé / bien optimisé sur chaque page."

### 1.1 The gap cells the plan attacks (ranked: daily_path × non-frozen × actionable)
1. **encaissement × ERROR = NEVER** — no-session CASH soft-skips; the recommended 409 *"ouvre un fond de caisse d'abord"* modal **does not exist** (NON-frozen, daily-path, single highest-value buildable fix).
2. **dashboard × DATA = PARTIAL** — only single-order *Ticket Moyen 1,50 €* eyeballed; **multi-order AOV/rounding + CA-vs-Z reconciliation of the RENDERED KPI never asserted.**
3. **order history × {a11y, empty, loading, error} = NEVER; DATA/PERF partial/never** — 344-page / 3431-row table never axe'd, no empty/loading/fetch-error UX, the *"3431 entrées / 344 pages"* count + pagination boundaries never asserted, query/render cost never profiled.
4. **Z-close/X screen × {a11y, empty-day, loading} = NEVER** — fiscally exhausted, but the **screen + PDF** visual/a11y/empty-day/close-in-progress UX never driven.
5. **OSS × loading = NEVER, empty/error = PARTIAL** — empty board + feed-down on-screen UX never forced; autoscroll tail-hiding (FP-04).
6. **KDS board × {loading, DATA, perf}** — per-column COUNT badges + elapsed-timer correctness not asserted on the rendered board; 50-card poll re-render never measured.
7. **PERF = NEVER across nearly every operator surface** — corpus only measured FCP/LCP-on-load (known P2). Bundle weight (`app.js` 2.27MB, `pos-app.js` 2.18MB, `admin-shell.js` 2.01MB, `vendor.js` 1.89MB, `pos-wizard.js` 289KB), **N+1-feeding-a-page** (history 3431 rows, dashboard KPI aggregates, stock availability tree), heavy re-render (KDS/OSS poll redraw) — statically analyzable, read-only friendly.
8. **stock rupture × {loading, DATA, perf}** + **sales/items reports × {a11y, empty, loading, DATA}** — report SCREEN totals/grouping/period-sum never asserted on-page.
9. **a11y on POPULATED/MODAL state** — axe only ran on idle/first-paint; gap = axe on the OPEN encaissement modal / multi-split payment / populated KDS board / OSS queue.

> **Caveat (read with §3.2):** cells 1–9 that are anchorable read-only became `FP-` findings in §3.1; the cells that need a live browser to discover (Z-close a11y/empty/loading, OSS empty-board, dashboard 0-order KPI, /historique a11y/empty/loading, report-screen a11y/empty) have **no static finding** and live in **§3.2 live-discovery backlog** — they are driven in the execution phase's visual passes, not assumed green.

### 1.2 Already CLOSED — do NOT re-raise (the plan must not re-discover these)
H1 invoice NF525 block · H2 kiosk XFF spoof · H5 set-branch-legal/footer · H7 discount ticket TVA netting · CP-2 TR label · T2-LIV delivery raw labels · KDS `kds_counter_payment_unpaid` raw label · M-KDS-4 history empty `<time>` · kiosk confirmation i18n migration · web button-name/progressbar a11y · F1 manual-discount→Z (disproven) · POS ERG-01..05 + cash-trail CASH-01/03.

### 1.3 KNOWN-OPEN-DEFERRED — list, do NOT count as new findings (owner V1.0.X decisions)
Brand-orange `#F4501E` contrast 3.49:1 (4 surfaces, owner brand call) · `aria-required-children` profile-menu (3 surfaces) · FCP 3.4s POS/KDS/OSS (P2) · web-standalone nested-interactive ×87 + contrast ×56 · KDS history `role=dialog` without focus-trap (P2) · POS 5 power-user keyboard frictions (V1.0.2) · `€1.50` en-US in frozen `pos-wizard.js` (POS-ERG-07).

---

## §2 — PRIORITIZATION & THE ABUSE-SET

### 2.1 Daily-path weighting (do NOT give 236 admin pages equal billing)
Constitution daily path drives the weight: **open caisse → take orders (borne + comptoir) → KDS prepares → client sees status (OSS) → encash (comptoir, TPE Plan B) → Z close → read day numbers (dashboard).** Tier-1 surfaces (heavy): encaissement, dashboard, KDS board, OSS, order history, Z-close, kiosk cart/confirmation, POS cart. Tier-2 (medium): stock rupture, sales/items reports, catalogue. Tier-3 (light, breadth only): the remaining ~220 CENTRAL admin CRUD pages — one shared "admin-CRUD abuse sweep" task, not per-page.

### 2.2 The hostile-UX ABUSE-SET (every Tier-1 surface gets driven through ALL of these)
1. **Network:** slow-3G + offline injected **mid-flow** (mid-wizard, mid-encaissement, mid-poll). 2. **Viewport:** ~360px (kiosk-min / phone) **and** huge 2560px. 3. **Overflow:** 60-char product name, 999 quantity, 200-line cart/board/pending-list. 4. **RTL/long text:** Arabic + German strings (longest locale). 5. **Forced states:** empty / error (API 500) / loading — each forced and screenshot-analyzed. 6. **Concurrency:** two operator tabs editing the same order; rapid double-tap on every action button. 7. **Data-truth:** the rendered total/count/badge re-derived independently and asserted == source-of-truth.

### 2.3 What "abusive" means for THIS lens (vs the inherited fiscal-break framing)
Not "can I forge a total" (done, round-5). It is: *can I make a page show a wrong number, break its layout, strand the user with a dead button or no feedback, or make sync look broken to a human* — under the abuse-set, on every daily-path surface.

---

## §3 — MASTER FINDINGS (the work-list)
> Filled from the synthesis pass (deduped from 83 raw findings). IDs `FP-NN`. Each: surface · dimension · severity · anchor (file:line) · frozen? · daily_path? · scope-minimal recommendation. Acceptance test path named per `ultra-architect-planify` Verified-Citation rule (existing `tests/...` OR `(test TO BE CREATED at …)`).

### 3.1 Master findings index (38 — deduped from 83 raw; full anchors+recommendations in `reports/test-e2e/goal-felt-product-2026-06-08/SYNTH_master_findings.json`)
`Sev` P1/P2/P3 · `D` dimension · `Fz` ✶=frozen-fix (owner gate) · `▶`=daily-path. Anchors are real file:line read in this worktree.

| ID | Sev | D | Fz | ▶ | Surface — Title | Anchor |
|----|:--:|---|:--:|:--:|---|---|
| FP-01 | P1 | err | · | ▶ | **/kiosk/error/network** — 'Réessayer' + 'Appeler le personnel' are DEAD (emit-only, no listener, no router/reload fallback) — borne fully stuck on connectivity loss | `KioskErrorNetworkComponent.vue:54-67 + KioskAppComponent.vue:121-127` |
| FP-02 | P1 | DATA | · | ▶ | **/kiosk/confirmation receipt** — receipt counts ONLY loyaltyDiscount, drops promoDiscount → printed subtotal−discount ≠ total | `KioskConfirmationComponent.vue:230,276 vs kioskCart.js:241/252` |
| FP-03 | P1 | i18n | · | ▶ | **POS parked-order recall** — 'partial restore' warning renders raw key `pos.park_restore_partial` (absent fr.json) → money-relevant 'verify cart' alert suppressed | `ParkedOrdersComponent.vue:207-209` |
| FP-04 | P1 | DATA | · | ▶ | **OSS customer wall** — auto-scroll fixed `translateY(-50%)` over an UNCAPPED feed → queue tail never enters viewport; a near-end customer never sees their N° go PRÊT | `PreparingAndReadyComponent.vue:486-494 + OrderStatusScreenOrderService.php:146` |
| FP-05 | P1 | sync | · | ▶ | **POS Orders Tracker** — on the box (APP_ENV=local) cashier gets ZERO WS-loss cue; kanban silently coasts on 8s poll while KDS does warn | `PosOrdersTrackerComponent.vue:97 + ConnectionStatusBanner.vue:89` |
| FP-06 | P2 | DATA | · | ▶ | **Borne cart footer** — subtotal recomputed from UNROUNDED prices → visible line totals can fail to sum to displayed grand total | `kioskCart.js:225-231` |
| FP-07 | P2 | DATA | · | ▶ | **Dashboard Overview vs Sales Summary** — same FR label = different scope: total_sales LIFETIME vs MONTH; total_orders LIFETIME vs TODAY | `OverviewComponent.vue:12-13 + DashboardService.php:347-371` |
| FP-08 | P2 | DATA | · | ▶ | **Sales Report** — same money two ways on one page: cards `19,00 €` (currencyAmountFormat) vs rows `19.00` (flatAmountFormat, US point, no €) | `SalesReportListComponent.vue:196-198 + SimpleOrderResource.php:50-52` |
| FP-09 | P2 | err | · | ▶ | **Dashboard RealtimeReport** — false `0.00` no-revenue day on any poll failure (no .catch, US format) — same false-zero class as DASH-04 | `RealtimeReportComponent.vue (fetchData no .catch)` |
| FP-10 | P2 | DATA | · | ▶ | **Borne confirmation** — shows loyalty points 'gagnés' but points awarded only OnDelivery → cancelled/refunded order displays points client never keeps | `KioskConfirmationComponent.vue:208-213 vs AwardLoyaltyPointsOnDelivery.php:98-102` |
| FP-11 | P2 | i18n | · | ▶ | **KDS legacy 4-col (rollback path)** — raw key `kds_counter_payment_unpaid` on unpaid badge (bare $t() missing label. namespace) | `KitchenDisplaySystemComponent.vue:694,873` |
| FP-12 | P2 | i18n | · | ▶ | **KDS status-conflict banner** — raw key `label.kds_status_conflict` (wrong ns; correct is message.) shown to chef during a real two-station bump | `KitchenDisplaySystemComponent.vue:1673` |
| FP-13 | P2 | vis | · | ▶ | **/kds V2 KdsOrderCard** — hard-coded height:462px fights responsive 2-row grid → row 2 clips on sub-1000px-tall kitchen screens, chef misses aging tickets | `KdsOrderCard.vue:320-324 vs KdsV2Grid.vue:421-429` |
| FP-14 | P2 | a11y | · | ▶ | **PosCounterCollectModal** — highest-freq cashier modal has role=dialog but MISSING aria-modal=true (only POS dialog without it) | `PosCounterCollectModal.vue:42` |
| FP-15 | P2 | i18n | · | ▶ | **Cash drawer → Mouvements** — raw machine enum (order_payment/drawer_open/cashback) + English '↑ IN'/'↓ OUT' on the pre-close reconcile table | `PosCashDrawerSessionDialog.vue:292-294` |
| FP-16 | P2 | PERF | · | ▶ | **admin SPA first-load** — ships 296K frozen pos-wizard.js UNCONDITIONALLY on every surface, not just POS (loader line non-frozen) | `master.blade.php:272,47` |
| FP-17 | P2 | PERF | · | ▶ | **master.blade cache-bust** — pos-wizard.css/.js busted with per-request `{{ time() }}` → 296K JS never cached across full loads | `master.blade.php:47,272` |
| FP-18 | P2 | PERF | · | ▶ | **/admin/dashboard** — fans out ~18 independent XHR on mount (15 self-fetching widgets), no batched aggregate | `DashboardController.php:38-60` |
| FP-19 | P2 | PERF | · | ▶ | **dashboard customerStates** — 18 hour-bucket queries each `->get()->count()` over default full-MONTH range (materializes a month 18×) | `DashboardService.php:314` |
| FP-20 | P2 | PERF | · | ▶ | **KDS poll** — N+1: `loadMissing('orderItem')` per order because list() omits `orderItems.orderItem` from eager-load (~50 extra q every 5s) | `KitchenDisplaySystemOrderService.php:73 + KDSOrderDetailsResource.php:50` |
| FP-21 | P2 | PERF | · | ▶ | **POS Tracker LIVRÉS column** — renders entire day's delivered uncapped, re-fetch+re-diff whole board every 8s → caisse sluggishness by dinner | `PosOrdersTrackerComponent.vue:550` |
| FP-22 | P2 | sec | · | ▶ | **OSS public endpoint** — unauthenticated `/api/frontend/oss-order` echoes raw `$exception->getMessage()` in 422 (SQL/table-name disclosure to any LAN device) | `OrderStatusScreenController.php:103,139` |
| FP-23 | P2 | sec | · | ▶ | **Kiosk bootstrap** — machine user+password plaintext in page DOM when APP_ENV=local (IP-allowlist bypass + default 'kiosk123') | `config/kiosk.php:139,183 + master.blade.php:121-127` |
| FP-24 | P2 | sync | · | ▶ | **OSS wall + Kiosk** — transient WS-loss banner suppressed on every non-KDS surface → sustained backend/poll failure shows silently stale columns | `OrderStatusScreenComponent.vue:9 + KioskAppComponent.vue:11 + PosComponent.vue:72` |
| FP-25 | P2 | DATA | · | · | **/admin/pos-orders** — status column BLANK for CANCELED/REJECTED/OUT_FOR_DELIVERY/PENDING (enum map incomplete) + filter offers only 3 statuses | `PosOrderListComponent.vue:244-250,34-40` |
| FP-26 | P2 | vis | · | ▶ | **/kiosk/cart line items** — name + selections truncate to one line (nowrap+ellipsis); weaker than the 2-line clamp the grid uses; worst at 360px | `KioskCartComponent.vue:957-966,776-784` |
| FP-27 | P2 | i18n | · | ▶ | **/kiosk/idle language selector** — EN/العربية buttons visible but DEAD (changeLanguage no-op under ADR-007 FR-lock) — a control that lies | `KioskIdleScreenComponent.vue:14-34,263-271` |
| FP-28 | P2 | sync | · | ▶ | **/kiosk/waiting offline cash** — strands borne on forever 'syncing' spinner (no poll/redirect/outcome) | `KioskWaitingComponent.vue:244-249 + KioskAppComponent.vue:340-347` |
| FP-29 | P2 | i18n | · | ▶ | **Kiosk wizard cart-recap** — raw key `kiosk.wizard.generic.step_fallback` for a label-less composer step (fr.json-only fix → frozen comp untouched) | `KioskWizardComponent.vue:1275,1584,1605,2089` |
| FP-30 | P3 | i18n | · | ▶ | **Kiosk wizard manual note** — raw key `label.note` (typo; fr.json has plural `label.notes`) (fr.json-only fix) | `KioskWizardComponent.vue:2099` |
| FP-31 | P3 | a11y | · | ▶ | **PosCounterCollectModal muted labels** — references non-existent token `--pos-v5-muted` → falls back #555/#777, marginal AA fail on 10.5px sub-label | `PosCounterCollectModal.vue:630/645/672/738/750/803` |
| FP-32 | P3 | i18n | · | ▶ | **CASH success toast** — 'Tiroir ouvert (simulation)' on every counter cash collect — wording preference (simulation is accepted V1 state) → owner G-04 | `PosCounterCollectModal.vue:528-531` |
| FP-33 | P3 | PERF | ✶ | ▶ | **admin-pos-v4 blade (FROZEN)** — same per-request `{{ time() }}` cache-bust → POS re-downloads 296K wizard every full load → owner G-02 | `admin-pos-v4.blade.php:35,136` |
| FP-34 | P3 | DATA | · | ▶ | **POS loyalty redeem modal** — 'preview discount' a client estimate from a prop rate; stale prop briefly shows wrong € (server overwrites on apply) | `PosLoyaltyRedeemModal.vue:206-213,311-313` |
| FP-35 | P3 | PERF | · | ▶ | **All push-driven boards** — every Echo event triggers a full list re-fetch → fan-out + full-list re-render flicker during bursts (debounce/delta) | `PosOrdersTrackerComponent.vue:692 + PreparingAndReadyComponent.vue:294 + KioskWaitingComponent.vue:288` |
| FP-36 | P3 | PERF | · | ▶ | **20+ admin list components** — systemic Vue `:key='<object>'` anti-pattern forces full DOM remount on every refresh/poll/filter (→ `:key='row.id'`) | `SalesReportListComponent.vue:193 +19 sites` |
| FP-37 | P3 | a11y | · | ▶ | **POS daily-path modals** — no focus-trap (Tab leaks behind dialog); the shared `trapFocus` helper is sentinel-tested DEAD CODE, imported by zero components | `posA11y.js:7 (no prod import)` |
| FP-38 | P3 | i18n | · | · | **Web storefront cluster (daily_path=false)** — `9.50€` not `9,50 €`, no live order-tracking, qty no cap, long-name overflow, label grammar — OUT of V1 in-store path → owner G-05 | `appService.js:71-77 + frontend/* (account/menu/cart/checkout)` |

---

### 3.2 Live-discovery backlog — matrix NEVER cells with NO static finding (require a browser pass at execution)
**Honesty note:** the 38 findings above are everything anchorable **read-only**. Several matrix NEVER/forced-PARTIAL cells produced **no FP finding** because the defect (or its absence) can only be seen by **driving the live surface** — forcing the empty/loading/error state, axe-ing the rendered screen. Executing all 38 findings does **NOT** turn the matrix green; these cells must be driven in the execution phase (clone :8766), and any defect found becomes a new `FP-`/`FPL-` finding then. They are folded into the relevant wave's visual pass, not skipped.
1. **Z-close / X-report screen** — a11y (never axe'd), empty-day / no-sales close state, close-in-progress loading, dedicated visual pass on screen + PDF. *(drive in W3/W6 visual pass)*
2. **OSS client wall** — empty-board (0 orders) forced state + initial-poll loading state. *(W4)*
3. **Dashboard** — 0-order-day empty KPI ("CA 0,00 / Ticket Moyen —") + per-widget loading skeleton. *(W3/W5)*
4. **/admin/historique** — a11y + filtered-empty + pagination-load + fetch-error UX of the 344-page table (FP-25 covers /admin/pos-orders, a DIFFERENT surface). *(W6 a11y pass + W3 data)*
5. **Sales / items report screens** — a11y + empty-period + report-generation loading (FP-08 covers format only). *(W3/W6)*
6. **Stock rupture** — category-tree loading skeleton + all-in-stock empty state. *(W3)*

## §A — AGENT ARMY MAP (for the execution phase)
Central supervisor (this orchestrator) + parallel lanes. Per `ultra-architect-planify` Axis 4 + `PARALLEL_PROTOCOL.md` (disjoint lanes, §6 shared zones serialized).

| Role | Subagent | Tools | Fires on |
|---|---|---|---|
| **Supervisor** | (main) | all | orchestrate · synthesize · §10 verdict · BRAIN |
| Lane: BORNE | general-purpose | Read/Edit/Bash/Playwright | kiosk non-frozen surfaces |
| Lane: CAISSE | general-purpose | Read/Edit/Bash/Playwright | encaissement + posOrders + cash (non-frozen) |
| Lane: KDS+OSS | general-purpose | Read/Edit/Bash/Playwright | board/cards/OSS wall |
| Lane: CENTRAL | general-purpose | Read/Edit/Bash/Playwright | dashboard/history/stock/reports |
| **Data-correctness verifier** | general-purpose | Read + DB | re-derive rendered number == SoT (every Tier-1) |
| **Perf/optimization** | Explore→general | Read + Bash | bundle/N+1/re-render static profile |
| **a11y/i18n (populated state)** | general-purpose | Read + Playwright+axe | axe on OPEN modals + populated boards |
| **QA Visual** | general-purpose | Read + Playwright | run spec, capture, analyze |
| **RED Visual / adversary** | general-purpose | Read | independently re-analyze QA screenshots, dispute |

**Dispatch discipline:** read-only audit fan-out = single message, N parallel; **one implementer per lane** (never 2 writers on a lane); QA-Visual ∥ RED-Visual OK; RED dispute ALWAYS before "done". E2E mutations → `foodking_e2e` :8766 only. Registry appends (`routes/api.php`, `router/index.js`, `store/index.js`) serialize per wave.

---

## §X — CONVERGENCE WAVES (execution roadmap, daily-path-first, non-frozen before gated)
Each wave = the Axis-3 6-point checkpoint (tasks PASS · frozen-diff 0 · NF525 chain unchanged · visual gate fired+Read · RED dispute clean · BRAIN updated). E2E mutations on `foodking_e2e` :8766 ONLY. **Ordering rationale:** W1 (zero-risk raw-key kills) → W2 (borne-never-stuck) → W3 (the owner's "bien calculé sur chaque page") → W4 (sync-perceived) → W5 (perf/optimization) → W6 (security+a11y) → W7 (off-path polish + gated). 33 of 38 findings are non-frozen and ship across W1–W6 without any gate.

### W1 — One-line i18n + token fixes on the daily error/warning paths (raw-key kill)
- **Scope:** FP-03 park_restore_partial, FP-11 kds_counter_payment_unpaid, FP-12 kds_status_conflict namespace, FP-15 cash-movement type keys, FP-29 step_fallback, FP-30 label.note, FP-31 --pos-v5-muted token, FP-27 dead language selector (hide). All fr.json-additions or single-token component edits, zero frozen-code, zero logic change.
- **Parallelism:** HIGH — 8 independent files, fully parallelizable; fr.json edits serialized into one merge to avoid JSON conflicts.
- **Checkpoint:** grep for each raw token shows the namespaced key resolves; Vitest i18n raw-label sweep green; visual capture of KDS legacy badge + parked-recall warning + cash-movements table shows real FR text.
- **Tests (Verified-Citation):** Vitest i18n raw-label sweep (`tests/js/*i18n*`) + new `tests/e2e/(TO CREATE) felt-w1-rawkeys.spec.js` asserting each token resolves to FR.

### W2 — Dead/inert error-recovery CTAs + kiosk-waiting auto-redirect (borne never stuck)
- **Scope:** FP-01 network-error dead buttons (reload/router fallback), FP-28 minimal offline-waiting auto-redirect countdown (NON-frozen branch only). The FP-28 FULL sync-handoff is deferred to owner gate **G-01**.
- **Parallelism:** MEDIUM — 2 components, parallel; both need a kiosk-flow visual pass.
- **Checkpoint:** Forced offline mid-flow: network-error 'Réessayer' actually reloads/reconnects and 'Appeler le personnel' surfaces an instruction; an offline cash order auto-returns to idle within the PREPARING_AUTO_REDIRECT window (no infinite spinner). Captured + read.
- **Tests (Verified-Citation):** New `tests/e2e/(TO CREATE) felt-w2-kiosk-offline-recovery.spec.js` (force offline, assert retry reloads + auto-redirect).

### W3 — On-screen totals reconcile (data-correctness per rendered page — the owner's "bien calculé")
- **Scope:** FP-02 receipt full-discount, FP-06 kiosk subtotal from rounded lines, FP-07 dashboard same-label-different-scope, FP-08 sales-report format split, FP-09 realtime false-zero error-state, FP-10 loyalty-points conditional wording.
- **Parallelism:** MEDIUM — kiosk (FP-02/06/10) and admin (FP-07/08/09) are disjoint sub-lanes, parallel; within each, sequential to avoid store/service churn.
- **Checkpoint:** Kiosk: a promo+loyalty multi-line cart shows footer total = sum of visible line totals AND printed subtotal−discount=total. Admin: Overview vs SalesSummary labels scope-qualified; sales-report rows and cards both render `19,00 €`; a simulated poll failure shows 'Données indisponibles' not `0.00`. **Data-asserted on the DOM, not eyeballed.**
- **Tests (Verified-Citation):** New `tests/e2e/(TO CREATE) felt-w3-data-reconcile.spec.js` (DOM total==sum of lines; row==card format) + PHPUnit `--filter Dashboard` for scope labels.

### W4 — Sync-perceived: make WS-loss / staleness visible on the local box
- **Scope:** FP-05 POS tracker WS-loss banner (drop !isDevEnv), FP-24 OSS+kiosk backoff staleness cue, FP-04 OSS auto-scroll travels real overflow (or cap+counter).
- **Parallelism:** MEDIUM — FP-05 + FP-24 share ConnectionStatusBanner wiring (do together); FP-04 independent OSS marquee logic (parallel).
- **Checkpoint:** Simulated WS drop on the box: POS tracker shows 'Mode hors-ligne — actualisation lente'; OSS/kiosk show 'connexion lente' in BACKOFF. With 20-40 active orders every queued N° eventually scrolls into view and reaches PRÊT visibly (or +N counter shown). Captured.
- **Tests (Verified-Citation):** New `tests/e2e/(TO CREATE) felt-w4-degradation-cue.spec.js` (kill WS, assert banner) + `felt-w4-oss-scroll.spec.js` (every N° enters viewport).

### W5 — Perf tail: bundle weight, dashboard fan-out, poll N+1, render churn
- **Scope:** FP-16 guard pos-wizard.js to POS routes, FP-17 master.blade static cache-bust, FP-18 dashboard batched summary, FP-19 customerStates SQL count + GROUP BY, FP-20 KDS eager-load orderItems.orderItem, FP-21 LIVRÉS column cap, FP-35 debounce board re-fetch, FP-36 :key=row.id sweep.
- **Parallelism:** HIGH — backend (FP-18/19/20), blade (FP-16/17), frontend-render (FP-21/35/36) are 3 disjoint lanes; the :key sweep is mechanical across 20 files and fans out.
- **Checkpoint:** Non-POS admin first-load no longer requests pos-wizard.js; pos-wizard URL stable across reloads (cacheable); KDS poll issues 1 query not ~50 for 50 orders (query-count assert); dashboard mount drops ~18→≤8 round-trips; LIVRÉS column DOM bounded. **Measured, not asserted.**
- **Tests (Verified-Citation):** PHPUnit query-count assertions (`tests/Feature/(TO CREATE) KdsPollQueryCountTest`, `DashboardFanoutTest`) + Vitest `:key` lint + bundle-request assertion in e2e.

### W6 — Security-client + a11y-on-populated-state hardening
- **Scope:** FP-22 OSS public endpoint generic error (stop getMessage leak), FP-23 kiosk auto-login de-couple from APP_ENV (owner-confirmed config, gate **G-03**), FP-14 PosCounterCollectModal aria-modal, FP-37 wire trapFocus into daily-path modals.
- **Parallelism:** MEDIUM — FP-22 (backend) + FP-14/FP-37 (frontend modals) parallel; FP-23 needs owner confirmation on deployed APP_ENV before the config change lands (gate-coupled).
- **Checkpoint:** curl to /api/frontend/oss-order error path returns a generic constant, real exception only in logs; PosCounterCollectModal has aria-modal=true and Tab stays inside; with KIOSK_AUTO_LOGIN_TRUSTED_IPS unset the credential payload is NOT in the DOM regardless of APP_ENV (owner-confirmed).
- **Tests (Verified-Citation):** `tests/Feature/(TO CREATE) OssPublicErrorLeakTest` (generic message) + Vitest aria-modal + focus-trap spec; FP-23 blocked on G-03.

### W7 — Low-priority admin-CRUD polish (off the daily path)
- **Scope:** FP-25 pos-orders status map + filter, FP-34 loyalty preview rate SSOT, FP-26 kiosk cart line-clamp, FP-32 simulation-toast wording (pending owner **G-04**), FP-33 admin-pos-v4 cache-bust (pending owner **G-02**), FP-38 web-storefront cluster (deferred — out of V1 in-store scope, **G-05**).
- **Parallelism:** LOW — mostly independent small fixes; gate-blocked items (FP-32/FP-33) and out-of-scope (FP-38) wait on owner. Run last so daily-path waves land first.
- **Checkpoint:** Cancelled order shows a real status badge + is filterable; kiosk cart long names wrap to 2 lines at 360px; gated items either countersigned (then applied) or explicitly deferred in BRAIN. FP-38 confirmed out-of-scope for the V1 LOCAL envelope.
- **Tests (Verified-Citation):** Per-fix targeted specs; gated items (FP-32/G-04, FP-33/G-02) and FP-38/G-05 wait on owner.

---

## §G — OWNER GATES (the only blockers Claude cannot clear) — WHO / WHAT / WHERE
Only **5 gates**, covering exactly **5 of 38** findings; the other **33 are non-frozen and execute without any gate**.

| Gate | WHO | WHAT (artifact) | WHERE |
|---|---|---|---|
| **G-01** | Owner countersign (frozen-zone LOCK, §7) | FP-28 FULL offline-waiting sync-handoff — swap synthetic `offline_` id → real order id/queue number on replay success + explicit 'commande non envoyée — voyez la caisse' on MAX_ATTEMPTS abandon. Touches FROZEN KioskWaitingComponent + KioskAppComponent (the W2 minimal auto-redirect is NON-frozen and ships without this). | `KioskWaitingComponent.vue:244-249 + KioskAppComponent.vue:340-347` |
| **G-02** | Owner countersign (frozen-zone LOCK, §7) | FP-33 — replace per-request `{{ time() }}` cache-bust with static `?v=N` in admin-pos-v4.blade.php (§7 frozen). Trivial, zero wizard behavior change; bundle with non-frozen master.blade FP-17 under one LOCK. | `admin-pos-v4.blade.php:35,136` |
| **G-03** | Owner config confirmation + decision | FP-23 — confirm box .env is NOT APP_ENV=local + rotate default 'kiosk123'; approve de-coupling kiosk credential bypass from APP_ENV to explicit `KIOSK_AUTO_LOGIN_TRUSTED_IPS` (or `KIOSK_REQUIRE_MACHINE_LOGIN=true`). Code is non-frozen; the security posture + IP list are owner inputs. | `config/kiosk.php:139,183 + box .env` |
| **G-04** | Owner wording decision | FP-32 — cash-encash success copy: keep 'Tiroir ouvert (simulation)' until real hardware, OR neutral 'Encaissé en espèces — Commande N°{order}' now. Simulation = accepted V1 state, not a defect — felt-product wording call only. | `PosCounterCollectModal.vue:528-531 + fr.json:559` |
| **G-05** | Owner scope decision | FP-38 web-storefront cluster (FR currency, customer live order-tracking, qty cap, overflow, label grammar, 'Veg', geocode i18n, silent-fetch error-state). Confirm whether web ordering is in V1 scope at all; per the LOCAL borne+counter envelope these are `daily_path=false` and recommended DEFERRED. | `appService.js:71-77 + frontend/(account/menu/cart/checkout)` |

> Gate↔finding map: **G-01**=FP-28 full sync-handoff (frozen kiosk) · **G-02**=FP-33 admin-pos-v4 cache-bust (frozen blade) · **G-03**=FP-23 kiosk auto-login decouple + password (config+box .env) · **G-04**=FP-32 cash-toast wording · **G-05**=FP-38 web-storefront scope. All other 33 findings are non-frozen → executable without a gate.
> Also pre-known (already deferred, §1.3, NOT new gates): brand-orange contrast 3.49:1 policy, the V1.0.X a11y items.

---

## §R — REFERENCES
- `CONSTITUTION.md` · `SYSTEM_MAP.md` (5 disjoint lanes) · `SYNC_CONTRACT.md` · `PARALLEL_PROTOCOL.md` · `CLAUDE.md` §5/§7/§8.
- Prior campaign: `reports/test-e2e/goal-100pct-2026-06-07/CONVERGENCE_VERDICT.md`.
- This fan-out matrix + raw findings: `reports/test-e2e/goal-felt-product-2026-06-08/` (written on assembly).
- Per-task pipeline: `~/.claude/skills/ultra-audit-profond/`.

## §F — FINAL RULE
DONE = **the owner looks at every daily-path page and the number is right, the layout holds under abuse, nothing is dead or silent, and it feels fast** — proven with screenshot galleries + per-page data-truth assertions, not test-green alone. Production-perfect on the felt product, or it's not done. Frozen felt-product fixes are surfaced as gates, never smuggled.
