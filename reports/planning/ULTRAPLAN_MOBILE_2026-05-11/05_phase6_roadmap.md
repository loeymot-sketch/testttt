# 05 — Phase 6.B → Launch Roadmap

> AGENT-5 (Plan / Software Architect) — `/ultraplan` cycle 2026-05-11

## Executive summary

**Current state**: V0 standalone mock at HEAD `ebb712dd8`. 16 commits cycle complete. `mobile/` served by `php -S :8081`. 0 P0 / 0 P1 customer-facing. 4 waves Playwright green (101 states). 23 P2 + 14 P3 cosmetic backlog. Frozen-zones intact (0 lines diff).

**Path recommendation** : **Path B — Backend FoodKing existing Laravel + Sanctum** (NOT Supabase). Rationale :
- `routes/api.php:1113-1121` already exposes `/api/frontend/order` with `idempotency` middleware + `kiosk-orders` throttle
- `routes/api.php:1208-1213` already exposes `/api/frontend/loyalty/{balance,history,add-points,redeem}` under `auth:sanctum`
- `PricingService` + `FiscalSequenceService` + `IdempotencyKeyMiddleware` + `BranchScope` are NF525-hardened (iter11-14)
- Supabase would force re-implementing the NF525 regulatory spine — 4-6 extra weeks for no functional gain

**Launch window**: **6-8 weeks** to pilot soft-launch from this HEAD, assuming ~25 agent-hours/week. Critical path goes through 8 backend B-01..B-08 (loyalty regulatory) before Phase 6.D can wire up.

**5 distinct phases (6.B → 6.G)** with parallelization opportunities on platform shell (6.E) and pre-launch ops (6.F). Phase 6.B + 6.C must be sequential. Phase 6.D requires backend backlog closed first.

---

## Dependency graph

```
                                    ┌─ B-01..B-08 backend remediation (3-5d) ─┐
                                    │  (loyalty regulatory pre-req)            │
                                    │                                          v
   START → 6.B (Backend wire) ──→ 6.C (Payment+Fiscal) ──┬──→ 6.D (Loyalty wire)
   (auth + catalog +              (Stripe + composition_  │
    branch_id resolution)         snapshot + fiscal_seq)  │
                                                          v
                                                       6.E (Platform shell PWA or Capacitor)
                                                          │
                                                          v
                                                       6.F (Pre-launch ops)
                                                          │
                                                          v
                                                       6.G (Soft launch + monitoring)
```

**Sequencing constraints (hard)**:
- 6.B is the foundation — nothing else can start without real auth + real menu
- 6.C requires 6.B (orders need authed user + real items)
- 6.D requires both 6.B (auth) AND B-01..B-08 closed (regulatory NF525)
- 6.E can start in parallel with 6.D
- 6.F requires 6.C complete (KDS visibility = paid order → KDS notification path)
- 6.G requires all of 6.B/6.C/6.D/6.F + 1 week internal staff dogfood

---

## Phase 6.B — Backend Foundation (auth + catalog + branch)

### Goals
Replace `mobile/data/menu.js` + `mobile/data/user.js` + `mobile/data/loyalty.js` hardcoded objects with real Laravel API calls. Establish authenticated user context with `mobile:order` Sanctum ability. Resolve `branch_id=1` (Le Cayenne) at first launch.

### Tasks

**6.B.1 — Create new Sanctum ability `mobile:order`** (1d)
- File: `app/Http/Controllers/Auth/SignupController.php` (or new `MobileTokenController`)
- Mirror existing `kiosk:order` ability pattern. Token issued at OTP verify carries `mobile:order` only (privilege separation).
- Acceptance: PHPUnit test issuing token with `mobile:order` ability, calling `tokenCan('mobile:order')` returns true, `tokenCan('kiosk:order')` returns false.

**6.B.2 — Customer-facing menu endpoint** (1.5d)
- File: NEW `app/Http/Controllers/Frontend/MenuController.php` method `customer()` OR amend existing `kiosk()` to accept either ability
- Route: `routes/api.php` add `GET /menu/customer` with `auth:sanctum + abilities:mobile:order + throttle:kiosk-menu + kiosk.locale`
- Resolves `branch_id` from `$request->user()->branch_id ?? config('app.default_branch_id')` (Le Cayenne = 1)
- Returns projection identical to `KioskMenuService::projectItems()` shape
- Acceptance: cURL with `mobile:order` token returns 60 items + 13 categories + meats[9] + sauces[15] + crudites[3] + supplements[7]

