# AUDIT_PLAN — abuse-e2e-2026-06-01

**Mission (owner, verbatim):** « abuse-e2e test-e2e all pages and fonctions one by one
with screenshots and analyse them so deep and with a bad mood … test out everything
even the notifications … ne me retourne que tout les test sont validé et tourne en
boucle jusqu'à tout bon. »

**Mandate:** dual-team adversarial loop. GStack captures (PNG+DOM+console+network
quartet via `helpers/mega-audit-snap.js`); adversarial supervisor attacks visual-first;
loop until **two consecutive rounds with open P0+P1 = 0 and set-equality**.
**No iteration cap.** No push. Frozen zones & NF525 invariants untouchable.

## Terminal states (pre-decided — advisor gate)
1. **CONVERGENCE** — 2 consecutive rounds P0+P1=0, set-equal, all specs PASS → deliver.
2. **GATE-ESCALATION** — a blocking P0/P1 whose root cause sits in a frozen zone
   (`PaymentComponent.vue`, `KioskWizard/App/Upsell*.vue`, `public/js/pos-wizard.js`,
   `FiscalSequenceService`, `ZReportService`, `AuditLogService`, `BranchScope`,
   `PricingService`, `OrderStateMachine`) or needs an NF525/owner judgment →
   STOP, write `ESCALATION.md`, surface to owner. Do **not** loop forever; do **not**
   silently patch a frozen file. (CLAUDE.md §10 human gate.)

## Environment (pre-flight verified 2026-06-01)
- Server: `php artisan serve` @ 127.0.0.1:8000 (single-thread → **capture runs SERIAL**).
- Realtime: soketi (Pusher-compat) @ 127.0.0.1:6001 + `queue:work redis` + redis **UP**
  → Waves D/F exercise the **real** WS push path. Chef must subscribe to
  `private-branch.1` ⇒ login **chef@lecayenne.fr** (branch_id=1), NOT admin (admin polls 60s).
- Creds (pass `123456`): admin@lecayenne.fr · pos@lecayenne.fr · chef@lecayenne.fr ·
  bm.t2admin@lecayenne.fr · kiosk-lecayenne/kiosk123.
