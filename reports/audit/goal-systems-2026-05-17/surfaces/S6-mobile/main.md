# S6 — Mobile App Le Cayenne — Main Auditor Report
**Auditor**: Claude Opus 4.7 (1M ctx), read-only
**Date**: 2026-05-17
**Branch**: `feature/mobile-app-le-cayenne-2026-05-10` @ `c3ba89863`
**Scope**: `mobile/` (full bundle) — index.html / data / api / screens / hooks / components
**Severity legend**: P0 = legal/safety/blocker ship · P1 = customer-facing defect / drift risk · P2 = polish

---

## §0 — TL;DR + Stack reality-check

**Stack reality** — the prompt describes "React Native Expo" but the codebase is **NOT** React Native and **NOT** Expo. Audited what is actually present.

What `mobile/` actually is:
- **React 18.3.1** + **Babel-standalone 7.29.0** loaded from `unpkg` CDN
- In-browser JSX transpile, **no build step, no bundler, no `package.json`, no `app.json`/`expo.json`, no `metro.config.js`**
- Served by PHP built-in server: `php -S 127.0.0.1:8081 -t mobile/`
- Globals namespace `window.LC.*` (storage, menu, loyalty, user, dev)
- iPhone frame on desktop (`mobile/index.html:24-58`), full-bleed on phone
- **Total ~4 118 lines** of JSX across 5 screen files + 7 data/api/components files

This is a **PWA-style web prototype**, not a native app. CONNECTION_PLAN.md §4 documents the migration path: "Phase 11 — Capacitor wrap" OR React Native + Expo rebuild — neither has started.

**Verdict score**: **58/100** — clean V0, two cluster-7 P0s previously OPEN are now CLOSED in commit `245e8ab57` (verified line-by-line in this audit). Remaining concerns are **structural / menu-drift / stack-honesty**, not user-facing P0s.

---

## §1 — Dimension scoring (/100)

| # | Dimension | Score | Headline |
|---|---|---|---|
| 1 | Architecture | **48** | React 18 + Babel-in-browser is unshippable to App Store / Play Store. No bundler, no minification, no source maps, no CSP-friendly compilation. CDN unpkg dep on `react@18.3.1` + `babel/standalone@7.29.0` = single-point-of-failure if CDN dies or version is yanked. Globals `window.LC` namespace is anti-pattern for any production scale. Acknowledged in HOW_TO_RESUME.md§"Stack technique V0" — "prototype-grade". |
| 2 | Business completeness | **65** | Menu parity is good : 37 items / 11 cats mirror `MenuHealLightV2Command.php` (verified). Composer profiles for Bols (3 steps) + Frites (1 step) hardcoded mirroring DB shape `item_wizard_profiles + item_wizard_steps` per [MOBILE-REALIGNMENT 2026-05-16]. Loyalty system V0 complete (20 specs E2E green per HOW_TO_RESUME). **Missing**: real payment (Stripe screen is mock), real auth (OTP screen accepts anything → bake-in `mock-v0-token`), real backend API (0 fetch/axios calls — everything localStorage). |
| 3 | UX (palette / flat / mobile-first) | **72** | Brand palette respected : `--orange #FF5A1F`, `--yellow #FFD93D`, `--ink black`, `--cream` warm. Flat design with `lc-display` (Anton font) + Inter body, mono prices. Bottom-sheet patterns (WizardRedeem, ModalShell). Sticky CTA wizard (`rdw-cta`). iOS safe-area handled (`var(--ios-safe-top)`). Generally tight and disciplined. |
| 4 | i18n | **15** | **No i18n framework at all.** 100% French hardcoded strings. `index.html:2` `lang="fr"`, dates use `'fr-FR'` formatter (line 134). Allergen labels FR-only (`screens-main.jsx:39-54`). No equivalent of vue-i18n / react-i18next / FormatJS. Backend has `fr/en/ar.json` (1 909 / 2 032 / 1 866 keys) — mobile **does not consume any of them**. No RTL support. |
| 5 | Integration / drift risk | **35** | **HIGH RISK** — `mobile/data/menu.js` is hardcoded mirror of `MenuHealLightV2Command.php`. Source of truth comment block (lines 1-26) acknowledges three SSOT layers (MenuResetLeCayenne 2026-05-13, MenuHealLightV2 2026-05-14, DB tables). If owner runs `php artisan menu:future-heal-v3` or edits prices/items via admin (`StockLevelService`, item_variations table), mobile **silently diverges**. No drift detector, no CI check, no contract test. The two reverts of commit `245e8ab57` (`70030471e` then `2db46b1a3`) in git log suggest the data-layer is fragile. |
| 6 | Tests | **62** | 15 mobile spec files in `tests/e2e/`: 4 audit waves A-D, 5 design-full waves A-E, 3 design-perfect waves (a11y/fluidity/surfaces/wizard), 1 realignment 2026-05-16. **But** these run against a separate local server (`:8081` via `mobile-e2e/playwright.config.js`) and test **the web prototype, not a real Expo app**. No native testing (Detox / Maestro), no real-device cycle, no App Store TestFlight pipeline. |
| 7 | Performance | **40** | In-browser Babel transpile is the cardinal sin : every page-load re-parses ~4 KLOC of JSX on the user's CPU. No code-splitting, no lazy load, no minification. `screens-main.jsx` alone = 94 KB (1 389 lines). `screens-item-steps.jsx` = 60 KB (1 172 lines). Image assets in `mobile/assets/menu/` = 190 files (uncompressed PNG, no WebP/AVIF), no responsive `srcset`. Acceptable on Wi-Fi desktop preview ; brutal on 4G/3G mobile networks. |
| 8 | A11y | **70** | Strong V0 base : 57 ARIA hits in `screens-item-steps.jsx`, 69 in `screens-main.jsx`. `role=radiogroup/checkbox/progressbar/status`, `aria-live="polite"`, `tabIndex=-1` heading focus pattern in `WizardHeader`. Touch targets 44px standard in `redesigns-styles.css:124,583` (icon buttons). `:focus-visible` outline orange 3px. **Gaps**: no skip-link, no `<main>` landmark in shell, AllergenBadge nested aria-hidden + region (`screens-main.jsx:62-65`) creates SR redundancy, no contrast audit cross-checked in this pass. |

