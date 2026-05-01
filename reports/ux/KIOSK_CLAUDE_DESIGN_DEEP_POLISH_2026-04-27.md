# Kiosk Claude Design Deep Polish — Audit + Execution

Date: 2026-04-27  
Task: KIOSK-CLAUDE-DESIGN-DEEP-POLISH-2026-04-27  
Mode: UX audit, targeted frontend implementation, browser verification  
Verdict: PASS_WITH_KNOWN_EXTERNAL_RISKS

## Scope

Objective: compare the Claude prototype (`borne (Remix)/Borne FoodKing.html`) with the active Vue kiosk flow, then improve the active implementation without breaking quote, payment, offline, or backend pricing flows.

Touched product scope:

- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue`
- Existing previous-pass kiosk step polish retained in Sauce, Supplements, Menu.
- `resources/js/languages/fr.json`
- `resources/js/languages/en.json`
- `tests/js/KioskWizard.spec.js`
- `tests/js/KioskCategoriesRestyle.spec.js`
- Compiled bundles under `public/js/*` and `public/mix-manifest.json` via `npm run production`.

Not touched:

- Backend pricing logic.
- Order quote / HMAC flow.
- Payment implementation.
- Offline queue mechanics.
- Stock/menu central API contract.

## Prototype vs Active Audit

| Area | Claude prototype signal | Active UI before polish | Action taken | Status |
| --- | --- | --- | --- | --- |
| Global tone | Full dark red kiosk, high CTA contrast, large touch targets | Already close, but catalog still had split old/new feeling | Kept dark/red system and strengthened category/product hierarchy from previous pass | PASS |
| Category navigation | Strong visual category chips/cards | Sidebar existed, top area lacked prototype-like quick category affordance | Added quick category strip with active state and image/emoji media | PASS |
| Wizard shell | Product customization takes over full attention | Wizard could feel visually mixed with catalogue during opening | Overlay made fixed full-screen with high z-index; browser final states show focused wizard | PASS |
| Meat selection | Card-first choice, not tiny plus-first | User had to target plus too early in previous versions | Card tap selects first unit; plus appears only after selected for repeat portions | PASS |
| Meat quota comprehension | User sees what is included vs paid | Count existed, but the mental model was weak | Added dynamic quota instruction: initial, remaining, complete | PASS |
| Included vs supplement | Paid extras must be obvious | Price badge existed, but included/free state less explicit | Added `Inclus` / `Supplément €x` badges on meat cards | PASS |
| Sauce pricing | First sauce free, extras paid, visible immediately | User reported live price confusion | Browser verified 4 sauces: +3 extras, total updates to €10.00 | PASS |
| Supplements repeat | Customer may want two of same supplement | One-tap first selection plus repeat behavior needed proof | Browser verified Boursin ×2 and total updates to €12.00 | PASS |
| Menu / boisson | Must offer full menu, frites, boisson seule, sans menu | Previous concern: boisson seule and drink list semantics | Browser verified `Boisson seule`, required central drink list, total €13.20 | PASS |
| Recap | Must be readable and trustable before cart | DOM correct; screenshot during transition looked dim, final cart state OK | Browser verified recap content and add-to-cart total | PASS |

## Page-by-Page UX Checklist

### 1. Idle / Start

- Visual direction: dark/red FoodKing style, coherent with Claude prototype.
- User psychology: large start affordance and theme toggle keep the kiosk approachable.
- Current decision: no extra code in this pass; priority was product selection path.
- Remaining: hardware/presence UAT later.

### 2. Categories

- User goal: find category and product fast without reading long instructions.
- Improvement retained: top quick category strip mirrors the sidebar and reduces eye travel.
- Product card hierarchy: image first, product name, subtitle, price, plus button.
- Browser proof: `active-kiosk-categories-polished-2026-04-27.png`.
- Remaining: many product/category images are still repeated or placeholder-like. This is data/media curation, not wizard logic.

### 3. Wizard Shell

- User goal: once product is chosen, the screen must feel like a focused customization flow.
- Improvement: wizard overlay is full-screen fixed with stronger stacking.
- Stepper remains visible for orientation; bottom total remains sticky.
- Accessibility review: native buttons used for nav; focus-visible styles exist; updates use `aria-live` where important.

### 4. Viande Step

- User goal: understand "2 viandes included" immediately and select without precision targeting.
- Implemented:
  - Card tap selects the first unit.
  - Quantity controls appear only after selection.
  - Plus repeats the same meat when quota remains.
  - Dynamic instruction:
    - initial: `2 portions incluses...`
    - after one choice: `Encore 1 portion...`
    - complete: `Viandes complètes...`
  - Card badges: `Inclus` or `Supplément €x`.
- Browser proof:
  - `active-kiosk-wizard-viande-polished-before-choice-2026-04-27.png`
  - `active-kiosk-wizard-viande-polished-after-choice-2026-04-27.png`
  - `active-kiosk-wizard-viande-polished-complete-2026-04-27.png`
- Logical proof:
  - Tacos L starts at `0 / 2`.
  - Tap Merguez => `1 / 2`, plus control visible.
  - Tap plus => `2 / 2`, next step enabled.
- Pricing proof:
  - Included meat repeat keeps total at €8.50.

### 5. Sauce Step

- User goal: see first sauce free, paid sauce count, and live total.
- Browser proof: `active-kiosk-wizard-sauce-polished-price-live-2026-04-27.png`.
- Scenario verified:
  - Ketchup selected as free.
  - Mayonnaise, Algérienne, Curry selected as paid extra sauces.
  - UI shows `+3 sauces supplémentaires (€0,50)`.
  - Sticky total updates from €8.50 to €10.00.
- Risk checked: frontend displays calculated running total only; backend remains pricing SSOT for commit.

### 6. Crudités Step

- User goal: default included garnishes should not slow down the customer.
- Current behavior: selected garnish list flows through recap.
- Browser proof via recap: Salade, Tomate, Oignon included.
- No code change in this pass.

### 7. Supplements Step

- User goal: add paid extras quickly, including two of the same extra.
- Browser proof: `active-kiosk-wizard-supplements-polished-repeat-price-live-2026-04-27.png`.
- Scenario verified:
  - Tap Boursin card => quantity 1, total €11.00.
  - Tap plus => Boursin ×2, total €12.00.
- UX status: first tap is card-wide; repeat uses explicit plus.

### 8. Menu / Boisson Step

- User goal: choose formula clearly; "Boisson seule" must not look like a fake product.
- Browser proof: `active-kiosk-wizard-menu-boisson-only-central-list-2026-04-27.png`.
- Scenario verified:
  - Options shown: Menu Complet, + Frites, Boisson seule, Sans menu.
  - Boisson seule selected.
  - Real drink list shown from the central menu catalog: Capri-Sun, Eau Plate 50cl, Orangina, Oasis, Sprite, Fanta, Coca-Cola Zero, Coca-Cola.
  - Drink selection required before recap.
  - Total updates to €13.20 after Boisson seule.

### 9. Recap / Add To Cart

- User goal: verify order once, trust every supplement and price, add to cart.
- Browser proof:
  - `active-kiosk-wizard-recap-polished-before-cart-2026-04-27.png`
  - `active-kiosk-after-add-to-cart-polished-2026-04-27.png`
  - `active-kiosk-after-add-to-cart-polished-late-2026-04-27.png`
- Scenario verified:
  - Tacos L base €8.50.
  - Merguez ×2 included.
  - 4 sauces: first free + 3 paid.
  - Boursin ×2 = +€2.00.
  - Boisson seule + Capri-Sun = +€1.20.
  - Final total: €13.20.
  - Add to cart succeeds; catalog cart bar shows `1 article` and €13.20.

## Validation Evidence

Browser / Playwright-in-app:

- Prototype reference screenshots:
  - `reports/ux/screenshots/claude-prototype-idle-compare-2026-04-27.png`
  - `reports/ux/screenshots/claude-prototype-wizard-compare-2026-04-27.png`
  - `reports/ux/screenshots/claude-prototype-menu-compare-2026-04-27.png`
- Active verified screenshots:
  - `reports/ux/screenshots/active-kiosk-categories-polished-2026-04-27.png`
  - `reports/ux/screenshots/active-kiosk-wizard-viande-polished-before-choice-2026-04-27.png`
  - `reports/ux/screenshots/active-kiosk-wizard-viande-polished-after-choice-2026-04-27.png`
  - `reports/ux/screenshots/active-kiosk-wizard-viande-polished-complete-2026-04-27.png`
  - `reports/ux/screenshots/active-kiosk-wizard-sauce-polished-price-live-2026-04-27.png`
  - `reports/ux/screenshots/active-kiosk-wizard-supplements-polished-repeat-price-live-2026-04-27.png`
  - `reports/ux/screenshots/active-kiosk-wizard-menu-boisson-only-central-list-2026-04-27.png`
  - `reports/ux/screenshots/active-kiosk-wizard-recap-polished-before-cart-2026-04-27.png`
  - `reports/ux/screenshots/active-kiosk-after-add-to-cart-polished-2026-04-27.png`
  - `reports/ux/screenshots/active-kiosk-after-add-to-cart-polished-late-2026-04-27.png`

Automated tests:

- `npx vitest run tests/js/KioskWizard.spec.js tests/js/KioskCategoriesRestyle.spec.js tests/js/kioskA11yAxe.spec.js`
  - PASS: 3 files, 109 tests.
- `npx vitest run tests/js/Kiosk*.spec.js tests/js/kiosk*.spec.js`
  - PASS: 64 files, 560 tests.
- `npm run production`
  - PASS: Mix compiled successfully.
- `git diff --check` on touched kiosk UI/test files
  - PASS.

Known non-blocking test noise:

- `baseline-browser-mapping` package warns that local data is old.
- Isolated Vue tests log missing Vuex modules/actions in mocked mounts.
- Unit tests log `axios unavailable` for pricing preview fallback.
- Axe unit sentinel logs `ECONNREFUSED ::1:3000` in a mocked local fetch, but tests pass.

## Invariants Checked

- Pricing SSOT: no backend pricing rule moved into frontend. Frontend running total remains preview/UX; backend quote/commit remains authoritative.
- Branch isolation: not touched.
- OrderStatus enum: not touched.
- Dispatch after commit: not touched.
- OrderService / FrontendOrderService: not touched in this UX pass.
- Kiosk payment/offline flows: not changed.
- EventContract: not touched.

## Remaining Risks / Next Corrections

1. Offline banner visible in local browser: `Connexion perdue — hors ligne` appeared during the run. Browser console did not show new JS errors, but this is user-visible and should get a dedicated network/offline-health audit before commercial release.
2. Media curation: product/category images are still repeated in places. The interaction is now much stronger, but final polish needs real menu imagery from the central catalog.
3. Full payment/hardware UAT remains outside this pass: payment is still simulation-oriented per human decision, and printer/TPE/KDS physical validation remains later.
4. Light theme visual audit should be repeated after the dark theme is accepted; current requested path prioritized the dark/red Claude prototype.

## Double Audit

First audit conclusion:

- The biggest customer-risk was not the backend flow; it was cognitive friction in the wizard:
  - first meat choice required too much precision,
  - quota meaning was not explicit enough,
  - paid extras needed immediate proof in the live total.

Second audit after implementation:

- The revised flow matches the customer mental model:
  - choose by touching the card,
  - repeat only when needed with plus,
  - every paid decision updates the sticky total,
  - recap tells the same story as the steps.
- The implementation remains bounded to UI/UX and tests.
- No release claim is made for payment/hardware/offline-health; those remain separate release gates.

FINAL_VERDICT: PASS_WITH_KNOWN_EXTERNAL_RISKS
