# K13 — Pricing/composition helpers (FRONTEND)

> HEAD verified : `6a33a9763` (vs plan reference `245e8ab57` — drift OK,
> branch `feature/mobile-app-le-cayenne-2026-05-10`).
> Audit READ-ONLY. NF525 invariant : **frontend pricing = preview-only**,
> SSOT = `app/Services/Pricing/PricingService.php`.

## Files audited

- `resources/js/helpers/kioskPricing.js` — 147 lines (pure helpers)
- `resources/js/helpers/kioskPricingPreview.js` — 245 lines (debounced
  axios client, side-effect on `window.axios`)
- `resources/js/helpers/kioskFormatPrice.js` — 91 lines (pure + Vue mixin)
- `resources/js/helpers/kioskExtrasPartition.js` — 109 lines (pure helpers)
- `resources/js/helpers/kioskMenuBundledExtras.js` — 66 lines (pure regex helper)
- `resources/js/helpers/kioskTacosSize.js` — 127 lines (pure regex helper)
- `resources/js/helpers/kioskSandwichSplit.js` — 75 lines (pure helper)
- `tests/js/kioskMenuBundledExtras.spec.js` — 73 lines (Vitest, healthy)
- `tests/js/posKioskVariationParity.spec.js` — 405 lines (Vitest,
  **REAL on HEAD** — see P0-14 verdict §Findings)

## NF525 contract trace (read carefully)

The orchestrator asked for an explicit NF525 trace : do any K13 helpers
emit a *price* field that flows into the order payload posted to backend ?

### Outbound payload audit
- `kioskPricingPreview.js:66-91` (`normalizeKioskPricingPreviewItem`) :
  hard-allow-list = `{ item_id, quantity, instruction, item_variations[],
  item_extras[], item_addons[] }`. Modifier rows pass through
  `normalizePreviewModifiers` (lines 93-108) which **whitelists only
  `id` + optional `quantity > 1`**. No `price`, `convert_price`, `total`,
  `branch_id` ever attached.
- `kioskPricingPreview.js:114-129` (`buildKioskPricingPreviewPayload`) :
  identical posture for top-level payload. Coupon codes trimmed/clamped
  to 64 chars. No price/branch.
- Final wizard checkout payload (out of scope but cross-traced for
  completeness) : `resources/js/store/modules/kioskCart.js:98-112`
  `sanitizeKioskOrderItem` mirrors the same allow-list. `convert_price`
  is read on lines 227, 268, 283, 306, 328 only to compute the
  **display subtotal** (a Vuex getter) — never serialized into
  `buildKioskQuotePayload` (line 142-158). `payload.subtotal/total/
  discount/delivery_charge` set on lines 173-177 are populated from
  the signed `quote.quote_token` server response (an authentic backend-
  produced quote replayed verbatim, not a fresh client computation), so
  the SSOT chain stays intact.

### Verdict
- **NF525 contract risk : NO.**
- All five running-total helpers (`calculateKioskRunningTotal`,
  `getKioskExtraSauceUnitPrice`, `getKioskMenuAddonPrice`,
  `kioskSumPaidViandesSurcharge`, partition helpers) feed **display-only**
  bindings (`runningTotal`, `formatPrice(...)`, getters `subtotal`/`total`).
  None of them write into the request body. The preview helper is the
  only network emitter and it is explicitly id-only.
- `composition_snapshot` is built server-side from the
  `item_id + variations[] + extras[] + addons[]` payload and the catalog
  state at order create — frontend cannot poison it.

## Findings

### P0 (blocker pre-merge V1)
*None. The K13 surface is NF525-safe and the parity test is real.*

### P1 (high — V1.0.1 sprint)

- **K13-P1-01 : kioskFormatPrice() prefix without `position` produces
  "EUR12,50" / "€12,50" — bad layout when `currencySymbol` set but
  `position` falsy**
  - File : `resources/js/helpers/kioskFormatPrice.js:35-41`
  - Issue : when `options.currencySymbol` is provided but `options.position`
    is anything other than `'right'`, the code does
    `return ${options.currencySymbol}${formatted}` — i.e. no space
    separator. For French branches the catalogue typically stores
    `site_default_currency_symbol='€'` and `site_currency_position='right'`,
    but if the admin misconfigures or the field is missing,
    `getPriceOptionsFromStore` returns `position: 'right'` only when
    explicitly set ; otherwise an undefined position will fall to the
    `${symbol}${formatted}` branch with **no space** ("€12,50" instead of
    "12,50 €" or "€ 12,50").
  - Evidence : line 65 reads `position: lists.site_currency_position || 'right'`
    — defaults to `'right'` for store path. But anyone calling
    `formatKioskPrice(12.5, { currencySymbol: '€' })` without `position`
    gets `'€12,50'`.
  - Suggested fix : insert a space in left-position branch
    (`'${symbol} ${formatted}'`), and/or default `position` inside
    `formatKioskPrice` itself, not only inside `getPriceOptionsFromStore`.

