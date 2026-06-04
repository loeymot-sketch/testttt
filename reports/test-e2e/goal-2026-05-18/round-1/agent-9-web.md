# Agent 9 — Web Standalone Specialist (Round 1)

**Mission** : READ-ONLY Phase A audit `/Users/1millnonstop/Downloads/web/` × 4 viewports, 7 sub-systems W.1–W.7.
**Baseline** : Cycle 2026-05-17 GREEN 32/32 (`tests/e2e/test-e2e-website-realignment-2026-05-16.spec.js`).
**Posture** : STANDALONE site, no wireup proposals. Pickup-only. Stripe mocked.

---

## 1. Anchor verification

| Asset | State |
|---|---|
| Web root `/Users/1millnonstop/Downloads/web/` | 9 JSX + index.html + 6 CSS + assets/ + data/ + README (3391 LOC total) |
| `data/menu.js` (493 LOC) | 11 cats / 41 items / 11 sauces / 4 meats / 9 supplements / 4 supp-bols / Pepper 0/500/1500/5000 / earn_ratio=1 |
| `assets/menu/` | 190 PNG (mirror mobile) |
| `wizard-v2.jsx` (515 LOC) | 4 templates : sandwich / tacos / custom (Bols+Frites) / simple via DirectAddView |
| Spec | `test-e2e-website-realignment-2026-05-16.spec.js` 13 tests × 4 viewports = 52 cells (was 32; extended L/M/N/O/P MASSIVE-LOGIC) |
| Config | `tests/web-e2e/playwright.config.js` viewports 390 / 768 / 1280 / 1920 |
| Screenshots | 16 baseline (A01-home / B01-menu / Z01-home / Z02-menu × 4 viewports) |

**Viewport drift (P3 doc)** : Plan §W = `mobile 375 / desktop 1366`. Config = `mobile 390 / desktop 1280`. Functionally equivalent breakpoints. Round-1 attests as-implemented.

**Route inventory** : 8 SPA routes (`home/menu/orders/loyalty/about/checkout/payment/confirm/track`), NOT 23+ pages. Effective surface ~80 rendered states via sub-tabs/modals. P3 §W language drift.

---

## 2. W.1 — Landing + Hero + Brand

**Files** : `screens.jsx::WebHome` (71-409), `components.jsx::WebNav/WebFooter`.

- **GREEN** : Hero (OUVERT badge / h1 "Sandwich. Tacos. Bols. Galette." / 2 CTAs / search input redirecting to menu / 3 stats 30sec/11h-00h/1€=1pt), marquee 8 canonical items × 3 loops, Featured Big Cayenne 9,50€ matches canonical, Why-us / Testimonials / Hours / App-CTA / Stats / Press / Compare / FAQ Home — all aligned canonical Le Cayenne. Confirmed `desktop-A01-home.png` + `mobile-A01-home.png`.
- **P2 W.1.3 daily-deal copy drift** : "Sandwich Cayenne + Menu à 9,00 €" (line 173 screens.jsx) implies promo but Sandwich Cayenne 7,50€ + f-menu 2,50€ = **10,00 €** canonical. The 9€/strike-11€ has no promo logic backing. Owner-gate : implement `DAILY_DEAL` flag OR fix copy.
- **P1 W.1.7 Lighthouse perf blocker** : index.html loads `babel-standalone` (~3MB) + React dev UMD + 6 CSS via CDN, in-browser JSX compile. Perf ≥80 unachievable without Vite/Rollup build step + production React. Owner-gate Phase 6.
- **P3 W.1.6 a11y** : nav buttons lack `aria-current="page"` on active route.

---

## 3. W.2 — Catalog Browsing

**Files** : `screens.jsx::WebMenu` (412-510), `ItemCard` (33-68), `screens-v3.jsx::ItemDetailModal`.

