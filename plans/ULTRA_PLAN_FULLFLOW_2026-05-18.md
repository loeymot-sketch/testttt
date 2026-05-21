# ULTRA-PLAN — Full Flow Coverage Le Cayenne (Mobile + Web Standalone)
**Date** : 2026-05-18
**Skills composés** : `ultra-architect-planify` + `ultra-audit-profond` (per-task) + `test-e2e` (convergence loop)
**Scope** : 40+ tâches couvrant FULL FLOW accueil → menu → wizard → cart → paiement → commande livrée/pickup → loyalty
**Constrainte critique** : autre session goal en cours → AUCUN touch PROJECT_BRAIN.md / MEMORY.md / Graphiti / processus du goal session

---

## §0 — Preamble

### §0.1 — Working-tree
In-place sur branche courante. Aucun commit auto. Owner décide commit final.

### §0.2 — Anchor verification (2026-05-18 via `grep -lE`)
**Mobile** : `screens-onboarding.jsx` (Splash/Onb1-4/Login/OTP) + `screens-main.jsx` (Home/Menu/Cart/Confirm/Orders/OrderDetail/Profile/Loyalty + TabBar) + `screens-item-steps.jsx` (Wizard FSM 4 templates + DirectAdd + 9 ScreenStep*) + `screens-modals.jsx` (ModalPayChoice/PointsGain/CardLink/OptOut/Wallet/Toast/Stripe) + `components/WizardRedeem.jsx` (loyalty redeem 3-step) + `components/LoyaltyQR.jsx` + `components/BarcodeMock.jsx` ✓

**Web** : `screens.jsx` (WebHome/WebMenu/WebLoyalty/WebAbout + ItemCard) + `screens-v3.jsx` (ItemDetailModal + Press/Compare/FAQ/Leaderboard/Challenge/Team) + `wizard-v2.jsx` (WizardFlow + buildSteps + DirectAddView) + `flows.jsx` (CartDrawer) + `funnel.jsx` (Checkout/Payment/Confirm/Track) + `account-v2.jsx` (AccountFlow login/signup/social/OTP/forgot) + `loyalty-v2.jsx` (LoyaltyProfileTab) + `orders.jsx` (OrdersPage) + `components.jsx` (WebNav/WebFooter/WebModal + WC_I icons + WebQR) ✓

**Tests** : 17 mobile spec + 52 web spec × 4 viewports + 15+ mobile loyalty specs existants ✓

### §0.3 — Per-task pipeline
Chaque T-X.Y.Z exécutée via `ultra-audit-profond` (20-step / 10 gates). Non re-décrit ici.

### §0.4 — Convergence per cycle
`test-e2e` skill convergence rule : 2 consecutive rounds P0+P1=0 avec findings sets identiques → DELIVER.

---

## §1 — Système 1 : Mobile App Le Cayenne (PWA, 390×844)

### Contract
HTML+React+Babel-standalone, no build. `mobile/data/menu.js` SSOT mirror DB seed commands. Full flow client-side: 20 écrans + 7 modals. localStorage persistence. Loyalty Pepper Club mock (10:1 ratio per loyalty.js legacy).

### Frozen zones (central system NEVER touched)
Voir CLAUDE.md §7 + `memory/reference_frozen_zones.md`. 12 fichiers. Diff 0 ligne strict.

### Décomposition en 4 sub-systèmes × 5 tâches = 20 tâches mobile

#### Sub M1.1 — Onboarding + Auth flow (5 tâches)
**Anchors** : `mobile/screens-onboarding.jsx` (316 LOC)

- **T-M1.1.1** Audit ScreenSplash — branding "LE CAYENNE" + logo + tap-to-start
   • anchor : `mobile/screens-onboarding.jsx:ScreenSplash` (search needed)
   • test : (test TO BE CREATED at `tests/e2e/test-e2e-mobile-fullflow-onboarding.spec.js`)
   • visual : `http://127.0.0.1:8081/index.html` (boot screen if !auth && !onboarding-seen)

- **T-M1.1.2** Audit ScreenOnb1 — V2 EST.2024 medallion 60×60 ink-bg yellow text, headline + dots progression
   • anchor : `mobile/screens-onboarding.jsx:ScreenOnb1`
   • test : (in same fullflow-onboarding spec)
   • visual : after splash tap

