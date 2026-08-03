# Audit Plan — Borne Cats 309–318

**Run ID**: `borne-cats-309-318-2026-05-10`
**Branch**: `feature/mobile-app-le-cayenne-2026-05-10`
**Working dir**: `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`
**Surface**: Kiosk only (`/kiosk/idle`, `/kiosk` SPA mounting all sub-routes through Vue Router)
**Catalog scope**: Cats 309–318 except 306/307/308 (sandwich/burger/tacos — owner gate)
**Pricing SSOT endpoint**: `POST frontend/order/quote` (verified `resources/js/store/modules/kioskCart.js:608`)
**Final order endpoint**: `POST frontend/order` (verified `kioskCart.js:675`)
**State budget target**: ~67 PNGs total across 5 waves

---

## Wave A — Idle + Welcome + Categories visual tour

**Spec file**: `tests/e2e/test-e2e-borne-2026-05-10-wave-A.spec.js`
**Screenshots dir**: `tests/e2e/__screenshots__/test-e2e-borne-A/`
**Surfaces / contexts**: idle screen → categories welcome → each visible category browse page (no item open, no cart interaction).
**Estimated states**: 13

### Numbered states

1. `01-idle-screen` — `/kiosk/idle` baseline; verify Cayenne palette, no dark mode flash, language pickable
2. `02-idle-language-fr` — language selector at FR (default)
3. `03-idle-language-en` — toggle to EN, verify i18n catches over
4. `04-categories-welcome` — first paint after idle tap; verify cat 315 NOT in sidebar, count 9 visible categories (309/310/311/312/313/314/316/317/318) — no 306/307/308/315
5. `05-cat-309-assiettes` — cat 309 selected, 4 items shown (Poulet/Kefta/Merguez/Mixte 12.50–14.50€)
6. `06-cat-310-ojja` — 4 items 13.50€
7. `07-cat-311-omelettes` — 3 items 7.50–9.50€
8. `08-cat-312-salades` — 4 items 7.50€
9. `09-cat-313-poulet-croustillant` — 4 items 6–13.50€
10. `10-cat-314-menus-enfants` — 2 items 6€
11. `11-cat-316-desserts` — 3 items 3.80€
12. `12-cat-317-boissons` — 8 items 1–1.50€
13. `13-cat-318-supplements` — 8 items 0.50–2€

### Acceptance criteria

| MUST PASS (P0/P1) | BEST EFFORT (P2/P3) |
|---|---|
| Cat 315 absent from sidebar at every state | Image lazy-load shimmer presence |
| Idle/welcome both render in light mode (no dark flash) | Animation jank on category swap |
| Cat thumbnails load (no broken `<img>`) | Slight layout shift |
| Sidebar uses `data-testid` matching `kiosk-categories-sidebar-item-<id>` (or fall-back to text-by-name) | |
| No console error from kiosk-shell.js | |
| Cayenne primary `#F4501E` not pink `#E8001C` | |

---

## Wave B — Wizard "frites incluses" templates (assiette + omelette)

**Spec file**: `tests/e2e/test-e2e-borne-2026-05-10-wave-B.spec.js`
**Screenshots dir**: `tests/e2e/__screenshots__/test-e2e-borne-B/`
**Surfaces / contexts**: full wizard journey for one item per cat (309 assiette, 310 ojja, 311 omelettes, 314 menus enfants). Verify NO menu step, NO frites_style step. Recap totals must equal item base price + supplements selected.
**Estimated states**: 16 (4 cats × 4 wizard states avg)

### Numbered states

**Cat 309 — Assiette Poulet** (template `assiette` → viande + sauce + garnitures + supplements + recap)
1. `01-309-wizard-step-viande` — viande selection visible; pick Poulet
2. `02-309-wizard-step-sauce` — sauce selection
3. `03-309-wizard-step-garnitures` — garnitures (no menu step in pipeline — verify next is supplements)
4. `04-309-wizard-recap` — récap shows base 12.50€, no menu line, no frites_style line

**Cat 310 — Ojja Bœuf** (template `omelette`)
5. `05-310-wizard-step-sauce`
6. `06-310-wizard-step-garnitures`
7. `07-310-wizard-step-supplements`
8. `08-310-wizard-recap` — verify 13.50€ base, no menu step, no frites_style step

**Cat 311 — Omelette Fromage** (template `omelette`)
9. `09-311-wizard-step-sauce`
10. `10-311-wizard-step-garnitures`
11. `11-311-wizard-recap`

**Cat 314 — Menu Enfant Cheese** (template `omelette`)
12. `12-314-wizard-step-sauce`
13. `13-314-wizard-step-garnitures`
14. `14-314-wizard-recap` — 6€ formule complète