- **K13-P1-02 : Arabic (`ar`) locale not wired into kiosk format chain**
  - File : `resources/js/helpers/kioskFormatPrice.js:28-55`
  - Issue : the helper accepts an `options.locale` override but the Vue
    mixin (`kioskPriceMixin`, lines 80-89) **never reads locale from the
    store**. `getPriceOptionsFromStore` (lines 62-69) extracts only
    `currencySymbol/position/digits` — no locale. So Arabic kiosks fall
    back to the hardcoded default `'fr-FR'` on line 31 even when the
    branch language is `'ar'`. Pricing renders correctly numerically but
    digits stay Latin and RTL-aware bidi marks are not injected, breaking
    the AR a11y posture announced in K20 cross-cutting scope.
  - Evidence : `KioskConfirmationComponent.vue:255` proves the project
    is locale-aware elsewhere
    (`locale === 'ar' ? 'ar-SA' : locale === 'en' ? 'en-GB' : 'fr-FR'`).
  - Suggested fix : extend `getPriceOptionsFromStore` to pull
    `globalState.locale` (already present in store per K14/K20 scope)
    and forward it through `formatKioskPrice(value, options)`.

- **K13-P1-03 : `calculateKioskRunningTotal` couples to two contract
  shapes for `_viandeMeta` (underscore + non-underscore fallback)**
  - File : `resources/js/helpers/kioskPricing.js:138-143`
  - Issue : the helper accepts both `selections._viandeMeta` (official
    wizard contract) **and** `selections.viandeMeta` (legacy) :
    `Array.isArray(selections._viandeMeta) ? selections._viandeMeta :
    (Array.isArray(selections.viandeMeta) ? selections.viandeMeta : null)`.
    This dual-shape forgiveness silently hides regressions like the
    PHASE9 W-P0-1 surcharge miss documented in the comment above (lines
    130-137). The codebase's discipline elsewhere is to fail loud — here
    we forgive.
  - Suggested fix : keep underscore-only and add a Vitest assertion
    that ensures a Sandbox using the non-underscore key produces a
    warning (or has been fully refactored away).

- **K13-P1-04 : `getKioskExtraSauceUnitPrice` defaults to 0.50€ when
  catalogue has no priced sauce variation**
  - File : `resources/js/helpers/kioskPricing.js:29-44`
  - Issue : `let unit = 0.50;` is a **branch-blind hardcoded price**.
    Used in lines 88-95 to surcharge extra sauces. Cayenne uses 0.50€ ;
    other branches may differ. Even though backend re-prices, the
    customer-facing running-total display will be wrong for branches
    that don't match the default → trust erosion at checkout
    (per the NF525 owner gate principle "no silent divergence").
  - Suggested fix : read the default from
    `window.foodkingConfig.kioskMenuPricing.extraSauceUnitPrice`
    (consistent with `getKioskMenuPricingConfig` pattern, lines 21-27)
    or — better — make the catalogue source mandatory and surface
    `null` if absent, letting the wizard hide the "extra sauce" CTA.

### P2 (medium — backlog priorisé)

- **K13-P2-01 : `parseFloat(...) || 0` swallow on `NaN`/empty in 6+ sites
  — silent zero**
  - File : `resources/js/helpers/kioskPricing.js:59, 84, 102, 126;
    kioskExtrasPartition.js:47, 75; kioskMenuBundledExtras.js:39`
  - Issue : when an extra/variation arrives with `convert_price: "abc"`
    or `null`, every helper coerces to 0 silently. Customer sees the
    item as free in the display while backend correctly bills the price.
  - Suggested fix : `Number.isFinite()` gate + warn(once) in dev mode.

- **K13-P2-02 : `kioskIsViandePaidExtra` uses substring "viande" — fails
  for English/Arabic catalogues**
  - File : `resources/js/helpers/kioskExtrasPartition.js:46-55`
  - Issue : the regex match `name.includes('viande')` is FR-only.
    Catalogues with `name: 'Double Steak'` or `'Beef +2€'` will fall
    into the `supplements` bucket and double-count if the
    `_viandeMeta` path is also populated. The group_label heuristic
    saves most cases (`meat`/`protein` accepted on line 50) but it
    relies on admin discipline.
  - Suggested fix : prefer `group_label` strictly ; warn(once) if name
    contains "viande"-like substring but group_label is missing.

