# K06b — Wizard steps modifiers (Taille / FritesStyle / Supplements / Menu / GenericChoices)

> HEAD: `6a33a9763b7ef8da9ffb350732b1cdff1fab2261`
> Branch: `feature/mobile-app-le-cayenne-2026-05-10`
> Auditor: K06b sub-agent (read-only)
> Frozen-zone: NO (steps under `steps/` are auditable + modifiable; the parent
> `KioskWizardComponent.vue` is frozen and consumed read-only here).

## Files audited

- `resources/js/components/frontend/kiosk/steps/KioskStepTailleComponent.vue` (269 lines)
- `resources/js/components/frontend/kiosk/steps/KioskStepFritesStyleComponent.vue` (308 lines)
- `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue` (504 lines)
- `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue` (818 lines)
- `resources/js/components/frontend/kiosk/steps/KioskStepGenericChoicesComponent.vue` (236 lines)
- Cross-ref (read-only): `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (line 1819–1937 — payload assembly + role tagging)
- Cross-ref (read-only): `resources/js/helpers/kioskExtrasPartition.js` (109 lines — SSOT partitioning)
- Cross-ref (read-only): `resources/js/helpers/kioskPricing.js` line 46+113 — `getKioskMenuAddonPrice` preview computation
- Cross-ref (read-only): `app/Services/Pricing/PricingService.php` line 230–298 — backend SSOT recompute
- Cross-ref (read-only): `config/kiosk.php` line 37–41 + 80–84 — `menu_pricing` ratios (full=1.0, fries=0.6, drink=0.4)
- Locale files: `resources/js/languages/fr.json` (line 1644+1657–1726), `en.json` (line 1800+1860–1882), `ar.json` (line 1630+1691–1700)

## NF525 SSOT verdict — Y / no leak

**YES, contract respected.** No `price` field is emitted to backend from any of the 5 step components.

Evidence:
- KioskStepTaille emits only `taille` key + meta `{ viandeCount, label, attrId, realId }` — zero price (line 120–125).
- KioskStepFritesStyle emits only `fritesStyleExtraId` (number|null) — line 105–107.
- KioskStepSupplements emits a `supplements` map `{[id]: count}` — line 162–170, normalized via `emitSupplements`. No price.
- KioskStepMenu emits `menuChoice`, `boissonChoice`, `fritesSauceOrder`, `fritesSauce` — line 442–446, 462–478, 480–495. The `_boissonMeta` payload only forwards id/name. The displayed `+{{ formatPrice(menuPrice) }}` (line 7–9) is purely cosmetic (`getKioskMenuAddonPrice` returns a ratio-applied preview, never serialized).
- KioskStepGenericChoices emits `composerChoices` shape `{step_id, step_key, label, source_type, choices: {[key]: {id, name, source_type, item_attribute_id, addon_item_id, role, count}}}` (line 105–115, `normalizeChoice` line 116–126) — no price field.

Downstream payload assembly (KioskWizardComponent line 1804–2005):
- `normalizedExtras = [{ id, name, ?quantity }]` — id is the only authoritative key
- `normalizedVariations = [{ id, variation_name, name, ?quantity }]`
- `normalizedAddons = [{ id, name, addon_item_id, role, ?quantity }]`
- `item_extra_total`, `item_variation_total`, `total` — sent as fields BUT backend `PricingService::calculateOrder` recomputes from `$dbExtras` / `$dbVariations` (line 233–239) and overwrites in `$itemsArray[$i]['item_variation_total']` / `['item_extra_total']` / `['total_price']` (line 293–295). Client values are IGNORED for `composition_snapshot` (line 270–276) — snapshot built from DB, never overwritten (T07 SSOT comment line 266).

Conclusion: the 5 modifier steps preserve the SSOT contract. The frontend preview is cosmetic; backend pricing is authoritative.

## Findings

### P0 (blocker pre-merge V1)

None — no SSOT breach, no auth/branch issue, no fiscal hazard detected in these 5 step components.

### P1 (high — V1.0.1 sprint)

- **K06b-P1-01: AR locale missing 3 quantity-control aria-labels in Supplements**
  - File: `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue:31,68,75,85`
  - Issue: Components reference `kiosk.wizard.supplement_qty_label`, `supplement_increase`, `supplement_decrease`. These keys exist in fr.json (line 1624–1626) and en.json (line 1780–1782), but are ABSENT from ar.json (verified by `grep` — only `supplements_empty`, `supplements_badge_paid`, `supplement_default_desc` present at line 1610–1613).
  - Evidence: Vue-i18n silently falls back to the key string itself when missing, so AR users see "kiosk.wizard.supplement_qty_label" raw text read by NVDA / VoiceOver. CLAUDE.md §6 Visual Test Mandate explicitly bans raw `Label.X`.
  - Suggested fix: add to ar.json near line 1614:
    ```json
    "supplement_qty_label": "كمية {name}",
    "supplement_increase": "إضافة {name}",
    "supplement_decrease": "إزالة {name}",
    ```

- **K06b-P1-02: GenericChoices step uses untranslated FR-only fallback strings**
  - File: `resources/js/components/frontend/kiosk/steps/KioskStepGenericChoicesComponent.vue:51,84`
  - Issue: `stepLabel` falls back to literal `'Choisissez vos options'` (line 51) and `validationHint` falls back to `'Minimum ${this.minSelect} choix'` (line 84). The `fallbackText()` helper (line 88–91) compares the translation to the key — if Vue-i18n returns the key unchanged (missing key), the FR string wins. Result: EN and AR users see French.
  - Evidence: `grep 'generic\.' resources/js/languages/{fr,en,ar}.json` returns ZERO matches across all three locales. No `kiosk.wizard.generic.step_fallback` key exists anywhere.
  - Suggested fix: add `generic: { step_fallback: "...", min_hint: "..." }` under `kiosk.wizard.*` in all 3 locales (FR/EN/AR). Confirm `composer_step.label` is the primary source (multi-locale via admin Composer Studio), the fallback only fires when label is null.

- **K06b-P1-03: No `aria-describedby` linking price labels to cards (a11y SR cumulative-total leak)**
  - Files (all 5): zero matches for `aria-describedby` (grep verified count=0).
  - Issue: Cards announce only their label via `aria-label`, but the price suffix (`+1.00€`, `+2.00€` on FritesStyle line 47; row price on Supplements line 58–63) is purely visual. SR users hear "Cheddar fondu, radio not checked" without the price context. WCAG 2.1 SC 1.3.1 + EAA 2025 require programmatic association of UI-visible costs.
  - Suggested fix: For FritesStyle line 31–35 add `:aria-describedby="`price-${extra.id}`"` and on the `<span class="kiosk-frites-style-price">` add `:id="`price-${extra.id}`"`. Same pattern for Supplements rows and Menu cards. Aggregate `totalPrice` on Supplements (line 7–9) should be wrapped in `<output aria-live="polite">` for cumulative announcement when count changes.

- **K06b-P1-04: Supplements aggregate total has no aria-live; multiplier ×N silent for SR**
  - File: `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue:6–10,60–62`
  - Issue: `totalPrice > 0` displays `+{{ formatPrice(totalPrice) }}` but no `role="status"` / `aria-live="polite"`. Increment/decrement buttons announce only the new count (`aria-live` is on the per-row qty value line 78) — the aggregate is silent. Multi-supplement orders confuse SR users.
  - Suggested fix: wrap `kiosk-supplements-price` in `<span role="status" aria-live="polite">`. Also add `aria-label` reading the running total when changed (e.g. `Total suppléments 2.50 euros`).

- **K06b-P1-05: Menu step accepts auto-preselection without explicit a11y announcement**
  - File: `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue:360–373` (`mounted()` calls `selectChoice('full')` when category has `default_menu_kiosk`).
  - Issue: When auto-preselection fires, SR users land on the step with a card already pre-selected, but no `<output aria-live="polite">` announces it. Mounted lifecycle bypasses the `validation-hint` shown only when `localChoice===null`. Also flags a potential UX surprise: customer may NEXT through without realising they paid +X€ for the menu upgrade.
  - Suggested fix: emit an `aria-live` announcement on mount (e.g. polite region "Formule complète présélectionnée +3€ — modifiez ou continuez"). Owner gate: validate copy.

- **K06b-P1-06: FritesStyle relies on emoji-only visual cards (Cheddar = 🧀, Oignons = 🧅) with no real product image**
  - File: `resources/js/components/frontend/kiosk/steps/KioskStepFritesStyleComponent.vue:43–45,127–131,232–245`
  - Issue: Cards intentionally use emoji+gradient backgrounds because the migration `2026_05_10_040000_add_frites_style_upgrade_extras` did not commit thumb assets (comment line 108–116 explicit). Emoji rendering across kiosk devices may vary wildly (Apple vs Noto vs Twemoji). Marketing risk: customer doesn't see what they buy at +2€. Confirmed by `find tests/.../frites-style*` showing many screenshot rounds — owner-validated for now (Round 3 fix C-002 P1, line 109).
  - Suggested fix: ship real `extras.thumb` JPEG assets via admin upload + migration update; add `<img v-if="extra.thumb" :src="extra.thumb">` fallback before emoji.

### P2 (medium — backlog)

- **K06b-P2-01: KioskStepTaille fallback labels (S/M/L/XL with viandeCount derivation) are brittle to admin renaming**
  - File: `resources/js/components/frontend/kiosk/steps/KioskStepTailleComponent.vue:74–77`
  - Issue: Variation name parsing uses `.toLowerCase().includes('xxl' | 'xl' | ' l' | …)` — admin renaming a variation to e.g. "Géant" or "Taille 4" breaks viandeCount=4 inference. Inferring meat count from variation NAME contradicts the principle "no name-based heuristics" mentioned line 43–45.
  - Suggested fix: store `viande_count` as a dedicated `item_variation` column (DB migration). Failing that, surface a `meta_json` on `item_variations` and read explicit `meta.viande_count`.

- **K06b-P2-02: KioskStepMenu hardcodes drink categories via regex `/boisson|drink|soda|sodas|beverage|beverages/`**
  - File: `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue:415–418`
  - Issue: Admin creating a category "Sodas & Bières" works; renaming to e.g. "Rafraîchissements" silently drops the menu. Same fragility as P2-01 — name-based detection.
  - Suggested fix: dedicated `is_drink_category` boolean column on `categories` table, or category-level `tags`.

- **K06b-P2-03: KioskStepFritesStyle prefers ascending price sort — may misalign with marketing intent**
  - File: `resources/js/components/frontend/kiosk/steps/KioskStepFritesStyleComponent.vue:83–88`
  - Issue: `upgradeExtras` sorts by price ASC, but if owner wants "Cheddar+Oignons" first for marketing, no override. Hardcoded.
  - Suggested fix: read `extras[].position` if defined (already on `item_extras` DB schema); fallback ASC otherwise.

- **K06b-P2-04: Supplements quantity max=9 silently caps (no SR-visible message)**
  - File: `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue:156–158`
  - Issue: `normalizeCount` caps at 9 silently. Above 9, user clicks `+` and nothing happens (button stays enabled). No `aria-live` warning.
  - Suggested fix: disable `+` button when `count === 9` (`:disabled` already on line 84 but no count condition) and add `aria-describedby` for max state.

- **K06b-P2-05: GenericChoices step ignores `min_select` enforcement programmatically**
  - File: `resources/js/components/frontend/kiosk/steps/KioskStepGenericChoicesComponent.vue:144–148`
  - Issue: When `min_select=2` and `current > 0`, decrement is allowed if `selectedTotal > minSelect`, but the hint only DISPLAYS the violation — there's no `aria-invalid` on the container. SR users may NEXT without knowing they're under min.
  - Suggested fix: emit `aria-invalid="true"` on `.kiosk-generic-grid` and `aria-errormessage` linking to the hint when `showValidationHint`.

### P3 (low — nice-to-have)

- **K06b-P3-01: Taille step uses hardcoded color `#F4501E` not CSS var**
  - File: `resources/js/components/frontend/kiosk/steps/KioskStepTailleComponent.vue:175,182,215,231,260`
  - Issue: Inconsistent with `var(--kiosk-primary, #F4501E)` pattern used in Menu/Supplements. Theme switch (dark mode V2) will skip these.
  - Suggested fix: replace hardcoded RGBA with `var(--kiosk-primary, …)` + soft variants.