- **T-M1.1.3** Audit ScreenOnb2/Onb3 — V2 check medallion + Onb4 starburst rays + skip CTA + dots
   • anchor : `mobile/screens-onboarding.jsx:ScreenOnb2,Onb3,Onb4`
   • test : (same spec)

- **T-M1.1.4** Audit ScreenLogin — phone input format FR (06 prefix), "06 42 79 98 84" placeholder, validation client-side, CTA disabled until ≥10 digits, dev-helpers gated
   • anchor : `mobile/screens-onboarding.jsx:ScreenLogin` (line ~199)
   • test : (TO BE CREATED at `tests/e2e/test-e2e-mobile-login-validation.spec.js`)

- **T-M1.1.5** Audit ScreenOTP — 4-digit input numeric-only, auto-submit at length=4, dev code "1234" gated by `window.LC.isDev`, success → set auth + go home
   • anchor : `mobile/screens-onboarding.jsx:ScreenOTP` (line ~246)
   • test : (same spec)

**Acceptance Sub M1.1** : NEW spec `test-e2e-mobile-fullflow-onboarding.spec.js` 5+ tests GREEN. Visual captures of 6 screens (splash, onb1-4, login, otp) Read+analyzed.

#### Sub M1.2 — Home + Menu + Item flow (5 tâches)
**Anchors** : `mobile/screens-main.jsx` (ScreenHome + ScreenMenu + bottom TabBar) + `mobile/screens-item-steps.jsx` (ScreenItem entry + ScreenItemWizard + ScreenItemDirectAdd)

- **T-M1.2.1** Audit ScreenHome — hero "BONJOUR IKYES" + Marquee canonical (post heal 2026-05-18 : Sauce Cayenne / Sandwichs faluche / Tacos M&L / Bols / Burgers / Frites / Menu enfant / Prêt 8 min) + featured Sandwich Cayenne 7,50€ + 11-choix badge + 3 cat tiles visible above fold
   • anchor : `mobile/screens-main.jsx:90-220 (ScreenHome)`
   • test : existing `test-e2e-mobile-realignment-2026-05-16.spec.js:80 G data parity + Z visual` ; (extend with explicit Marquee canonical assertion at `tests/e2e/test-e2e-mobile-fullflow-home.spec.js`)
   • visual : home screen capture

- **T-M1.2.2** Audit ScreenMenu — "MENU 11 catégories · 41 produits" + chip row scrollable 11 cats + item grid by cat section + add-to-cart + button + photos resolve
   • anchor : `mobile/screens-main.jsx:240-450 (ScreenMenu)`
   • test : existing A "Home shows 11 cats badge + Menu lists 11 cats" PASS ; (extend at `tests/e2e/test-e2e-mobile-fullflow-menu.spec.js`)

- **T-M1.2.3** Audit ScreenItemDirectAdd — simple cat items (Coca / Glace / Boissons / Menu enfant) qty stepper + qty floor at 1 + total = item.price × qty + add to cart → cart screen
   • anchor : `mobile/screens-item-steps.jsx:1120-1170 (ScreenItemDirectAdd)`
   • test : existing F simple cats direct-add PASS ; (extend at `tests/e2e/test-e2e-mobile-fullflow-direct-add.spec.js`)

- **T-M1.2.4** Audit ScreenItemWizard sandwich/tacos templates — Sandwich Cayenne sauce_locked skips SAUCE, Galette free sauce shows SAUCE, Big Cayenne viande_count=2 enforced, Tacos has_sauce=false skips
   • anchor : `mobile/screens-item-steps.jsx:786-1115 (ScreenItemWizard)`
   • test : existing B sandwich-family + C tacos + O sauce_locked + P viande_count=2 PASS

- **T-M1.2.5** Audit ScreenItemWizard custom-bols (3-step composer) + custom-frites (1-step Nature pre-select) + bol_drink optional
   • anchor : `mobile/screens-item-steps.jsx:786-1115` + composer profile in menu.js
   • test : existing D bols + E frites + N bol-sauce-fallback + K frites Nature pre-select PASS

**Acceptance Sub M1.2** : 5 NEW + existing tests all GREEN. 5 screens captured per visual mandate.

#### Sub M1.3 — Cart + Payment + Confirm + Tracking (5 tâches)
**Anchors** : `mobile/screens-main.jsx:594-780 (ScreenCart + ScreenConfirm)` + `mobile/screens-modals.jsx:40-110 (ModalPayChoice + ScreenStripe + ModalPointsGain)` + `mobile/api/storage.js` (cart persistence)

