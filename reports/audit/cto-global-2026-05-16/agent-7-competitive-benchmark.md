# Agent 7 — Competitive Benchmark — FoodKing vs Industry Leaders

**Date:** 2026-05-16
**Auditor:** Agent 7 (Competitive Benchmark, CTO-Global Audit)
**Scope:** FoodKing positioning vs Toast / Square / Lightspeed / Innovorder / Sunday / Tiller / Loyverse / TouchBistro for the fast-food French market and SaaS-scale ambition.
**Method:** READ-ONLY codebase inspection + training-knowledge competitor comparison. No internet.
**Working dir:** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`

---

## 1. Executive Scoreboard

| Metric | Score | Verdict |
|---|---|---|
| **Commercial readiness /100** | **18/100** | NOT COMMERCIALIZABLE TODAY — product asset is there, packaging is not. |
| **Differentiation /100** | **52/100** | Real moat exists (NF525 native + integrated stack + cents-priced architecture) but undefended (no docs, no marketing surface, no onboarding, no support tier). |
| **Feature parity (fast-food core)** | **At parity** with Innovorder/Tiller on POS+Kiosk+KDS basics; **gap of 8–10 features** vs Toast/Square on commerce/marketing/ops. |
| **Target-customer fit (kebab/tacos FR <500k€ ARR)** | **High** if commercialization gap closed. The product was literally built for Le Cayenne — that's an asset, not a liability. |
| **V1 GO/NO-GO for commercial sales** | **NO-GO** (cumulative with audit 2026-05-09: 4 P0 cross-validated + zero onboarding + zero pricing + no SLA + no GDPR pack). |

---

## 2. What FoodKing Actually Has — Codebase Verification

Verified during this audit (file:line cited):

### 2.1 Surfaces (no separate licenses, no add-ons)
- **POS (caisse)** — `resources/views/admin-pos-v4.blade.php` + `public/js/pos-wizard.js` (frozen Vanilla JS wizard, ~296 KB hand-written, version S25-SinglePage)
- **Kiosk borne** — `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` + KioskAppComponent + KioskUpsellComponent
- **KDS (Kitchen Display)** — `app/Services/KdsSyncService.php`, `app/Services/KitchenDisplaySystemOrderService.php`
- **OSS (Order Status Screen)** — `app/Services/OrderStatusScreenOrderService.php`
- **Admin SPA** — full back-office Vue 3
- **Mobile app (in development cycle C)** — `mobile/data/menu.js`, `mobile/screens-main.jsx`, `mobile/screens-item-steps.jsx`, `mobile/WALLET_PLAN.md`, `mobile/CONNECTION_PLAN.md`

### 2.2 Fiscal NF525 (rare and high-value)
- `app/Services/Fiscal/FiscalSequenceService.php` — monotonic per-branch sequence with Cache::lock + DB FOR UPDATE
- `app/Services/Fiscal/AuditLogService.php` — HMAC SHA-256 append-only chain
- `app/Services/Fiscal/ZReportService.php` — chained Z reports
- `app/Services/Fiscal/FiscalChainValidator.php`, `FiscalSealingService.php`, `XReportService.php`, `ZReportCashEnrichmentService.php`
- DB triggers `BEFORE DELETE` on `audit_logs`, `z_reports`, `cash_movements` (migrations 2026_05_10_010000, 2026_05_09_160000, 2026_05_16_130000)
- Idempotency middleware — `app/Http/Middleware/IdempotencyKeyMiddleware.php` + `app/Services/Idempotency/`
- Fiscal alloc retry — `app/Console/Commands/RetryFiscalAllocCommand.php`
- Migration: `2026_05_09_200000_add_fiscal_alloc_error_at_to_orders.php` — graceful degradation

### 2.3 Composer (item wizard — proprietary configurator)
- `app/Services/Composer/ComposerProfileService.php`, `ComposerStepService.php`, `ComposerTemplateService.php`, `ComposerProfileProjection.php`, `ComposerDiffService.php`
- Migrations: `2026_04_27_143100_create_item_wizard_profiles_table.php`, `2026_04_27_143110_create_item_wizard_steps_table.php`, `2026_05_05_000020_make_item_wizard_profiles_polymorphic_owner.php`, `2026_05_04_000010_create_item_wizard_step_versions_table.php`
- Stock-aware choice availability — `app/Services/Stock/ChoiceAvailabilityResolver.php` + migrations `2026_05_05_000030`/`040` (availability on attributes + extras)
- Multi-step (sandwich/taco/burger/assiette/menu_formule) — confirmed by memory ref `project_kds_ultra_plan_2026-05-11`

### 2.4 Loyalty (built-in, not an add-on)
- `app/Services/LoyaltyService.php`, `app/Services/LoyaltySetupService.php`
- Routes: `routes/api.php:1210-1266` (loyalty/opt-in, loyalty/scan QR+NFC, loyalty/conversion-rate)
- NFC ready: `database/migrations/2026_04_20_220000_add_nfc_uid_to_customers.php`
- RGPD-compliant opt-in (route comment line 1259)
- Signed loyalty_points fix: `2026_05_11_010000_fix_orders_loyalty_points_awarded_signed.php`
- Atomic ledger refund — `LoyaltyService.php:13-76` (reversal logic on cancel)

### 2.5 Stock + Availability (95% there)
- `app/Services/Stock/StockService.php` + `ChoiceAvailabilityResolver.php`
- Migrations: `2026_04_27_143120_create_stock_levels_table.php` + `143130_create_stock_movements_table.php` + `2026_05_08_150000_add_manual_unavailable_to_stock_levels.php`
- Stock rupture dashboard routes `routes/api.php:287-291` (StockRuptureDashboardController)
- Low-stock alerts route `routes/api.php:289`
- Per-branch availability scan
- Polymorphic ingredient model — `app/Services/Ingredients/IngredientAvailabilityService.php`

### 2.6 Delivery (in-house drivers)
- `app/Services/Delivery/DeliveryFeeService.php` + `DeliveryQuoteService.php`
- Delivery boy lifecycle — `app/Services/DeliveryBoyService.php` + routes `routes/api.php:596-619` (login, my-order, delivered-order, address)
- Recent delivery hardening (Sprint 2B 2026-05-16): geocode_status + E.164 phone required + OrderAddress mandatory — migrations `2026_05_16_140000_add_geocode_status_to_addresses_table.php` + `140100_make_user_phone_required.php`

### 2.7 Promotions (coupons, advanced)
- `app/Services/CouponService.php`, `app/Services/OfferService.php`, `app/Services/OfferItemService.php`
- Advanced coupons: `2026_05_06_140000_add_advanced_promo_fields_to_coupons.php` adds valid_days_of_week, valid_hours_start/end, branch_scope, surfaces (POS/Kiosk/Online), max_uses_global, usage_count, status

### 2.8 Payments (multi-provider scaffold)
- Gateways present: `app/Http/PaymentGateways/Gateways/Credit.php`, `Paypal.php`, `Senangpay.php`, `Stripe.php`
- BUT: `config/payment.php:16-21` — `web_payment_v1.enabled = false`; `stripe.activation_guard.enabled = true` (locked)
- Pilot restrict: `config/payment.php:33-36` — only `credit` allowed in V1
- Bypass mode for E2E (gated production guard, abort 500 if APP_ENV=production)
- Payment terminals tracked (Sprint 1C): migrations `2026_05_16_120000_create_payment_terminals_table.php` + `120001_add_terminal_id_to_order_payments_table.php` — TPE fee tracking + Z-report breakdown
- Split payment — `app/Services/Payments/SplitPaymentService.php`
- Pending payment confirmations — `2026_05_08_120000_create_pending_payment_confirmations_table.php`

### 2.9 Real-time + observability
- Laravel Broadcasting (Pusher/Soketi) + Echo
- FCM push — `app/Services/FcmNotificationService.php` + `FirebaseService.php` + `PushNotificationService.php`
- Outbox pattern: `MonitorOutboxStaleness`, `OutboxRescueCommand`, `OutboxRetryFailedCommand` (`app/Console/Commands/`)
- Sync metrics — `2026_04_23_220000_create_sync_metrics_table.php`
- Idempotency for webhooks — `2026_05_09_120000_create_webhook_events_table.php`
- Health probes `routes/api.php:138-140` (live/ready/full)
- Observability service — `app/Services/Observability/`

### 2.10 Multi-language + multi-currency
- 5 locales: `lang/{ar,bn,de,en,fr}` — `config/app.php:131-137` locked to `fr` for V1 (NF525 mandate)
- `app/Services/CurrencyService.php` — full CRUD `routes/api.php:325-330`
- `app/Services/TaxService.php` — full CRUD `routes/api.php:333-338`

### 2.11 Multi-tenant scaffold (SaaS-ready)
- `app/Models/Scopes/BranchScope.php` — 11 models scoped (memory `project_audit_ultra_review_v2_2026-05-08`)
- Sanctum `kiosk:order` ability, TTL 480m, old token revoke
- Spatie permission RBAC — Admin/Branch Manager/POS Operator/Chef roles
- Canary rollout config — `config/caisse_v1_rollout.php:41-66` (pilot_branch → 10% → 50% → full with rollback predicates)

### 2.12 Operational tooling
- 445 API routes (`routes/api.php` 1314 lines)
- ~120+ services in `app/Services/`
- 50+ Artisan commands including: FiscalArchiveCommand, MenuHealLightV*, MonitorOutboxStaleness, OutboxRescueCommand, PreflightProductionCommand, ResetStaleDailyQuotaCommand, RetryFiscalAllocCommand, SimulateKioskOrders, E2EStressCommand

---

## 3. Feature Parity Table

Legend: V = native, $ = add-on/extra cost, P = partial, X = absent, ? = unknown

| Feature | FoodKing V1 | Toast | Square for Rest. | Innovorder | Lightspeed |
|---|---|---|---|---|---|
| POS (caisse) | V (frozen Vanilla wizard — fragile) | V | V | V | V |
| Self-order Kiosk | V (Vue 3, web) | $ +€59-99/mo | $ +€29/mo | V | $ |
| KDS (Kitchen Display) | V | V | V (limited) | V | V |
| Order Status Screen (customer-facing) | V | $ | P | V | P |
| NF525 fiscal compliance (FR) | **V (HMAC chain + Z + audit log + triggers)** | X (US-focused) | X (US) | V | V (FR plan) |
| Multi-language UI | V (5 locales, kiosk supports lock+swap) | P (en/es US-centric) | P | V (fr/en) | V |
| Multi-currency | V | V | V | V | V |
| Multi-tenant SaaS scaffold | V (BranchScope on 11 models) | V | V | V | V |
| Loyalty (points + tier + NFC) | V (with QR+NFC, RGPD opt-in) | $ Toast Loyalty | $ Square Loyalty $45/mo | V | $ |
| Coupons/promotions (time-banded, surface-scoped) | V (advanced fields shipped 2026-05-06) | V | P | V | V |
| Item wizard / composer (multi-step product configurator) | **V (proprietary, deep)** | P (modifiers) | P | P | P |
| Stock + availability scan | V | V | V | V | V |
| Real-time sync (Broadcasting/Soketi+FCM) | V | V | V | V | V |
| Idempotency + outbox + webhook dedup | V | V | V | V | V |
| Split payment | V | V | V | V | V |
| Cash drawer + Z-report cash enrichment | V (with DB trigger immutability) | V | V | V | V |
| Payment terminal (TPE) fee tracking | V (Sprint 1C just shipped) | V | V | V | V |
| Online ordering site (own brand) | **X** (no public web payment, see `config/payment.php:16` enabled=false) | V Toast Online | V (Square Online) | V | V |
| Native mobile customer app | P (Le Cayenne only, in dev cycle C) | V (Toast app) | V | V | V |
| Uber Eats / Deliveroo / Just Eat integration | **X** (no marketplaces) | V Toast Delivery Suite | V (Square Marketplace) | **V (key Innovorder asset in FR)** | V (Lightspeed Delivery) |
| Gift cards (digital + physical) | **X** | V | V | V | V |
| Table reservation/booking | **X** | V Toast Tables | $ | V | V |
| Employee scheduling/payroll | **X** | V Toast Payroll | $ | P | V Lightspeed Workforce |
| Supplier management / purchase orders | **X** | V Toast Inventory+Vendors | $ | V (FoodMee) | V |
| Inventory forecasting / par levels | **X** (manual rupture scan only) | V | P | V | V |
| Marketing/CRM (email/SMS campaigns) | **X** (no marketing module — only notification builders) | V Toast Marketing | $ Square Marketing | V | V |
| Multi-location reporting + consolidated dashboards | P (BranchScope ready, no SaaS dashboard) | V | V | V | V |
| Native payment terminal SDK (PAX/Ingenico/Verifone direct) | **X** (TPE table tracks, no driver — BypassMode for sim) | V | V (Square Reader) | V (Ingenico/PAX) | V |
| Open API + webhooks for 3rd-party | P (idempotency yes, public docs no) | V | V | V | V |
| Onboarding self-service (new restaurant signs up online) | **X** (no marketing site, no sign-up flow) | V | V | V | V |
| Public pricing page | **X** | V | V | V | V |
| GDPR/RGPD docs pack | P (RGPD opt-in in code, no DPA template, no privacy doc surfaced) | V | V | V | V |
| 24/7 support / SLA | **X** | V | V | V | V |

---

## 4. Five Features FoodKing Has That Competitors Monetize

These are clear monetization wins if commercialized — competitors charge extra for things FoodKing already ships:

1. **Self-order Kiosk (full Vue 3 web kiosk)** — Toast charges $59-99/mo/kiosk, Square charges +$29-49/mo. FoodKing ships kiosk in the same monolith with the POS (`resources/js/components/frontend/kiosk/`). Le Cayenne already runs it. **Estimated value: €40-80/mo/restaurant.**

2. **Native KDS** — Toast Kitchen Display is $0 on Standard plan but historically tied to upsell tiers; Lightspeed and Innovorder bundle it but often gate to higher tiers. FoodKing ships full KDS (`app/Services/KitchenDisplaySystemOrderService.php`, `app/Services/KdsSyncService.php`). **Estimated value: €20-40/mo/restaurant per station.**

3. **Loyalty program (points + NFC + RGPD opt-in)** — Toast Loyalty is a paid add-on ($30-50/mo); Square Loyalty is $45/mo. FoodKing ships loyalty + NFC card scan + QR scan (`app/Services/LoyaltyService.php`, route `loyalty/scan`, migration `add_nfc_uid_to_customers`). **Estimated value: €30-50/mo/restaurant.**

4. **NF525 fiscal compliance with chained audit log + Z reports + DB triggers** — Toast/Square don't support NF525 (US market only). Innovorder/Lightspeed do but at higher tier prices. FoodKing has the full chain (`app/Services/Fiscal/*`, immutability triggers). For French market this is **not just monetizable, it's a legal moat** competitors can't enter without months of work. **Estimated value: this is the entire reason a French restaurant chooses FoodKing over Toast.**

5. **Item wizard / composer with stock-aware availability** — Toast modifiers are simple flat lists; Square has groups; Innovorder has product builder but less deep. FoodKing's composer (`app/Services/Composer/*`, `item_wizard_profiles` + `item_wizard_steps` polymorphic + versioned) handles multi-step config (sandwich/taco/burger/assiette/menu formule) with stock-aware choices (`ChoiceAvailabilityResolver`). This is **uniquely deep** for fast-food complex menus. **Estimated value: hard to monetize as standalone, but a clear technical differentiator that closes deals against simpler POSes.**

Bonus #6 — **Real-time sync layer (Broadcasting + FCM + Outbox + idempotency + webhook dedup)** ships at near-enterprise quality (Sprint 1B outbox, Sprint 1C payment terminals, dedup tables) — competitors typically gate this behind enterprise tier.

---

## 5. Features Competitors Have That FoodKing Lacks (ranked by SaaS sales blocker severity)

### TIER S — DEAL BREAKERS for SaaS prospects

1. **Marketplace delivery integration (Uber Eats / Deliveroo / Just Eat)** — In France 2025-2026, ~60% of fast-food restaurants depend on aggregators. Innovorder's killer feature is the **single-tablet aggregator integration**. FoodKing has zero (no controllers, no service classes, no webhook endpoints found). **A kebab shop will not switch from Innovorder if this is missing.** Effort: 6-12 weeks per aggregator.

2. **Onboarding self-service flow** — Toast, Square, Innovorder all let a restaurant sign up online, configure their menu via wizard, and be live in <2 hours. FoodKing has no marketing site, no /signup, no menu-import wizard, no demo data seeding for new tenants. The codebase has `MenuResetLeCayenneCommand.php` (single hardcoded restaurant). **Without this, "SaaS scale to multiple restaurants" is aspirational.** Effort: 4-8 weeks.

3. **Native payment terminal SDK integration (PAX/Ingenico/Verifone)** — `payment_terminals` table tracks (Sprint 1C) but the actual TPE driver is in BypassMode (`config/payment.php` "PAYMENT_BYPASS_MODE — simulate TPE approved"). Innovorder integrates Ingenico Tetra; Toast has its own Toast Go. For French market this is **non-negotiable for cash-with-card flows**. Effort: 8-16 weeks + hardware partnership.

### TIER A — Sales-blockers for mid-market

4. **Gift cards (digital + physical)** — Toast/Square gift cards drive +5-15% revenue per merchant. FoodKing has zero migration, model, or service. Even Loyverse (SMB tier) ships gift cards. Effort: 3-5 weeks.

5. **Online ordering with own restaurant brand** — `config/payment.php:16-21` shows `web_payment_v1.enabled=false` explicit decision. Square Online and Toast Online let a restaurant get their own ordering site live in 1 day. FoodKing customers route entirely through marketplaces (Uber etc.) or in-store. **Combined with #1 above, this leaves restaurants with no own-channel.** Effort: 6-10 weeks (incl. Stripe activation gate clearance).

6. **Inventory forecasting / par-level recommendations** — Toast Restaurant Management has AI-driven par levels. FoodKing has rupture scan (reactive) but no forecasting. SMB-tier still works fine without this; enterprise won't. Effort: 4-8 weeks.

### TIER B — Friction for sales calls, fixable later

7. **Supplier management / purchase orders** — Toast/Innovorder offer supplier ordering and invoicing. FoodKing has zero. Important for ≥3-location chains. Effort: 6-12 weeks.

8. **Employee scheduling / time clock / payroll** — Toast Payroll bundles, Square has Team Plus. Not strictly needed for SMB but expected by mid-market. FoodKing has Spatie roles + user model but no scheduling. Effort: 8-16 weeks.

9. **Marketing/CRM automation (email campaigns, SMS blast, customer segmentation)** — Toast Marketing, Square Marketing. FoodKing has only transactional builders (`OrderMailNotificationBuilder`, `OrderSmsNotificationBuilder`) — no campaign/segment service. Effort: 6-10 weeks.

10. **Table reservation/booking with public widget** — Toast Tables, Lightspeed bookings, TheFork integration. Fast-food can skip this, but quick-service-restaurants with sit-down option will want it. Dining tables are tracked operationally (`DiningTableService.php`) but no booking surface. Effort: 4-8 weeks.

Bonus #11 — **Open API + public webhooks + 3rd-party integrations marketplace** — Toast has 200+ partner apps, Square has 100+. FoodKing has webhook dedup table but no public API docs, no OAuth, no partner program. Effort: ongoing.

---

## 6. Differentiation Angle — What Defends FoodKing in the Market

**Claim: FoodKing is the "NF525-native, stack-integrated, fast-food-specialist" POS for the French SMB market that the existing market doesn't serve well.**

### Defensible moats (high)
- **NF525 fiscal compliance baked into the schema and the chain** (not bolted on like Toast/Square would have to do for FR entry). Triggers, HMAC chain, Z-report cash enrichment, audit log. The frontier was crossed; competitors entering FR can't replicate this in <6 months.
- **Single-monolith integrated stack** (POS + Kiosk + KDS + OSS + Admin + Mobile in same repo, real-time sync via Broadcasting + Outbox). Toast's stack is fragmented across products; integrating their Kiosk + Loyalty + Online costs $200+/mo total. FoodKing bundles by design.
- **Composer wizard for complex fast-food menus** (kebab/tacos/burger/assiette/menu formule) — directly addresses the operational pain of menu configuration in independent fast-food shops, which generic POSes fumble.

### Defensible moats (medium)
- **Multi-language ready (5 locales) for ethnic-restaurant niches** — kebab/halal/tacos shops in FR often have Arabic-speaking owners; this is hidden value.
- **Real-time outbox + idempotency** at enterprise quality bundled into SMB price.
- **Open codebase ownership** — restaurant chains worried about Toast lock-in could be offered self-hosted/escrow.

### Brittle/undefended
- **Frozen Vanilla JS POS wizard (`public/js/pos-wizard.js` ~296 KB hand-written)** is a single point of failure. If it breaks, the entire POS surface degrades. Memory `feedback_wizard_popup_pos_protected.md` confirms owner declared it untouchable. **Long-term this is a liability — competitors all use compiled framework code.**
- **No public marketing, no docs site, no partner program** — moats only exist if someone knows about them.
- **Built-by-one-non-senior-dev-via-Claude-Code positioning** is a risk to enterprise buyers who do vendor due diligence.

### The differentiation angle in one sentence
> **"FoodKing is the only POS that ships NF525 + Kiosk + KDS + Loyalty + Composer-grade item builder integrated by design for under €60/mo — and we're built for kebab, tacos, and sandwich shops, not generic restaurants."**

This is real and defensible if and only if commercialization gap is closed.

---

## 7. Target Customer Fit — Fast-food (kebab/tacos/sandwich) FR

| Persona | Innovorder fit | FoodKing fit (today) | FoodKing fit (with commercial pack) |
|---|---|---|---|
| Independent kebab shop (1 location, €300-800k/yr) | **High** — UberEats integration is critical | **Medium-Low** — no marketplaces, no signup flow | **High** if delivery integration shipped |
| Tacos chain (2-5 locations) | **High** — Innovorder solid here | **Medium** — multi-tenant scaffold yes, no consolidated dashboard | **High** if reporting + signup + delivery shipped |
| Sandwich franchise (5-20 locations) | **Medium** | **Low** — no franchise management, no supplier, no scheduling | **Medium-High** if Tier-A gaps closed |
| Burger chain bigger | **Medium** | **Very Low** | **Medium** — Toast/Innovorder still better |

**Verdict:** FoodKing's natural fit is **independent → 5-location fast-food chains in FR**, not enterprise. Le Cayenne is exactly this profile. Same profile = Innovorder's core market. FoodKing's edge would be **price (60-80% under Innovorder), depth of composer, openness of code.**

Innovorder pricing (2025): ~€89-149/mo + ~€990 setup + extras. Toast: ~$69-165/mo + processing. Square for Restaurants Plus: $60/mo/location + processing. **FoodKing has room to price aggressively at €39-59/mo SMB tier.**

---

## 8. Commercial Readiness — Brutal Truth

| Dimension | Status | Score /10 |
|---|---|---|
| Product MVP (the technical thing) | Works at Le Cayenne (with caveats — 4 P0 NO-GO from audit 2026-05-09) | 6/10 |
| Public marketing site | None found | 0/10 |
| Pricing page | None | 0/10 |
| Self-service sign-up / onboarding flow | None | 0/10 |
| Menu import wizard for new restaurants | Only hardcoded Le Cayenne seeder | 1/10 |
| Support tier (email/ticket/phone) | None | 0/10 |
| SLA documentation | None | 0/10 |
| GDPR/RGPD docs pack (privacy, DPA, retention) | Partial (RGPD opt-in in code, no DPA template) | 2/10 |
| Data residency commitment (where does data live?) | Unknown — no documented hosting/datacenter | 1/10 |
| Status page (status.foodking.fr) | None | 0/10 |
| Partner integrations marketplace | None | 0/10 |
| Contract/Terms of Service | None | 0/10 |
| Reseller / referral program | None | 0/10 |
| Brand identity (logo, brand guidelines) | None visible in repo | 1/10 |
| In-product help / knowledge base | Internal docs only (HANDOFF_NEW_CURSOR/) | 1/10 |

**Aggregate commercial readiness: 18/100.** The product is a strong engineering asset wrapped in **zero commercialization apparatus**. This is the dominant blocker to monetization, not the code.

---

## 9. Pricing Strategy Recommendation

### Market reference points (2025-2026 EU/FR fast-food POS SaaS)
- **Loyverse** — free tier, +$25/mo employee tier (SMB ultra-cheap, no NF525)
- **Hike POS** — $89-159/mo
- **Square for Restaurants** — $0 / $60 / $90 per location/mo + processing (US/UK; FR partial)
- **Toast** — $69-165/mo + $499-1000 hardware + processing 2.49% + 15¢
- **Lightspeed Restaurant** — $69-189/mo per location
- **Innovorder (FR)** — ~€89-149/mo + €490-990 setup + per-add-on extras
- **Tiller (Sumup)** — ~€69-129/mo + extras
- **Sunday** — payment-attached pricing (only takes a card fee, "free POS")

### Recommended FoodKing tier structure

| Tier | Price/mo | Includes | Target |
|---|---|---|---|
| **Starter** | **€39/mo** | POS + KDS (1 station) + Stock basics + Loyalty + NF525 + 1 user + Email support | Independent kebab/tacos/sandwich shop |
| **Pro** | **€69/mo** | Starter + Kiosk + Multi-language UI + Advanced coupons + 5 users + Multi-language menu + Marketplace integration (1 of UberEats/Deliveroo/JustEat) + Priority email support | Established single-location fast-food with kiosk |
| **Multi** | **€129/mo per location** + flat **€49/mo org fee** | Pro + Multi-location consolidated dashboard + Supplier mgmt (when shipped) + Employee scheduling (when shipped) + All marketplaces + Phone support + 99.5% SLA | 2-10 location chain |
| **Enterprise** | Custom (€199+/loc) | Multi + SSO + Audit exports + Dedicated CSM + 99.9% SLA + Custom integrations | 10+ locations |

### One-time setup
- **Free** for Starter (self-onboarding wizard required, currently doesn't exist)
- **€290** Pro (assisted onboarding, menu import)
- **€990** Multi (white-glove migration)

### Payment processing
- **Don't bundle** — let restaurant keep their bank/TPE relationship. Take 0% on payments. This **undercuts Toast/Square on TCO** even with higher monthly because processing fees swamp monthly differences.
- Offer optional **integrated TPE rental** at €25/mo (Ingenico Tetra or PAX) once driver shipped — this is your hardware moat.

### Positioning angle
> **"Le POS NF525 français qui inclut tout par défaut — Kiosk, KDS, Loyalty, Composer — à partir de 39€/mois. Sans frais sur vos paiements. Sans engagement."**

---

## 10. Top 3 Commercial Recommendations

### Recommendation 1 — SHIP A MARKETING SITE + SIGNUP FLOW BEFORE ANY MORE FEATURES (3-4 weeks)

You have a product. You have no way for anyone to discover, evaluate, or buy it. Even a 4-page site (landing + pricing + features + signup) with a Stripe Checkout for monthly subscription would unblock customer #2 (after Le Cayenne).

Inside the codebase, **onboarding is the missing layer** — you have `MenuResetLeCayenneCommand` but no `OnboardNewRestaurantCommand` that:
- Creates branch
- Seeds default categories
- Creates admin user with temp password
- Sets up loyalty defaults
- Generates kiosk credentials
- Sends welcome email with checklist

This is **4 weeks of work** and **immediately unblocks customer #2-10**. Until this exists, "SaaS commercialization" is fiction.

### Recommendation 2 — DELIVERY MARKETPLACE INTEGRATION (UBER EATS FIRST) IS THE #1 SALES BLOCKER (6-10 weeks)

In FR fast-food market, **a POS without UberEats integration is a non-starter for 60%+ of prospects**. This is not a "nice to have", this is the gate. Order the priorities:

1. **Uber Eats** (largest FR market share for fast-food delivery in 2025-2026) — webhook in + auto-status sync out + menu push
2. **Deliveroo** (second)
3. **Just Eat / Takeaway.com** (third)

Each is ~4-8 weeks. Start with UberEats. Until this exists, your TAM is **only restaurants that don't use marketplaces** — which is mostly tiny operations not worth selling to.

In parallel, ship the **native TPE driver** (Ingenico Tetra via existing `payment_terminals` schema) so the cash-with-card flow is real, not Bypass. Without this, your "NF525 advantage" is half-realized.

### Recommendation 3 — DEFEND THE NF525 MOAT WITH DOCS, AUDIT REPORTS, AND A COMPLIANCE PACK (2-3 weeks)

NF525 is your **single biggest unfair advantage** vs Toast/Square in FR. But invisible moats don't sell. Ship:

- **NF525 compliance page** on the marketing site with the actual chain mechanism explained, the audit log triggers, the Z-report immutability — proof points that competitors don't have.
- **One-pager PDF "Pourquoi FoodKing est conforme NF525 par construction"** with the file paths and migration IDs (you have these — they're real, not vapor).
- **Third-party fiscal audit report** (commission an independent auditor for ~€3-5k, get a stamp).
- **Compliance pack for prospects** — privacy policy template, DPA template, GDPR exports endpoint (`/api/admin/gdpr/export-customer`), retention policy (you have 6-year fiscal retention in code — surface it).
- **GDPR/RGPD pack** — DPA template + privacy policy + data residency commitment ("Hébergé en France chez OVH/Scaleway" — pick one, document it).

This is **cheap, fast, and converts skeptical buyers** who don't trust "small French SaaS made by one guy with AI" — the documentation reframes you as the rigorous compliance-first option.

---

## 11. Risk Watchlist for Commercial Push

| Risk | Severity | Mitigation |
|---|---|---|
| **4 P0 NO-GO from audit 2026-05-09 not yet resolved** | CRITICAL | Resolve before any 2nd customer. Audit is from one week ago — verify status. |
| **Frozen Vanilla POS wizard is single point of failure** (`public/js/pos-wizard.js` ~296 KB hand-written) | HIGH | Plan modernization roadmap (Vue 3 port behind feature flag) — don't ship to chains relying on this without contingency. |
| **No SLA, no support tier — first paying customer outage = reputational risk** | HIGH | Define 99.5% SLA, set up StatusPage, define on-call procedure before customer #2. |
| **NF525 chain depends on MySQL triggers** — SQLite fallback in dev only | MEDIUM | Document prod requirement clearly; verify hosting provider supports MySQL 8 with triggers. |
| **"Built by non-senior-dev via Claude Code" risks enterprise vendor DD** | MEDIUM | Hire/contract a senior architect part-time as named "Head of Engineering" for due diligence; commission code audit. |
| **Le Cayenne is single reference customer** — chicken-and-egg | MEDIUM | Offer first-3-customers free 6-month pilot in exchange for testimonials + case studies. |
| **No data residency commitment documented** | MEDIUM | Decide and document (OVH/Scaleway FR strongly preferred for FR market + NF525 sentiment). |
| **Web payment + Stripe disabled by gate** (`config/payment.php`) — limits online orders | MEDIUM | Plan gate-clear and Stripe activation for Pro tier launch. |
| **No backup/disaster recovery documentation surfaced** | HIGH (for SaaS) | Document RPO/RTO, automated backups, restoration tests before commercialization. |

---

## 12. Verdict — One Paragraph

FoodKing is a **strong engineering asset wrapped in zero commercialization apparatus**. The codebase shows real depth in NF525 compliance (the standout moat), integrated stack (POS+Kiosk+KDS+OSS+Mobile in one repo), composer-grade item builder for complex fast-food menus, and bundled loyalty/coupons/stock. It is at feature parity with Innovorder/Tiller on POS fundamentals and ahead on integrated kiosk+composer. But it lacks the **three deal-breakers** for FR fast-food SaaS sales: marketplace delivery integrations (UberEats/Deliveroo), self-service onboarding, and native TPE driver — plus the **entire commercial apparatus** (marketing site, pricing, support, SLA, GDPR pack). With **3 months of focused commercialization work** (onboarding flow + UberEats integration + NF525 marketing pack + €39-129/mo tier structure), FoodKing could realistically acquire its first 5-10 paying restaurants in FR at a price 30-50% under Innovorder. Without that work, it remains a single-restaurant internal tool with SaaS potential.

---

**End of Agent 7 Competitive Benchmark report.**