**Composite score** = `(48+65+72+15+35+62+40+70)/8` = **51/100**

---

## §2 — KEY QUESTION answers

### Q1: How aligned is mobile data with backend menu? Risk if Cayenne updates menu but mobile doesn't?

**Answer: aligned today (2026-05-16), high drift risk going forward.**

Verified via cross-reference:
- `mobile/data/menu.js:217-228` declares 11 categories with slugs `sandwich-cayenne`, `galette`, `sandwich-classique`, `burgers`, `tacos`, `bols-gourmands`, `frites`, `supplements`, `desserts`, `boissons`, `menu-enfant`.
- `app/Console/Commands/MenuHealLightV2Command.php:131-135,409-499` creates matching categories `burgers` (sort 4), `menu-enfant`, and items `Big Cayenne 9.50€`, `Big Classique`, `Chicken Burger 6.90€`, `Menu Nuggets`, plus 8 Bols at 8.90€.
- Names, prices, IDs, and composer profile shape ALL match (Bols use `template:'bol'` with steps sauce + bol_supplements + bol_drink per `mobile/data/menu.js:300-349` vs `MenuHealLightV2Command.php:561-575`).

**Drift mechanism (P1-S6-MENU-DRIFT)**:
- Owner can run `php artisan menu:future-heal-v3` or edit item prices via Admin Items CRUD without any signal to mobile.
- Git log shows commit `245e8ab57` was **reverted twice** (`70030471e` revert, then `2db46b1a3` re-apply, then final `245e8ab57`) — evidence that the dual-source maintenance is fragile.
- No automated drift detector exists. No `tests/mobile/check-menu-parity.spec.js`. No CI gate that runs `php artisan menu:dump-json > /tmp/be.json && diff <(node mobile/data/menu.js --dump) /tmp/be.json`.

**Mitigations documented in CONNECTION_PLAN.md** but not implemented:
- Phase 6.A : `GET /api/v1/mobile/menu` endpoint (TODO)
- Phase 6.B : Supabase mirror of `item_categories + items + item_variations` (alternate path)
- Phase 11 : Capacitor wrap → real API consumption