- **GREEN** : "11 catégories · 41 créations" displayed (matches canonical). Side aside (desktop) + chips (mobile) + search (name+desc) + diet filters AND-logic + counter + empty state + reset. `ItemCard` uses `<img loading="lazy">` with **emoji fallback on 404** (line 42-46). Spec test F confirms 10/10 critical photos HTTP 200.
- **P1 W.2.6 mock nutri values** : `ItemDetailModal` defaults `{kcal:720, p:32, g:38, c:56}` for every item (no `item.nutri` ever set in menu.js, verified). Misleading nutritional disclosure. Owner-gate : remove block or seed real values.
- **P2 W.2.5 a11y** : search input has no `<label>` (placeholder-only).

---

## 4. W.3 — Wizard Composer (4 templates × 4 viewports)

**File** : `wizard-v2.jsx` (515 LOC), REWRITE Wave 4 cycle 2026-05-17.

- **GREEN dispatch** : `buildSteps(item)` reads `item.wizard_template || cat.wizard_template || 'simple'`. 4 cases handled.
- **GREEN sandwich/tacos** : viandes (min=max=`item.viande_count`, Big Cayenne 2/Tacos L 2/others 1), sauce ONLY if `!sauce_locked && has_sauce` (Cayenne items skip), crudités ONLY if `has_crudites` (tacos skip), supplements max=6 with allergens, menu addon → cascade_drink + cascade_frites_style via `getActiveSteps` (152-178).
- **GREEN custom (Bols)** : `composer_profile.steps[]` → sauce (pre-filled from `bol_sauce_default`, rename-resistant fallback heal MASSIVE-LOGIC 2026-05-17) + bol_supplements (max=4, includes Boule gratinée +2€) + bol_drink (`__none` sentinel filter).
- **GREEN custom (Frites)** : single `frites_style` step, default `__nature`.
- **GREEN simple** : `baseSteps.length===0` → `DirectAddView` (qty stepper × item.price). MASSIVE-LOGIC heal : qty propagated to cart subs count.
- **GREEN allergens aggregation** (256-269) : item + radio + multi sources, displayed Recap + live preview. Spec test P confirms FIC 1169/2011 propagation (oeuf/lactose).
- **GREEN pricing** : `computeWizardTotal` maps state → `priceFor` opts. Spec tests E+H+L confirm bowlFullCombo=13,30 / sandCayenneMenu=10,00 / multi-sauce edges.
- **P1 W.3.9 visual sweep missing** : §W.3 demands "4 templates × 4 viewports = 16 GREEN" — currently spec D attests **logically** via 4-project run but **no per-template per-viewport screenshot** in baseline. Round-2 must add 16+ captures (sandwich/tacos/bol/frites step-1 × 4 viewports).

---

## 5. W.4 — Cart + Checkout + Payment

**Files** : `flows.jsx::CartDrawer` (8-106), `funnel.jsx` (107-458).

- **GREEN CartDrawer** : 3 pickup slots, promo (only `CAYENNE10`; owner memory says `WELCOME10+CAYENNE` — verify canonical), notes, totals, Pepper preview.
- **GREEN CheckoutPage** : 5 days × 6 slots picker, promo re-validation, notes, pickup location card. `disabled={!ctx.slot}` gate.
- **GREEN pickup-only context** : no delivery toggle — per FAQ_HOME line 110 "Non, on est pickup-only". §W copy "delivery vs pickup choice" doesn't apply; P3 doc drift in §W.
- **GREEN PaymentPage** : 4 methods (counter recommended ★ / card with 4242 test prefill / Apple / Google) + security banner + auth-bonus banner for guests.
- **P1 W.4.5 Stripe FULLY MOCKED** : zero `loadStripe` / `https://js.stripe.com` / `confirmCardPayment` in any JSX. Card form decorative. Clicking "Payer" calls `setRoute('confirm')` directly. Copy "Stripe · 3D-Secure" misleading. Owner-gate : amend copy to "Paiement simulé V1" OR wireup `@stripe/stripe-js` Phase 6 (B6-04).
- **GREEN ConfirmationPage** : confetti + random orderId `C-####` + TicketQR procedural mock (NOT real scannable QR) + pickup time + total.
- **GREEN TrackingPage** : 4 stages auto-advance via setTimeout 6000ms (no real backend polling, B6-05 deferred).
- **P2 W.4.8 card form a11y** : labels present but no `autocomplete="cc-*"` attributes.
- **P3 W.4.7 trackingPage hardcoded `C-1234`** (line 426) doesn't reuse ConfirmationPage random orderId.