**6.B.3 — Phone OTP signup/verify endpoints** (2d)
- File: `app/Http/Controllers/Auth/SignupController.php` (existing per CONNECTION_PLAN §3) — verify `otp()` + `verify()` exist; create if missing
- Provider: Twilio (€0.05/SMS) recommended — owner D2 question
- Config: `config/services.php` add `twilio` block
- Mobile rate-limit: 3 SMS per 15 min per phone (mirror existing `throttle:5,1` pattern)
- Acceptance: POST `/api/auth/signup/otp` with `phone='+33642799884'` returns 200 + sends real SMS; POST `/api/auth/signup/verify` returns Sanctum token with `mobile:order` ability

**6.B.4 — Mobile API client layer** (2d)
- File: NEW `mobile/api/api.js`
- Fetch wrapper with bearer token + base URL (env-injected `window.LC_API_BASE`)
- Methods: `signupOtp(phone)`, `verifyOtp(phone, code)`, `getMenu()`, `getProfile()`, `getLoyaltyBalance()`, `getLoyaltyHistory(page)`
- Bearer extracted from `mobile/api/storage.js::getAuth()` (existing)
- 401 handling: clearAuth() + redirect to ScreenLogin
- Acceptance: 6 API methods return shapes identical to V0 mock fixtures; offline state returns cached data with stale banner

**6.B.5 — Branch context for mobile** (0.5d)
- File: `mobile/data/menu.js::BRANCH` becomes derived from `api.getProfile().branch_id` (default 1 if guest)
- For V0 pilot: hardcode to `branch_id=1` Le Cayenne (single-restaurant launch)
- Acceptance: `window.LC.branch.id === 1`, name === "Le Cayenne", zip === "62210"

**6.B.6 — Replace mock screens with real API calls** (1.5d)
- File: `mobile/screens-onboarding.jsx::ScreenLogin::onNext` — replace mock with `await api.signupOtp(phone)`
- File: `mobile/screens-onboarding.jsx::ScreenOTP::onNext` — replace mock with `await api.verifyOtp(phone, code)`
- File: `mobile/index.html` bootstrap — replace `window.LC.menu = require('data/menu.js')` with `window.LC.menu = await api.getMenu()` + loading state
- Acceptance: end-to-end signup → home with real items, 0 hardcoded menu in DOM, Playwright wave-A still green with API stub

### Risks
- **Twilio SMS cost overrun if abuse**: throttle 3/15min per phone + 10/hour per IP. ~50€/mo expected for 1000 OTP.
- **Mock-API shape drift**: mobile expects exact `viandes/has_sauce/has_crudites` flags. Backend `KioskMenuService` projection MAY not expose them today — verify with curl before declaring 6.B.2 done.
- **`mobile:order` ability missing in OrderRequest::authorize()**: current code checks `tokenCan('kiosk:order')`. Need to allow either ability — may touch frozen-zone-adjacent file; call out in LOCK plan if needed.

### Estimated effort
**8.5 days agent** (~2 weeks calendar at 25h/week)

### Acceptance criteria (phase-level)
- Signup with real phone → SMS received → token issued
- Menu screen renders real 60 items from `/api/frontend/menu/customer`
- 4 waves Playwright still green (with API stubbed at network layer for CI determinism)
- 0 frozen-zone diff
- BRAIN.md §3 + §7 updated

---

## Phase 6.C — Real Payment + Fiscal

### Goals
Replace V0 "PAYER MAINTENANT" Stripe placeholder with real Stripe flow. Construct `composition_snapshot` JSON server-side via `PricingService` SSOT. Allocate NF525 `fiscal_sequence_no` on paid order.

### Tasks

**6.C.1 — Mobile order POST with X-Idempotency-Key** (2d)
- File: NEW `mobile/api/api.js::createOrder({items, payment_method})`
- Generates UUIDv4 idempotency key per submit, stored in cart state until success
- POST `/api/v1/frontend/order` with `X-Idempotency-Key: <uuid>` header
- Payload: `{items: [{item_id, variation_id, qty, extra_ids: [], wizard_selections: {}}], payment_method: 'stripe' | 'cash_at_counter' | 'card_at_counter'}`
- Acceptance: replay of same key returns same `order_id` with `Idempotency-Replayed: true` header; double-tap pay button creates 1 order, not 2