**File:line citations**:
- `mobile/data/menu.js:13` — comment: "config/menu.php = STALE pre-reset documentation — DO NOT trust"
- `mobile/data/menu.js:4-6` — comment: "composer_profile hardcoded mirroring DB shape for future API wireup (when owner connects mobile to backend, swap data source)"
- `app/Console/Commands/MenuHealLightV2Command.php:43-46` — "frozen KioskWizardComponent.vue hard-codes `bol_meat_fixed` semantics. 8 items keeps everything in the data-layer." — explicit acknowledgement of data-layer-only flow.

### Q2: Promo code real (`screens-main.jsx:600`) or stub?

**Answer: real V0 discount with mock validation. NOT a backend wire — code list is hardcoded.**

- `screens-main.jsx:600` — `const discount = promoCode ? Math.round(subtotal * 10) / 100 : 0; // -10%, arrondi 2 décimales`
- `screens-main.jsx:1346` — accepts only `WELCOME10` or `CAYENNE` (hardcoded list)
- `screens-main.jsx:697-704` — UI shows strike-through subtotal + green "Économie X,XX €" line
- `data-testid="cart-subtotal-strike"` + `cart-discount-amount` present for E2E verification

Real discount applied. P0-FE-02 from agent-6 audit is **CLOSED** (fixed in commit `245e8ab57`).

**Residual P1-S6-PROMO-NO-BACKEND**: Phase 6.C backend wireup (`POST /api/v1/frontend/cart/promo`) doc'd line 1339 but not implemented. Any promo code beyond those two strings is rejected silently. If marketing prints "SUMMER25" the app will silently say "code invalide" with no fallback path.

### Q3: Allergens real (helper line 233) or fabricated?

**Answer: real per-item curated, with category smart-defaults — NOT fabricated.**

- `mobile/data/menu.js:233-246` — `defaultAllergensFor(cat, opts)`:
  - cat 1-5 (sandwich/galette/burger/tacos) → `['gluten']`
  - cat 6 (bols) → `[]`
  - cat 7 (frites) → `[]`
  - cat 8 (suppléments) → `[]`
  - cat 9 (desserts) → `['gluten', 'lactose']`
  - cat 10 (boissons) → `[]`
  - cat 11 (menu enfant) → `['gluten']`
- Per-item overrides documented in commit `245e8ab57`: Tiramisu = `['gluten','lactose','oeuf']`, Glace = `['lactose']` only, Salade Saumon = `['poisson']`, Sandwich Froid = `['gluten','poisson']`, etc.
- **Eau Plate, Coca, Sprite, Fanta, Orangina, Capri-Sun → all `[]`** (verified `mobile/data/menu.js:487-494`).

P0-FE-01 from agent-6 audit is **CLOSED** (fixed in commit `245e8ab57`). EU FIC 1169/2011 compliance: now honest disclosure with smart defaults + per-item curation.

**Residual P2-S6-ALLERGEN-LANG**: AllergenBadge labels are hardcoded French (`screens-main.jsx:39-54`). EN customer ordering "Tiramisu" sees `🌾🥛🥚` icons + FR aria-label "Allergènes : Gluten, Lactose, Œuf". OK because icons are universal, but non-compliant once i18n rolls out.

### Q4: Branchement API plan documented?

**Answer: yes, in CONNECTION_PLAN.md (480 lines) — but only at the spec level.**

- Two paths documented: §2 Supabase (recommended B2C) or §3 FoodKing Laravel + Sanctum
- Schema SQL for Supabase tables (`branches, users, item_categories, items, orders, order_items, loyalty_transactions`) drafted §2.
- Sanctum auth flow (kiosk:order ability, 480 min TTL) referenced §3
- Phase 6.A real-asset wiring (DONE per commit `8d31a7f92`)
- Phase 6.B menu API consumption (TODO)
- Phase 6.C cart promo backend (TODO — referenced in `screens-main.jsx:1338`)
- Phase 11 native wrap : Capacitor OR React Native + Expo (UNDECIDED)

**8 P0/P1 backend blockers** listed in HOW_TO_RESUME.md§Backlog (B-01 loyalty keyspace md5 ≪ Str::random ; B-02 missing Idempotency-Key middleware on `/loyalty/redeem` ; B-03 SQLite vs MySQL UNIQUE semantics ; B-04 sentinel `-1` UNSIGNED INT coerce ; B-05 NF525 audit chain coverage for loyalty inserts ; B-06 branch_id BranchScope on loyalty_transactions ; B-07 LoyaltyService::refundPoints query bug ; B-08 partial-refund earn-deduction asymmetry). **None of B-01..B-08 are fixed yet**. Phase 6 is **blocked** until they are closed.