- **T-M1.3.1** Audit ScreenCart — empty state "Ton panier est vide" + CTA, line items photo + composition_summary + qty stepper + line total, subtotal/discount/total, promo WELCOME10/CAYENNE applies -10% with strike-through, instruction textarea 190 char limit
   • anchor : `mobile/screens-main.jsx:594-715 (ScreenCart)`
   • test : (TO BE CREATED at `tests/e2e/test-e2e-mobile-fullflow-cart.spec.js`) — assert composition_summary visible + promo apply + total recompute + line round-trip

- **T-M1.3.2** Audit cart→pay flow — "Valider ma commande" CTA opens ModalPayChoice OR direct goes to confirm (V0 mock). snapshotOrder creates order { id: C-XXXX, total, eta, items }
   • anchor : `mobile/index.html:snapshotOrder + ModalPayChoice trigger`
   • test : (same fullflow-cart spec) — assert modal opens with 2 options (counter ★ vs Stripe card)

- **T-M1.3.3** Audit ModalPayChoice → ScreenStripe (mock CB form) → ModalPointsGain (+N pts derived from order.total) → loyalty screen
   • anchor : `mobile/screens-modals.jsx:40-110 + 162-180 (ModalPointsGain)`
   • test : (TO BE CREATED at `tests/e2e/test-e2e-mobile-fullflow-payment.spec.js`)

- **T-M1.3.4** Audit ScreenConfirm — yellow celebration + QR mock + order ID C-XXXX + total + eta "~12 MIN" hardcoded OR derived from order.eta + 2 CTAs (Accueil / Suivre)
   • anchor : `mobile/screens-main.jsx:717-780 (ScreenConfirm)`
   • test : (TO BE CREATED at `tests/e2e/test-e2e-mobile-fullflow-confirm.spec.js`)

- **T-M1.3.5** Audit pickup vs livraison logic — V0 mobile pickup-only (per owner V1 dine-in disabled feature flag). Verify ScreenConfirm copy mentions retrait sur place + no delivery flow visible
   • anchor : `mobile/screens-main.jsx:ScreenConfirm` + ScreenOrderDetail
   • test : (same fullflow-confirm spec) — assert "Retrait sur place" or "Pickup" text visible, no "Livraison" UI elements

**Acceptance Sub M1.3** : 3 NEW E2E specs GREEN. Cart→Pay→Confirm full flow click-through verified.

#### Sub M1.4 — Orders + Loyalty + Profile + Modals (5 tâches)
**Anchors** : `mobile/screens-main.jsx:ScreenOrders + ScreenOrderDetail + ScreenLoyalty + ScreenProfile` + `mobile/screens-modals.jsx:WizardRedeem trigger` + `mobile/components/WizardRedeem.jsx` (264 LOC) + `mobile/components/LoyaltyQR.jsx`