**6.C.2 — Composition snapshot construction** (1.5d, backend-side)
- File: `app/Services/Frontend/FrontendOrderService.php` (existing) — extend or verify `composition_snapshot` is built via `app/Services/Pricing/CompositionSnapshotBuilder.php`
- Server-side build from `wizard_selections + extra_ids + variation_id` references; mobile NEVER sends snapshot directly (NF525 invariant)
- PricingService recalculates `line_total` and `order.total` server-side (Pricing SSOT)
- If client total ≠ server total (price drift), return 409 with new total; mobile re-fetches menu and re-displays
- Acceptance: PHPUnit test creating order with `wizard_selections` from Tacos XXL flow produces composition_snapshot matching kiosk reference fixture byte-for-byte (12,50 + 0,50 sauce + 1,00 Œuf + 3,00 Menu + 1,00 Cheddar fondu = 18,00 €)

**6.C.3 — Stripe integration (web flow for V0 PWA)** (3d)
- File: NEW `mobile/screens-modals.jsx::ScreenStripeCheckout` (replaces V0 placeholder)
- File: `app/Http/Controllers/Frontend/PaymentController.php` (existing) — verify `createIntent()` works for mobile flow
- Flow: mobile creates order with `payment_method='stripe'` + `payment_status='pending'` → backend creates Stripe PaymentIntent → returns `client_secret` → mobile uses Stripe.js Element (Payment Element supports card + Apple Pay + Google Pay natively) → on `paymentIntent.succeeded` webhook, backend updates `orders.payment_status='paid'` + triggers fiscal allocation
- Acceptance: Test card `4242 4242 4242 4242` completes; webhook updates DB `paid`; mobile receives realtime push (or polling 5s fallback) and transitions to Confirm screen

**6.C.4 — Fiscal sequence allocation for mobile orders** (1d, READS only)
- File: READ-only `app/Services/Fiscal/FiscalSequenceService.php` (frozen — DO NOT EDIT)
- Listener: existing `app/Listeners/AwardLoyaltyPointsOnDelivery::handle` already triggers on order paid event — verify it also calls `FiscalSequenceService::allocate()` for mobile orders (channel-agnostic)
- If channel-aware logic blocks mobile orders, requires LOCK plan (frozen-zone touch)
- Acceptance: A paid mobile order has `fiscal_sequence_no` populated within 5s; Z-report includes mobile orders; fiscal hash chain unbroken

**6.C.5 — Stripe webhook hardening** (1d)
- File: `app/Http/Controllers/Webhook/StripeWebhookController.php` (find or create)
- Idempotency: existing `webhook_events` table (iter11) — verify Stripe uses same pattern as SenangPay
- Acceptance: replay of same `evt_xxx` returns 200 idempotent; signed via `Stripe-Signature` header

**6.C.6 — Receipt + Z-report integration** (0.5d)
- File: READ `app/Services/Fiscal/ZReportService.php` (frozen — read-only)
- Verify mobile orders appear in Z-report aggregation by `channel='mobile_app'` field
- Acceptance: Z-report run after 3 mobile + 2 kiosk orders shows correct grand total + per-channel breakdown

### Risks
- **Frozen-zone touch on FiscalSequenceService**: most likely NOT needed if channel-agnostic, but if listener has explicit channel filter, requires LOCK plan
- **Stripe webhook test deferral**: webhook can't be tested without ngrok/public tunnel during dev. Use Stripe CLI `stripe listen --forward-to` pattern.
- **Apple Pay domain verification**: requires Stripe dashboard domain approval — owner action item, 24h lead time

### Estimated effort
**9 days agent** (~2 weeks calendar)

### Acceptance criteria
- End-to-end: Tacos XXL configured → cart 18,00 € → pay with Stripe test card → backend allocates fiscal_sequence_no → mobile shows Confirm with C-XXXX → Z-report includes order
- 0 client-side price authority
- Idempotency replay returns same order
- Apple Pay button visible on iOS Safari (V0 PWA)

---

## Phase 6.D — Loyalty Backend Wireup

**HARD PREREQUISITE**: B-01..B-08 (8 P0/P1) closed first. ~4-5d.