- Helpers: `login.js` (loginAsAdmin/PosOperator/ChefOperator/Kiosk, all clear rate-limits),
  `mega-audit-snap.js` (quartet recorder — IMPORT, don't duplicate), `kiosk-order.js`,
  `place-order.js`, `rate-limit.js`. Playwright workers:1. Bundles rebuilt (webpack OK).

## P1-objectivity rule (advisor gate — so the loop can converge)
A **visual** defect is **P1 (blocking)** only with measured evidence:
- element overlap → bounding-box intersection >50% between two clickable nodes (DOM geometry)
- contrast → computed ratio < 4.5:1 (or <3:1 for ≥18px bold)
- i18n leak → visible text matches `^[a-z]+(\.[a-z_]+){1,4}$`
- numeric mismatch → exact differing strings across surfaces
- truncation → `scrollWidth > clientWidth+2` AND cuts a critical word
Pure aesthetic judgment with no measurement → **P2 (non-blocking, disclose only)**.
This kills the "fresh reviewer surfaces a different subjective P1 every round" non-convergence.

## Waves (6, non-overlapping, each spec runnable in isolation)

### Wave A — KIOSK customer journey (borne, light mode, palette Cayenne)
Spec: `tests/e2e/test-e2e-abuse-A-kiosk.spec.js` · Dir: `__screenshots__/test-e2e-A/`
States: 01 idle (lang chips) → 02 menu first category → 03 product card open →
04 wizard step viande/crudités/sauces (composition chips) → 05 add-to-cart →
06 upsell screen → 07 cart bottom-sheet (qty ±, edit, remove, allergens) →
08 checkout/confirm → 09 Plan-B payment routed-to-counter (`kiosk.payment_route_all_to_counter`) →
10 confirmation/tracker number → 11 empty-cart state → 12 a11y panel (contrast AA/AAA, audio).
Assert: cart line total = Σ(line×qty) = grand total; no `label.*` leak; confirmation shows real order #.
Frozen: KioskWizard/App/Upsell are frozen — **observe only**, never edit.

### Wave B — POS caisse full register (Vanilla-JS wizard popup is FROZEN — observe only)
Spec: `tests/e2e/test-e2e-abuse-B-pos.spec.js` · Dir: `__screenshots__/test-e2e-B/`
States: 01 login→pos · 02 first-page featured filter + "Toutes" toggle · 03 add item ·
04 wizard popup (composition) · 05 cart + grand-total · 06 discount (reason required) ·
07 loyalty redeem block · 08 counter-collect payment modal (cash: received/change; non-cash) ·
09 receipt/ticket · 10 orders tracker (tracker-amount = receipt total) · 11 parked orders ·
12 cash session open / drawer · 13 delivery order → fee = Hénin-Beaumont whole-km `5€+1€/km ceil`.
Assert numeric integrity across cart→modal→receipt→tracker. Payment via `POS_SIMULATION_HARDWARE` stub.
Frozen: `PaymentComponent.vue`, `PosV5TrancheRow.vue`, `public/js/pos-wizard.js` — observe only.

### Wave C — KDS kitchen display (chef@lecayenne.fr, subscribes private-branch.1)
Spec: `tests/e2e/test-e2e-abuse-C-kds.spec.js` · Dir: `__screenshots__/test-e2e-C/`
States: 01 chef login→KDS · 02 pile with cards (customization lines visible) · 03 sync-mode banner
(`kds-sync-mode-banner` should show LIVE/subscribed, not 60s poll) · 04 new-order arrival (WS) ·
05 bump PREPARING→PREPARED (`kds-card-cta-ready`) · 06 recall (`kds-recall`, recall badge) ·
07 history drawer (`kds-history-*`) · 08 empty pile state · 09 error banner state · 10 aria-live region.
Assert: card total = order total; bump transitions reflect within a few s; recall re-adds.

### Wave D — OSS customer screen + CROSS-SURFACE numeric integrity & live sync
Spec: `tests/e2e/test-e2e-abuse-D-oss-sync.spec.js` · Dir: `__screenshots__/test-e2e-D/`
2-3 contexts. Flow: POS pay an order → assert SAME total on POS tracker, KDS card, OSS screen.
States: 01 OSS preparing column · 02 OSS ready column · 03 POS-pay→KDS push latency (measure ms,
real WS) · 04 KDS-ready→OSS move · 05 four-surface fact-check (cart=receipt=tracker=KDS=OSS).
Assert: numeric equality across all surfaces (mismatch = P0). No silent `.catch` on cascade.

### Wave E — ADMIN pages page-by-page (visual + technical)
Spec: `tests/e2e/test-e2e-abuse-E-admin.spec.js` · Dir: `__screenshots__/test-e2e-E/`
States: 01 dashboard · 02 items catalog · 03 item edit/availability · 04 stock-rupture-dashboard ·
05 orders list · 06 order detail · 07 EOD / Z-report / clôture view · 08 cash overview ·
09 sales report (net realized) · 10 items report (units sold) · 11 settings · 12 any empty/error states.
Assert: no `label.*` leak, no console error (ex vendor/WS), no unexpected 4xx, report numbers render.

### Wave F — NOTIFICATIONS + CASCADE (the "even the notifications" mandate)
Spec: `tests/e2e/test-e2e-abuse-F-notif-cascade.spec.js` · Dir: `__screenshots__/test-e2e-F/`
2-3 contexts. Scenarios:
- CASCADE-1: admin marks item rupture → POS shows ÉPUISÉ (`pos-availability-banner`) + kiosk hides it.
- NOTIF-1: new POS/kiosk order → KDS new-order toast/sound indicator appears (real WS).
- NOTIF-2: order ready → customer-facing notification / tracker update.
- NOTIF-3: push notification surface (`PushNotification` model) if wired.
States: 01 pre-cascade · 02 admin toggle rupture · 03 POS ÉPUISÉ · 04 kiosk hidden ·
05 KDS new-order notif · 06 ready notif. Assert cascade actually propagates (no fake-pass).

## Pre-flight repair + KNOWN SIGNALS (smoke-validated 2026-06-01 — reviewers MUST account)
- **DB↔.env drift repaired (env only, no product/frozen change):** dev DB had the
  canonical kiosk machine row (machine_id `KIOSK-LC-001`, branch 1) but its `username`
  had been overwritten to `borne-test` by a prior E2E rename → SPA auto-login posting
  `kiosk-lecayenne` got HTTP 400 `credentials_invalid` → red "Identifiants invalides"
  screen. Fixed by restoring `username=kiosk-lecayenne`, `password=Hash(.env pass)`,
  `status=ACTIVE`, active linked user. After fix: `POST /api/auth/kiosk-login → 201`,
  real kiosk attract screen renders. (Latent seeder fragility: `KioskMachineTableSeeder`
  is not idempotent against username drift due to the `(branch_id,machine_id)` unique key
  — note as P3 test-infra, NOT a product runtime defect.)
- **Known kiosk realtime signals on /kiosk/idle** (classify with judgment, do NOT auto-P0):
  - `POST /api/broadcasting/auth → 302` then `GET /api/login → 401`: the kiosk SPA's global
    Echo bootstrap attempts private-channel auth with the web/session guard the public
    kiosk terminal doesn't hold. If the kiosk's CORE ordering flow (idle→menu→wizard→cart→
    checkout→confirmation) works WITHOUT it, this is **at most P2** (realtime-degraded,
    kiosk falls back to kiosk-event/poll), NOT a P0 silent_error. Only escalate if it
    actually breaks an order or a status update the customer depends on. Investigate +
    cite evidence; don't assume.
  - **CSP `report-only` violations** (connect-src to broadcasting/auth, /api/login) and
    `POST /api/frontend/csp-report → 204`: report-only ⇒ logged, "no further action taken"
    ⇒ **allowlisted noise**, never a finding.

## EXPANSION WAVES G–P ("cover all topics" — owner mandate 2026-06-02)
> Added after coverage gap analysis. A–F cover happy paths; G–P cover the un-audited
> high-risk surfaces. Run AFTER A–F converges + fixes applied. Same per-round mechanics
> (serial capture, interleaved review, checkpoint findings). Frozen fiscal services are
> OBSERVE-ONLY — drive Z/refund through the UI, never edit ZReportService/FiscalSequenceService.
> Ordered by production risk (fiscal/payment/data-loss first).

### Wave H — Fiscal Z-Report + X-Report (⭐⭐ NF525 CRITICAL)  role: admin
URLs: `/admin/fiscal/z-report` (list/open/close/pdf), `/admin/fiscal/x-report`, dashboard LastZReportWidget.
States: empty list · open modal · open confirmed (seq_no=1 OPEN) · orders flowing (seq increments) ·
close modal (net/cash/change summary) · closed (PDF enabled, orders read-only) · Z PDF (fiscal header+QR+signature) ·
X intraday pivot · error: close with unsettled · error: refund attempt on closed window (422 locked).
KEY ASSERTION: fiscal_sequence_no gap-free + monotonic across the Z-window; close idempotent; closed-window orders immutable.

### Wave I — Refund + Counter-Entry (⭐⭐ NF525 netting)  role: pos
URL: `/admin/pos-orders/{id}` → refund modal → POST `/admin/pos-orders/{id}/refund-with-counter-entry`.
States: order detail (refund enabled only if PAID) · refund modal (reason required) · in-flight · success (mirror order#, new seq_no, same Z-window) · error Z-closed (422) · error already-refunded (409) · receipt with [REMBOURSEMENT] negative lines · original↔mirror linked.
KEY ASSERTION: mirror order seq_no in SAME Z-window as original; negative items sum to net; receipt shows REMBOURSEMENT (no fake paid).

### Wave P — Idempotency / duplicate-submit (⭐⭐ double-charge risk)  role: pos+kiosk
Routes w/ X-Idempotency-Key: POST `/admin/pos`, `/api/frontend/order`, `/payment-confirm`, `/kds-order/change-status`, cash-drawer open, refund.
States: double-tap pay (1st pending, 2nd queued) · 1st returns (receipt) · 2nd returns SAME order# (no dup) · retry-with-same-key dedup · KDS bump double-tap (status unchanged) · 409 on payload-diff same-key.
KEY ASSERTION: same key ⇒ same response, zero duplicate write (no 2 orders / 2 payments / 2 refunds).

### Wave M — Kiosk machine boot + offline sync (⭐⭐ order-loss risk)  role: kiosk
URLs: `/kiosk/idle` boot auto-login, offline buffer, `/api/frontend/payment/reconcile-pending`, KioskOfflineConflictModalComponent.
States: idle attract · boot spinner · login success · login failure (red "Identifiants invalides", retry) · network error mid-order · offline order buffered (saved-locally) · reconnect auto-sync · conflict modal (partial-sent PENDING) · resolved · unresolved→cashier CTA.
KEY ASSERTION: no proceed past idle without kiosk-login 201; offline orders survive reload; reconcile idempotent; offline total == online total.

### Wave G — Admin + customer auth / password (⭐ baseline access control)  role: admin+guest
URLs: `/auth/login`, `/auth/forgot-password`, `/auth/reset-password`, `/admin/profile/change-password`, `/auth/logout`.
States: login idle · bad-creds error · throttle after N fails (429) · forgot-pw OTP screen+countdown · reset form (validation) · profile change-pw modal · logout (session cleared→login).
KEY ASSERTION: token auth persists across reload (authcheck same user/role); lockout throttle fires; reset persists (login w/ new pw).

### Wave J — Delivery-boy cash session (⭐ livreur float reconciliation)  role: admin/bm
URLs: `/admin/delivery-boy/cash-sessions/*` (open/show/close/reconcile), movements list.
States: livreur list+balance · open modal (opening float) · session detail (running balance, movements) · order-collected auto entry · manual cash-in · cash-out · close modal (count+variance) · closed read-only (variance marker) · reconcile (variance→GL) · error close-with-unsettled.
KEY ASSERTION: balance = opening + cash-in − cash-out ± collects (no skew); movements idempotent; close locks further entries.

### Wave K — Receipt reprint / duplicata (⭐ fiscal counter continuity)  role: pos
URLs: receipt modal, POST `/admin/pos/orders/{id}/print-receipt`, ReceiptDuplicataMarker / ReceiptRemboursementMarker.
States: receipt modal · PDF ORIGINAL · print in-flight · print success (counter shown) · reprint (counter++ but SAME seq_no, [DUPLICATA]) · printer-offline error · multiple prints linked by seq_no.
KEY ASSERTION: fiscal counter increments first-print only / reprint idempotent on seq_no; DUPLICATA marker present (no fake original).

### Wave L — Customer web auth + order tracker (⭐ self-service visibility)  role: guest/customer
URLs: `/auth/login`, signup/OTP, `/` TrackOrderComponent (anon by #+phone), `/my-account/my-orders` (auth) + detail.
States: login + bad-creds · signup + OTP (invalid/expired) · new-pw · anon tracker (status+ETA) · my-orders list (filter, re-order) · order detail (items, receipt, live status badge) · empty ("no orders yet") · network-error banner.
KEY ASSERTION: tracker resolves correct order (#+phone); order detail status syncs live (no stale badge).

### Wave N — Auto-86 stock cascade + empty states (⭐ silent loss)  role: admin
URLs: `/admin/stock/rupture`, POST `/admin/menu/availability/toggle` (incl. stock_quantity=0).
States: rupture dashboard · toggle OOS · cascade: kiosk item hidden / POS grayed / KDS no new cards / OSS upsell removed (assert ≤2s WS) · empty kiosk category (fallback) · empty kiosk menu · empty POS category · empty KDS pile ("ready") · empty OSS · re-enable reverses cascade · toggle-fail 422.
KEY ASSERTION: item hidden on ALL surfaces ≤2s of toggle; empty states never crash; re-enable reverses.

### Wave O — Network error + timeout handling (⭐ graceful degradation)  role: all
Components: ConnectionStatusBanner, KioskErrorNetworkComponent; POS payment timeout; dashboard skeleton; OSS stale banner.
States: kiosk offline banner · kiosk menu 10s timeout→error screen+retry · reconnect recovers · POS payment-device timeout (retry/cash) · dashboard skeleton→stream · admin idle>session→re-auth · OSS no-update 10s→"connection lost" · KDS connection-lost banner · retry button refires.
KEY ASSERTION: UI never blank/unresponsive (skeleton or error always); retry recovers; session timeout forces visible re-auth (no silent 401).

## Out of scope (intentional — reviewers must not penalize)
- Mobile RN + web standalone (separate codebases, audited 2026-05-30).
- Dine-in / floorplan (V1 disabled `pos.dine_in_enabled=false`).
- Real bank TPE / SumUp / CONECS hardware (simulation stub only in dev).
- Multi-branch / SaaS (V1 LOCAL single-box branch_id=1).

## Per-round mechanics
1. Capture: 6 GStack agents, **SERIAL** (single-thread server). Each writes+runs+debugs its
   spec to Playwright-green (max 3 debug attempts; else capture partial + report failure).
2. Review: 6 adversarial agents, **PARALLEL** (pure reads + vision), emit
   `round-N/wave-W-findings.json` + return summary via schema. Visual-first, measured-P1 rule.
3. Aggregate open_P0+P1 in JS. Cluster by root cause. Fix agents **SERIAL** (git-index),
   commit per cluster (stash defense), NEVER frozen zones. Re-round.
