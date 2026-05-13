# K08 — Order Summary + Loyalty

## Files audited
- `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue` — 758 lines
- `resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue` — 1071 lines
- `resources/js/composables/useKioskTheme.js` — 245 lines
- `tests/js/kioskLoyaltyConsentWiring.spec.js` (60 lines) — existing
- `tests/js/KioskUpsellOrderSummaryRestyle.spec.js` — existing

HEAD `6a33a9763`. Branch `feature/mobile-app-le-cayenne-2026-05-10`.

## Scope clarification
`KioskOrderSummaryComponent` is the **wizard per-item recap** rendered as the last
step before "Add to cart" (registered as step `recap` in `KioskWizardComponent.vue`
at lines 564–796). It is NOT a multi-line cart-side recap. The total-parity
question therefore translates to: **recap-line total == cart line total after
`ADD_ITEM`** (and indirectly, the wizard payload `total` written into the cart
store). The cart-level totals/discounts UI lives in `KioskCartComponent`.

`KioskLoyaltyComponent` is the standalone loyalty screen between cart and upsell
(route `kiosk.loyalty` in `kioskRoutes.js:189-195`). It handles **code check +
registration + redeem-or-keep decision**. No QR/NFC scanning is wired (despite
the `loyalty_scanned` analytics event at line 590 — misleading naming).

## Findings

### P0 (blocker pre-merge V1)

- **K08-P0-01: `loadConfig()` swallows all errors silently → minRedeemPoints
  fallback locks at 100 while backend default is 50.**
  - File: `KioskLoyaltyComponent.vue:428-441` and `KioskLoyaltyComponent.vue:335`
  - Issue: `axios.get('frontend/loyalty/config')` is wrapped in `try { … } catch (_) {}`
    with **no error logging, no retry, no user-visible state**. When the call
    fails (timeout, 429, network blip on borne), `minRedeemPoints` stays at the
    hardcoded 100. Backend (`LoyaltyController::config` line 415) ships default
    **50**. A customer with 60 points on a misconfigured borne will be told
    "Vous avez 60 points — il en faut au moins 100" while the backend would
    accept a 50-point redemption. Silent UX regression with revenue impact.
  - Evidence: `data() { … minRedeemPoints: 100, … }` (line 335) vs
    `LoyaltyController.php:415` `$minRedeem = (int) Settings::group(...)->get(
    'loyalty_min_redeem_points', 50)`. Catch block at line 440 has no logging.
  - Suggested fix: (a) align kiosk fallback to 50; (b) on config-fetch failure
    set `this.error = $t('kiosk.loyalty_screen.config_unavailable')` and disable
    redemption (force keep-points path) rather than guessing minimum; (c) log
    via `kioskAnalytics` so SRE observes failures.

### P1 (high — V1.0.1 sprint)

- **K08-P1-01: Loyalty A11y — back button, numpad `del`, error region all
  missing accessible names / live announcements.**
  - File: `KioskLoyaltyComponent.vue:6` (back), `:41-57` (numpad), `:59`
    (error), `:165-170` (points value), `:283-285` (confirmed amount).
  - Issue: (a) back button has no `aria-label` (SVG only — screen reader hears
    "button"); (b) the `del` numpad key renders a pure SVG with no
    `aria-label="Supprimer"` (digit buttons are OK because text content
    suffices); (c) `kiosk-loyalty-error` div has no `role="alert"` so timeouts
    / not-found messages don't get announced; (d) `kiosk-loyalty-points-value`
    (the big "X points") has no `aria-live` — when a customer registers and
    `step` transitions to `balance`, no announcement; (e) the step transition
    itself does not move focus to the new primary action (skip → register form
    → balance card → confirmed view) — keyboard/SR users lose context.
  - Suggested fix: add `aria-label` to back + numpad del; `role="alert"` +
    `aria-live="assertive"` on error; `aria-live="polite"` on points value;
    `this.$nextTick(() => this.$refs.stepHeading.focus())` on each step
    transition (tabindex="-1" on `h2`/`kiosk-loyalty-confirm-title`).