- **K06b-P3-02: FritesStyle "Nature" card uses no `data-extra-id` attribute for E2E selection**
  - File: `resources/js/components/frontend/kiosk/steps/KioskStepFritesStyleComponent.vue:14`
  - Issue: `data-testid="kiosk-frites-style-nature"` exists but upgrade cards use `:data-testid="\`kiosk-frites-style-upgrade-${extra.id}\`"` (line 34) — Playwright tests must hardcode the extra id (709/710/711/712 — comment line 70 mentions the IDs). DB seed shift breaks tests.
  - Suggested fix: add `data-extra-name="cheddar" | "cheddar-oignons" | "nature"` for semantic E2E selection.

- **K06b-P3-03: KioskStepMenu duplicates option-card markup 4× — refactor candidate**
  - File: `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue:21–92`
  - Issue: full / frites / boisson / none cards are 95% identical structure. A `<MenuOptionCard>` sub-component would deduplicate ~70 lines and centralize a11y attributes.
  - Suggested fix: extract sub-component (purely cosmetic refactor).

- **K06b-P3-04: KioskStepGenericChoices choice cards lack visual price (when source is variation with surcharge)**
  - File: `resources/js/components/frontend/kiosk/steps/KioskStepGenericChoicesComponent.vue:20–23`
  - Issue: Composer-driven generic choices may carry surcharges (e.g. premium meat). Template shows only name + count, no price chip. The price IS captured in `composerVariationTotal` in wizard (line 1884) but never previewed.
  - Suggested fix: if `choice.price > 0`, render a price chip (`+1.00€`) below the name. Preview only — backend recomputes anyway.

