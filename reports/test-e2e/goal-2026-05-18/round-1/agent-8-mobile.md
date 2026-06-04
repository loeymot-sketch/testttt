# Agent 8 — Mobile Standalone Specialist · Round 1

**Mission** : Phase A audit on Mobile App standalone (§M, M.1–M.6) — production-perfect verification.
**Cycle baseline** : 2026-05-17 GREEN 12/12 E2E (`test-e2e-mobile-realignment-2026-05-16.spec.js`).
**Mode** : READ-ONLY. ANTI-FICTION strict. Mobile stays STANDALONE (no API wireup).

---

## 1. Anchor verification

| Anchor | Status | Path |
|---|---|---|
| Onboarding | OK | `mobile/screens-onboarding.jsx` (316 L) |
| Main screens | OK | `mobile/screens-main.jsx` (1389 L) |
| Wizard | OK | `mobile/screens-item-steps.jsx` (1205 L) |
| Modals (Stripe/PayChoice/OrderDetail) | OK | `mobile/screens-modals.jsx` (26 KB) |
| Menu canonical | OK | `mobile/data/menu.js` (624 L) — 11 cats / 41 items / 11 sauces / 4 viandes / 9 supplements / 4 supplements_bols / composer_profile on bols+frites |
| Loyalty | OK | `mobile/data/loyalty.js`, `loyaltyRewardState.js` |
| Orders mock | OK | `mobile/data/orders.js` (154 L) |
| User profile | OK | `mobile/data/user.js`, `wallet-spec.js` |
| Owner photos | OK | `mobile/assets/menu/generated_chicken-burger.png` (764 KB), `generated_big-burger.png` (750 KB), `signature/cayenne-hero.png` (1.4 MB) |
| E2E spec | OK | `tests/e2e/test-e2e-mobile-realignment-2026-05-16.spec.js` (18 tests A–Z) |
| Existing screenshots baseline | PARTIAL | `tests/e2e/__screenshots__/test-e2e-mobile-realignment-2026-05-16/` (only A01-home, A02-menu-tab, A03-menu-scrolled, Z00-home-overview present) |
| Playwright viewport | OK | 390×844 (iPhone 13), config: `tests/mobile-e2e/playwright.config.js:36` |

---

## 2. M.1 Onboarding + Auth — Findings

Screens audited (`screens-onboarding.jsx`) :
- `ScreenSplash` (00 Splash) — pepper-orange logo, EST.2024, auto-advance 1800 ms or tap.
- `ScreenOnb1` (01 Bienvenue) — yellow hero + slanted BIENVENUE + `assets/menu/signature/cayenne-hero.png` Slot.
- `ScreenOnb2` (02 Vitesse) — black hero "30s" + tenders Slot `generated_tenders-12-pieces.png`.
- `ScreenOnb3` (03 Pickup) — orange hero "VIENS RÉCUPÉRER" + conveyor bags.
- `ScreenOnb4` (04 Fidélité) — black starburst + loyalty card 347 pts + QR card.
- `ScreenLogin` (05 Login) — phone +33 input, country code FR, benefits list, sticky CTA.
- `ScreenOTP` (06 OTP) — 4-digit fieldset+legend (a11y A11-005 fixed), dev demo gate (A-001 fix r2), auto-advance on 4 digits.

**Findings** :
- **NONE — P0/P1/P2** — clean onboarding flow, all 7 screens render via canonical data; no fictional copy; dev affordance properly gated by `window.LC.isDev`.
- **OBSERVATION — P3** — No explicit OS permission asks (geolocation/push/camera). Grep `mobile/` for `permission`, `getCurrentPosition`, `push` returned zero matches outside docs. Mobile is web-prototype (React in iframe `iPhone-13`), not native — permission prompts are out of scope until React Native wrap.

---

## 3. M.2 Catalog browsing — Findings

Screens audited (`screens-main.jsx`) :
- `ScreenHome` (07 Home) — greeting + Marquee + featured signature card + 6 categories grid + featured items strip + nouveautés + restaurant info.
- `ScreenMenu` (08 Menu) — 11 categories filter chips + per-cat sections, line-clamp 2 descriptions, allergen badge (FIC 1169/2011).
- `ScreenItem` (09 Item Detail) — placeholder with redirect to wizard.

