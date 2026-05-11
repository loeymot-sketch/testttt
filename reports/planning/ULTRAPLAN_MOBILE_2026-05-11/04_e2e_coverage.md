# 04 — E2E Coverage Audit
> AGENT-4 (E2E-COVERAGE-AUDITOR) — `/ultraplan` mobile cycle 2026-05-11
> Branch `feature/mobile-app-le-cayenne-2026-05-10` @ `ebb712dd8`
> READ-ONLY audit. Sources : 4 wave specs + `mobile/data/menu.js` + `mobile/screens-item-steps.jsx` + 2 verdict reports.

---

## Executive summary

| Dimension | Current | Target | Coverage |
|---|---|---|---|
| **Captured states (waves A+B+C+D)** | **101** | ≥ 240 | **~42%** |
| **Distinct items exercised** | **12 / 60** | 60 (or stratified subset ≥ 35) | **20%** |
| **Categories where ≥ 1 item full-wizarded** | 11 / 13 | 13 | **85%** |
| **Categories with 100% item coverage** | **2 / 13** (Menus Enfants 0/2 partial; Desserts 1/3; Drinks 1/8) | 13 | **15%** |
| **Wizard templates exercised** | 7 / 8 (`tacos`, `sandwich`, `burger`, `assiette`, `omelette`, `salade`, `snacking`, `simple`) | 8 | **88%** |
| **Wizard step keys exercised** | 8 / 8 (VIANDES, SAUCE, CRUDITES, SUPPLEMENTS, MENU, DRINK, FRITES_STYLE, FRITES_SAUCE, RECAP) | 9 | **100%** |
| **Edge-case validation tests** | 2 (aria-disabled init viandes; qty-minus clamp) | ≥ 14 | **~14%** |
| **Loyalty rewards exercised** | 1 / 8 (`reward-row-1`) | 8 (or both locked + unlocked variants) | **12%** |
| **Automated a11y (axe-core)** | **0** | 1 page-level run per major screen | **0%** |
| **Onboarding negative paths (OTP wrong / phone format)** | **0** | ≥ 4 | **0%** |

**TL;DR** — The current 101-state suite proves **happy-path** functionality + a handful of edge cases (idempotency replay, RGPD opt-out, qty clamp, validation-gate at viandes step). It does **not** prove per-item coverage, sauce/crudités/supplement combinatorial integrity, OTP failure paths, accessibility, performance, or multi-line cart math. **Estimated true coverage ≈ 20% of high-value behavior.**

> **Stale-source note** — `mobile/data/menu.js:245` comment says "47 items réels", but the actual ITEMS array is **60** (TACOS 4 + SANDWICHS 8 + BURGERS 6 + ASSIETTES 4 + OJJA 4 + OMELETTES 3 + SALADES 4 + SNACKING 4 + MENUS_ENFANTS 2 + SIDES 2 + DESSERTS 3 + DRINKS 8 + SUPPLEMENTS_ITEMS 8 = 60). Reconcile after this audit.

---

## Per-item coverage matrix (60 items)

Legend : ✅ full wizard / direct-add asserted · 🟡 menu-list tile captured via filter chip only (no wizard descent) · ❌ never touched

### Cat 1 — Nos Tacos (4)
| Item | Slug | Wizard variant | Coverage | Wave : state |
|---|---|---|---|---|
| Tacos M (1 Viande) | `tacos-m` | viandes=1 | ❌ | — |
| Tacos L (2 Viandes) | `tacos-l` | viandes=2 | ❌ | — |
| Tacos XL (3 Viandes) | `tacos-xl` | viandes=3 | ❌ | — |
| Tacos XXL (4 Viandes) | `tacos-xxl` | viandes=4 | ✅ | B:15–25 (full 9-step) + C:15,16,17–25 |

> **Critical gap (brief)** — only 1/4 viande-count paths exercised. M/L/XL never validate `canAdvance(VIANDES)` against `selections.meatIds.length === item.viandes` for viandes ∈ {1,2,3}.