- **T-M1.4.1** Audit ScreenOrders — tabs En cours / Historique, active order card with progress bar 4 steps, history grouped by date, re-order CTA (currently doesn't copy cart per cycles précédents = P2 backlog), filter status
   • anchor : `mobile/screens-main.jsx:784-913 (ScreenOrders)`
   • test : (TO BE CREATED at `tests/e2e/test-e2e-mobile-fullflow-orders.spec.js`)

- **T-M1.4.2** Audit ScreenOrderDetail — order id + items + composition_summary + status timeline + reorder CTA
   • anchor : `mobile/screens-main.jsx:ScreenOrderDetail`
   • test : (same orders spec)

- **T-M1.4.3** Audit ScreenLoyalty — HERO Pepper Club tier + POINTS card (RGPD opt-out gated per S-001 cycle B) + ACTIONS grid 3-col + TABS (Récompenses/Historique/Infos) + REWARDS horizontal cards + History dots earn/spend, balance from window.LC.loyalty.account
   • anchor : `mobile/screens-main.jsx:ScreenLoyalty (search needed)` + `mobile/components/LoyaltyQR.jsx`
   • test : existing `tests/mobile-e2e/loyalty-01-earn-order-app.spec.js` + 14 autres loyalty-* specs

- **T-M1.4.4** Audit WizardRedeem 3-step bottom-sheet — step1 reward picker + step2 idempotency 10-min window check + step3 success + ModalOptOutConfirm RGPD opt-out clears balance + history (D-002 cycle B fix)
   • anchor : `mobile/components/WizardRedeem.jsx (264 LOC)` + `mobile/screens-modals.jsx:ModalOptOutConfirm + ModalCardLink`
   • test : existing `tests/mobile-e2e/loyalty-04-redeem-wizard.spec.js` + 11-opt-out + 06-link-plastic-card

- **T-M1.4.5** Audit ScreenProfile — user info + ModalWalletV0Notice apple/google stubs + opt-out CTA + logout flow clears storage.auth
   • anchor : `mobile/screens-main.jsx:ScreenProfile` + `mobile/screens-modals.jsx:ModalWalletV0Notice (apple/google)`
   • test : (TO BE CREATED at `tests/e2e/test-e2e-mobile-fullflow-profile.spec.js`)

**Acceptance Sub M1.4** : 2 NEW + existing 15+ loyalty specs all GREEN. Loyalty redeem full flow + opt-out RGPD verified.

---

## §2 — Système 2 : Site Web Le Cayenne (SPA, 4 viewports)

### Contract
HTML+React+Babel-standalone, no build. `web/data/menu.js` SSOT mirror mobile. Full flow client-side: 9 routes + 4 modals + multi-viewport. localStorage absent (state in React, no persistence). Pepper Club 1:1 ratio per menu.js PEPPER_CLUB.

### Frozen zones
None (web = standalone, no central system shared code).

### Décomposition en 4 sub-systèmes × 5 tâches = 20 tâches web

#### Sub W2.1 — Home + Menu + Item + Wizard (5 tâches)
**Anchors** : `web/screens.jsx:WebHome (88-410) + WebMenu (412-498) + ItemCard (52-83)` + `web/screens-v3.jsx:ItemDetailModal` + `web/wizard-v2.jsx:WizardFlow + DirectAddView`

- **T-W2.1.1** Audit WebHome — hero "SANDWICH. TACOS. BOLS. GALETTE." + WHY 4-points canonical + daily special "Sandwich Cayenne + Menu 9.00€" + featured Big Cayenne XL + testimonials canonical + gallery + hours + insta + marquee canonical
   • anchor : `web/screens.jsx:88-410`
   • test : existing A home canonical sur 4 viewports + (extend at `tests/e2e/test-e2e-website-fullflow-home.spec.js`)

- **T-W2.1.2** Audit WebMenu — sidebar 11 cats + grid items + search + diet filter chips + active count
   • anchor : `web/screens.jsx:412-498`
   • test : existing B menu 11 cats PASS sur 4 viewports

- **T-W2.1.3** Audit ItemDetailModal — nutri info + allergens + popular badge + customize CTA → wizard + add direct CTA → cart
   • anchor : `web/screens-v3.jsx:ItemDetailModal`
   • test : (TO BE CREATED at `tests/e2e/test-e2e-website-fullflow-item-detail.spec.js`)

- **T-W2.1.4** Audit WizardFlow 4 templates (sandwich/tacos/custom-bols 3-step/custom-frites 1-step/simple direct-add) + cascade menu→drink+frites_style+frites_sauce (heal 2026-05-18 H1) + computeWizardTotal
   • anchor : `web/wizard-v2.jsx:WizardFlow + buildSteps + getActiveSteps + computeWizardTotal`
   • test : existing D buildWizardSteps + E computeWizardTotal + (TO BE CREATED at `tests/e2e/test-e2e-website-fullflow-wizard-interactive.spec.js` — actually click through full wizard 5 items)

- **T-W2.1.5** Audit DirectAddView qty stepper — qty preserved to cart line (heal 2026-05-17 H1) + total = item.price × qty + allergen badge if applicable
   • anchor : `web/wizard-v2.jsx:DirectAddView (455-498)`
   • test : (in same wizard-interactive spec) — assert direct-add Coca qty=3 → cart line qty=3, total 4.50€

**Acceptance Sub W2.1** : NEW specs covering 5 ItemDetailModal/Wizard interactive flows × 4 viewports. Photos all resolve (no 404).

#### Sub W2.2 — Cart Drawer + Checkout + Payment (5 tâches)
**Anchors** : `web/flows.jsx:CartDrawer` + `web/funnel.jsx:CheckoutPage (107-220) + PaymentPage (225-327)`

- **T-W2.2.1** Audit CartDrawer side-panel slide-in — empty state, line items + composition subs counter (heal 2026-05-17 filter '__none'), time slots ASAP/20m/40m, promo CAYENNE10 -10%, notes textarea, totals + loyalty pts preview, "Passer commande" CTA
   • anchor : `web/flows.jsx:CartDrawer`
   • test : (TO BE CREATED at `tests/e2e/test-e2e-website-fullflow-cart-drawer.spec.js`)

- **T-W2.2.2** Audit CheckoutPage step 1/2 Pickup — day picker AUJ/JEU/.../DIM (5-day range) + 6 time slots (ASAP/20m/40m/1h/1h30/2h) + promo + notes + pickup location card + Continue CTA disabled until slot
   • anchor : `web/funnel.jsx:107-220 (CheckoutPage)`
   • test : (TO BE CREATED at `tests/e2e/test-e2e-website-fullflow-checkout.spec.js`)

- **T-W2.2.3** Audit PaymentPage step 2/2 — methods counter★ vs card vs Apple Pay vs Google Pay + Stripe card form when method=card (num/exp/cvc/name) + security badge + account upsell yellow banner if !auth + Payer CTA shows total
   • anchor : `web/funnel.jsx:225-327 (PaymentPage)`
   • test : (same checkout spec) — assert all 4 payment methods clickable + CTA changes label

- **T-W2.2.4** Audit cart→checkout flow integration — cart adds bowl + sandwich + coca, click "Passer commande" navigates to checkout, ctx state preserved (cart + promo + ctx.day/slot/method), back button returns to cart drawer
   • anchor : `web/index.html:goCheckout + setCart + setCtx flow`
   • test : (TO BE CREATED at `tests/e2e/test-e2e-website-fullflow-cart-to-checkout.spec.js`)

- **T-W2.2.5** Audit pickup-only vs delivery — V0 web pickup-only per owner D5 default. Verify CheckoutPage shows ONLY pickup location card (no address input), no delivery method option
   • anchor : `web/funnel.jsx:CheckoutPage`
   • test : (in checkout spec) — assert no "Livraison" text/input, "Retrait sur place" prominent

**Acceptance Sub W2.2** : 3 NEW E2E specs × 4 viewports = 12+ tests GREEN. Cart→checkout→payment click-through verified.

#### Sub W2.3 — Confirmation + Tracking + Orders (5 tâches)
**Anchors** : `web/funnel.jsx:ConfirmationPage (332-382) + TrackingPage (387-458)` + `web/orders.jsx:OrdersPage (13-75)`

- **T-W2.3.1** Audit ConfirmationPage — confetti 28 pieces × 12 colors animation + checkmark + order ID C-XXXX + ticket card with WebQR 13×13 + ready time + total + 2 CTAs (Suivre / Accueil)
   • anchor : `web/funnel.jsx:332-382 (ConfirmationPage)`
   • test : (TO BE CREATED at `tests/e2e/test-e2e-website-fullflow-confirm.spec.js`)

- **T-W2.3.2** Audit TrackingPage — status "EN PRÉPARATION" + progress bar 4 steps (Reçu/Cuisine/Prêt/Récupéré) + mock setTimeout 6s advances to "Prêt" + info cards points earned + contact phone + Voir QR CTA
   • anchor : `web/funnel.jsx:387-458 (TrackingPage)`
   • test : (same confirm spec)

- **T-W2.3.3** Audit OrdersPage — auth gate "Connecte-toi" if !auth + history 5 canonical past orders (Big Cayenne / Bowl Curry+Gratiné / Tacos L / Sandwich Cayenne + Menu / Chicken Burger cancelled) + stats (count/total spent/balance) + filter tabs Tout/Livrées/Annulées + cards + re-order CTA
   • anchor : `web/orders.jsx:13-75 (OrdersPage)`
   • test : (TO BE CREATED at `tests/e2e/test-e2e-website-fullflow-orders.spec.js`)

- **T-W2.3.4** Audit auth gate flow — visit OrdersPage when !auth → CTA "Connecte-toi" opens AccountFlow modal → on auth → re-renders OrdersPage with history visible
   • anchor : `web/orders.jsx:14-30 (OrdersPage auth gate)`
   • test : (same orders spec)

- **T-W2.3.5** Audit livraison/pickup confirmation copy — web V0 pickup-only. ConfirmationPage + TrackingPage mention "retrait" / "pickup" / "Hénin-Beaumont" address. NO "livreur" / "delivery person" / "address delivery" copy.
   • anchor : `web/funnel.jsx:ConfirmationPage + TrackingPage`
   • test : (in same confirm spec) — grep visible text for no-delivery assertions

**Acceptance Sub W2.3** : 2 NEW E2E specs × 4 viewports = 8+ tests GREEN. Confirm→track→orders flow verified.

#### Sub W2.4 — Account + Loyalty + About + Cross-cutting (5 tâches)
**Anchors** : `web/account-v2.jsx:AccountFlow (8-237)` + `web/screens.jsx:WebLoyalty + WebAbout` + `web/loyalty-v2.jsx:LoyaltyProfileTab` + `web/components.jsx:WebNav + WebFooter + WebModal`

- **T-W2.4.1** Audit AccountFlow modal — login/signup tabs + social CTAs (Google + Apple stubs) + email + password + phone (signup) + OTP 4-digit numeric-only + forgot password (back to login button now has aria-label per heal 2026-05-18 H4) + error display
   • anchor : `web/account-v2.jsx:8-237 (AccountFlow)`
   • test : (TO BE CREATED at `tests/e2e/test-e2e-website-fullflow-account.spec.js`)

- **T-W2.4.2** Audit WebLoyalty dashboard — Pepper Club tiers (Novice 0 / Pepper 500 / Master 1500 / Légende 5000 per owner D2) + balance card + REWARDS canonical (Frites/Boisson/Bowl Gourmand/Sandwich Cayenne offert/Big Cayenne XL -50%) + tier progress visual + 1€=1pt ratio displayed
   • anchor : `web/screens.jsx:WebLoyalty + REWARDS const + TIERS const`
   • test : (TO BE CREATED at `tests/e2e/test-e2e-website-fullflow-loyalty.spec.js`)

- **T-W2.4.3** Audit LoyaltyProfileTab — profile editor (name/email/phone) + notif settings + saved cards (mock) + prefs + save CTA
   • anchor : `web/loyalty-v2.jsx:LoyaltyProfileTab (141 LOC)`
   • test : (same loyalty spec)

- **T-W2.4.4** Audit WebAbout — l'enseigne text canonical (Abdoullah chef, 14 rue de la République Hénin-Beaumont, 2024 premier menu Sandwich Cayenne signature) + timeline + values cards 3-points + footer brand canonical
   • anchor : `web/screens.jsx:WebAbout`
   • test : assertion no "smash burgers" stale text (existing protection in test-e2e-website-realignment-2026-05-16.spec.js)

- **T-W2.4.5** Audit cross-cutting — WebNav sticky + burger mobile + cart count badge + WebFooter 4 cols (brand + navigation + contact + legal — LCEN added by parallel goal session) + WebModal generic shell + WebQR mock + multi-viewport responsive (390/768/1280/1920)
   • anchor : `web/components.jsx:40-149 (WebNav + WebFooter + WebModal)` + `web/styles-mobile.css`
   • test : existing 4-viewport multi-project Playwright config + Z visual sweep PASS sur 4 viewports

**Acceptance Sub W2.4** : 2 NEW E2E specs × 4 viewports + cross-cutting visual sweep. AccountFlow + Loyalty dashboard click-through verified.

---

## §A — Agent Army Map

| Rôle | Subagent type | Tools | Used for waves |
|---|---|---|---|
| Architect | `general-purpose` | Read-only | W1 audit + W4 RED reconcile |
| Security | `general-purpose` | Read-only | W1 audit + W4 RED |
| UX/A11y | `general-purpose` | Read + axe | W1 audit + W3 visual |
| Standalone-Parity | `general-purpose` | Read | W1 audit (mobile↔web identity) |
| **Cart/Checkout/Loyalty-Flow** | `general-purpose` | Read + Playwright | W1 NEW — specialty for this cycle (full flow audit) |
| Implementer | `general-purpose` | Edit+Write+Bash | W2 heal (sequential per file) |
| RED-team | `general-purpose` | Read-only | W3 hostile dispute |
| QA Visual | `general-purpose` | Read+Playwright | W3 visual capture + analyze |
| RED Visual | `general-purpose` | Read | W3 cross-check QA findings |

### Fan-out matrix
- **W1 Phase A** : 5 read-only specialists SINGLE MESSAGE parallel (Architect + Security + UX/A11y + Standalone-Parity + Cart-Checkout-Loyalty-Flow)
- **W2 Heal** : Implementer subagent sequential per file (write conflicts avoided)
- **W3 Visual gate** : QA Visual + RED Visual SINGLE MESSAGE parallel (independent reads)
- **W4 RED dispute** : 1 RED-team after impl ; max 2 rounds before escalation

---

## §X — Convergence Waves

### W1 — Pre-flight + 5 parallel deep audits (~30 min)
- **Scope** : All 40 tâches across both surfaces, 5 specialists dispatch
- **Parallelism** : 5 sub-agents single-message parallel
- **Checkpoint** : reports written to `reports/audit/fullflow-2026-05-18/wave-1/{architect,security,ux-a11y,standalone-parity,fullflow}.md`
- **Interrupt-resume** : per-subagent report written to disk independently

### W2 — Heal all P0/P1 + author NEW E2E specs (~1h30)
- **Scope** : 8-12 NEW E2E specs covering uncovered flows (onboarding/login/cart/payment/confirm/orders/loyalty/account/about/cross-cutting)
- **Parallelism** : Implementer sequential per file ; multiple specs authored in parallel (no write conflict)
- **Checkpoint** : 100+ tests passing across both surfaces (existing 17+52=69 + ~30-50 NEW)
- **Interrupt-resume** : per-spec persistence

### W3 — /test-e2e skill convergence loop (~45 min)
- **Scope** : Invoke /test-e2e skill : GStack main team + Adversarial supervisor, loop until 2 consecutive clean rounds
- **Parallelism** : QA Visual + RED Visual parallel post-capture
- **Checkpoint** : 2 consecutive cycles P0+P1=0 with identical findings sets
- **Interrupt-resume** : per-round artifacts saved

### W4 — Final adversarial RED + ship verdict (~20 min)
- **Scope** : 1 final RED hostile dispute, write FINAL_VERDICT, frozen-zone verify per-file
- **Parallelism** : sequential
- **Checkpoint** : 0 P0 résiduel, 0 frozen-zone touch
- **Interrupt-resume** : verdict file persisted

### Convergence-failure protocol
Max 3 heal loops per problem cluster → debug subagent (systematic-debugging Phase 1) → if still unclear loop 4 escalate owner.

---

## §G — Owner Gates

| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G-NULL | Aucun gate bloquant (carte blanche owner) | N/A | N/A | N/A | N/A |

---

## §R — References

- `~/.claude/skills/ultra-architect-planify/SKILL.md` (this plan template)
- `~/.claude/skills/ultra-audit-profond/SKILL.md` (per-task 20-step pipeline)
- `~/.claude/skills/test-e2e/SKILL.md` (dual-team convergence loop)
- `~/.claude/skills/superpower-gstack/SKILL.md`
- `plans/ULTRA_PLAN_FRONTENDS_STANDALONE_2026-05-18.md` (precedent cycle this morning)
- `reports/audit/ultra-frontends-2026-05-18/FINAL_VERDICT.md`
- Existing tests : `tests/e2e/test-e2e-mobile-realignment-2026-05-16.spec.js` (17 GREEN) + `tests/e2e/test-e2e-website-realignment-2026-05-16.spec.js` (52 × 4 viewports GREEN) + 15+ mobile loyalty-*.spec.js

---

## §F — Final Rule (DONE criteria)

Cycle DONE quand :
- [ ] 40 tâches T-M*/T-W* couvertes (audit + heal + test)
- [ ] 8-12 NEW E2E specs authored + GREEN
- [ ] 100+ tests passing total (existing 69 + ~30-50 new)
- [ ] 2 consecutive clean rounds P0+P1=0 (/test-e2e convergence)
- [ ] 0 ligne diff sur 12 frozen-zone files (verified per-file `git status --short`)
- [ ] 0 P0 résiduel adversarial RED final
- [ ] Visual mandate fired per page per surface (screenshots Read+analyzed)
- [ ] `reports/audit/fullflow-2026-05-18/FINAL_VERDICT.md` written
- [ ] NO PROJECT_BRAIN.md / MEMORY.md / Graphiti touched (owner constraint)

🟢 Production-perfect full flow coverage. Mobile + Web standalone Le Cayenne ready démo + iteration design + Phase 6 wireup mechanical.

— Fin du plan —