- **K06b-P3-05: KioskStepMenu has dead `fritesSauceList` alias kept "backward-compat" without test reference**
  - File: `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue:266–268`
  - Issue: comment "Backward-compat alias: anciens tests / code externe utilisent `fritesSauceList`" — but `grep "fritesSauceList"` shows no external consumer outside the component itself. Dead code.
  - Suggested fix: remove alias after confirming no test depends on it (re-grep at PR time).

## Existing E2E coverage

- `tests/js/kioskTacosSize.spec.js` — covers Taille step viandeCount inference + emit contract
- `tests/js/kioskWizardGenericComposer.spec.js` — covers GenericChoices composerChoices emit / shape
- `tests/js/kioskWizardEditRestore.spec.js` — round-trip cart→wizard→modify→save (touches Taille + Supplements selections persistence)
- `tests/js/kioskWizardEditRoundtrip.spec.js` — verifies `_wizardSelections` is NOT serialized to backend (SSOT proof)
- `tests/js/kioskWizardNavigation.spec.js` — navigation through step order (Menu cascade Full→Boisson→FritesSauce)
- `tests/js/KioskWizard.spec.js` — orchestrator-level (full happy-path)
- `tests/js/posVariationMultiQty.spec.js` + `posKioskVariationParity.spec.js` — POS↔Kiosk price computation parity (relevant for Menu + Supplement parity)
- `tests/js/kioskA11yAxe.spec.js` + `kioskA11yStructuralAudit.spec.js` + `kioskA11yTouchTargets.spec.js` — a11y baseline (axe + WCAG 2.1)
- E2E: `tests/e2e/test-e2e-borne-2026-05-10-wave-C.spec.js` — production-like full Kiosk flow, captures `04-312-wizard-step-frites-style.png` etc.