- **K13-P2-03 : `kioskMenuBundledExtras` regex incomplete for menu
  upgrades**
  - File : `resources/js/helpers/kioskMenuBundledExtras.js:15`
  - Issue : `FRITES_UPGRADE_NAME_REGEX` does not cover "Frites Truffe",
    "Frites BBQ", "Frites Curly". The fallback (lines 60-65) catches
    only "cheddar/crisp/fromage". Items with other adjectives leak
    into the `supplements` partition.
  - Suggested fix : maintain a single curated `frites_style` group_label
    and remove the name-regex fallback (or extend the regex with
    `curly|truffe|bbq|sauce` variants).

- **K13-P2-04 : `kioskTacosSize` `M` regex matches lone "M" anywhere**
  - File : `resources/js/helpers/kioskTacosSize.js:51`
  - Issue : `\b(?:tacos\s+)?m\b` matches "Pizza M" or "Burger M" too —
    not just tacos. Order is mitigated by digit-priority (line 87) but
    the `hasPresetSizeInName` boolean can flip true for non-tacos items
    and hide a legitimate Taille step elsewhere.
  - Suggested fix : require the surrounding `tacos` context for letter
    sizes, or rename helper to `productSize` and document explicitly
    that it now covers all multi-size items.

- **K13-P2-05 : Negative quantity guard missing in
  `normalizeKioskSelectionCount`**
  - File : `resources/js/helpers/kioskPricing.js:70-77`
  - Issue : Boolean `true` → 1, negative int → 0. But the helper does
    not clamp **upper** bound. A wizard bug pushing `999` into
    `selections.supplements[id]` would multiply runtime cost by 999 in
    display ; backend reprices but UX trust dies first. Other parts
    of kioskCart clamp to `MAX_ITEM_QTY` (20).
  - Suggested fix : add cap `Math.min(count, 99)` or pull
    `MAX_ITEM_QTY` from window config.

### P3 (low — nice-to-have)

- **K13-P3-01 : `kioskSandwichSplit` virtual-row insertion always
  duplicates parent label**
  - File : `resources/js/helpers/kioskSandwichSplit.js:38-57`
  - Issue : when `coldSlugs` is non-empty, the helper pushes a duplicate
    parent row with `name: coldLabel`. If a future redesign needs more
    sub-columns, the pattern doesn't scale — duplicated objects per
    sub-column. Not a bug, just a smell.

- **K13-P3-02 : Magic numbers in `getKioskMenuPricingConfig` defaults
  (full=1, frites=0.6, drink=0.4)**
  - File : `resources/js/helpers/kioskPricing.js:3-7`
  - Issue : these ratios are not documented in `docs/PRICING.md` (file
    absent). A reader has no way to verify these match backend menu
    pricing ratios. Risk : drift if backend changes ratios without
    propagating to `window.foodkingConfig.kioskMenuPricing`.
  - Suggested fix : reference a backend constants file in JSDoc, or
    inject these via blade-rendered `<script>foodkingConfig = ...`
    served by a Laravel route that owns the SSOT.

- **K13-P3-03 : `kioskFormatPrice` fallback path on Intl exception
  produces "7.00 BAD" with a dot, not a comma**
  - File : `resources/js/helpers/kioskFormatPrice.js:51-54`
  - Issue : when an invalid locale/currency triggers the `try/catch`,
    the fallback returns `${num.toFixed(digits)} ${currency}` —
    period-separated. French branches with bad config see
    "12.50 EUR" instead of "12,50 EUR".
  - Suggested fix : `.replace('.', ',')` on the fallback too.

## posKioskVariationParity.spec.js verdict — REAL on HEAD

The audit asked specifically whether this spec is REAL or FAKE
(fixture-self-comparison) on the current HEAD. **Verdict : REAL.**

Evidence from the spec file :
- Line 36-38 : both **production helpers** are imported :
  `computePosCartLineDisplayTotal` (POS) and `calculateKioskRunningTotal`
  (kiosk).
- Lines 100-114 : two distinct helper functions `realPosTotal` and
  `realKioskTotal` route through the actual code paths, not a shared
  intermediate.
- Cases 4, 5, 6, 7 (lines 239-389) end with
  `expect(posTotal).toBeCloseTo(kioskTotal, 2)` where the two totals
  are produced by **different functions on different code paths**.
- Cases 1, 2, 3 explicitly document an **expected divergence** between
  POS (variation surcharge applied) and Kiosk (variation free, surcharge
  modelled as paid extra) and assert
  `expect(posTotal).toBeGreaterThan(kioskTotal)` — a falsifiable claim,
  not a tautology.