**Common / cart**
15. `15-cart-after-add-309` — add to cart from recap, navigate to cart, verify single line
16. `16-cart-thumbnails-quartet` — verify recap thumbnails (pain/viande/sauce/garniture/supplement) render in cart

### Acceptance criteria

| MUST PASS | BEST EFFORT |
|---|---|
| Each pipeline contains EXACTLY the expected steps (no menu, no frites_style) | Step transition smoothness |
| Cart line total = item base + Σ supplements (P0 numeric_integrity) | |
| Récap thumbnails render (no broken `<img>`) | |
| No console error during wizard flow | |
| Quantity stepper visible | |

---

## Wave C — Wizard "menu upsell" templates (salade + snacking) with P0-1 verification

**Spec file**: `tests/e2e/test-e2e-borne-2026-05-10-wave-C.spec.js`
**Screenshots dir**: `tests/e2e/__screenshots__/test-e2e-borne-C/`
**Surfaces / contexts**: 2 journeys per cat = 4 journeys total. Journey 1 = menu='frites' picks Cheddar Oignons (+2€); Journey 2 = menu='frites' picks Cheddar (+1€) then flips to menu='none' to verify P0-1 fritesStyleExtraId clearing.
**Estimated states**: 14

### Numbered states

**Cat 312 Salade Royale — Journey 1 (menu='frites' + Cheddar+Oignons)**
1. `01-312-wizard-step-garnitures`
2. `02-312-wizard-step-sauce`
3. `03-312-wizard-step-menu-frites` — pick "Frites" radio
4. `04-312-wizard-step-frites-style` — 3 cards visible (Nature 0€ / Cheddar +1€ / Cheddar+Oignons +2€), pick Cheddar+Oignons
5. `05-312-wizard-recap-with-cheddar-oignons` — total = 7.50 + 2 = 9.50€ (P0 numeric_integrity)

**Cat 312 Salade Tunisienne — Journey 2 (P0-1 fritesStyleExtraId clearing)**
6. `06-312-j2-step-menu-frites-cheddar` — pick frites then Cheddar (+1€)
7. `07-312-j2-step-menu-flip-to-none` — go back to menu step, change to 'none'
8. `08-312-j2-recap-no-overcharge` — verify recap shows base price ONLY (7.50€, NO +1€ frites_style line); also dump cart payload via `page.evaluate(() => store.state.kioskCart.items)` to JSON sidecar — verify `fritesStyleExtraId === null` in line item

**Cat 313 Filets Croustillants — Journey 1 (menu='frites' + Cheddar+Oignons)**
9. `09-313-wizard-step-sauce`
10. `10-313-wizard-step-menu-frites`
11. `11-313-wizard-step-frites-style-cheddar-oignons`
12. `12-313-wizard-recap-with-upgrade` — verify +2€ applied (total = 7.50 + 2 = 9.50€ for Filets 6 pcs)

**Cat 313 Ailes 6 — Journey 2 (P0-1 clearing)**
13. `13-313-j2-step-menu-flip-to-none` — same flip pattern
14. `14-313-j2-recap-no-overcharge` — recap + payload sidecar

### Acceptance criteria

| MUST PASS | BEST EFFORT |
|---|---|
| frites_style step appears ONLY when menuChoice='frites' or 'full' | Card hover states |
| 3 frites_style cards visible with prices 0€/+1€/+2€ | |
| **P0-1 verification**: after flip menu→'none', `fritesStyleExtraId === null` in cart payload AND recap shows no upgrade surcharge | |
| Récap total = base + frites_style upgrade (numeric_integrity) | |
| **Sidecar payload dump**: state 08 + 14 must save `*.payload.json` capturing cart line item JSON for adversarial review | |

> **P0-1 anti-bypass note**: A clean PNG is NOT proof the bug is fixed — the payload is what charges the customer. The spec MUST emit a sidecar JSON of the cart store at state 08 and 14, dumped via `page.evaluate(() => __vuex_store.state.kioskCart.items)`. Adversarial review fails if sidecar shows `fritesStyleExtraId !== null` even if recap PNG looks clean. The store may be exposed as `window.__vuex_store` in dev mode; if not, instrument the spec to query via `getApp().__store` or fallback to inspecting the wizard state directly via component instance.

---

## Wave D — Direct-add simple products (boissons + desserts + suppléments)