### Pre-Phase backend remediation (B-01..B-08)
Per `reports/review/mobile-loyalty-audit-2026-05-10/99_VERDICT.md §6`:
- **B-01 P0**: loyalty_code keyspace `Str::upper(Str::random(8))` — 2 line fix at `LoyaltyController.php:82,162`
- **B-02 P0**: Idempotency-Key middleware on `/loyalty/redeem` — registry update + route attach
- **B-03 P0**: UNIQUE behavior MySQL vs SQLite cross-driver test
- **B-04 P0**: `orders.loyalty_points_awarded` schema fix UNSIGNED→SIGNED
- **B-05 P0**: NF525 audit chain on `loyalty_transactions` inserts (regulatory blocker)
- **B-06 P1**: `branch_id` column on loyalty_transactions + BranchScope global
- **B-07 P1**: refundPoints query bug (order_id=NULL silent loss)
- **B-08 P1**: Partial refund proportional earn deduction

**Effort**: 4-5 days agent total.

### Goals (post B-prereq)
Wire ScreenLoyalty + LoyaltyQR + WizardRedeem to real backend. Create `LoyaltyReward` model + `/loyalty/rewards`. Add HMAC-signed QR endpoint. Resolve rate alignment.

### Tasks

**6.D.1 — Create LoyaltyReward model + table** (1d)
- File: NEW migration `database/migrations/2026_XX_XX_create_loyalty_rewards_table.php`
- File: NEW `app/Models/LoyaltyReward.php` with BranchScope
- Schema: `id, branch_id, name, points_cost, type, payload jsonb, is_active, created_at`
- Seed: 8 rewards mirroring `mobile/data/loyalty.js::REWARDS` array

**6.D.2 — Endpoint `/api/v1/frontend/loyalty/rewards`** (0.5d)
- File: `app/Http/Controllers/Frontend/LoyaltyController.php` add `rewards()` method
- Route: `routes/api.php:1208` group, add `GET /rewards` with `auth:sanctum`

**6.D.3 — Endpoint `/api/v1/frontend/loyalty/qr/sign`** (1d)
- File: `app/Http/Controllers/Frontend/LoyaltyController.php` add `qrSign()` method
- HMAC SHA-256 of `loyalty_code|expires_at|user_id` keyed by `app.key`
- TTL 5 min, returns `{payload: 'FK:<code>', signature, expires_at}`
- File: `LoyaltyController::scan()` accept optional signature, validate before lookup

**6.D.4 — Mobile API client wireup** (1.5d)
- File: `mobile/api/api.js` add: `getLoyaltyBalance()`, `getLoyaltyHistory(page)`, `getLoyaltyRewards()`, `redeemReward(reward_id, idempotency_key)`, `getLoyaltyQR()`, `getLoyaltyConfig()`
- File: `mobile/screens-main.jsx::ScreenLoyalty` — replace `window.LC.loyalty.*` mock with API
- File: `mobile/components/WizardRedeem.jsx` — replace `LC.loyaltyRewardState::redemptionIdempotencyKey()` with X-Idempotency-Key header
- File: `mobile/hooks/useLoyaltyQR.js` — replace mock with `await api.getLoyaltyQR()` (server-signed)

**6.D.5 — Earn ratio + config fetch** (0.5d)
- File: `mobile/data/loyalty.js::CONFIG` — replace hardcoded `earn_ratio` with `await api.getLoyaltyConfig()`
- Cache 1h in localStorage

**6.D.6 — Welcome bonus + earn methods backend** (1d — partial, can defer V1.1)
- File: `app/Listeners/AwardWelcomeBonusOnSignup.php` (new) listens on User created event
- Idempotency: check `loyalty_transactions::where('user_id', $userId)->where('source_surface', 'mobile_welcome')->exists()`

### Estimated effort
**B prereq: 4-5d agent + 6.D itself: 5.5d agent** = **~10 days total** (~2.5 weeks calendar)

### Acceptance criteria
- Mobile loyalty 100% wired to backend (0 mock data)
- QR generated mobile + scanned at kiosk returns user_id 200
- Redeem with X-Idempotency-Key: replay returns same response, no double-debit
- Earn at kiosk (paid order) credits mobile balance within 30s
- 20/20 mobile-loyalty E2E specs still green

---

## Phase 6.E — Mobile Platform Shell

**Can run in PARALLEL with 6.D** (different code zones).

### Path 1 — PWA (lean, 1 week) — RECOMMENDED for pilot