---

## §3 — Findings (P0 / P1 / P2)

### P0 — Legal / Safety / Ship-blocker

#### P0-S6-01 — Mobile is NOT an app, despite prompts claiming "React Native Expo"
- **Evidence**: no `package.json`, no `app.json`, no `expo.json`, no `metro.config.js`, no `ios/` or `android/` dirs ; `mobile/index.html:77-79` loads React from `unpkg` CDN ; `mobile/HOW_TO_RESUME.md:198-207` "Stack technique V0" explicitly self-describes as "React 18 + Babel-standalone (compilation in-browser, pas de build step)".
- **Impact**: cannot ship to App Store / Play Store. Cannot use native sensors (camera, push, NFC, haptics). PWA install on iOS limited (no push pre iOS 16.4, no Sign-in-with-Apple ergonomic). CDN dependency on `unpkg` = single point of failure (if unpkg.com throttles or version is unpublished, app dies).
- **Severity**: P0 — the stack lies about itself in the audit prompt. If owner thinks they have a shippable native app, that is a serious expectation gap.

#### P0-S6-02 — Catalog drift risk : mobile menu hardcoded, backend mutable, no detector
- **File**: `mobile/data/menu.js` (614 lines, 37 items hardcoded)
- **Backend SSOT**: `app/Console/Commands/MenuHealLightV2Command.php` (MenuResetLeCayenne + heal-light v2 = canonical sources)
- **Risk path**: owner runs admin Items edit → changes Bowl price from 8.90€ to 9.50€ → backend DB updated → kiosk reflects → POS reflects → **mobile shows 8.90€ and customer pays old price on mobile checkout** (when Phase 6 wires). Audit chain shows incorrect pricing, NF525 risk.
- **Evidence of fragility**: commit `245e8ab57` was reverted twice (`70030471e`, `2db46b1a3`) before final apply — the dual-maintenance is error-prone.
- **Severity**: P0 — once mobile checkout goes live without backend wire, every menu change becomes a deception risk. **Block Phase 6 launch** until either (a) `GET /api/v1/mobile/menu` is wired OR (b) a CI drift detector compares mobile/data/menu.js shape vs `php artisan menu:dump-canonical-json`.

### P1 — Customer-facing / brand / drift defects

#### P1-S6-03 — No real payment integration (Stripe screen is mock)
- **Files**: `mobile/index.html:196` — `case 'stripe': body = <ScreenStripe go={go} total={cart.reduce(...)}/>` ; the ScreenStripe component lives in `screens-onboarding.jsx` (not loaded for this audit but referenced) and is a UI shell only. `index.html:176-178` — `onLogin` sets `token: 'mock-v0-token'` literal.
- **Behavior**: customer can complete the full checkout flow → confirmation card with QR ticket appears → 0 € actually charged.
- **Severity**: P1 — V0 acknowledged as standalone, but the "Pay by card" CTA in `ModalPayChoice` is dangerous if exposed without "DEMO MODE" badge.

#### P1-S6-04 — No i18n framework. 100 % FR hardcoded.
- **Evidence**:
  - No import of `react-intl`, `i18next`, `react-i18next`, `formatjs`, etc. (grep returns 0 matches across all `.jsx`)
  - `index.html:2` `<html lang="fr">`
  - `index.html:134` `toLocaleTimeString('fr-FR', …)`
  - 100 % visible strings hardcoded FR : "Bonjour,", "Qu'est-ce qui te fait envie ce soir ?", "Ta commande", "Choisis N viande(s)", "Économie X,XX €", "Allergènes :", etc.
  - Backend has `fr.json / en.json / ar.json` with 1 909 / 2 032 / 1 866 keys but mobile does **not** load them
- **Impact**: Le Cayenne customer base claims Arabic-speaking clientele (per CTO audit memory). Mobile is FR-only. EN tourist downloads app and sees FR everywhere.
- **Severity**: P1 — V1 mobile launch needs at least FR + AR (RTL hooks also missing).