### Cat 2 — Nos Sandwichs (8)
| Item | Slug | Viandes | Coverage |
|---|---|---|---|
| Le Méga | `le-mega` | 2 | ❌ |
| Le Terminator | `le-terminator` | 2 | ✅ (B:26–31) |
| Le Suprême | `le-supreme` | 0 (fixed garnish) | ❌ |
| Le Cayenne | `le-cayenne` | 1 | ❌ |
| Sandwich Froid | `sandwich-froid` | 0 | ❌ |
| Panini | `panini` | 1 | ❌ |
| Sandwich Classique (Pain) | `sandwich-pain` | 1 | ❌ |
| Sandwich Classique (Galette) | `sandwich-galette` | 1 | ❌ |

> Of the 4 distinct viande counts {0, 1, 2}, only viandes=2 is covered. `template:'sandwich'` step ordering for 0-meat & 1-meat sandwiches is **untested**.

### Cat 3 — Nos Burgers (6)
| Item | Slug | viandes | Coverage |
|---|---|---|---|
| Burger Poulet | `burger-poulet` | 0 | ❌ |
| Cheese Burger | `cheese-burger` | 0 | ✅ (B:32–36) |
| Fish Burger | `fish-burger` | 0 | ❌ |
| Double Cheese | `double-cheese` | 0 | ❌ |
| Big Burger | `big-burger` | 0 | ❌ |
| Grill Burger | `grill-burger` | 0 | ❌ |

> Template `burger` has identical step sequence across all 6 items, but item-specific prices/descriptions/composition snapshot are **not pricing-validated**.

### Cat 4 — Nos Assiettes (4)
| Item | Slug | Coverage |
|---|---|---|
| Assiette Poulet | `assiette-poulet` | ✅ (C:01) |
| Assiette Kefta | `assiette-kefta` | ❌ |
| Assiette Merguez | `assiette-merguez` | ❌ |
| Assiette Mixte (14,50€) | `assiette-mixte` | ❌ |

### Cat 5 — Ojja (4)
| Item | Slug | Coverage |
|---|---|---|
| Ojja Bœuf | `ojja-boeuf` | ❌ |
| Ojja Poulet | `ojja-poulet` | ❌ |
| Ojja Viande Hachée | `ojja-hachee` | ❌ |
| Ojja Merguez | `ojja-merguez` | ✅ (C:02, C:13) |

### Cat 6 — Omelettes (3)
| Item | Slug | Coverage |
|---|---|---|
| Omelette Nature | `omelette-nature` | ❌ |
| Omelette Fromage | `omelette-fromage` | ✅ (C:03) |
| Omelette Champignons Fromage | `omelette-champi` | ❌ |

### Cat 7 — Nos Salades (4)
| Item | Slug | Coverage |
|---|---|---|
| Salade Chèvre | `salade-chevre` | ❌ |
| Salade Royale | `salade-royale` | ✅ (C:04, C:14) |
| Salade Saumon | `salade-saumon` | ❌ |
| Salade Tunisienne | `salade-tunisienne` | ❌ |

### Cat 8 — Poulet croustillant (4)
| Item | Slug | Coverage |
|---|---|---|
| Wings 6 | `wings-6` | ❌ |
| Wings 12 | `wings-12` | ✅ (C:05, C:11) |
| Tenders 6 | `tenders-6` | ❌ |
| Tenders 12 | `tenders-12` | ❌ |

> Brief mentions BBQ vs generic — Wave C state 11 only verifies the 15-sauce list exists in `LC.menu.sauces` and picks 1 (Barbecue). Per-sauce iteration not done.

### Cat 9 — Menus Enfants (2)
| Item | Slug | Coverage |
|---|---|---|
| Menu Cheese Burger Enfant | `menu-cheese-enfant` | ✅ (C:06, C:12) |
| Menu Nuggets Enfant | `menu-nuggets-enfant` | ❌ |

### Cat 10 — Frites & Accompagnements (2)
| Item | Slug | Coverage |
|---|---|---|
| Frites Moyenne | `frites-moyenne` | ❌ |
| Frites Grande | `frites-grande` | ✅ (C:07, C:10 Cheddar upgrade) |

> Only `frites-grande` tested. **Cheddar+Oignons (+1,50€)** style never validated end-to-end (C:10 only covers Cheddar fondu +1€).

### Cat 11 — Nos Desserts (3)
| Item | Slug | Coverage |
|---|---|---|
| Glace | `glace` | ❌ |
| Tarte Daim | `tarte-daim` | ❌ |
| Tiramisu | `tiramisu` | ✅ (C:08 direct add) |