- **K08-P1-02: Latent recap-vs-cart parity risk for composer-mode items
  (`composerChoiceEntries`).**
  - File: `KioskOrderSummaryComponent.vue:265-267` (recap total) vs
    `KioskWizardComponent.vue:1872-1905, 1958, 1972` (wizard payload).
  - Issue: `calculateKioskRunningTotal` (`helpers/kioskPricing.js:79-147`)
    handles base + sauce surcharges + non-sauce supplements + menu addon +
    frites_style + `_viandeMeta` paid surcharges. It does NOT account for
    `composerChoiceEntries` (`variation` / `extra` rows pushed by the composer
    profile). The wizard payload, on the other hand, folds
    `composerVariationTotal` and `composerExtraTotal` into `itemVariationTotal`
    / `itemExtraTotal` (lines 1884, 1894, 1958). For standard non-composer
    items (the Cayenne menu V1) this is a no-op; for any composer-enabled item
    introduced later, the recap will UNDER-DISPLAY the price compared to what
    the cart records.
  - Evidence: helper `kioskPricing.js` has no branch for composer entries;
    wizard `addToCart` includes them in `lineTotal` (line 1976).
  - Suggested fix: extend `calculateKioskRunningTotal` to consume an optional
    `composerEntries` argument and pass `this.composerChoiceEntries()` from
    the recap context (the recap is invoked inside the wizard so it has
    access). Add a Vitest assertion `recap.runningTotal === wizard.lineTotal`
    on a composer-profile fixture.

- **K08-P1-03: Recap total `aria-live="polite"` fires on every keystroke ⇒
  noisy / talkover.**
  - File: `KioskOrderSummaryComponent.vue:128-140`
  - Issue: The `<div class="kiosk-summary-total" role="status" aria-live="polite">`
    wraps both the "Total" label and the price value. Each time the customer
    increments quantity (line 161-165 `incrementQty`), the entire region
    re-announces. With multiple wizard step interactions firing related
    `selections` updates, the SR queue floods. Best practice: scope live to
    the price value alone, or use `aria-atomic="false"` and only announce the
    diff.
  - Suggested fix: move `role="status" aria-live="polite"` to the
    `kiosk-total-price` span only; remove from the outer div. Already pinned
    `aria-label="Total X,YZ€"` is sufficient for screen reader pickup.

- **K08-P1-04: RGPD opt-out path missing post-registration (customer cannot
  revoke consent from the borne).**
  - File: `KioskLoyaltyComponent.vue:545-618` (register + consent) +
    `routes/api.php:1250` (`/loyalty/opt-in` route present, no opt-out).
  - Issue: Consent is captured via `KsConsentModal` and `submitRegister` only
    proceeds if accepted. Decline (`onConsentDecline:612-618`) cancels
    registration but does NOT offer a "delete my account" path. A customer
    who registered earlier and wants to revoke consent on a future visit has
    no kiosk UI to do so — they'd need to call/email the restaurant. RGPD
    Article 7(3) requires withdrawal of consent "as easy as giving it".
    Backlog item B-XX (loyalty endpoints) doesn't include `/loyalty/opt-out`.
  - Evidence: Only `optIn` endpoint at `routes/api.php:1250`. No
    `optOut`/`forget`/`delete` controller method in `LoyaltyController.php`.
  - Suggested fix: out of K08 scope (cross-team) but flag for owner gate:
    decide if (a) kiosk hosts a "forget my data" CTA from the balance step,
    or (b) the restaurant team handles it manually with a documented SLA.
    Either way, surface the policy in the consent modal copy.