---

## 6. W.5 — Order Tracking + Account + History

**Files** : `account-v2.jsx::AccountFlow` (12-235), `orders.jsx::OrdersPage` (13-73).

- **GREEN AccountFlow** : 5 modes (login/signup/otp/success/forgot), validations (email contains @., phone ≥9 digits, password ≥4 chars), demo OTP `1234` displayed inline (line 206) — mock for Phase 6 B6-03 Twilio.
- **GREEN OrdersPage** : 5 PAST_ORDERS aligned canonical (Big Cayenne XL / Bowl Frites Curry+Gratiné / Tacos L / Sandwich Cayenne+Menu / Chicken Burger). Auth gate present.
- **P2 W.5 mocks consolidation** : ProfileTab values hardcoded (Ikyes/+33 6 12 34 56 78/Visa 4242), Social login buttons (Google/Apple) have no handlers, Recommander opens drawer without pre-filling cart with order items, **Logout `onLogout={()=>{}}` no-op** in screens.jsx line 716, referral "Copier" button has no `navigator.clipboard.writeText`. All acceptable for V1 mock pilot; Round-2 minimum heal : either disable dead buttons or wire local-only handlers.

---

## 7. W.6 — Loyalty + Rewards

**Files** : `screens.jsx::WebLoyalty` (539-720), `loyalty-v2.jsx`, `screens-v3.jsx::Leaderboard/Challenge`.

- **GREEN tier alignment** : TIERS = Novice@0 / Pepper@500 / Master@1500 / Légende@5000 mirrors `PEPPER_CLUB.tiers` exactly. Spec G asserts both.
- **GREEN earn ratio** : `earn_ratio=1` (D1 canonical). Copy consistently "1 € = 1 pt".
- **GREEN REWARDS** : 5 rewards (Grande Frites 300pt / Boisson 200pt / Bowl Gourmand 800pt / Sandwich Cayenne 1000pt / Big Cayenne XL -50% 2000pt) — all canonical items. Lock state at points<cost.
- **GREEN wallet** : QR mock (`WebQR` 13×13 procedural NOT scannable), identifier `LECAY-347-A9F2C`, tier progress bar.
- **GREEN tabs** : Rewards / History (4 mock entries) / How (4 bullets + RGPD) / Profile (LoyaltyProfileTab nested).
- **GREEN Leaderboard + Challenge + Achievements** : all mock, aligned canonical names.
- **P3 W.6.10/11** : Rewards "Échanger" button + Referral "Copier" have no `onClick`. Add at minimum stub toast "Phase 6".

---

## 8. W.7 — Pages Support (legal, FAQ, contact, allergens)

**Files searched** : `WebAbout` + `WebFooter` + `FAQ_HOME` + `FAQ_ABOUT` + `ItemDetailModal` + grep.

- **P1 W.7.1 NO dedicated legal pages** : zero routes for `/cgv`, `/cgu`, `/mentions-legales`, `/confidentialite`, `/contact`, `/allergens`, `/rgpd`. Footer text `© 2026 LE CAYENNE · CGU · CONFIDENTIALITÉ` is plain `<span>`, NOT links. Account modal CGU `<u>CGU</u>` line 140 non-clickable. **France e-commerce compliance gap (Article L221-5 Code de la consommation + Article 19 LCEN)** — V1 user-facing public site needs dummy pages with placeholder text + RGPD opt-out minimum, OR explicitly marked "DEMO V1 — non-public". Owner-gate P1 BLOCKER for public launch.
- **P2 W.7.2 no /allergens consolidated page** : disclosed per-item (ItemDetailModal + wizard recap + live preview + DirectAddView) — FIC 1169/2011 article 9 compliant minimum, but consolidated page would harden.
- **GREEN W.7.3 FAQ embedded** : Home 5 Q + About 4 Q covering livraison/loyalty/végé/paiement/carte/équipe/produits/embauche/événements.
- **GREEN W.7.4 contact** : Footer phone+email+address+hours. About TeamStrip Abdoullah/Karim/Léa. No `/contact` form — likely OK V1 pilot.
- **GREEN W.7.5 footer brand text** : aligned canonical.
- **P3 W.7.6 App store CTAs dead** : Footer iOS/Android buttons no `href`/`onClick`. Hide or "Bientôt".

