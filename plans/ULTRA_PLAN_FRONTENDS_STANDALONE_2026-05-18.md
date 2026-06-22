# ULTRA-PLAN — Le Cayenne Frontends Standalone Excellence (Mobile + Site Web)
**Date** : 2026-05-18
**Auteur** : Claude orchestrator (Opus 4.7 1M context)
**Skills composés** : `ultra-architect-planify` + `ultra-audit-profond`
**Cycle** : Standalone-only — NO production wireup, NO touch to main FoodKing system
**Constrainte critique** : autre session goal en cours → NE PAS toucher PROJECT_BRAIN.md, MEMORY.md, Graphiti, ni les processus du goal session (ports 8081/8082)
**Cycle servers dédiés** : mobile :8181 + web :8182 (isolés)

---

## §0 — Preamble

### §0.1 — Working-tree decision
Cycle exécuté in-place sur la branche courante `feature/mobile-app-le-cayenne-2026-05-10`. Aucun commit auto. À la fin du cycle, owner décide commit + push manuel.

### §0.2 — Anti-fiction anchors (verified 2026-05-18 via `ls` + `find`)
**Mobile** :
- Root : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile/` ✓
- Fichiers : `index.html`, `screens-main.jsx`, `screens-item-steps.jsx`, `screens-modals.jsx`, `screens-onboarding.jsx`, `shared.jsx`, `styles.css`, `redesigns-styles.css`, `image-slot.js`, `icons.jsx`, `design-canvas.jsx`, `ios-frame.jsx`, `tweaks-panel.jsx` ✓
- Sub-dirs : `mobile/data/` (menu.js + loyalty.js + orders.js + user.js + dev-helpers.js + loyaltyRewardState.js), `mobile/api/` (storage.js), `mobile/hooks/` (useCountdown + useLoyaltyQR), `mobile/components/` (BarcodeMock + LoyaltyQR + WizardRedeem), `mobile/assets/menu/` (191 PNG/SVG), `mobile/debug/` ✓

**Web** :
- Root : `/Users/1millnonstop/Downloads/web/` ✓
- Fichiers : `index.html`, `screens.jsx`, `screens-v3.jsx`, `wizard-v2.jsx`, `flows.jsx`, `funnel.jsx`, `account-v2.jsx`, `loyalty-v2.jsx`, `orders.jsx`, `components.jsx`, `styles.css`, `styles-v2.css`, `styles-v3.css`, `styles-v4.css`, `styles-v5.css`, `styles-mobile.css`, `README.md` ✓
- Sub-dirs : `web/data/` (menu.js), `web/assets/menu/` (191 PNG/SVG) ✓

**Test infra** :
- `tests/mobile-e2e/playwright.config.js` ✓
- `tests/web-e2e/playwright.config.js` ✓
- `tests/e2e/test-e2e-mobile-realignment-2026-05-16.spec.js` (17 tests)
- `tests/e2e/test-e2e-website-realignment-2026-05-16.spec.js` (13 tests × 4 viewports = 52)

### §0.3 — Convergence criteria global
- Per task : 20-step ultra-audit-profond pipeline + Convergence Evidence Bundle pasted in cycle report
- Per wave : checkpoint 6-point (tests pass + frozen diff 0 + visual gate + RED disputed + heal max 3 loops + cycle report updated)
- Per cycle : DONE = all 6 waves green + 0 frozen-zone touch + 0 P0/P1 résiduel adversarial final

### §0.4 — Per-task pipeline reference
Chaque tâche ci-dessous est exécutée via `ultra-audit-profond` (20-step pipeline avec 10 gates G1-G10). Non re-décrit ici par tâche — voir skill SKILL.md.

### §0.5 — Owner constraints (verbatim)
- "I forbid you to touch the goal" — pas de BRAIN/MEMORY/Graphiti
- "Both surfaces stay SEPARATED" — no API/MCP wireup à FoodKing central
- "Maximum tasks, ultra-orchestrated"
- "E2E test visual capture each page each step + correct visually + technical + security review at each step"
- "Immediate execution after plan written"

---

## §1 — Système 1 : App Mobile Le Cayenne (standalone PWA)

### Contract
HTML + React 18 + Babel-standalone runtime (no build). Mobile-only viewport (390×844 iPhone 13). Consume `mobile/data/menu.js` SSOT mirror central system DB (post menu-reset 2026-05-13 + heal-light V2 2026-05-14). Composer profiles hardcoded pour Bols (3-step) + Frites (1-step). Pepper Club loyalty mock. localStorage-only persistence (storage.js).

### Frozen zones (strict-no-touch — central system FoodKing)
Voir CLAUDE.md §7 + `memory/reference_frozen_zones.md` (13 fichiers). Mobile app n'y touche jamais (verified par cycles précédents 0 ligne diff sur 12 fichiers vérifiés per-file).

### Anchors verified 2026-05-18
- `mobile/index.html` (entry)
- `mobile/data/menu.js` (440 LOC, 11 cats / 41 items / pools / composer helpers / priceFor)
- `mobile/screens-item-steps.jsx` (FSM `computeActiveSteps` 4 templates + cascade + `buildLineItem` + 9 ScreenStep* components + `aggregatedAllergens` FIC)
- `mobile/screens-main.jsx` (ScreenHome + ScreenMenu + ScreenCart + ScreenConfirm + ScreenOrders + ScreenOrderDetail + ScreenProfile + ScreenLoyalty)
- `mobile/screens-modals.jsx` (ModalPayChoice + ModalOptOutConfirm + ModalWalletV0Notice + PromoCodeRow + ScreenStripe)
- `mobile/screens-onboarding.jsx` (ScreenSplash + Onb1-4 + ScreenLogin + ScreenOTP)
- `mobile/api/storage.js` (208 LOC, namespaced `lecayenne.*` localStorage)
- `mobile/hooks/useLoyaltyQR.js` (113 LOC, chained setTimeout + visibilitychange + ref guard)
- `mobile/components/WizardRedeem.jsx` (264 LOC, 3-step + idempotency 10-min window)

### Décomposition en 4 sub-systèmes

#### Sub 1.1 — Data Layer Mobile (SSOT canonical mirror)
**Anchors** : `mobile/data/menu.js`, `mobile/data/loyalty.js`, `mobile/data/orders.js`, `mobile/data/user.js`, `mobile/data/dev-helpers.js`, `mobile/data/loyaltyRewardState.js`
**Tasks** :
- **T-M1.1.1** Audit data parity menu vs heal-light V2 canonical (11 cats / 41 items / 4 viandes / 11 sauces / 9 supplements @ 0.90€ + allergens / 4 supplements_bols + Boule gratinée 2€ / 3 frites styles / 3 formules / 8 formule drinks)
  - anchor: `mobile/data/menu.js:11-25 (header SSOT pointer)`, `mobile/data/menu.js:203-228 (CATEGORIES)`, `mobile/data/menu.js:161-171 (SUPPLEMENTS + allergens)`
  - test: existing `tests/e2e/test-e2e-mobile-realignment-2026-05-16.spec.js:80 (G — Data parity)` PASS
  - visual: N/A (data check)
- **T-M1.1.2** Audit pricing function `priceFor()` edges (sauce length 0/1/2/3 — no negative bug at 0)
  - anchor: `mobile/data/menu.js:515-551 (priceFor)`
  - test: existing M-test for multi-sauce pricing PASS
- **T-M1.1.3** Audit composer profile builders (Bol 3-step sauce + bol_supplements + bol_drink, Frites 1-step frites_style with Nature pre-select)
  - anchor: `mobile/data/menu.js:buildBolComposerProfile + buildFritesComposerProfile`
  - test: existing D + E tests PASS, R N (bol sauce fallback) PASS
- **T-M1.1.4** Audit loyalty Pepper Club paliers + earn_ratio (mobile uses 10:1 in loyalty.js mock vs web 1:1 in menu.js PEPPER_CLUB — INTENTIONAL divergence per owner D1 default)
  - anchor: `mobile/data/loyalty.js` (earn_ratio 10), `mobile/data/menu.js:PEPPER_CLUB` if exists
  - test: (test TO BE CREATED at `tests/e2e/test-e2e-mobile-loyalty-paliers.spec.js`)
- **T-M1.1.5** Audit storage round-trip serialization (cart with bol fields + composer_profile circular ref check)
  - anchor: `mobile/api/storage.js:58-66 (getCart/setCart)`, `mobile/screens-item-steps.jsx:buildLineItem`
  - test: existing J — Cart round-trip preserves bol fields PASS
**Acceptance** : G data parity + H pricing + I cart line + J round-trip + M multi-sauce + N bol fallback all PASS. Aggregated 17/17 mobile spec GREEN.

#### Sub 1.2 — Wizard FSM Mobile (computeActiveSteps + canAdvance + buildLineItem)
**Anchors** : `mobile/screens-item-steps.jsx` (~1180 LOC total)
**Tasks** :
- **T-M1.2.1** Audit `computeActiveSteps()` 8 templates (tacos/sandwich/burger/assiette/omelette/salade/snacking/simple + custom for Bols/Frites)
  - anchor: `mobile/screens-item-steps.jsx:56-130 (computeActiveSteps)`
  - test: existing C tacos, B sandwich-family, F simple cats PASS
- **T-M1.2.2** Audit `canAdvance()` validation per step (VIANDES exact N pick, SAUCE min 1, FRITES_STYLE !== undefined, BOL_DRINK always true optional)
  - anchor: `mobile/screens-item-steps.jsx:135-170 (canAdvance)`
  - test: existing P viande_count=2 enforcement PASS
- **T-M1.2.3** Audit `buildLineItem()` shape + composition_summary string + qty floor + sauce_locked surfacing in cart line
  - anchor: `mobile/screens-item-steps.jsx:905-980 (buildLineItem)`
  - test: existing I cart line composition PASS ; (test TO BE CREATED at `tests/e2e/test-e2e-mobile-wizard-edge-sauce-locked.spec.js` for Sandwich Cayenne summary string)
- **T-M1.2.4** Audit `aggregatedAllergens` FIC 1169/2011 (item + supplements + bol_supps + drinks)
  - anchor: `mobile/screens-item-steps.jsx:790-820 (aggregatedAllergens)`
  - test: existing L aggregated allergens PASS
- **T-M1.2.5** Audit 9 ScreenStep* render components (radio/multi + visual + a11y radio/checkbox roles + Choice card)
  - anchor: `mobile/screens-item-steps.jsx:280-700 (Screen Step components)`
  - test: visual via Playwright + axe ; (test TO BE CREATED at `tests/e2e/test-e2e-mobile-wizard-a11y.spec.js`)
- **T-M1.2.6** Audit cascade menu state cleanup (menu='full' → drink + fritesStyle + fritesSauce ; menu='frites' clears drinkId ; menu='boisson' clears fritesStyleId)
  - anchor: `mobile/screens-item-steps.jsx:114-127 (cascade)` + `mobile/screens-item-steps.jsx:475-525 (ScreenStepMenu pick handler)`
  - test: (test TO BE CREATED at `tests/e2e/test-e2e-mobile-wizard-cascade.spec.js`)
**Acceptance** : 9 ScreenStep components rendent corrects sans console errors. Cascade state cleanup verified. All FSM transitions deterministic. 17/17 spec GREEN + 2 new specs GREEN.

#### Sub 1.3 — UI/UX Mobile per page (Home + Menu + Cart + Checkout + Confirm + Orders + Loyalty + Profile + Onboarding)
**Anchors** : `mobile/screens-main.jsx` (~1400 LOC) + `mobile/screens-onboarding.jsx` (316 LOC) + `mobile/screens-modals.jsx` (336 LOC) + `mobile/styles.css` + `mobile/redesigns-styles.css`
**Tasks** :
- **T-M1.3.1** Audit Home (ScreenHome) — hero "BONJOUR", 11 choix badge, 3+ cat tiles, featured Sandwich Cayenne, Marquee canonical (Sauce Cayenne maison / Sandwichs faluche / Tacos M & L / Bols Frites/Riz / Burgers brioché / Frites Cheddar / Menu enfant / Prêt 8 min), bottom nav ACCUEIL/MENU/COMMANDES/PROFIL
  - anchor: `mobile/screens-main.jsx:90-220 (ScreenHome)`
  - test: existing Z visual sweep PASS + (test TO BE CREATED at `tests/e2e/test-e2e-mobile-home-canonical-content.spec.js`)
  - visual: capture A01-home.png, Read+analyze raw labels / palette / branding / Marquee canonical
- **T-M1.3.2** Audit Menu (ScreenMenu) — header "MENU 11 catégories · 41 produits" + chip row 11 cats (chip "GALETTE" not truncated) + item cards + allergen badges + photos resolve
  - anchor: `mobile/screens-main.jsx:240-450 (ScreenMenu)`
  - test: existing A "Home shows 11 cats badge and Menu screen lists 11 cats" PASS
  - visual: capture A02-menu-tab.png + A03-menu-scrolled.png
- **T-M1.3.3** Audit Cart (ScreenCart) — empty state, line items with composition_summary, qty stepper, promo code WELCOME10/CAYENNE, special instructions textarea 190 char, subtotal + discount + total, "Valider ma commande" CTA
  - anchor: `mobile/screens-main.jsx:594-715 (ScreenCart)`
  - test: existing J round-trip PASS ; (test TO BE CREATED at `tests/e2e/test-e2e-mobile-cart-empty-and-promo.spec.js`)
- **T-M1.3.4** Audit ModalPayChoice + Stripe placeholder — "Payer à la caisse" recommended ★ vs "Payer maintenant" Stripe stub
  - anchor: `mobile/screens-modals.jsx:40-110 (ModalPayChoice + ScreenStripe)`
  - test: (test TO BE CREATED at `tests/e2e/test-e2e-mobile-pay-choice.spec.js`)
- **T-M1.3.5** Audit Confirmation (ScreenConfirm) — yellow celebration, QR ticket mock, order ID C-XXXX, eta ~12 MIN, +25 pts badge if first order, CTAs "Accueil" + "Suivre →"
  - anchor: `mobile/screens-main.jsx:717-780 (ScreenConfirm)`
  - test: (test TO BE CREATED at `tests/e2e/test-e2e-mobile-confirm-screen.spec.js`)
- **T-M1.3.6** Audit Orders (ScreenOrders) — tabs En cours / Historique + active order card progress bar + history grouped by date + re-order CTA
  - anchor: `mobile/screens-main.jsx:784-913 (ScreenOrders)`
  - test: (test TO BE CREATED at `tests/e2e/test-e2e-mobile-orders-tabs.spec.js`)
- **T-M1.3.7** Audit Loyalty (ScreenLoyalty) — HERO Pepper Club + POINTS card RGPD-gated + ACTIONS grid 3-col + TABS (Récompenses / Historique / Infos) + REWARDS list + History dots earn/spend
  - anchor: `mobile/screens-main.jsx:ScreenLoyalty` (search needed) + `mobile/components/LoyaltyQR.jsx` + `mobile/components/BarcodeMock.jsx`
  - test: existing loyalty-01..15 mobile-e2e specs (15+ specs already) — verify all still PASS post-cycle
- **T-M1.3.8** Audit Profile (ScreenProfile) — user info, opt-in/out RGPD, settings, logout
  - anchor: `mobile/screens-main.jsx:ScreenProfile`
  - test: existing loyalty-11-opt-out spec PASS
- **T-M1.3.9** Audit Onboarding (Splash + Onb1-4 + Login + OTP) — 4 hero designs (EST.2024 medallion / check medallion / starburst rays), phone input format FR, OTP 4-digit
  - anchor: `mobile/screens-onboarding.jsx (all)`
  - test: existing test-e2e-mobile-design-full-wave-A.spec.js + design-perfect-wave-a11y.spec.js
**Acceptance** : 9 mobile pages visually verified via Playwright capture + Read+analyze each PNG. 0 raw labels (`Label.X`, `kiosk.X`, `0undefined`, `NaN €`). 0 fictional menu refs. Branding cohérence. Photos all resolve (no 404).

#### Sub 1.4 — A11y + Perf + Security Mobile
**Anchors** : tous les fichiers JSX + `mobile/index.html` (viewport meta)
**Tasks** :
- **T-M1.4.1** A11y WCAG 2.1 AA sweep — focus management (headingRef.focus tabIndex=-1), ARIA roles (radio/checkbox/dialog/tab/tablist), keyboard nav (Enter/Space onKeyDown), live regions (aria-live polite/atomic), contrast ≥4.5:1 (--gray-3 #6F6A60 4.7:1 ✓), focus-visible outline
  - anchor: tous les ChoiceCard + WizardCTA + steppers + form fields
  - test: (test TO BE CREATED at `tests/e2e/test-e2e-mobile-axe-audit.spec.js`) avec axe-core inject, expected 0 critical + 0 serious
- **T-M1.4.2** Viewport meta accessibility — `width=device-width, initial-scale=1.0, viewport-fit=cover` (no `maximum-scale=1` per cycle B 2026-05-11 ADV-A11-016 closure)
  - anchor: `mobile/index.html:6 (viewport meta)`
  - test: assertion sur meta tag dans test axe-audit
- **T-M1.4.3** Perf — FCP < 2s sur 390×844, scroll 60fps, prefers-reduced-motion respected (Marquee animation, wizard step transitions)
  - anchor: `mobile/redesigns-styles.css` (rdw-* animations)
  - test: (test TO BE CREATED at `tests/e2e/test-e2e-mobile-perf.spec.js`) avec performance.now() measurements
- **T-M1.4.4** Security review — localStorage XSS protection (no `dangerouslySetInnerHTML`), no eval, no inline event handlers with user input, dev-helpers gated behind dev mode
  - anchor: `mobile/api/storage.js`, `mobile/data/dev-helpers.js`
  - test: grep audit pour `dangerouslySetInnerHTML|eval(|new Function|document.write`
- **T-M1.4.5** Loyalty consent RGPD — opt-out clears balance + history, points re-zero, modal confirmation
  - anchor: `mobile/screens-modals.jsx:ModalOptOutConfirm`
  - test: existing loyalty-11-opt-out PASS
**Acceptance** : axe 0 critical/0 serious. FCP < 2s. No security smell. RGPD opt-out verified.

---

## §2 — Système 2 : Site Web Le Cayenne (standalone SPA)

### Contract
HTML + React 18 + Babel-standalone runtime (no build). Multi-viewport responsive (mobile 390 / tablet 768 / desktop 1280 / wide 1920). Consume `web/data/menu.js` SSOT mirror central system DB (post menu-reset 2026-05-13 + heal-light V2 2026-05-14). 4 wizard templates canoniques (sandwich / tacos / custom-bols / custom-frites / simple). 11 catégories × 41 items. Pepper Club tiers 0/500/1500/5000 earn_ratio 1:1. AccountFlow / WizardFlow / ItemDetailModal / CartDrawer / CheckoutPage / PaymentPage / ConfirmationPage / TrackingPage / OrdersPage / LoyaltyProfileTab / WebHome / WebMenu / WebLoyalty / WebAbout.

### Frozen zones
None (web is entirely owner-owned standalone code, no central system touch).

### Anchors verified 2026-05-18
- `web/index.html` (App() top-level state-driven router)
- `web/data/menu.js` (440 LOC, canonical mirror mobile)
- `web/screens.jsx` (~835 LOC, WebHome + WebMenu + WebLoyalty + WebAbout + ItemCard)
- `web/screens-v3.jsx` (~250 LOC, ItemDetailModal + Press + Compare + FAQ + Leaderboard + Challenge + Team)
- `web/wizard-v2.jsx` (510 LOC, buildSteps + getActiveSteps cascade + computeWizardTotal + DirectAddView + WizardFlow)
- `web/flows.jsx` (108 LOC post-trim, CartDrawer only)
- `web/funnel.jsx` (460 LOC, CheckoutPage + PaymentPage + ConfirmationPage + TrackingPage)
- `web/account-v2.jsx` (237 LOC, AccountFlow login/signup/social/OTP/forgot)
- `web/loyalty-v2.jsx` (141 LOC, LoyaltyProfileTab editor + notif + cards + prefs)
- `web/orders.jsx` (74 LOC, OrdersPage)
- `web/components.jsx` (149 LOC, WebNav + WebFooter + WebModal + icons)

### Décomposition en 4 sub-systèmes

#### Sub 2.1 — Data Layer Web + W_CATS/W_ITEMS bridge
**Anchors** : `web/data/menu.js`
**Tasks** :
- **T-W2.1.1** Audit data parity web vs mobile (must be 100% — verified cross-surface auditor cycle 2026-05-17)
  - anchor: `web/data/menu.js:171-185 (CATEGORIES)`, `web/data/menu.js:120-140 (SUPPLEMENTS + allergens)`, `web/data/menu.js:248-270 (Bol composer)`
  - test: existing `tests/e2e/test-e2e-website-realignment-2026-05-16.spec.js:70 (G — Data parity)` PASS sur 4 viewports
- **T-W2.1.2** Audit `priceFor()` parity vs mobile (formules identiques : sauce extras 0.50€ / supplements / bol supplements / bol drink / formules / frites style)
  - anchor: `web/data/menu.js:405-416 (priceFor)`
  - test: existing H pricing parity + L multi-sauce edges PASS
- **T-W2.1.3** Audit `window.W_CATS / W_ITEMS / W_DIET` backwards-compat globals (legacy code in screens.jsx consume these)
  - anchor: `web/data/menu.js:450-475 (W_CATS/W_ITEMS exports)`
  - test: assertion `window.W_CATS.length === 12` (11 + 'Tout' chip) + `window.W_ITEMS.length === 41`
- **T-W2.1.4** Audit `composer_profile` builders + Bol sauce default fallback (rename-resistant per cycle 2026-05-17 P0 heal)
  - anchor: `web/data/menu.js:240-280 (buildBolComposerProfile + buildFritesComposerProfile)`
  - test: existing M bol sauce default fallback PASS
- **T-W2.1.5** Audit Pepper Club paliers + earn_ratio (Novice 0 / Pepper 500 / Master 1500 / Légende 5000, ratio 1:1)
  - anchor: `web/data/menu.js:418-432 (PEPPER_CLUB)`
  - test: existing G assertion `pepperRatio: 1` + `pepperTiers: [Novice@0, Pepper@500, Master@1500, Légende@5000]`
**Acceptance** : G + H + L + M PASS sur 4 viewports = 24+ tests GREEN. Cross-surface parity 100% maintenue.

#### Sub 2.2 — Wizard FSM Web (buildSteps + getActiveSteps + computeWizardTotal + DirectAddView)
**Anchors** : `web/wizard-v2.jsx`
**Tasks** :
- **T-W2.2.1** Audit `buildSteps(item)` 4 templates (sandwich + tacos + custom-bols + custom-frites + simple)
  - anchor: `web/wizard-v2.jsx:11-139 (buildSteps)`
  - test: existing D `buildWizardSteps returns canonical steps per template` PASS
- **T-W2.2.2** Audit `getActiveSteps()` cascade dynamic insertion (cascade_drink + cascade_frites_style based on state.menu)
  - anchor: `web/wizard-v2.jsx:144-173 (getActiveSteps)`
  - test: (test TO BE CREATED at `tests/e2e/test-e2e-website-wizard-cascade.spec.js`)
- **T-W2.2.3** Audit `computeWizardTotal()` mirror mobile priceFor (sauce/supps/bol_supps/bol_drink/formule/frites_style)
  - anchor: `web/wizard-v2.jsx:178-194 (computeWizardTotal)`
  - test: existing E PASS
- **T-W2.2.4** Audit `suppOptions()` reads allergens directly from SUPPLEMENTS pool (post-heal MASSIVE-LOGIC 2026-05-17)
  - anchor: `web/wizard-v2.jsx:28-31 (suppOptions)`
  - test: existing P supplement allergens propagate PASS
- **T-W2.2.5** Audit `WizardFlow` shell (header + step counter + progress dots + back/close + footer CTA + multi-radio/multi-multi rendering + recap + DirectAddView pour simple cats)
  - anchor: `web/wizard-v2.jsx:200-447 (WizardFlow + DirectAddView)`
  - test: (test TO BE CREATED at `tests/e2e/test-e2e-website-wizard-interactive-walk.spec.js`)
- **T-W2.2.6** Audit `DirectAddView` qty stepper + onAdd carry qty to cart (post-heal MASSIVE-LOGIC P0 2026-05-17)
  - anchor: `web/wizard-v2.jsx:449-500 (DirectAddView)` + `web/index.html:onAdd cart handler`
  - test: (test TO BE CREATED at `tests/e2e/test-e2e-website-direct-add-qty.spec.js`)
**Acceptance** : 4 templates wizard testés × tous les viewports. Cascade state cleanup verified. Qty preserved cart→checkout. Allergen aggregation works.

#### Sub 2.3 — UI/UX Web per page multi-viewport (15 routes/modals)
**Anchors** : tous les screens jsx + funnel jsx + flows jsx + account-v2 + loyalty-v2 + orders + screens-v3
**Tasks** :
- **T-W2.3.1** Audit WebHome (hero "SANDWICH. TACOS. BOLS. GALETTE." + WHY 4-points + daily special + featured Big Cayenne XL + testimonials + gallery + hours + insta + footer)
  - anchor: `web/screens.jsx:88-410 (WebHome)`
  - test: existing A home canonical PASS sur 4 viewports
  - visual: capture mobile-A01 + tablet-A01 + desktop-A01 + wide-A01
- **T-W2.3.2** Audit WebMenu (sidebar categories 11 + grid items + search input + diet filter chips + active count)
  - anchor: `web/screens.jsx:412-498 (WebMenu)`
  - test: existing B menu 11 cats PASS
- **T-W2.3.3** Audit ItemDetailModal (nutri + allergens + popular badges + customize CTA → opens wizard, add-to-cart direct CTA → cart)
  - anchor: `web/screens-v3.jsx:ItemDetailModal`
  - test: (test TO BE CREATED at `tests/e2e/test-e2e-website-item-detail-modal.spec.js`)
- **T-W2.3.4** Audit CartDrawer (side panel slide-in, empty state, line items + composition subs, time slots ASAP/20m/40m, promo code, notes textarea, totals, Passer commande CTA)
  - anchor: `web/flows.jsx:CartDrawer`
  - test: (test TO BE CREATED at `tests/e2e/test-e2e-website-cart-drawer.spec.js`)
- **T-W2.3.5** Audit CheckoutPage (step 1/2 Pickup + day picker AUJ/JEU/.../DIM + 6 time slots + promo + notes + pickup location card + Continue CTA disabled until slot picked)
  - anchor: `web/funnel.jsx:107-220 (CheckoutPage)`
  - test: (test TO BE CREATED at `tests/e2e/test-e2e-website-checkout-flow.spec.js`)
- **T-W2.3.6** Audit PaymentPage (step 2/2 Paiement + methods counter★/card/Apple/Google Pay + Stripe card form when method=card + security badge + account upsell + Payer CTA)
  - anchor: `web/funnel.jsx:225-327 (PaymentPage)`
  - test: (in same checkout spec)
- **T-W2.3.7** Audit ConfirmationPage (confetti animation 28 pieces × 12 colors + checkmark + order ID + ticket card QR + ready time + total + 2 CTAs)
  - anchor: `web/funnel.jsx:332-382 (ConfirmationPage)`
  - test: (test TO BE CREATED at `tests/e2e/test-e2e-website-confirm-tracking.spec.js`)
- **T-W2.3.8** Audit TrackingPage (status EN PRÉPARATION + progress bar 4 steps + mock update setTimeout 6s + info cards + Voir QR CTA)
  - anchor: `web/funnel.jsx:387-458 (TrackingPage)`
  - test: (in same confirm-tracking spec)
- **T-W2.3.9** Audit OrdersPage (auth gate + history 5 canonical past orders + stats + filter tabs + cards + re-order CTA)
  - anchor: `web/orders.jsx:13-75 (OrdersPage)`
  - test: (test TO BE CREATED at `tests/e2e/test-e2e-website-orders-page.spec.js`)
- **T-W2.3.10** Audit AccountFlow modal (login/signup tabs + social CTAs + OTP 4-digit + forgot password + errors visible)
  - anchor: `web/account-v2.jsx:8-237 (AccountFlow)`
  - test: (test TO BE CREATED at `tests/e2e/test-e2e-website-account-flow.spec.js`)
- **T-W2.3.11** Audit WebLoyalty + LoyaltyProfileTab (Pepper Club dashboard tiers + balance + rewards + history + editor + notif + saved cards + prefs)
  - anchor: `web/screens.jsx:WebLoyalty` + `web/loyalty-v2.jsx:LoyaltyProfileTab`
  - test: (test TO BE CREATED at `tests/e2e/test-e2e-website-loyalty-dashboard.spec.js`)
- **T-W2.3.12** Audit WebAbout (l'enseigne text canonical + chef Abdoullah + Hénin-Beaumont + timeline 2024 premier menu canonical)
  - anchor: `web/screens.jsx:WebAbout`
  - test: assertion sur text canonical (no "smash burgers" stale)
- **T-W2.3.13** Audit WebNav + WebFooter (sticky nav, mobile burger, cart count badge, footer brand canonical, navigation links)
  - anchor: `web/components.jsx:40-149 (WebNav + WebFooter)`
  - test: existing tests use nav navigation OK
- **T-W2.3.14** Audit P2 pages (Press + Compare + FAQ + Leaderboard + Challenge + Team)
  - anchor: `web/screens-v3.jsx (all P2 components)`
  - test: visual sweep + raw labels check
- **T-W2.3.15** Audit ResponsiveAll — verify each page renders OK aux 4 viewports (mobile 390 / tablet 768 / desktop 1280 / wide 1920)
  - anchor: `web/styles-mobile.css` (mobile overrides last in cascade)
  - test: visual sweep multi-viewport pour 6 routes principales (home / menu / item / cart / checkout / confirm)
**Acceptance** : 15 pages/modals visuellement validées × 4 viewports = 60 captures Read+analyzed. 0 raw labels. 0 fictional refs. Responsive intact. Photos all resolve.

#### Sub 2.4 — A11y + Perf + Security Web
**Anchors** : tous les fichiers JSX + `web/index.html`
**Tasks** :
- **T-W2.4.1** A11y WCAG 2.1 AA sweep multi-viewport (focus / ARIA / contrast / keyboard / aria-live / focus-visible / `--gray-3 #6F6A60` post-heal MAX-AUDIT 2026-05-18)
  - anchor: tous les composants interactifs + `web/styles.css:--gray-3`
  - test: (test TO BE CREATED at `tests/e2e/test-e2e-website-axe-audit.spec.js`) avec axe-core inject sur 4 viewports
- **T-W2.4.2** Viewport meta accessibility — verify no `maximum-scale=1` blocking pinch-zoom (WCAG SC 1.4.4)
  - anchor: `web/index.html:6`
  - test: assertion in axe spec
- **T-W2.4.3** Perf — FCP < 1.5s desktop / < 2s mobile, CDN React+Babel cached, 190 PNG lazy-load `loading="lazy"`, scroll 60fps
  - anchor: `web/screens.jsx:55 (ItemCard <img loading="lazy">)`
  - test: (test TO BE CREATED at `tests/e2e/test-e2e-website-perf.spec.js`)
- **T-W2.4.4** Security review — XSS protection, no eval, no dangerouslySetInnerHTML, AccountFlow OTP mock no real secret leak, no inline event handlers with user input
  - anchor: tous les fichiers JSX
  - test: grep audit + manual review
- **T-W2.4.5** Skip link "Skip to content" WCAG 2.4.1 — currently missing per A11y audit 2026-05-18 (P2 deferred V1.x)
  - anchor: `web/index.html:body` first child
  - test: assertion in axe spec (mark expected fail = backlog confirmed)
**Acceptance** : axe 0 critical/0 serious sur 4 viewports. FCP measured. Skip link backlog documented (not blocking V0 standalone).

---

## §A — Agent Army Map + Fan-Out Matrix

### Roles (9 base + 2 specialty)
| Rôle | Subagent type | Tools | Prompt template |
|---|---|---|---|
| Architect | `general-purpose` | Read-only | `~/.claude/skills/superpower-gstack/agents/architect-prompt.md` |
| Security | `general-purpose` | Read-only | `~/.claude/skills/superpower-gstack/agents/qa-red-team-prompt.md` (SECURITY mode) |
| UX / A11y | `general-purpose` | Read + axe | inline brief: WCAG 2.1 + ARIA + flat design + branding |
| DBA | `general-purpose` | Read | N/A pour standalone (no DB) — replaced by Data-Layer auditor |
| SRE / Sync | `general-purpose` | Read | N/A pour standalone (no sync) — replaced by Standalone-Parity auditor |
| Implementer | `general-purpose` | Edit + Write + Bash | inline brief: TDD-first, scope-minimal, ≤30 LOC inline-edit exception |
| RED-team | `general-purpose` | Read-only | hostile dispute post-impl |
| QA Visual | `general-purpose` | Read + Playwright | runs spec + captures + analyzes |
| RED Visual | `general-purpose` | Read | re-analyzes QA screenshots independently |
| **Standalone-Parity** | `general-purpose` | Read | mobile ↔ web data identity + composer profile shape mirror |
| **Data-Layer** | `general-purpose` | Read + Node eval | priceFor + composer + W_* globals verification |

### Fan-Out per task type (standalone-frontend only — NO DBA/SRE/Fiscal for these surfaces)
| Task type | Architect | Security | UX/A11y | Data-Layer | Standalone-Parity | Implementer | RED | QA Vis | RED Vis |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Data layer audit | x | x | . | x | x | . | x | . | . |
| Wizard FSM | x | . | x | x | . | x | x | . | . |
| UI/UX page | x | x | x | . | . | x | x | x | x |
| A11y + Perf + Security | . | x | x | . | . | x | x | x | x |
| Cross-surface integration | x | x | x | x | x | . | x | . | . |

### Dispatch discipline
- 5 read-only specialists = SINGLE MESSAGE multi-Agent calls (parallel)
- Implementer = NEVER parallel with another implementer (write conflict)
- QA Visual + RED Visual = parallel OK (read-only)
- RED dispute = ALWAYS after implementer commit, BEFORE declare DONE

### Reporting contract
Per skill : `reports/audit/ultra-frontends-2026-05-18/wave-<N>/<role>-<task-id>.json`. Schema : Sev / file:line / Issue / Reproduction / Severity rationale / Fix. ≤ 1200-1500 words per agent.

---

## §X — Convergence Waves (W1-W6)

### W1 — Pre-flight + data parity baseline (sequential, ~10 min)
- **Scope** : verify both surfaces still load, run existing 17 mobile + 52 web tests, capture baseline
- **Parallelism** : sequential (single owner verifies servers + runs both suites)
- **Checkpoint** : 69/69 GREEN baseline + servers up on dedicated ports 8181/8182
- **Interrupt-resume** : write `reports/audit/ultra-frontends-2026-05-18/wave-1/RESUME.md` with baseline counts

### W2 — Data layer + Wizard FSM audit (parallel 2 sub-systems, ~30 min)
- **Scope** : T-M1.1.* + T-M1.2.* + T-W2.1.* + T-W2.2.* (sub 1.1 + 1.2 + 2.1 + 2.2)
- **Parallelism** : 5 read-only specialists in single message dispatch (Architect + Security + Data-Layer + Standalone-Parity + Implementer)
- **Checkpoint** : 6-point per skill (existing G/H/I/J/L/M/N/D/E PASS + frozen diff 0 + RED dispute closed + heal max 3 loops)
- **Interrupt-resume** : per-task evidence bundle written incrementally

### W3 — UI/UX page-by-page audit + heal (sequential per surface, ~1h)
- **Scope** : T-M1.3.* + T-W2.3.* (sub 1.3 + 2.3)
- **Parallelism** : per surface sequential (mobile then web), but per page within surface : Architect + UX/A11y + RED dispatched parallel
- **Checkpoint** : visual mandate gate per page (Read+analyzed screenshot), 0 raw labels, 0 fictional refs, branding cohérence
- **Interrupt-resume** : per-page screenshot saved + analysis written

### W4 — A11y + Perf + Security sweep (sequential, ~30 min)
- **Scope** : T-M1.4.* + T-W2.4.* (sub 1.4 + 2.4)
- **Parallelism** : axe spec run multi-viewport per surface
- **Checkpoint** : axe 0 critical / 0 serious. FCP measured. Security smell-free.
- **Interrupt-resume** : axe.json + perf.json per surface saved

### W5 — Full E2E visual capture each page each step (sequential, ~45 min)
- **Scope** : Run all existing specs + new specs authored in W2-W4 + visual sweep per page per surface
- **Parallelism** : QA Visual + RED Visual parallel post-capture
- **Checkpoint** : Convergence Evidence Bundle per page assembled. 2 consecutive clean rounds (P0+P1=0) per skill convergence rule.
- **Interrupt-resume** : per-page bundle persisted

### W6 — Final adversarial RED + security review + ship verdict (sequential, ~20 min)
- **Scope** : 1 final RED-team subagent hostile dispute across both surfaces + 1 Security review subagent + write FINAL_VERDICT
- **Parallelism** : 2 sub-agents in single message (RED + Security)
- **Checkpoint** : 0 P0 résiduel + Convergence Evidence Bundle final + frozen-zone diff 0 per-file verified
- **Interrupt-resume** : FINAL_VERDICT.md persisted

### Convergence-failure protocol
Max 3 healing cycles per problem cluster. Loop 3 fails → dispatch debug subagent (superpowers:systematic-debugging Phase 1) → if still unclear loop 4 escalate to owner.

---

## §G — Owner Gates

Pour ce cycle standalone, owner a donné carte blanche : pas de gate bloquante.

| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G-NULL | Aucun gate requis | N/A | N/A | N/A | N/A (carte blanche owner) |

Le cycle exécute autonomement jusqu'à GO V1 unconditional. Final verdict présenté à owner pour review + commit + push manuel.

---

## §R — References

- `~/.claude/skills/ultra-architect-planify/SKILL.md` (ce skill — plan template)
- `~/.claude/skills/ultra-audit-profond/SKILL.md` (per-task 20-step pipeline)
- `~/.claude/skills/superpower-gstack/SKILL.md` (FoodKing composition)
- `~/.claude/skills/test-e2e/SKILL.md` (dual-team adversarial visual)
- `~/.claude/skills/superpowers:dispatching-parallel-agents/SKILL.md` (single-message multi-Agent dispatch)
- `CLAUDE.md` §6 (visual mandate) + §7 (frozen zones)
- Existing reports : `reports/audit/longterm-goal-2026-05-17/FINAL_VERDICT.md`, `reports/audit/massive-logic-2026-05-17/FINAL_VERDICT.md`, `reports/test-e2e/max-audit-2026-05-18/CONVERGENCE_FINAL.md`

---

## §F — Final Rule (DONE criteria)

Cycle DONE quand :
- [ ] Tous W1-W6 closed (6-point checkpoint chacun)
- [ ] 0 P0 / 0 P1 résiduel adversarial final
- [ ] Convergence Evidence Bundle published per task
- [ ] 0 ligne diff sur 12 fichiers frozen central system (verified per-file `git status --short`)
- [ ] E2E suites mobile + web GREEN sur 2 runs stables consécutifs
- [ ] Visual mandate fired per page per surface (screenshots Read+analyzed)
- [ ] Security review smell-free
- [ ] `reports/audit/ultra-frontends-2026-05-18/FINAL_VERDICT.md` written
- [ ] NO PROJECT_BRAIN.md / MEMORY.md / Graphiti touched (per owner constraint — autre session goal en cours)
- [ ] Owner peut commit + push manuel

🟢 Production-perfect (not "almost there"). Mobile + Web standalone Le Cayenne prêts démo + iteration design.

— Fin du plan —