**Spec file**: `tests/e2e/test-e2e-borne-2026-05-10-wave-D.spec.js`
**Screenshots dir**: `tests/e2e/__screenshots__/test-e2e-borne-D/`
**Surfaces / contexts**: cats 316 (3 desserts), 317 (8 boissons), 318 (8 suppléments). Tap each item, verify direct add (NO wizard popup), verify cart count increments, verify cart line price.
**Estimated states**: 10

### Numbered states

1. `01-d-cat-316-baseline` — desserts grid (3 items), cart count = 0
2. `02-d-316-tap-glace-direct-add` — tap Glace, observe cart count → 1, NO wizard appears (assert `[data-testid=kiosk-wizard-overlay]` absent)
3. `03-d-316-cart-after-3-desserts` — add all 3 desserts; cart shows 3 distinct lines @ 3.80€ each = 11.40€
4. `04-d-cat-317-baseline` — 8 boissons grid
5. `05-d-317-tap-coca-direct-add` — direct add + cart count increment
6. `06-d-317-multi-add-coca-x3` — same item +/- stepper goes to qty 3
7. `07-d-cat-318-baseline` — 8 suppléments grid (Sauce/Fromage/Œuf/Galette etc 0.50–2€)
8. `08-d-318-tap-fromage-direct-add` — verify NO wizard for 318 either (Phase A direct-add)
9. `09-d-bottom-sheet-after-mixed-cart` — open cart bottom sheet; horizontal compact cards (target 280×80px)
10. `10-d-cart-totals-mixed` — final mixed cart total matches Σ(line × qty)

### Acceptance criteria

| MUST PASS | BEST EFFORT |
|---|---|
| Tap on 316/317/318 item → NO `KioskWizardComponent` overlay opens | Toast "ajouté au panier" copy quality |
| Cart count increments by exactly 1 per tap (or qty stepper) | |
| Bottom-sheet card layout = horizontal 280×80px (not vertical 364px) | |
| Cart total = Σ(line items) — no orphan items | |
| No console error on rapid multi-tap | |

---

## Wave E — Cart + Checkout + Payment full journey

**Spec file**: `tests/e2e/test-e2e-borne-2026-05-10-wave-E.spec.js`
**Screenshots dir**: `tests/e2e/__screenshots__/test-e2e-borne-E/`
**Surfaces / contexts**: build a multi-item cart (1 wizard from cat 309 + 1 wizard from cat 312 with menu='boisson' + 1 supplement + 1 boisson), then full checkout: cart → loyalty → payment method → TPE waiting → cash instruction (alt branch) → confirmation. Loyalty inscription button visibility tested here.
**Estimated states**: 14

### Numbered states

1. `01-e-cart-multi-line` — 4 lines visible: Assiette Poulet 12.50€, Salade Royale w/ menu boisson, Supplément Fromage 1€, Coca 1.50€
2. `02-e-cart-bottom-sheet-open` — open horizontal bottom-sheet
3. `03-e-cart-qty-increment` — +/- stepper changes line subtotal AND grand total in lock-step (P0)
4. `04-e-cart-totals-ssot` — verify cart-displayed total === backend `frontend/order/quote` response total (capture network response as sidecar; correlated against displayed grand total)
5. `05-e-cart-eat-in-takeaway-mode` — mode selector visible
6. `06-e-loyalty-step-input` — loyalty page; verify loyalty inscription button visible (yellow gradient `#F5C518→#E0B214`, NOT invisible white text on white bg)
7. `07-e-payment-method-select` — payment method screen; CB and Espèces buttons visible, Cayenne primary palette
8. `08-e-payment-tpe-waiting-overlay` — TPE waiting overlay; light mode background (white), Cayenne accent #F4501E (NOT dark mode regression)
9. `09-e-payment-tpe-result-ok` — simulate success; navigation to confirmation
10. `10-e-confirmation-success` — confirmation shows order number + total = cart total (P0 numeric_integrity cross-surface)
11. `11-e-cash-alt-branch-instruction` — alternate flow: from state 07 pick Espèces; cash instruction page displays vertical-centered instruction (1fr auto grid, not crammed top)
12. `12-e-cash-instruction-amount-display` — amount shown matches cart total
13. `13-e-confirmation-after-cash` — confirmation reached via cash branch
14. `14-e-back-to-idle` — confirmation auto-redirects (or via button) to `/kiosk/idle`

### Acceptance criteria

| MUST PASS | BEST EFFORT |
|---|---|
| Loyalty inscription button visually visible (contrast ratio ≥ 4.5:1, gradient applied) | Animation/spinner timings |
| TPE overlay light-mode bg (no dark theme leakage) | |
| Cash instruction vertical centering verified (1fr auto grid CSS or visual inspection of bounding boxes) | |
| Cart total === payment screen total === confirmation total (P0 cross-surface numeric_integrity) | |
| Bottom-sheet horizontal compact cards 280×80px | |
| Network sidecar at state 04 captures `frontend/order/quote` for SSOT proof | |