- **K08-P1-05: `discountValue` from backend is not capped against `total` for
  display, but applied capped in `applyLoyalty` ⇒ "= 5€" shown while actual
  redemption gives 3€.**
  - File: `KioskLoyaltyComponent.vue:167-170, 198-201, 515`
  - Issue: The badge shows `formatPrice(Math.min(discountValue, total))` and
    the redeem option subtitle does the same. So display IS capped. But the
    APPLIED discount at line 515 is also `Math.min(discountValue, this.total)`
    — and `total` here is the Vuex `kioskCart/total` (state.subtotal - prior
    discounts). Two issues: (a) at line 167 the closure captures `total` at
    render time; if cart updates after loyalty screen mount (it shouldn't,
    `kiosk.loyalty` is past cart, but the toast / re-mount race exists), the
    cap could mismatch; (b) the backend `discount_value` is computed by
    `LoyaltyController::check` using `points / loyalty_points_for_1_euro_discount`
    (line 723 returns a static number based on points balance, NOT on current
    cart). If a customer has 1000 points = 10€ discount but cart is 6€, the
    badge correctly shows "= 6€ off", but if backend rounds differently than
    kiosk's `formatPrice` (currency rounding mode), a 1-cent mismatch may
    appear vs the final receipt.
  - Suggested fix: pin a Vitest test on `discount_value` cap behavior (cart
    < discount_value, cart > discount_value, cart == discount_value). Add a
    contract test asserting backend `LoyaltyController::check` rounding mode
    == frontend `kioskFormatPrice` rounding.

### P2 (medium — backlog priorized)

- **K08-P2-01: Misleading analytics event `loyalty_scanned`.**
  - File: `KioskLoyaltyComponent.vue:590`
  - Issue: Event is fired inside `_doSubmitRegister` (registration success),
    not on scan. Misleading for analytics queries.
  - Suggested fix: rename to `loyalty_registered` (or split into two events
    `loyalty_register_succeeded` / `loyalty_scanned`, the latter wired to a
    future QR/NFC path).

- **K08-P2-02: Backend `/loyalty/scan` route present but UNUSED by kiosk —
  B-XX backlog risk.**
  - File: `routes/api.php:1257` vs kiosk component (no axios.post to /scan).
  - Issue: A POST `/api/frontend/loyalty/scan` exists for QR/NFC resolution
    (Phase 8.3). Kiosk loyalty UI only supports keyboard-typed code via
    `/check`. If marketing introduces QR loyalty cards, no UI path consumes
    them. Mobile loyalty audit B-01..B-08 backlog mentions `/loyalty/rewards`
    — kiosk doesn't assume them (good).
  - Suggested fix: confirm with owner whether kiosk V1 ships QR/NFC scan
    (camera permission + dependency cost is substantial). If yes, add a
    button "Scanner mon code" alongside "Verify" that opens a USB-HID barcode
    listener or camera modal.

- **K08-P2-03: Numpad supports digits + `del` only — no support for
  alphanumeric loyalty codes despite placeholder text "Ex.: code ou 06 12…".**
  - File: `KioskLoyaltyComponent.vue:339, 1539` (FR placeholder)
  - Issue: Placeholder says "Ex. : code ou 06 12 34 56 78" — "code" can be
    alphanumeric (legacy backend codes are 6-8 chars A-Z0-9). The numpad only
    types digits, the underlying `<input type="text">` accepts physical
    keyboard input but bornes typically have no keyboard. Customer with a
    legacy alpha code cannot type it.
  - Suggested fix: either (a) add a "Switch to letters" toggle that swaps the
    numpad for the `KsVirtualKeyboard` (already imported); or (b) clarify the
    placeholder to "Tapez votre numéro de téléphone".