## Proposed new E2E tests

- **T-K06b-01: Frites style upgrade SSOT — frontend price IS NOT trusted by backend**
  - Steps: pick "Salade Royale" → menu = full → frites_style = Cheddar fondu (id=711, price 1.00€). Override DB extras price to 5.00€ via test seeder, then submit order.
  - Assertions: POST payload has `item_extras: [{ id: 711, name: 'Cheddar fondu' }]` with **no `price` field**. Backend response total reflects 5.00€ extra (not 1.00€). `composition_snapshot` in DB stores `price=5.00`.

- **T-K06b-02: Supplements AR locale a11y — quantity buttons have non-key aria-labels**
  - Steps: switch locale to `ar`. Open Tacos wizard → reach Supplements step → click `+` on Cheddar.
  - Assertions: `aria-label` on `+` button does NOT contain the literal string `kiosk.wizard.supplement_increase` (i.e. translation must exist). Currently FAILS (P1-01).

- **T-K06b-03: Menu default_menu_kiosk preselection triggers polite aria-live announcement**
  - Steps: load category with `default_menu_kiosk=true`. Open wizard, reach Menu step.
  - Assertions: a `[role="status"][aria-live="polite"]` region is mounted with text describing preselection. Currently FAILS (P1-05).

- **T-K06b-04: GenericChoices step localizes step_fallback to active locale (FR/EN/AR)**
  - Steps: load composer step with `label=null` in FR / EN / AR.
  - Assertions: rendered `<h3>` shows respectively "Choisissez vos options" / English equivalent / Arabic equivalent — NOT the raw FR fallback for all locales. Currently FAILS (P1-02).