### Cat 12 — Nos Boissons (8)
| Item | Slug | Coverage |
|---|---|---|
| Coca-Cola | `coca` | ✅ (C:09 direct add + C:20 multi-line + C:23 pay flow) |
| Coca-Cola Zero | `coca-zero` | ❌ |
| Fanta Orange | `fanta` | ❌ |
| Sprite | `sprite` | ❌ |
| Oasis Tropical | `oasis` | ❌ |
| Orangina | `orangina` | ❌ |
| Eau Plate 50cl (1,00€) | `eau-plate` | ❌ |
| Capri-Sun | `capri-sun` | ❌ |

> Note : `eau-plate` is the **only** item priced 1,00€ — every other drink is 1,50€. Direct-add pricing assertion for distinct prices missing.

### Cat 13 — Suppléments (8)
| Item | Slug | Coverage |
|---|---|---|
| Sauce supplémentaire (0,50€) | `item-sauce-sup` | ❌ |
| Fromage supplémentaire | `item-fromage` | ❌ |
| Jambon de dinde | `item-jambon` | ❌ |
| Boursin | `item-boursin` | ❌ |
| Fromage à raclette | `item-raclette` | ❌ |
| Œuf | `item-oeuf` | ❌ |
| Galette pommes de terre | `item-galette` | ❌ |
| Salade verte (2,00€) | `item-salade` | ❌ |

> **Entire category zero-coverage.** `item-sauce-sup` is the only item with `has_sauce: true` in this category — its wizard descent (sauce step → direct-ish add 0,50€) is **never exercised**.

---

## Coverage gaps by category

| Cat | Items | Tested | Tested% | Critical gap | Risk severity |
|---|---|---|---|---|---|
| 1 Tacos | 4 | 1 | 25% | Only XXL (viandes=4) — M/L/XL canAdvance(VIANDES) unverified | **P0** |
| 2 Sandwichs | 8 | 1 | 12% | viandes=0 (Suprême, Froid) + viandes=1 (5 items) untouched | **P1** |
| 3 Burgers | 6 | 1 | 17% | 5 items / template-identical but pricing untested | P2 |
| 4 Assiettes | 4 | 1 | 25% | Assiette Mixte 14,50€ (highest price) untested | P2 |
| 5 Ojja | 4 | 1 | 25% | spicy variant covered ; non-spicy untested | P3 |
| 6 Omelettes | 3 | 1 | 33% | Nature (cheapest) + Champignons (most complex) untested | P2 |
| 7 Salades | 4 | 1 | 25% | Saumon + Tunisienne (different ingredients) untested | P3 |
| 8 Snacking | 4 | 1 | 25% | tenders-6/12 (sister wizard) untested ; 15-sauce variants 1/15 | **P1** |
| 9 Enfants | 2 | 1 | 50% | Nuggets untested | P2 |
| 10 Frites | 2 | 1 | 50% | Cheddar+Oignons +1,50€ upgrade path untested | **P1** |
| 11 Desserts | 3 | 1 | 33% | 2/3 direct-add prices unconfirmed | P3 |
| 12 Boissons | 8 | 1 | 12% | 7/8 direct-add prices unconfirmed (incl. 1,00€ eau-plate) | **P1** |
| 13 Suppléments | 8 | 0 | **0%** | Entire category untouched ; `item-sauce-sup` 0,50€ wizard descent unconfirmed | **P0** |

---

## Wizard variant coverage matrix

### Per-template state-machine coverage
| Template | Items | Tested item | Step keys hit (live) | Untested transitions |
|---|---|---|---|---|
| `tacos` | 4 (M/L/XL/XXL) | XXL only | VIANDES→SAUCE→CRUDITES→SUPPLEMENTS→MENU→(cascade)→RECAP | viandes ∈ {1,2,3} validation gate |
| `sandwich` | 8 | Le Terminator | Same as tacos | viandes ∈ {0,1} variants |
| `burger` | 6 | Cheese Burger | SAUCE→CRUDITES→SUPPLEMENTS→MENU→RECAP | 5/6 items |
| `assiette` | 4 | Assiette Poulet | SAUCE→SUPPLEMENTS→RECAP (no CRUDITES, no MENU) | 3/4 items |
| `omelette` | 9 (Ojja+Omelettes+MenusEnfants) | 3/9 | SAUCE→(SUPPLEMENTS)→RECAP | Menus Enfants has_supplements=false branch |
| `salade` | 4 | Salade Royale | SAUCE→SUPPLEMENTS→RECAP | 3/4 items |
| `snacking` | 4 | Wings 12 | SAUCE→(MENU optional)→SUPPLEMENTS→RECAP | tenders-6/12 |
| `simple` | 13 (Sides+Desserts+Drinks+Suppléments) | 4/13 | FRITES_STYLE / SAUCE / direct | item-sauce-sup wizard descent ; multiple direct-add prices |