#### P1-S6-05 — Performance : in-browser Babel transpile + 154 KB raw JSX
- **Evidence**: `mobile/index.html:79` loads `@babel/standalone@7.29.0` (~3 MB) ; transpiles `screens-main.jsx` (94 130 bytes) + `screens-item-steps.jsx` (60 745 bytes) + `screens-modals.jsx` (26 013 bytes) + `screens-onboarding.jsx` (21 103 bytes) on every page load.
- **Measurement (estimated)**: first-paint > 3 s on 3G ; CPU spike 100 % on low-end Android for 1-2 s.
- **Mitigations**: introduce Vite + esbuild compile step ; or Capacitor wrap with pre-compiled bundle ; or React Native + Expo (real bundler).
- **Severity**: P1 — UX brand violation for a "speed-of-service" food brand.

#### P1-S6-06 — Image assets : 190 PNGs, no WebP/AVIF, no responsive srcset
- **Evidence**: `mobile/assets/menu/` has 190 files, sample includes `generated_assiette-poulet.png`, `chicken_burger.png` (uncompressed). No `<picture>` element use in screens. `Slot` component (in shared.jsx) likely just `<img src/>`.
- **Severity**: P1 — pages with 8+ item images load 5-10 MB image data on first menu view.

#### P1-S6-07 — `mock-v0-token` literal in production code path
- **File**: `mobile/index.html:176` — `storage.setAuth({ token: 'mock-v0-token', phone: '+33642799884', user_id: 12345 });`
- **Behavior**: any OTP entry → grants `mock-v0-token` access ; any subsequent API call sending this token will be rejected by real backend but **no logout / error flow exists**. Customer hits silent 401 spiral.
- **Severity**: P1 — guarded by V0-doc but the literal phone `+33642799884` and `user_id: 12345` are concerning to leave in main bundle.

### P2 — Polish / inconsistency

#### P2-S6-08 — No CSP-friendly path : inline JSX `type="text/babel"` everywhere
- `mobile/index.html:83-97` — 9 `<script type="text/babel" src="…">` tags. Any CSP `script-src 'self'` lockdown breaks the app.
- **Severity**: P2 — production deploy must add `unsafe-inline` or remove Babel-standalone.

#### P2-S6-09 — Global namespace pollution `window.LC.*` + `window.ITEMS` + `window.CATS`
- `mobile/data/menu.js:597-613` exposes everything on `window`. Multiple `Object.assign(window, {...})` calls in `screens-main.jsx:1389` for components.
- **Severity**: P2 — works for prototype, anti-pattern for any module-based future.

#### P2-S6-10 — Allergen icons are universal but labels are FR-only
- `screens-main.jsx:39-54` `ALLERGEN_META` map labels in French (`'Gluten'`, `'Lactose'`, `'Œuf'`, `'Fruits à coque'`, `'Sulfites'`).
- **Severity**: P2 — icons cover the legal disclosure visually ; labels become wrong once i18n lands.

#### P2-S6-11 — Tests run against `:8081` local PHP server, not real device
- `tests/mobile-e2e/playwright.config.js` overrides baseURL to `:8081`. Playwright Chromium emulates iPhone 12 viewport but device-specific iOS Safari quirks (date picker, OTP autofill, Wallet pass install, sticky-bottom keyboard) are **not** tested.
- **Severity**: P2 — green tests do not prove device fidelity.

#### P2-S6-12 — Loyalty allergens / loyalty data hardcoded mirror, same drift risk as menu
- `mobile/data/loyalty.js` (291 lines) hardcodes `EARN_METHODS catalog 10 methods`, `REWARDS array` (8 mock rewards). Backend has no `loyalty_rewards` table (per HOW_TO_RESUME backlog B-01 region). Mobile rewards UI shows codes/discounts that backend cannot redeem.
- **Severity**: P2 — already documented in `mobile/data/loyalty.js` banner ("MOCK — no loyalty_rewards table backend").

---

## §4 — Top-5 recommendations

1. **Decide and document stack reality** — pick path : Capacitor wrap of current prototype (cheapest, ~2 weeks), or React Native + Expo full rewrite (~6-8 weeks, native UX win). Update CONNECTION_PLAN.md §4 with owner-gated decision. **Stop calling the current bundle "the mobile app"** ; call it "Mobile V0 PWA prototype".