Data layer verified :
- 11 categories @ `menu.js:217-229` — `sandwich-cayenne` (sort 1), `galette` (sort 2), `sandwich-classique` (sort 3), `burgers` (sort 4), `tacos` (sort 5), `bols-gourmands` (sort 6, wizard `custom`), `frites` (sort 7, wizard `custom`), `supplements`, `desserts`, `boissons`, `menu-enfant`.
- 41 items total (asserted in spec test G line 119).
- Owner photos integrated correctly :
  - `ITEM_IMG['chicken-burger'] = 'generated_chicken-burger.png'` (764 KB) `menu.js:64`.
  - `ITEM_IMG['big-chicken'] = 'generated_big-burger.png'` (750 KB) `menu.js:65`.
  - `HERO_IMG['sandwich-cayenne-classique'] = 'signature/cayenne-hero.png'` (1.4 MB) `menu.js:111`.

**Findings** :
- **P2 — `screens-main.jsx:109`** — `ScreenHome` featured card uses `findItem('tacos-xxl')` — slug `tacos-xxl` does NOT exist in canonical menu (only `tacos-1-viande` and `big-tacos-2-viandes`). Mitigated by fallback `|| window.LC.menu.items[0]`, but the intent (highlight a signature tacos) silently degrades to "Sandwich Cayenne" (items[0]). Regression risk LOW (fallback works), drift signal MEDIUM (intent lost).
- **P3 — `screens-main.jsx:103`** — Marquee items hardcoded category labels, not derived from `CATS`. Acceptable as marketing copy but drifts if labels rename.
- Other findings : NONE — menu list, sticky cart bar, image lazy-load all clean.

---

## 4. M.3 Wizard composer — Findings (4 templates)

Templates audited (`screens-item-steps.jsx:69-134`) :
- `sandwich` — viandes? → sauce → crudites → supplements → menu → cascade → recap (used by cat 1/2/3/4 — Sandwich Cayenne, Galette, Sandwich Classique, Burgers).
- `tacos` — viandes → sauce → crudites → supplements → menu → cascade → recap (used by cat 5).
- `custom` — driven by `item.composer_profile.steps[]` DB-mirror shape :
  - `has_bol_wizard` → SAUCE → BOL_SUPPLEMENTS → BOL_DRINK → recap (cat 6).
  - `has_frites_style` → FRITES_STYLE → recap (cat 7).
- `simple` — direct-add for cat 8/9/10/11 (Suppléments, Desserts, Boissons, Menu enfant).

Composer profile verified via `buildBolComposerProfile` `menu.js:295-360` + `buildFritesComposerProfile` `menu.js:362-385`. Mirrors DB shape (`item_wizard_profiles` + `item_wizard_steps`) per ProJet brain : owner intent = swap data source at API wireup, render layer unchanged.

P0 healing from 2026-05-17 still intact :
- `buildBolComposerProfile` line 299-308 : bol sauce default lookup with safe fallback to `SAUCES[0]` + console.warn (P0 fix preserved).
- Recap `aggregatedAllergens` `screens-item-steps.jsx:793-820` : FIC 1169/2011 aggregation across item + supps + bol supps + drinks + bol drinks (P0 fix preserved).

**Findings** :
- **NONE — P0/P1/P2** — 4 templates fully canonical, no fictional products, no stale wizard step references. `ScreenItemWizard` (line 988) dispatches via `lcMenu.findItem(itemId)` against canonical items. Test K (Frites pre-selects Nature), test N (Bol sauce default fallback), test O (Sandwich Cayenne sauce_locked sequence), test P (Big Cayenne requires 2 viandes) all green per baseline 12/12.

---

## 5. M.4 Cart + Checkout + Payment — Findings

Screens audited :
- `ScreenCart` (10 Cart) `screens-main.jsx:594-715` — line items with `composition_summary` fallback, promo code wired (WELCOME10/CAYENNE = -10%, P1 fix r2 cluster-7), loyalty banner, upsell strip, sticky checkout with subtotal/discount/total breakdown.
- `ModalPayChoice` `screens-modals.jsx:40-65` — "Payer à la caisse" (cash counter, recommandé) OR "Payer maintenant" (Stripe CB).
- `ScreenStripe` (11b Stripe) `screens-modals.jsx:68-109` — UI mock only (4242 4242 4242 4242), no SDK calls, no real charge.
- `ScreenConfirm` (11 Confirmation) `screens-main.jsx:719-777` — QR ticket + ETA + status.