### Per-formule cascade coverage (cat 1/2/3 only)
The `menuChoice` value drives a step cascade in `screens-item-steps.jsx:110-121`. **4 possible paths × 1 tested item per category** = many untested cascades.

| menuChoice | Cascade inserted | Wave coverage |
|---|---|---|
| `'none'` | no extra steps | B:30 (Terminator), B:35 (Cheese) ✅ |
| `'full'` (Menu complet +3€) | DRINK + FRITES_STYLE + FRITES_SAUCE | B:20–23 (Tacos XXL) ✅ |
| `'frites'` (Ajouter Frites +2€) | FRITES_STYLE + FRITES_SAUCE only | C:15 ✅ |
| `'boisson'` (Ajouter Boisson +2€) | DRINK only | C:16 ✅ |

> All 4 cascade paths tested **once on Tacos XXL only**. Sandwich/Burger cascade variants (e.g. `Le Méga` with `full` cascade) **untested**.

### Frites style upgrade coverage
- `null` → "Nature" (free, default) : ✅ B:23 (cascade), C:07 (standalone), C:15
- `'fs-cheddar'` → "Cheddar fondu" +1,00€ : ✅ B:22 (cascade), C:10 (standalone)
- `'fs-cheddar-oignon'` → "Cheddar + Oignons" +1,50€ : ❌ **never tested**

### Frites sauce coverage (1 free + 0,50€/each additional)
- 1 sauce (free) : ✅ B:23
- 2 sauces (+0,50€) : ❌
- 3+ sauces (+1,00€) : ❌

### Sauces — 15 generic
- Per-sauce selection assertion : only **1/15** sauces (Barbecue) clicked per wizard run across all waves
- "Sans Sauce" exclusivity (selecting `s-sans` should auto-clear other sauces — `is_no_sauce: true` flag in `menu.js:173`) : ❌ **never tested**
- 2 sauces +0,50€ : ✅ once (B:17 Tacos Ketchup+Mayo)
- 3+ sauces +1,00€ : ❌

### Crudités
- Default-ON state : ✅ B:18 (Tacos)
- Toggle 1 OFF : ✅ B:18 (Oignon off)
- All 3 OFF (empty crudités, special baseline) : ❌
- Re-toggle ON after OFF : ❌

### Suppléments — 7 items × pricing cascade
- 1 supplement (+1€ or +0,50€) : ✅ B:19 (Œuf +1€)
- 2+ supplements pricing cascade : ❌
- 3+ supplements pricing cascade : ❌ **brief explicitly flags this**
- Sauce supplémentaire (+0,50€, group:'Sauces') vs Suppléments (+1€) group distinction visual : ❌

---

## Edge cases NOT tested

Cross-cuts that no current wave exercises ; each could be a P0–P2 hidden defect.

### Wizard validation gates
1. **canAdvance gate without selection** (tested only at `B:15` for VIANDES). Sauce step with 0 picked, Menu step with no `menuChoice`, Drink step with no drink picked — all `aria-disabled='true'` paths **untested**.
2. **Sans Sauce exclusivity** — `menu.js:173` `is_no_sauce: true`. UI must auto-clear other sauces when "Sans Sauce" picked. **No test**.
3. **canAdvance(FRITES_STYLE) when `fritesStyleId===undefined` vs `null`** — `screens-item-steps.jsx:147` distinguishes "not yet picked" (`undefined`) from "Nature" (`null`). **No regression test**.
4. **Wizard back-navigation** — pressing the back chevron mid-cascade should restore prior selections (per `WizardHeader` `onBack` at `screens-item-steps.jsx:181`). **No test**.
5. **Close wizard mid-flow** — onClose dispose should not leak state to cart. **No test**.