**6.E.1 — Manifest + Service Worker** (2d)
- File: NEW `mobile/manifest.json` with name, short_name, icons (192, 512), start_url, display=standalone, theme_color
- File: NEW `mobile/sw.js` — basic cache-first for assets/, network-first for /api/
- File: `mobile/index.html` add `<link rel="manifest">` + register SW + apple-mobile-web-app-capable

**6.E.2 — Icons + splash assets** (1d)
- Generate from `mobile/assets/menu/signature/cayenne-hero.png` — orange brand color
- Sizes: 192×192, 512×512 (Android), 180×180 + apple-touch-icon (iOS), 1080×1920 splash

**6.E.3 — Offline state + cache invalidation** (1.5d)
- File: `mobile/sw.js` versioned cache; on `/api/frontend/menu` failure → serve cached + show "Mode hors-ligne" banner
- Cart persists in localStorage (already works V0)

**6.E.4 — Privacy + RGPD + ToS pages** (1d)
- File: NEW `mobile/legal/privacy.html`, `mobile/legal/terms.html`, `mobile/legal/rgpd.html`
- Linked from ScreenProfile + ScreenOTP (consent before signup)

### Path 2 — Capacitor wrapper (full native, 3 weeks)
**Deferred to V1.1**. PWA enough for pilot. Capacitor + App Store + Play Store add 12d agent + 1-2 weeks Apple review wall-clock + $99/yr Apple Dev + $25 Google Play.

### Estimated effort
**Path 1 PWA: 5.5 days agent** (~1.5 weeks)

### Acceptance criteria (Path 1)
- Lighthouse PWA score >90
- Installable on iOS Safari + Android Chrome
- RGPD pages live

---

## Phase 6.F — Pre-launch Operations

### Tasks

**6.F.1 — KDS notification path validation** (1d)
- READ `resources/views/kds/*` + `app/Http/Controllers/Admin/KitchenDisplayController.php`
- Verify mobile orders trigger same Outbox + Pusher + polling 5s fallback as kiosk orders
- Channel field on Order = 'mobile_app' should display distinct badge in KDS

**6.F.2 — Staff training package** (2d)
- 1-page printable SOP: "Comment reconnaître une commande mobile / Scanner QR client / Gérer client qui a payé sur mobile"
- Onsite training session 2h before soft launch (owner action)

**6.F.3 — Refund + cancel flow** (1.5d)
- File: NEW `mobile/screens-main.jsx::ScreenOrderDetail` add "Annuler ma commande" button (only if status=pending or in_progress within 5 min)
- File: NEW `app/Http/Controllers/Frontend/OrderController.php` method `cancel($order)` — Stripe refund + reverses fiscal sequence (or marks cancelled — owner gate)

**6.F.4 — Customer support flow** (0.5d)
- File: `mobile/screens-main.jsx::ScreenProfile` add "Aide & Support" row → tel: link + email contact
- Order detail page: "Un problème avec cette commande ?" deep-link to pre-filled email

**6.F.5 — Observability / analytics** (1.5d)
- Tool: PostHog (self-hosted free, recommended) OR Mixpanel
- Events: signup_started, signup_completed, menu_loaded, item_added_to_cart, checkout_started, payment_completed, order_received_at_kds
- Funnel: signup → first order in <7 days = activation metric

**6.F.6 — Error monitoring** (0.5d)
- Sentry SDK in mobile + backend (verify existing setup)

**6.F.7 — Pilot user selection** (0.5d, owner)
- Staff first (5 people) → 1 week dogfood → 5 friendly customers → 1 week → public soft-launch

### Estimated effort
**7 days agent** (~2 weeks calendar)

### Acceptance criteria
- 5 staff complete 5 test orders each end-to-end
- Cancel flow works
- PostHog/Sentry dashboards live
- KDS distinguishes mobile vs kiosk orders

---

## Phase 6.G — Launch

### Tasks

**6.G.1 — Soft launch announcement** (0.5d, owner)
- Instagram + Le Cayenne in-store QR code on tables
- Limited to Le Cayenne single branch (Hénin-Beaumont 62210)

**6.G.2 — Daily monitoring** (15 min/day for 2 weeks)
- Sentry dashboard for new errors
- PostHog funnel for conversion drops
- Manual review of first 50 orders for edge cases

**6.G.3 — Feedback collection** (ongoing)
- Post-order survey link in confirmation email/SMS

### Estimated effort
**1 day agent + ongoing 15min/day owner monitoring**