**Findings** :
- **P3 — `screens-modals.jsx:68`** — `ScreenStripe` is 100% visual placeholder. Card number 4242, expiry 12/28, CVV •••, CTA `go('confirm')` directly. No Stripe SDK loaded, no payment intent. Acceptable for V0 standalone prototype but unclear from external view — owner may believe Stripe is wired. Mobile is **STANDALONE** per GOAL contract, but document this clearly so no future agent assumes payment plumbing exists.
- **NONE — P0/P1/P2** — cart logic intact, promo apply correctly reduces total (cluster-7 fix preserved), checkout button transitions to pay modal, payment options work.

---

## 6. M.5 Order tracking + History + Loyalty — Findings

### M.5.A — Orders (12 Orders + 12b Order detail)

**P0 — `mobile/data/orders.js:33-37, 49-51, 62, 72-75, 85-87, 97-100`** — **FICTIONAL PRODUCTS in mock data drift from canonical menu**. Order history mock references 7 products not present in canonical `menu.js` :
- `Box Nashville` (item_id 2002) — line 35, 87
- `Box Familiale` (item_id 2003) — line 51
- `Box Solo` (item_id 2001) — line 99
- `Le Cheese Smash` (item_id 1001) — line 36, 62
- `Bowl Cheesy` (item_id 4001) — line 37, 75
- `Wrap Poulet` (item_id 5001) — line 74
- `Cookie XL` (item_id 9002) — line 100

Canonical menu items have IDs starting at 101 (Sandwich Cayenne), 201 (Galette), 301 (Sandwich Classique), 401 (Burgers), 501 (Tacos), etc. The item_ids 1001/2001/2002/2003/4001/5001/9002 are **legacy pre-MENU-RESET 2026-05-13** identifiers. When user navigates Orders tab → history, they see products that don't exist in catalog. Click "↻ Refaire" (line 900 `screens-main.jsx`) navigates to `menu` — but the same products cannot be reordered (they're not findable).

**P1 — `mobile/screens-modals.jsx:204-206`** — `ScreenOrderDetail` fallback array hardcodes `Box Nashville / Bowl Gratiné / Frite XXL` when `real` data missing (orderId not found in `LC.orders`). Same fictional product family as P0 above.

**P0 — `mobile/data/loyalty.js:118-119`** — `REWARDS` array IDs 6 and 7 reference fictional item_ids (2001 "Box Solo" / 2003 "Box Familiale") and reward name "Box Solo offerte" / "Box Familiale −50%". Both products don't exist in canonical menu — redemption flow would fail or apply discount to a phantom SKU.

**P1 — `mobile/data/loyalty.js:117`** — Reward id 5 "Burger gratuit (au choix)" payload `{category_id: 2}` — canonically cat 2 = **Galette**, not Burgers (cat 4 = Burgers per `menu.js:221`). Reward label says "Burger" but payload would unlock Galettes if logic ever runs.

**P1 — `mobile/data/loyalty.js:142, 144`** — `HISTORY` mock descriptions reference "Box Nashville" (line 142 description, line 144 "Burger gratuit (Box Nashville −50%)"). Loyalty history screen surfaces these to the user under "Mes points" tab.

### M.5.B — Loyalty (14 Loyalty)

Audited `ScreenLoyalty` `screens-main.jsx:1001-1389`. Architecture sound :
- HERO (LoyaltyQR + countdown + QR/barcode toggle), POINTS (balance + progress), TABS (Mes points / Récompenses / Historique), INFOS.
- Bound to `window.LC.loyalty.account` data layer (DEC-11 in 99_VERDICT.md).
- RGPD opt-out gates POINTS card display correctly.
- Wallet integration STUB only — `mobile/data/wallet-spec.js:43` `v0_mock: true`, Apple/Google buttons open `ModalWalletV0Notice` explaining V0 limitation.
- 20/20 baseline maintained per cycle 2026-05-17 GREEN (cf. project_v1_0_1_hardening memory).

**Findings (loyalty UI itself, separate from data fiction P0 above)** :
- **NONE — P0/P1/P2** — UI logic intact, state machine `loyaltyRewardState.js` correctly derives 7 states (LOCKED/UNLOCKED/SELECTED/APPLIED_NEXT_ORDER/CONSUMED/EXPIRED/REVERSED) from observable inputs.

---

## 7. M.6 Profile + Preferences + Settings — Findings