### Cart edge cases
6. **Multi-line >2 items** (current max in C:20 is 2 lines). 3+ lines pricing recompute integrity untested.
7. **Same item added twice** — does the cart merge (qty++) or create two lines? Behavior on Tacos XXL with **identical** composition twice **untested**. Kiosk merges by composition_snapshot ; mobile behavior unknown.
8. **Same item added twice with DIFFERENT composition** (e.g. Tacos XXL with Ketchup vs same Tacos XXL with Mayo) — should yield 2 separate lines. **Untested**.
9. **Qty stepper upper bound** — no max-qty assertion (could go to 99? 999?). **Untested**.
10. **Trash on the only line → empty state transition** — `C:21→C:22` does this but only via the loop ; no explicit "trash last line directly" assertion.
11. **Sticky CTA disabled state when cart empty** — `C:22` snaps empty state but `Valider ma commande` enabled/disabled state not asserted.

### Checkout
12. **Cancel during pay modal** — open pay-choice modal then close (X / backdrop / Escape). **No test**.
13. **Stripe placeholder flow completion** — `C:28` lands on Stripe screen but never **clicks** the "Confirmer le paiement" CTA (or whatever returns to confirm). **Untested**.
14. **ETA computation** — `confirmDetails.hasEtaTime` regex `\d+h\d+` is a presence-only assertion (`C:25`). Actual `now + 12min` math correctness **not validated**.
15. **Order ID format** — `/#C-\d+/` regex (`C:25`) is loose ; doesn't assert randomness across two pays (same session → same ID? collision?).

### Loyalty
16. **Insufficient balance redemption** — `loyalty-05-redeem-locked.spec.js` in `mobile-e2e/` covers it but Wave D doesn't ; cross-suite gap.
17. **Opt-in restoration after opt-out** — `D:22` confirms opt-out → balance=0 + banner. Reactivating the program (opt-in) flow **untested**.
18. **QR refresh after TTL=300s** — `D:12` waits 2.2s and verifies countdown decremented ; expiry + auto-regenerate path **untested**.
19. **Idempotency outside the 10-min window** — `D:19` tests replay inside window ; replay after >10min (key differs) **untested**.
20. **All 8 rewards iteration** — only `reward-row-1` (cost 100) tested ; rewards at higher costs (300/500/etc.) **untested**.

### Onboarding / Auth
21. **Skip onboarding from step 2/3/4** — Wave A only `clickNext` cascades through onb1→onb2→onb3→onb4. "Passer" / skip CTA from non-final step **untested**.
22. **Phone format variants** — A:107 hardcodes "06 12 34 56 78". International (+33), leading-zero, spaces-vs-dots, invalid (too short) — none tested.
23. **OTP wrong code** — `A:124-127` types `1234` (success). Wrong code path, error toast/banner, retry, max-attempts lockout — **untested**.
24. **OTP resend** — there's typically a "Renvoyer le code" affordance. Untested.
25. **Token expiry / re-login** — no test simulates `lecayenne.auth` expiry → forced login.

### Cross-screen invariants
26. **Cart count badge in TabBar** — never asserted on the Menu tab (the badge should reflect `LC.cart.length`). Wave A/B/C never check the badge across screen transitions.
27. **Loyalty balance consistency across screens** — `D:10` does cross-section (profile vs loyalty) but does NOT cross-check the home screen loyalty preview block (`screens-main.jsx`). Risk : drift between Home preview and Loyalty page.
28. **Auth state consistency on hard reload** — Wave B/C reload mid-test but never assert TabBar / greeting / featured-card render correctly after reload while cart persists.

### Performance / Visual
29. **No layout-shift (CLS) measurement** — image-slot async loads can shift cards. Untested.
30. **No transition timing assertion** — splash→onb1 (1800ms) ; pay-counter→confirm→points-gain (900ms `setTimeout` per `D:26` brief) ; no test confirms these delays land within ±50ms.
31. **Landscape orientation** — viewport pinned 390×844 portrait. No rotation test.
32. **Image lazy-loading verification** — no assertion that `.image-slot` for off-screen cards uses `loading="lazy"`.
33. **No FPS / scroll-jank measurement** — Menu screen with 60 items scrolling untested.