### Acceptance criteria
- 50+ mobile orders week 1 across 5+ unique users
- 0 P0 production incidents
- NPS >40

---

## Cross-phase concerns

### Frozen-zone touches (need explicit lock-plan)
1. **`OrderRequest::authorize()`** — likely needs amendment to accept `mobile:order` ability. Read first, then LOCK if logic change required (6.B.1 / 6.B.6).
2. **`FiscalSequenceService::allocate()`** — should be channel-agnostic; verify before touch. If listener has channel filter, requires LOCK plan (6.C.4).
3. **`OrderStateMachine`** — refund flow may require new states (refunded/cancelled). Likely already exists; if not, LOCK plan (6.F.3).

### Test coverage strategy
- **Mobile E2E (already exists)**: 4 waves Playwright + 20 loyalty specs — keep green at every commit
- **API contract tests (NEW)**: PHPUnit feature tests for each new endpoint
- **Integration smoke (NEW)**: nightly script signs up, orders, pays Stripe test card, validates fiscal_sequence_no
- **Visual regression**: Playwright screenshots versioned per existing CLAUDE.md §6 mandate

### Backwards-compat with kiosk
- **Order pool**: shared `orders` table, BranchScope enforced
- **KDS visibility**: distinguishes via `channel` field display
- **Loyalty unification**: same `User` row mobile vs kiosk, shared `loyalty_code`
- **Cross-screen sync**: existing Outbox + Pusher + polling 5s covers this

---

## Risks / Unknowns (top 10)

1. **Backend backlog B-01..B-08 slippage** — owner may deprioritize for POS audit P0 fixes. Mitigation: defer 6.D until B-prereq closed; do 6.B + 6.C + 6.E in parallel.
2. **OrderRequest::authorize() requires logic change** — frozen-zone-adjacent. Mitigation: read file before assuming.
3. **Stripe Apple Pay domain verification 24h delay** — adds calendar week if started late.
4. **iOS PWA limitations (no push pre-iOS 16.4)** — Mitigation: poll order status every 30s, document as known limit.
5. **Twilio SMS deliverability in France** — Mitigation: A/B test Vonage as backup.
6. **NF525 audit on cancelled orders** — fiscal_sequence_no for never-paid orders? Mitigation: read FiscalSequenceService policy + ask owner.
7. **Mobile order surge during staff lunch break** — Mitigation: limit acceptance to operating hours.
8. **Loyalty rate change rollout** — backend admin changing 10pt/€→20pt/€ creates drift. Mitigation: B-12 (rate_at_event column) + force refresh on next launch.
9. **Concurrent redemption double-debit** — Mitigation: server-side idempotency (B-02).
10. **First-day press / Instagram virality** — Mitigation: Soft launch to 5 users first.

---

## Recommended execution order

1. **Week 1-2**: Phase 6.B (Backend Foundation) + START B-01..B-08 backend remediation in parallel
2. **Week 3-4**: Phase 6.C (Payment + Fiscal) + COMPLETE B-01..B-08
3. **Week 4-5**: Phase 6.D (Loyalty wireup) + Phase 6.E Path 1 PWA in parallel
4. **Week 6**: Phase 6.F (Pre-launch ops)
5. **Week 7**: Staff dogfood + 5 friendly users
6. **Week 8**: Phase 6.G (Public soft launch)

Total: **6-8 weeks calendar** at ~25h/week agent time.

---

## Milestones

| Milestone | Phase | Week | Key deliverable |
|-----------|-------|------|-----------------|
| M1 — Auth + real menu live | 6.B | 2 | Mobile signs up with real SMS, sees real items |
| M2 — First paid order end-to-end | 6.C | 4 | Stripe test card → order → fiscal_sequence_no |
| M3 — Backend regulatory closed | B-01..B-08 | 4 | All P0/P1 backend backlog closed, NF525 audit chain covers loyalty_transactions |
| M4 — Loyalty wired | 6.D | 5 | Real points earned/redeemed/QR scanned at kiosk |
| M5 — Installable PWA | 6.E | 5 | Add-to-home-screen working on iOS + Android |
| M6 — Ops ready | 6.F | 6 | KDS distinguishes mobile, refund works, staff trained |
| M7 — Internal dogfood | — | 7 | 5 staff × 5 orders each, 0 P0 bugs |
| M8 — Soft launch | 6.G | 8 | Public launch, in-store QR code live, 50+ first-week orders |