Audited `ScreenProfile` `screens-main.jsx:916-994` :
- User card bound to `window.LC.user.current` (Ikyes B., +33 6 42 79 98 84, no email).
- Loyalty preview card bound to `LC.loyalty.account` (DEC-11 preserved).
- 7 menu rows : Ma carte fidélité, Moyens de paiement, Notifications, Allergènes (2 actives), Langue, Nous contacter, CGU & Confidentialité.
- Logout button → `go('logout')`.

`mobile/data/user.js` DEFAULT_USER has minimal fields (allergens `['gluten', 'lactose']`, notifications `{push: true, sms: true, email: false}`, language `fr`, loyalty_points 347, member_number `FK-12345`, plastic_card_linked false).

**Findings** :
- **P3 — `screens-main.jsx:931, 979`** — "Édition profil — bientôt disponible" + 6 of 7 menu rows are "bientôt disponible" toasts (Moyens de paiement / Notifications / Allergènes / Langue / Nous contacter / CGU). Only "Ma carte fidélité" routes to `loyalty`. Acceptable for V0 standalone, but the **acceptance gate "profile flow E2E GREEN"** is trivially green (only Logout + Loyalty tap actually navigate). Document for owner clarity.
- **NONE — P0/P1/P2** — no fictional data, no broken flows.

---

## 8. Visual capture specs

Existing baseline spec : `tests/e2e/test-e2e-mobile-realignment-2026-05-16.spec.js`

**Viewport** : 390×844 (iPhone 13, single viewport) per `tests/mobile-e2e/playwright.config.js:36`.

**Tests** (18 total, baseline GREEN 12/12 reported in cycle 2026-05-17) :
- G — Data parity (catalog 11 cats / 41 items / 11 sauces / 4 viandes / 9 supps / 4 bol supps / composer_profile on bols+frites)
- H — Pricing parity (bol 8.90 base / +boule gratinée 10.90 / +coca 12.40)
- A — Home shows 11 cats badge + Menu lists 11 cats
- B — Sandwich family 4 cats share template=sandwich
- C — Tacos wizard template=tacos
- D — Bols Gourmands custom composer 3-step
- E — Frites custom composer 1-step
- F — Simple categories direct-add
- I — Cart line composition for Bowl
- J — Cart round-trip preserves bol fields through localStorage
- K — Frites pre-selects Nature
- L — Aggregated allergens include selected supps + drinks
- M — Multi-sauce pricing (1 free, 0.50€ each extra)
- N — Bol sauce default fallback
- O — Sandwich Cayenne sauce_locked sequence
- P — Big Cayenne requires 2 viandes
- Z — Visual sweep all 11 categories (only Z00-home-overview + Z01-menu-landing captured)

**Existing screenshots** (only 4 files in `tests/e2e/__screenshots__/test-e2e-mobile-realignment-2026-05-16/`) :
- A01-home.png, A02-menu-tab.png, A03-menu-scrolled.png, Z00-home-overview.png

**GAP — Visual coverage SHORTFALL** :
The baseline spec sweeps data parity for 11 cats but only captures 2 visual screenshots (Z00 home + Z01 menu landing). It does NOT visually capture :
- Onboarding 4 screens
- Login + OTP
- Item wizard (any of 4 templates)
- Cart screen
- ModalPayChoice / ScreenStripe / Confirm
- **Orders screen (M.5.A)** — the very screen that would surface fictional Box Nashville/Familiale/etc.
- **Loyalty Rewards tab (M.5.B)** — surfaces "Box Solo offerte" + "Box Familiale −50%" fictional rewards
- Order detail screen
- Profile + Settings

**For Phase B remediation cycle**, additional screenshots needed at 390×844 :
- M.1 : onb1/onb2/onb3/onb4/login/otp (6 captures)
- M.3 : wizard step-by-step for each of 4 templates (bols 3-step + frites 1-step + sandwich 5-step + tacos 5-step = ~20 captures)
- M.4 : cart-full / pay-choice / stripe / confirm (4 captures)
- M.5.A : orders-current / orders-history / order-detail (3 captures)
- M.5.B : loyalty-points-tab / loyalty-rewards-tab / loyalty-history-tab (3 captures)
- M.6 : profile (1 capture)

---

## 9. Acceptance gate

### Baseline 12/12 E2E status
**MAINTAINED on Phase A (this audit is read-only — no code changes).**