### A11y (entirely 0%)
34. **No axe-core integration** — neither `tests/e2e/helpers/` nor any wave imports `@axe-core/playwright`. Zero automated WCAG runs.
35. **Keyboard navigation across full wizard** — `ChoiceCard` has `tabindex=0 + onKeyDown` (per verdict §"A11y baseline") but no spec drives Tab/Enter/Space.
36. **Screen reader announcements** — `aria-live` on cart count, balance changes, error toasts ? Not asserted.
37. **Focus management on modal open/close** — focus trap inside `ModalShell`, focus restore on close. **Untested**.
38. **Color contrast** — visual-only ; no axe color-contrast pass.

### Network / API contract
39. **API endpoints** — `mobile/api/` exists but no wave intercepts/mocks/asserts request payloads. If V1 wires real backend, contract drift undetectable.
40. **Network offline** — no offline simulation ; cart should persist via localStorage but `navigator.onLine=false` flow untested.

### Misc
41. **Allergens screen** — Wave D `D:09` lists "Allergènes" row but never clicks through.
42. **Notifications toggle** — same.
43. **Langue toggle FR↔EN** — no i18n switching test.
44. **Toast auto-close timing** — `D:07` waits 3.2s but doesn't assert toast is gone after.

---

## Recommended new specs (Phase 6.B testing wave)

Each entry includes proposed file name, scope, capture count, and the code anchor that motivates the spec.

### Tier 1 — Critical (P0 / P1) — adds **~88 states**

#### `audit-mobile-wave-E-items-coverage-2026-05-12.spec.js` (≈ 48 states)
- **Scope** : Drive every item slug through wizard or direct-add. One snap per item at the recap (or direct-add CTA) state.
- **Anchor** : `mobile/data/menu.js:398-402` ITEMS array (60 items). Exclude already-covered 12 → 48 new.
- **Per-item assertions** : `priceFor` engine value == CTA `span` value ; `composition_snapshot` (if exposed via DOM `data-*`) matches input selections.
- **Wave time budget** : ~9 min @ 11 s/item (boot+nav+walk-to-recap). Could parallelize 3-ways into E1/E2/E3 sub-specs.
- **Why P0** : Cat 13 (Suppléments) entirely zero ; Cat 12 (Drinks) only 1/8 ; Cat 1 viande gates {1,2,3} unverified.

#### `audit-mobile-wave-F-validation-gates-2026-05-12.spec.js` (≈ 14 states)
- **Scope** : For each `canAdvance` branch in `screens-item-steps.jsx:131-155` (8 step keys), capture (a) `aria-disabled='true'` empty state, (b) `aria-disabled='false'` after minimal valid selection.
- **Plus** : "Sans Sauce" exclusivity test (pick a sauce → pick `s-sans` → assert other sauce deselected) — `menu.js:173` `is_no_sauce: true`.
- **Plus** : `fritesStyleId===undefined` vs `===null` distinction (`screens-item-steps.jsx:147`).
- **Anchor** : `tests/e2e/audit-mobile-wave-B-2026-05-11.spec.js:158-159` currently the **only** aria-disabled assertion.

#### `audit-mobile-wave-G-cart-math-2026-05-12.spec.js` (≈ 12 states)
- **Scope** : Cart pricing integrity edge cases.
- 3-line cart (Tacos + Cheese Burger + Drink) total = sum-of-lines.
- Same item × 2 different compositions → 2 lines.
- Same item × 2 identical compositions → does it merge? Capture both outcomes (kiosk pattern in `app/Services/Pricing/PricingService.php` merges).
- Qty 1→2→3→1 cascade total integrity.
- 3+ supplements (+3,00€) pricing on Tacos XXL.
- 3+ frites sauces (+1,00€) on a frites-style cascade.
- 2-sauce assertion on burger (currently only tacos has multi-sauce).
- **Anchor** : `tests/e2e/audit-mobile-wave-C-2026-05-11.spec.js:783-823` qty stepper exists but only ± once.

#### `audit-mobile-wave-H-auth-negative-2026-05-12.spec.js` (≈ 8 states)
- **Scope** : Auth negative paths.
- Phone empty submit → error.
- Phone format too short → error.
- Phone international `+33...` accepted.
- OTP wrong code → error banner / toast.
- OTP wrong code 3× → lockout state (if implemented).
- OTP resend CTA path.
- Onboarding "Passer" from step 2 (if exposed).
- Logout from profile → returns to splash/login.
- **Anchor** : `tests/e2e/audit-mobile-wave-A-2026-05-11.spec.js:107-129` currently only tests happy OTP `1234`.