- Case 7 (lines 322-389) cross-tests the PHASE9 W-P0-1 surcharge fix
  by injecting a `_viandeMeta` entry on the kiosk path and asserting
  parity at 12€ exactly on both paths — a real contract assertion.

The header comment (lines 1-22) explicitly documents the prior
fixture-self-comparison bug and the rewrite. The Vitest run for this
spec on HEAD would exercise both production helpers (not stubs).

> Confirmation : MEMORY note "P0-14 was FAKE sentinel" is **stale on HEAD**.
> The rewrite is shipped, the assertions are real, the parity claim is
> falsifiable.

## Existing E2E / Vitest coverage

- `tests/js/kioskFormatPrice.spec.js` — symbol-right + Intl fallback +
  store extraction (3 cases, all green expected)
- `tests/js/kioskMenuBundledExtras.spec.js` — 7 cases covering
  has_menu/sauce/cheddar/group_label/zero-priced — solid
- `tests/js/kioskExtrasPartition.spec.js` — partition exclusivity
- `tests/js/kioskTacosSize.spec.js` — size detection
- `tests/js/kioskSandwichSplit.spec.js` — virtual-row split
- `tests/js/kioskPricingPreview.spec.js` — debounce + payload strip +
  abort logic
- `tests/js/posKioskVariationParity.spec.js` — POS↔Kiosk parity
  (7 cases real ; 1 skipped V1.0.1 menu-addon parity)

## Proposed new tests (priority order)

- **T-K13-01 (P1) : NF525 contract regression — payload sanitization**
  - Steps : feed `normalizeKioskPricingPreviewItem` an item with `price`,
    `convert_price`, `total`, `branch_id`, `instruction` (300 chars).
  - Assertions : output has only `item_id`, `quantity`, `instruction`
    (≤255), modifier arrays ; no price/branch keys ; modifier rows
    contain only `id` + optional `quantity > 1`.
  - Same fixture replayed against `sanitizeKioskOrderItem` from
    `kioskCart.js` to assert the **end-to-end** payload stays clean.

- **T-K13-02 (P1) : Arabic locale rendering**
  - Steps : mount a stub component using `kioskPriceMixin` with
    `globalState.locale = 'ar'` and `lists` = SAR currency config.
  - Assertions : output contains Arabic-Indic digits or at least passes
    through `Intl.NumberFormat('ar-SA', { style: 'currency', currency: 'SAR' })`.

- **T-K13-03 (P2) : `getKioskExtraSauceUnitPrice` reads catalogue first**
  - Steps : item with no sauce variation priced → assert default 0.50€
    AND warn(once) called ; item with priced sauce variation → unit
    matches catalogue.
  - Goal : pin the contract before refactoring to remove the magic 0.50.

- **T-K13-04 (P2) : Partition mutual-exclusivity invariant**
  - Steps : item with 10 extras spanning all four buckets +
    edge-case (price=0 viande, sauce with group_label='extra',
    cheddar with no has_menu).
  - Assertions : `garnitures ∪ supplements ∪ fritesUpgrades ∪
    viandesPaid` covers every non-sauce extra exactly once. A single
    Vitest assertion shipping the invariant prevents future divergence.

- **T-K13-05 (P3) : `kioskTacosSize` non-tacos guard**
  - Steps : feed `viandeCountFromName('Pizza M')`, `'Burger M'`,
    `'Croque M'`.
  - Assertions : returns `null` (after the proposed P2-04 fix tightens
    the regex), or documents the current FR-loose behaviour.

## Risks & open questions

- **OWNER GATE — none required for K13 scope.** No frozen-zone file
  modified ; all P0/P1/P2 fixes live in `resources/js/helpers/*` which
  is *not* in the §3 frozen list of the master plan.
- The K13 NF525 trace assumed kioskCart.js stays the only outbound
  surface for paid orders. K10 (Payment) and K18 (backend order create)
  should cross-confirm that no other axios call ships a price field
  for a paid kiosk order.
- The hardcoded `0.50€` extra-sauce default (K13-P1-04) and menu ratios
  (K13-P3-02) need a single SSOT. Recommend a backend-rendered
  `kiosk-config` blade endpoint shared by K17 (Menu API).
- The Arabic locale gap (K13-P1-02) is a cross-cutting issue with K14
  (RTL/allergens helpers) and K20 (a11y/i18n) — single fix surface in
  `kioskFormatPrice.js` + propagate via Vuex `globalState.locale`.

---

**Scope summary** : 0 P0, 4 P1, 5 P2, 3 P3 = **12 findings**. NF525
contract intact. posKioskVariationParity REAL on HEAD. Verdict for V1
merge from K13's perspective : **GO with V1.0.1 P1 backlog**.