---

## Cross-cutting assertions — every critical feature mapped to a wave

| # | Critical feature | Wave / state(s) |
|---|---|---|
| 1 | Cat 315 hidden from welcome | Wave A state 04 (welcome), 05–13 (sidebar item count) |
| 2 | Phase A direct-add for 316/317/318 (no wizard) | Wave D states 02, 05, 08 |
| 3 | Phase B+C frites_style step (3 cards 0/+1/+2€, only when menu='frites' or 'full') | Wave C states 04 (salade), 11 (snacking) |
| 4 | Phase D omelette/ojja flow — NO menu, NO frites_style, frites incluses | Wave B states 05–11 (cats 310/311), state 14 (314) |
| 5 | P0-1 fritesStyleExtraId clearing on menu→none flip | Wave C states 06–08 + 13–14 (with payload sidecar) |
| 6 | Numeric integrity: cart total = Σ(items × qty + supplements + frites_style upgrade) = backend | Wave B state 04, 16; Wave C state 05, 12; Wave D state 10; Wave E state 04, 10 |
| 7 | Récap thumbnails (pain/viande/sauce/garniture/supplement/menu/boisson) | Wave B state 16; Wave C state 05 |
| 8 | Light mode persistence (no dark mode flash) | Wave A state 01 + 04; Wave E state 08 |
| 9 | Cayenne palette `#F4501E` (no pink `#E8001C` drift) | Wave A state 04; Wave E states 07, 08 |
| 10 | Cart bottom-sheet horizontal compact 280×80px (not vertical 364px) | Wave D state 09; Wave E state 02 |
| 11 | Loyalty inscription button visible (yellow gradient) | Wave E state 06 |
| 12 | Payment TPE waiting overlay light mode (white bg, Cayenne accent) | Wave E state 08 |
| 13 | Cash instruction vertical centering (1fr auto grid) | Wave E state 11 |

No assertion unmapped.

---

## Out-of-scope explicit list

- Cats 306 / 307 / 308 (sandwich / burger / tacos) — owner gate
- POS / KDS / OSS / Admin surfaces (kiosk-only audit)
- Multi-tenant / multi-branch isolation (single branch `KIOSK-LC-001` tested)
- Real fiscal sequence allocation (would require completed payment with real TPE hardware)
- menuChoice='full' (frites + boisson) — only 'none' and 'frites' tested in Wave C to fit budget; can extend if needed
- Stock rupture / OOS markers — covered by separate cv1 specs
- Kiosk inactivity overlay / idle-timeout reset — orthogonal to category coverage

---

## Pre-flight done by orchestrator (before launching capture)

- Server health 200 on `/login` + `/kiosk/idle` + `/kiosk`
- Migrations 0 pending (last `2026_05_10_070000`)
- Bundles fresh (`kiosk-shell.js` 2026-05-10 17:24)
- Workers locked: 1
- Helpers verified (`login.js`, `mega-audit-snap.js`, `rate-limit.js`)
- Kiosk creds: `kiosk-lecayenne` / `kiosk123` / branch `KIOSK-LC-001`

## Spec runner template (each wave runnable in isolation)

```bash
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 \
  npx playwright test tests/e2e/test-e2e-borne-2026-05-10-wave-<W>.spec.js \
  --project=chromium --workers=1 --retries=0 --reporter=list
```

Each wave OWNS its `__screenshots__/test-e2e-borne-<W>/` dir. Captures via `attachMegaAuditRecorder(page, dir)` → quartet (PNG + DOM + console.json + network.json) per state. Wave C adds a 5th sidecar (`*.payload.json`) at states 08 and 14 for P0-1 verification.

## Adversarial review (Wave F — out-of-band, per round)

Wave F is NOT a Playwright spec. After each capture round, an adversarial supervisor agent is invoked PER WAVE to inspect that wave's artifact quartet and emit `reports/test-e2e/borne-cats-309-318-2026-05-10/round-<N>/wave-<W>-findings.json` per `FINDINGS_SCHEMA.md`. Loop continues until `verdict === GREEN` for all 5 waves (`open_P0 == 0` AND `open_P1 == 0`) for **two consecutive rounds with set-equality**.

## State-budget summary

| Wave | States |
|---|---|
| A | 13 |
| B | 16 |
| C | 14 |
| D | 10 |
| E | 14 |
| **TOTAL** | **67** (within 50–70 target) |