### Regression risk for fictional product P0
**CRITICAL OBSERVATION** — The baseline E2E spec G/H asserts canonical `menu.js` parity (cats / items / sauces / composer_profile) but does NOT iterate `mobile/data/orders.js` or `mobile/data/loyalty.js`. **Test G/H can stay green while the user-facing Orders + Loyalty screens display fictional Box Nashville / Box Familiale / Cookie XL / Wrap Poulet etc.**

The 12/12 E2E baseline therefore does **not catch** the M.5 P0 findings. A fictional product cleanup in `orders.js` + `loyalty.js` would NOT regress baseline tests but **would** restore data parity discipline to the standard set by `menu.js` canonical mirror.

### Per sub-system gate verdict
| Sub | Gate | Verdict |
|---|---|---|
| M.1 Onboarding + Auth | E2E onboarding GREEN visual 3 viewports | **PASS (1 viewport actually)** |
| M.2 Catalog browsing | visual catalog GREEN + image lazy-load OK | **PASS with P2 stale `tacos-xxl` ref** |
| M.3 Wizard composer | 4 templates E2E GREEN + composer parity | **PASS** |
| M.4 Cart + Checkout + Payment | checkout E2E GREEN visual | **PASS with P3 Stripe UI-mock note** |
| M.5 Order tracking + History + Loyalty | loyalty 20/20 + history visual GREEN | **FAIL — P0 fictional products in orders.js + loyalty.js** |
| M.6 Profile + Preferences + Settings | profile flow E2E GREEN | **PASS (trivially — most rows are toasts)** |

---

## 10. Cross-system flags

### Standalone confirmation
**CONFIRMED — Mobile is fully STANDALONE.** Verified absence of any wireup to central V1 :
- `grep -rn "axios\|fetch(\|api/v1" mobile/data/ mobile/screens-*.jsx` → only finds documentation comments mapping future endpoints (`/api/v1/frontend/...`) in `// Mapping backend (cf. CONNECTION_PLAN.md)` headers, never live calls.
- All data flows through `window.LC.*` hardcoded JS objects (menu / loyalty / orders / user / wallet-spec).
- `ScreenStripe` is UI placeholder only (no @stripe/stripe-js / @stripe/react-stripe-js import).
- No accidental wireup attempt observed.

### Mobile Data Contract drift (CRITICAL)
The two mock-data files `mobile/data/orders.js` + `mobile/data/loyalty.js` were **NOT updated during 2026-05-13 MENU-RESET + 2026-05-14 HEAL-LIGHT V2 + 2026-05-16 MOBILE-REALIGNMENT cycles**. Only `mobile/data/menu.js` was realigned. As a result, orders/loyalty mocks point to pre-reset item identifiers and product names that no longer exist anywhere else in the codebase.

The owner-stated discipline (cf. project_massive_logic_image_cycle_2026-05-17) requires "100% mobile↔web parity confirmed". This parity is broken at the orders+loyalty data layer. Any Phase B cleanup must heal these two files to mirror canonical 41 catalog items + 11 cats + valid item_ids.

### Recommendations (read-only deliverable, do not fix)
1. **P0 PHASE B** — Heal `mobile/data/orders.js` HISTORY entries to reference canonical item names + IDs from `menu.js` (e.g., `Box Nashville` → `Sandwich Cayenne` id 101; `Bowl Cheesy` → `bowl-frites-curry` id from BOLS array).
2. **P0 PHASE B** — Heal `mobile/data/loyalty.js` REWARDS array IDs 6/7 (remove or repoint to canonical IDs); fix reward 5 `category_id` (2 → 4 for Burgers); heal HISTORY descriptions.
3. **P1 PHASE B** — Heal `mobile/screens-modals.jsx:204-206` fallback to use canonical names (Sandwich Cayenne / bowl-frites-curry / Frites Grande).
4. **P2 PHASE B** — Heal `mobile/screens-main.jsx:109` `findItem('tacos-xxl')` → `findItem('big-tacos-2-viandes')` or `findItem('sandwich-cayenne-classique')` for SIGNATURE featured card.
5. **PHASE B EXTENSION** — Add `Z02–Z10` visual sweeps to baseline spec covering Orders/Loyalty/Profile screens to prevent future data drift hiding in untested surfaces.

---

**END OF AGENT 8 ROUND 1 REPORT** — 1789 words.