#### `audit-mobile-wave-I-loyalty-extended-2026-05-12.spec.js` (≈ 6 states)
- **Scope** : Loyalty deep-dive.
- Redeem each of 8 rewards (parameterized).
- Insufficient balance lock UI (cost > current balance).
- Opt-out → opt-in restoration flow.
- QR TTL expiry + auto-regen (need to mock Date.now or use `LC.dev.advanceTime`).
- Idempotency outside 10-min window → different key → new debit.
- Cross-screen balance consistency (Home preview vs Profile preview vs Loyalty screen — 3-way assert).
- **Anchor** : `tests/e2e/audit-mobile-wave-D-2026-05-11.spec.js:357-457` covers only reward 1 + replay inside window.

### Tier 2 — Quality (P2) — adds **~28 states**

#### `audit-mobile-wave-J-a11y-axe-2026-05-12.spec.js` (≈ 10 states)
- **Scope** : `@axe-core/playwright` page-level scan per major surface :
  Splash, Onb1–4, Login, OTP, Home, Menu, Item Wizard (one per template), Cart, Pay modal, Confirm, Loyalty.
- **Assertions** : 0 critical violations ; serious violations enumerated.
- **Anchor** : zero a11y automation today.

#### `audit-mobile-wave-K-keyboard-nav-2026-05-12.spec.js` (≈ 8 states)
- **Scope** : Drive a full Tacos XXL wizard using Tab + Space + Enter only.
- Verify focus ring visible (CSS `:focus-visible`) at each step.
- Verify modal focus trap on pay modal + redeem wizard.
- **Anchor** : `mobile/styles.css:36-45` defines `:focus-visible` but no test exercises it.

#### `audit-mobile-wave-L-stripe-completion-2026-05-12.spec.js` (≈ 4 states)
- **Scope** : Complete the Stripe placeholder flow → confirm → points-gain. Currently `C:28` lands on Stripe screen but never proceeds.

#### `audit-mobile-wave-M-cross-screen-invariants-2026-05-12.spec.js` (≈ 6 states)
- Cart count badge consistency Menu→Home→Profile transitions.
- Hard-reload mid-cart → cart persists + TabBar correct.
- Hard-reload mid-onboarding → splash on fresh ; home on auth-seeded.

### Tier 3 — Hardening (P3) — adds **~18 states**

#### `audit-mobile-wave-N-performance-2026-05-12.spec.js` (≈ 8 states)
- CLS measurement on Home + Menu via `performance.getEntriesByType('layout-shift')`.
- LCP, FCP timings.
- Splash→onb1 timing (1800ms ±50ms).
- pay-counter→points-gain modal timing (900ms ±50ms).
- 60-item Menu scroll FPS sample.

#### `audit-mobile-wave-O-modals-stack-2026-05-12.spec.js` (≈ 6 states)
- Open pay modal → Escape → close.
- Open redeem wizard → click backdrop → close.
- Modal stacking : open redeem + try to open card-link (should it stack or replace?).
- Toast + modal interaction.

#### `audit-mobile-wave-P-empty-states-2026-05-12.spec.js` (≈ 4 states)
- Orders historique with `LC.dev.clearOrdersHistory()` → empty state.
- Loyalty history with no entries.
- Notifications screen empty.
- Allergens never-set empty.

---

## Priority order

| Spec | Reason | Effort (hr) | Phase |
|---|---|---|---|
| E (items coverage) | 80% of "true coverage" gap closes with this single spec | 6h | 6.B-01 |
| F (validation gates) | Hidden P0 risk : `canAdvance` regressions silently break wizards | 3h | 6.B-02 |
| G (cart math) | Pricing correctness = NF525-class invariant ; current proof = 1 multi-sauce assertion | 3h | 6.B-02 |
| H (auth negative) | Auth happy-path is brittle ; OTP failure ⇒ blocked users in prod | 2h | 6.B-03 |
| I (loyalty extended) | Brief explicitly asks for opt-out/in + insufficient balance + cross-screen | 2h | 6.B-03 |
| J (axe-core a11y) | Zero a11y automation ⇒ owner-gate failure on accessibility audit | 4h | 6.B-04 |
| K (keyboard nav) | Same a11y story, complementary to J | 2h | 6.B-04 |
| M (cross-screen) | Cheap, high-value : 30 lines of code per assertion | 1.5h | 6.B-05 |
| L (Stripe completion) | Small but completes pay flow chain | 1h | 6.B-05 |
| N (performance) | Lower urgency for V0 ; matters for V1 SLA | 3h | 6.B-06 |
| O (modal stack) | Stability hardening | 2h | 6.B-06 |
| P (empty states) | Polish | 1h | 6.B-07 |