---

## 9. Visual capture specs (Round-2 plan)

Baseline dir : `tests/e2e/__screenshots__/test-e2e-website-realignment-2026-05-16/`.
Existing : 16. **Round-2 add ~22 named captures × 4 viewports = 88 new screenshots** :
- W.3 : 6 wizard captures (sandwich/tacos/bol/frites/simple/recap) × 4 = **24** (P1, fulfills §W.3 16-cell)
- W.4 : 6 captures (cart-drawer / checkout-day / payment-methods / payment-card / confirm-ticket / tracking-cooking) × 4 = **24** (P1)
- W.5 : 3 captures (orders-list / account-signup / account-otp) × 4 = **12** (P1)
- W.6 : 4 captures (loyalty-unauth / wallet / rewards-tab / profile-tab) × 4 = **16** (P1)
- W.7 : 1 footer × 4 = **4** (P2)
- W.1/W.2 polish : 4 × 4 = **16** (P3)

Spec extension : `test-e2e-website-realignment-2026-05-16.spec.js` add W/C/O/L test blocks. Boot : `python3 -m http.server 8082 --directory /Users/1millnonstop/Downloads/web`. Run : `npx playwright test --config=tests/web-e2e/playwright.config.js [spec] --workers=1`.

---

## 10. Acceptance gate

**32/32 baseline maintained** : ATTESTED LOGICALLY by READ-ONLY audit (0 code change). Spec actually runs 13 tests × 4 viewports = **52 cells** (not 32 — L/M/N/O/P MASSIVE-LOGIC additions). Re-run baseline pre-Round-2 to confirm.

**Round-1 NO-CODE-CHANGE gate** :
- 0 frozen-zone touches (web standalone has no V1-central overlap).
- 0 wireup added.
- All findings deferred to Round-2 implementation owner-gate.

**Severity** :
| Sev | Count | Items |
|---|---|---|
| P0 | 0 | — |
| P1 | 5 | W.1.7 perf · W.2.6 mock nutri · W.3.9 16-cell visual gap · W.4.5 Stripe mock copy · **W.7.1 NO legal pages (LCEN compliance blocker for public launch)** |
| P2 | 8 | W.1.3 daily-deal copy · W.2.5 search label · W.4.8 cc-autocomplete · W.5.2 social dead · W.5.3 reorder · W.5.5 ProfileTab mocks · W.5.6 logout no-op · W.7.2 /allergens page |
| P3 | 6 | viewport doc drift · §W route count · W.1.6 aria-current · W.4.7 orderId reuse · W.6.10/11 dead buttons · W.7.6 stores |

---

## 11. Cross-system flags

### Web STANDALONE — VERIFIED via grep

```
grep -E "axios|fetch\(|api/|localhost:8000|127.0.0.1:8000|/api/frontend|api\.lecayenne|stripe\.confirm|loadStripe" .../web/*.jsx .../web/*.html
→ zero matches
```

```
grep -E "Box Nashville|Cheese Smash|Le Gourmet|Bowl Cheesy|Wrap Poulet|Box Familiale|Bowl Veggie" .../web/*.jsx .../web/*.html .../web/data/menu.js
→ zero matches (anti-fiction clean)
```

**Confirmed** : No API endpoints, no backend wireup, no Stripe SDK, no real-time push, no SMS — all client-only mocks. External loads = Google Fonts + React UMD CDN + babel-standalone CDN only (rendering deps, not app API).

**No accidental wireup detected**. Phase 6 B6-01..B6-08 backlog remains deferred. Round-2 implementer can extend visual coverage + heal P1/P2 UX dead-buttons without any wireup work.

---

**End Agent 9 Round-1. READ-ONLY. P0=0 / P1=5 / P2=8 / P3=6. Standalone gate maintained. 52-cell baseline attested logically. W.7.1 (no legal pages) is the only LCEN/L221-5 production blocker before public V1 launch.**