- **T-K06b-05: Supplements aggregate total updates aria-live on count change**
  - Steps: pick 2× Bacon (`+2.00€`) then 1× Egg (`+1.50€`). Observe aria-live region during increments.
  - Assertions: SR queue receives "Total suppléments 2 euros" then "Total suppléments 4 euros" then "Total suppléments 5 euros 50". Currently FAILS (P1-04).

- **T-K06b-06: Taille step variation map preserves `realId` for backend lookup**
  - Steps: select Tacos → choose Taille XL (DB-driven variation with `realId=123`).
  - Assertions: emitted update payload `{ realId: 123, attrId: <taille_attr_id> }`; on order submission, `item_variations` array includes `{ id: 123, variation_name: 'Taille', name: 'XL' }`. Backend `PricingService` resolves the `xl` price from `$dbVariations[123]`.

## Risks & open questions

- **Q1 (owner gate):** P1-06 emoji-only FritesStyle cards — should the owner commit thumb assets (preferred for marketing), or accept emoji long-term? Confirmed acceptable in Round 3 2026-05-10 but design refresh project (memory `project_kiosk_design_refresh_2026-05-10`) may revisit.
- **Q2 (owner gate):** P1-05 auto-preselection on `default_menu_kiosk` — is this UX intentional? Customer may complete order without realizing they paid +3€. Suggest opt-in with a "Modify" CTA in the validation-hint band.
- **Q3 (architecture):** Generic step component uses `composer_step.id` OR `step_key` OR `label` as a stable identifier (line 48). If admin renames `label` mid-session, stale `composerChoices[stepId]` keys may orphan. Should we lock identity on `step_key`-only?
- **Q4 (NF525 — already validated):** `_wizardSelections` UI snapshot is correctly stripped by `sanitizeKioskOrderItem` (verified by `kioskWizardEditRoundtrip.spec.js`). No fix needed; documented for completeness.
- **Q5 (i18n strategy):** FR-lock V1 per CLAUDE.md §2 makes EN/AR gaps lower priority for V1 merge. P1-01 + P1-02 are V1.0.1 sprint candidates unless owner decides to surface multilocale in V1.

## Summary

- **P0:** 0
- **P1:** 6 (mostly a11y + i18n gaps)
- **P2:** 5
- **P3:** 5

**NF525 SSOT contract:** RESPECTED. No price field is leaked from the 5 step components; backend `PricingService` recomputes all totals from DB and seals `composition_snapshot` immutably.

**Verdict:** GO V1 (no blocker). Heal P1-01/P1-02/P1-03/P1-04 in V1.0.1 sprint to close a11y/i18n gaps. P1-05 (auto-preselection) and P1-06 (emoji cards) need owner gate before any action.