**Total Phase 6.B engineering** : ≈ 30 hr · adds ≈ 134 new captured states.

---

## Estimated test wall-clock (current vs target)

Source : verdict `99_VERDICT.md` reports Wave A 9.0s, Wave B 19.5s, Wave C 33.1s, Wave D 33.6s = **~95 s total** for 101 states ≈ **0.94 s/state**.

| Scenario | Specs | States | Est. wall-clock (sequential) | Parallel (4 workers) |
|---|---|---|---|---|
| **Current** | 4 (A/B/C/D) | 101 | 95 s | ~33 s |
| **+ Tier 1** (E/F/G/H/I) | +5 | +88 | +83 s = 178 s (~3 min) | ~60 s |
| **+ Tier 2** (J/K/L/M) | +4 | +28 | +26 s = 204 s (~3.4 min) | ~70 s |
| **+ Tier 3** (N/O/P) | +3 | +18 | +17 s = 221 s (~3.7 min) | ~80 s |
| **Target final** | 16 specs | **235 states** | **~3.7 min** | **~80 s** |

> **Risk** : `wave-J` axe-core injection adds ~1.5 s/page → realistic Tier 2 wall-clock may stretch to ~4.5 min. Plan parallelization in `playwright.config.js` if CI budget tight.

---

## Coverage delta summary

| Metric | Before | After (Tier 1+2+3) | Δ |
|---|---|---|---|
| States | 101 | 235 | +134 |
| Items wizard-touched | 12/60 | 60/60 | +48 |
| Validation gates tested | 1/8 step keys | 8/8 | +7 |
| Auth negative paths | 0 | 6 | +6 |
| Loyalty rewards | 1/8 | 8/8 | +7 |
| A11y automated runs | 0 | 10 | +10 |
| Performance metrics | 0 | 8 | +8 |
| Cross-screen invariants | 1 (loyalty data×ui) | 6 | +5 |
| Coverage estimate | ~20% | ~75–80% | +55–60 pts |

---

## Specific anchors (where to add tests in current files)

For tasks that fit inside an existing wave rather than a brand-new spec :

| Need | Existing file:line | Note |
|---|---|---|
| 4 viande counts in tacos | `tests/e2e/audit-mobile-wave-B-2026-05-11.spec.js:149-165` | Loop slugs `tacos-m/l/xl/xxl` ; mutate `meatIds.length` |
| Sans-sauce exclusivity | `tests/e2e/audit-mobile-wave-C-2026-05-11.spec.js:430` (Wings sauce step) | Pick BBQ then `s-sans` ; assert BBQ unchecked |
| 3+ supplements | `tests/e2e/audit-mobile-wave-B-2026-05-11.spec.js:189-193` | Add Boursin + Œuf + Galette ; assert +3,00€ |
| Cheddar+Oignons (+1,50€) | `tests/e2e/audit-mobile-wave-C-2026-05-11.spec.js:512-515` (state 10) | Replace pick "Cheddar fondu" with "Cheddar + Oignons" |
| Multi-line same-composition merge | `tests/e2e/audit-mobile-wave-C-2026-05-11.spec.js:783-846` (cart section) | Add 2nd Tacos XXL with identical selections ; assert qty=2 not 2 lines |
| Cart count badge cross-screen | `tests/e2e/audit-mobile-wave-A-2026-05-11.spec.js:182-202` (tab nav) | Add `cart` seed before navigation ; assert badge on Menu/Home tabs |
| Axe-core page scan | new helper `tests/e2e/helpers/axe-snap.js` ; called from each wave's `snap()` | Install `@axe-core/playwright` |
| Stripe completion | `tests/e2e/audit-mobile-wave-C-2026-05-11.spec.js:989-1004` (state 28 end) | Click "Confirmer le paiement" CTA in `[data-screen-label="11b Stripe"]` |

---

**End of 04_e2e_coverage.md** — AGENT-4 deliverable for ULTRAPLAN_MOBILE_2026-05-11.