- **K08-P2-04: `composition_summary` readable display not part of order
  summary recap.**
  - File: `KioskOrderSummaryComponent.vue` (whole template).
  - Issue: The recap renders structured rows (pain, viandes, sauces, etc.)
    derived from `selections` directly. It does NOT consume `composition_summary`
    (the human-readable string that backend produces for receipts / KDS card
    labels). For the wizard recap this is fine (selections are the SSOT here),
    BUT if a future "edit-from-cart" returns to this view via wizard rehydrate,
    the visible recap depends on `selections` being fully repopulated — partial
    rehydrate (missing `_viandeMeta` or `_boissonMeta`) shows wrong text
    silently. The recap has no fallback path consuming `composition_summary`.
  - Suggested fix: add a defensive `<div v-if="!hasSelections" class="kiosk-summary-fallback">
    {{ item.composition_summary }}</div>` that surfaces the saved composition
    if `selections` is empty/corrupt. Pin a Vitest for the empty-selections case.

- **K08-P2-05: Hardcoded brand colors in loyalty CSS — design drift vs palette
  noir/rouge/jaune/blanc.**
  - File: `KioskLoyaltyComponent.vue:680, 696, 707, 716, 722, 752, 793-799,
    822, 858, 1012-1013` (literal hex `#F4501E`, `#F5C518`, `#FFD700`,
    `#FFA500`).
  - Issue: Owner memo (`project_kiosk_design_refresh_2026-05-10.md`) defines
    palette noir/rouge/jaune/blanc. Loyalty page uses `#F4501E` (orange-red,
    same as K07 P1-02 finding) AND mixes `#FFD700` (gold) with `#FFA500`
    (orange) for the points avatar / progress fill — inconsistent yellow.
    Bypasses the `useKioskTheme` cascade entirely (this screen has its own
    `--kiosk-loyalty-screen` CSS but doesn't read any `var(--kiosk-*)` token).
  - Suggested fix: refactor to consume the same CSS custom properties as
    `KioskOrderSummary` (`--kiosk-primary`, `--kiosk-success`, `--kiosk-surface`,
    etc.). Single source of truth, theme-switchable.

- **K08-P2-06: `useKioskTheme` listens to `prefers-color-scheme` even when
  user has explicitly picked light/dark.**
  - File: `useKioskTheme.js:133-150, 137-138`
  - Issue: The `mediaListener` calls `recompute()` only when
    `preference.value === 'auto'`, so behavior is correct. BUT the listener
    is attached unconditionally and never detached after a manual pick. Minor
    memory/event noise. Also: the initial `applyHtmlTheme` is called in
    `onMounted`, not synchronously, so there IS a FOUC window unless
    `bootstrapKioskThemeEarly()` (line 232) is invoked before the Vue mount.
    Verify `bootstrap-kiosk.js` calls it.
  - Suggested fix: detach the matchMedia listener when `preference.value`
    transitions to a manual pick, re-attach if returning to `auto`. Document
    in a comment that `bootstrapKioskThemeEarly()` is required pre-mount.

### P3 (low — nice-to-have)

- **K08-P3-01: `viandeDisplayRows` rebuild on every render — non-memoized.**
  - File: `KioskOrderSummaryComponent.vue:216-241`
  - Issue: Iterates `_viandeMeta` array each render via `.filter().map().filter()`.
    For typical 1-3 viande rows it's negligible (< 0.1ms), but on bornes with
    low-end CPUs (Atom-class) and an Arabic locale that triggers extra layout
    passes, this multiplies. Mark as P3 because impact is minimal.
  - Suggested fix: cache in computed memoized via a `Map` keyed by
    `JSON.stringify(_viandeMeta)`, or accept the cost.

- **K08-P3-02: Loyalty register flow doesn't pre-fill email if customer types
  a phone number that already exists.**
  - File: `KioskLoyaltyComponent.vue:494-511` (checkLoyalty path).
  - Issue: Smooth UX would be: type phone in input → `/check` returns existing
    customer → skip register. Today, customer can type phone in check, get
    success (lands on balance step). Working as expected if backend supports
    phone-as-code lookup. Verify in `LoyaltyController::check`.

## Existing E2E coverage
- `tests/js/kioskLoyaltyConsentWiring.spec.js` — pins RGPD consent event names
  (`@accepted`/`@declined` past tense, not infinitive) and asserts kiosk
  loyalty listens correctly. Regression guard for Phase 9.1.10 fix.
- `tests/js/KioskUpsellOrderSummaryRestyle.spec.js` — likely covers the
  upsell + order summary visual contract (not yet read in this audit).
- `tests/Feature/KioskLoyaltyDoubleRedeemRefusedTest.php` — backend guard
  that a customer cannot redeem twice in a single order.
- `tests/Feature/KioskLoyaltyLedgerAtomicTest.php` — backend points-ledger
  atomicity test.
- `tests/js/productComposerSummary.spec.js` — composer profile + summary
  contract.

## Proposed new E2E tests

- **T-K08-01: Recap line total == cart line total for every menu archetype.**
  - Scenario: spin up wizard for Sandwich Familial, build 2 viandes (1 free
    + 1 +2€ paid), 3 sauces (2 paid extra), menu_full, frites_style cheddar,
    quantity 2. Read `KioskOrderSummary.runningTotal` from DOM
    (`[data-testid="kiosk-order-summary-total-price"]`), click "Add to cart".
    Read first cart line `item.total`. Assert equal.
  - Cover archetypes: sandwich / taco / burger / assiette / menu_formule.
  - Includes composer-profile assertion (P1-02 regression net).

- **T-K08-02: Loyalty `/config` failure ⇒ borne enters safe state, not silent
  100-min lock.**
  - Steps: mock `axios.get('frontend/loyalty/config')` to reject; mount
    `KioskLoyaltyComponent`; type a code → balance step. Assert
    `minRedeemPoints !== 100` OR an error banner is visible OR redemption is
    disabled. (Test will fail today — that's the P0 net.)

- **T-K08-03: A11y axe-core on loyalty step transitions.**
  - Steps: render at each `step` (input → register → balance with `canRedeem`
    true/false → confirmed). Run axe-core with WCAG2.1AA rules.
  - Assert 0 violations on missing `aria-label` (back button + numpad del +
    error region + points value).

- **T-K08-04: Recap fallback to `composition_summary` when selections are
  empty.**
  - Steps: mount `KioskOrderSummary` with `selections: {}` and `item: { …,
    composition_summary: 'Burger × 1, sauce algérienne, 2 garnitures…' }`.
  - Assert visible recap renders the fallback string.

- **T-K08-05: RGPD opt-out path E2E (gate on owner decision).**
  - Steps: register a customer; return to loyalty screen; assert presence of
    a "Effacer mes données" button (out-of-scope if owner decides
    backoffice-only) OR explicit copy "Pour vous désinscrire, contactez le
    restaurant" in the consent modal post-acceptance.

## Risks & open questions
- **[OWNER GATE]** RGPD opt-out policy on borne: kiosk UI or backoffice-only?
- **[OWNER GATE]** QR/NFC loyalty card scan UX: do we wire `/loyalty/scan`
  in V1 (camera + permission + lib cost) or defer to V1.0.1?
- **[OWNER GATE]** Loyalty palette: confirm yellow tone (`#FFD700` vs
  `#F5C518`) and align with `--kiosk-primary` cascade.
- **[OPEN]** Composition_summary fallback contract: does any production data
  scenario hit `selections === {}` today (cart-edit rehydrate path)?
- **[CROSS-AGENT]** K07 cart audit already flagged hex literal drift — K08
  confirms the pattern extends to loyalty CSS. Synthesis should treat as a
  single P1 design-token finding.
- **[CROSS-AGENT]** K20 (NF525) should verify the recap-vs-payload total
  parity under composer mode — K08-P1-02 is the canonical citation.
- **[BACKLOG]** B-01..B-08 backend loyalty endpoints (mobile audit) — kiosk
  does NOT assume `/loyalty/rewards`. Confirmed by code: only `/check`,
  `/register`, `/config`, `/opt-in` called from kiosk path.