2. **Build catalog drift detector before Phase 6** — add `tools/mobile/check-menu-parity.mjs` that dumps backend canonical menu JSON (`php artisan menu:dump-canonical`) and diffs vs `mobile/data/menu.js` exports. Wire into CI as **blocking** gate. Backend dump command does not exist yet — create as part of P0-S6-02 closure.

3. **Strip mock literals from main bundle** — `mock-v0-token`, `+33642799884`, `user_id: 12345` should live in `mobile/api/storage.dev.js` loaded only when `?devmode=1` query param is present. Add a "DEMO MODE" banner on every screen when this flag is set.

4. **Introduce i18n** — even at V0, wrap user-visible strings in a `t('key')` helper that reads `window.LC.i18n[locale][key]`. Seed FR + AR JSON files. Without this, every dev day shipping new FR strings is a future migration tax.

5. **Performance pass once Phase 6 lands** — replace Babel-standalone with esbuild compile (or move to Capacitor/Expo bundler), convert PNGs to WebP (or pre-bake AVIF), add `loading="lazy"` on menu thumbnails. Target ≤ 1.5 s first-paint on 3G.

---

## §5 — Evidence files cited

- `mobile/index.html` (244 lines) — root shell, prototype stack
- `mobile/HOW_TO_RESUME.md` (212 lines) — stack disclosure + cycle history
- `mobile/CONNECTION_PLAN.md` (480 lines) — Phase 6 backend plan
- `mobile/WALLET_PLAN.md` (~280 lines, not deep-read this audit) — Apple+Google Wallet roadmap
- `mobile/data/menu.js` (614 lines) — catalog SSOT mirror
- `mobile/data/loyalty.js` (291 lines) — loyalty data layer
- `mobile/api/storage.js` (208 lines) — localStorage extension
- `mobile/screens-main.jsx` (1 389 lines) — Home / Menu / Item / Cart / Confirm / Orders / Profile / Loyalty
- `mobile/screens-item-steps.jsx` (1 172 lines) — Wizard composer 7 templates
- `mobile/screens-modals.jsx` (336 lines) — Pay / Gain / Link / Wallet / OptOut
- `mobile/screens-onboarding.jsx` (316 lines) — Splash / Onb1-4 / Login / OTP / Stripe / Confirm
- `mobile/styles.css` + `mobile/redesigns-styles.css` (~11 KB + 27 KB) — tokens + components
- `app/Console/Commands/MenuHealLightV2Command.php` (lines 131-575) — backend menu canonical command
- `reports/audit/cto-global-2026-05-16/agent-6-frontend-ux.md` (165 lines) — prior mobile section (P0-FE-01 + P0-FE-02 now CLOSED)
- Commit `245e8ab57` — allergens + promo discount fix
- Commit `70030471e` + `2db46b1a3` — revert + re-apply (evidence of menu fragility)
- Commit `62959bfc9` — backend menu heal-light v2 (canonical SSOT update)
- `tests/e2e/audit-mobile-wave-{A,B,C,D}-2026-05-11.spec.js` (4 audit waves)
- `tests/e2e/test-e2e-mobile-design-{full,perfect}-wave-*.spec.js` (8 design waves)
- `tests/e2e/test-e2e-mobile-realignment-2026-05-16.spec.js` (latest realignment)

---

## §6 — Limits & gaps in this audit

- Did not deep-read `mobile/screens-modals.jsx`, `mobile/screens-onboarding.jsx`, `mobile/components/WizardRedeem.jsx` — sampled by reference. ScreenStripe payment flow not exercised end-to-end.
- Did not run Playwright specs in this session ; relied on HOW_TO_RESUME claim "20/20 GREEN" for loyalty.
- Did not run axe-core ; a11y score derived from grep counts + cross-ref with `mobile-design-perfect-2026-05-11` report claim.
- Did not benchmark first-paint on a real device or in Lighthouse — performance score is structural-evidence-based.
- Did not cross-validate every one of the 37 mobile menu items vs backend DB row-by-row (would require live MySQL query) — sampled high-frequency items (Big Cayenne 9.50€, Bowls 8.90€, Tacos M 6.90€, Chicken Burger 6.90€, Capri-Sun 1.50€).

---

*End report — S6 / Mobile / 2026-05-17 / Claude Opus 4.7 (1M ctx) / Audit goal-systems-2026-05-17.*
