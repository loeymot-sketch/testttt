# K06a — Wizard steps primary (Viande/Sauce/Garnitures/Pain)

> Branch `feature/mobile-app-le-cayenne-2026-05-10` HEAD `6a33a9763`.
> Scope read-only audit per `00_ULTRA_PLAN.md`. No code modified.

## Files audited

- `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue` — 689 lines
- `resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue` — 479 lines
- `resources/js/components/frontend/kiosk/steps/KioskStepGarnituresComponent.vue` — 393 lines
- `resources/js/components/frontend/kiosk/steps/KioskStepPainComponent.vue` — 264 lines
- `resources/js/components/frontend/kiosk/ds/KsAllergenBadge.vue` — 212 lines (NOT imported by steps; rendered in parent `KioskWizardComponent.vue:29` header — persistent mid-step)
- `tests/js/kioskStepSauceA11y.spec.js` — 103 lines (existing baseline)
- `resources/js/helpers/kioskViandeCatalog.js` — 142 lines (helper)
- `resources/js/helpers/kioskSauceCatalog.js` — 61 lines (helper — NOT used by Sauce step despite shared logic)

## Findings

### P0 (blocker pre-merge V1)

_None._ All four steps render real catalog data with empty-state fallbacks, OOS guards, filter-aware disabling, and `aria-checked`/`aria-disabled` semantics consistent with a working tactile + keyboard UX. No NF525 leak (frontend never sends prices). No frozen-zone touch.

### P1 (high — V1.0.1 sprint)

- **K06a-P1-01: Viande card ARIA semantics are non-standard for a multi-select tile that also exposes ± controls.**
  - File: `KioskStepViandeComponent.vue:27-43`
  - Issue: Outer `<div>` carries `role="group"` + `tabindex="0"` + `@click=selectFromCard` + `@keydown.enter|space=selectFromCard`. A `group` is not a focusable widget; the click handler turns it into a de-facto button while the inner +/- block is also `role="group"` (line 81). Screen readers may announce "group, group" without exposing the increment action, and there is no `aria-pressed`/`aria-checked` reflecting selected state. The grid container has no parent `role="group"` either.
  - Evidence: line 35 `role="group"` on tile; line 81 nested `role="group"` on quantity controls; no `aria-checked` because the cell may hold count >1 (legit multi-quantity). Compare with Sauce (`role="checkbox"`, line 30) and Pain (`role="radio"`, line 16) that follow ARIA APG.
  - Suggested fix: convert to `role="spinbutton"` with `aria-valuenow`/`aria-valuemin=0`/`aria-valuemax=maxViandes` on the outer tile (or split: an inner `<button>` "Add" then ±, both reachable). Keep `aria-label="<name>, <count>"`.

- **K06a-P1-02: No initial focus on first selectable card when a step mounts.**
  - File: all 4 steps (no `mounted()` focus logic); parent `KioskWizardComponent.vue:2199+` traps Tab but does not focus first choice on `currentStepIndex` change.
  - Issue: WCAG 2.4.3 + keyboard-only path requires the first interactive of a newly mounted step to receive focus. Today a screen-reader user lands somewhere indeterminate after Next.
  - Suggested fix: emit `focus-first` from step on `mounted` or have parent wizard call `firstFocusable.focus()` after `currentStepIndex` watch. The parent's existing `firstBtn?.focus()` at line 2308 fires once on dialog open, not on step change.

- **K06a-P1-03: Pain action badge uses hardcoded `right: 20px` (LTR-only) instead of `inset-inline-end`.**
  - File: `KioskStepPainComponent.vue:231`
  - Issue: Sister steps Sauce (`inset-inline-end: 22px`, line 432/449) and Garnitures (line 366) use logical properties; Pain regresses. Under `dir="rtl"` (AR locale via `kioskRtl.spec.js`) the ✓/+ badge will sit on the wrong side, breaking visual mirroring.
  - Suggested fix: replace `right: 20px` with `inset-inline-end: 20px`.

- **K06a-P1-04: Per-card allergen disclosure missing — header badge merges all selections but does not surface which variation/extra introduces the allergen.**
  - File: 4 step files (no `KsAllergenBadge` import) + `KioskWizardComponent.vue:29-37` (header, frozen).
  - Issue: EU FIC 1169/2011 + EAA 2025 ask the consumer to identify the allergen-bearing ingredient. The header badge resolves union allergens via `allergenBadgeSelections` (wizard:462) — strong baseline — but a user choosing between "Sauce Algérienne (mustard)" vs "Sauce Blanche (milk)" cannot tell which carries which. No `aria-describedby` linking the card to a per-item allergen list.
  - Suggested fix: render a compact `<KsAllergenBadge :allergens="sauce.raw.allergens" compact />` inside each Sauce/Viande/Garniture card (read-only, no merge) and add `aria-describedby="card-<id>-allergens"` to the card. The header badge stays as global guard.

### P2 (medium — backlog)

- **K06a-P2-01: Sauce step duplicates `computeSauceList` logic instead of using shared helper `kioskSauceVariationRowsForItem`.**
  - File: `KioskStepSauceComponent.vue:122,161` vs `helpers/kioskSauceCatalog.js:46`.
  - Issue: Two copies of sauce-attr detection (`isSauceLikeAttributeName`, `normalizeAttributes`, `variationsRowsForAttribute`, emoji map) — drift risk. The shared helper is what the menu step calls for frites-sauces, so the same product can yield two slightly different sauce lists. Same applies to the parent wizard line 765 which uses the shared helper for `canAdvance` validation — yet the step itself uses its own copy.
  - Suggested fix: import and call `kioskSauceVariationRowsForItem(this.item)` from `computed.sauceList`.

- **K06a-P2-02: Sauce + Garnitures grids lack a parent `role="group" aria-label`.**
  - File: `KioskStepSauceComponent.vue:20` (`.kiosk-sauce-grid`), `KioskStepGarnituresComponent.vue:14` (`.kiosk-garnitures-list`).
  - Issue: Pain (`role="radiogroup"`, line 9) and other steps (Taille, Menu, FritesStyle) carry a labelled group container. Sauce/Garnitures expose isolated `role="checkbox"` items without an explicit container — screen reader users hear "checkbox, checkbox…" with no group context. ARIA APG recommends a labelled wrapper for multi-checkbox patterns.
  - Suggested fix: wrap `.kiosk-sauce-grid` and `.kiosk-garnitures-list` in `<div role="group" :aria-label="$t('kiosk.wizard.step.sauce.title')">` (or `aria-labelledby` linking to the existing `<h3>` via `id`).

- **K06a-P2-03: Image fallback OK but emoji fallback names are heuristic and may mis-match real catalog.**
  - File: `kioskViandeCatalog.js:36-46` (`pickEmojiForViande`), `KioskStepSauceComponent.vue:196-205` (`getEmojiForSauce`), `KioskStepGarnituresComponent.vue:142-153`, `KioskStepPainComponent.vue:109-114`.
  - Issue: Pure substring match (e.g. "poulet" → 🍗) is FR-only and won't match AR names. When the seeder uses an Arabic name, fallback resolves to `🥩` (generic). Image fallback chain `viande.thumb && !brokenViandeThumbs` (line 56) is correct; emoji is only seen if no thumb. Acceptable but worth documenting as locale-aware backlog.
  - Suggested fix: extend emoji map with AR translit keywords OR move emoji to backend `Item::allergen_icon` / `Variation::icon` field.

- **K06a-P2-04: `_skip` defensive sentinel in Sauce empty-state may pollute `sauceOrder` downstream.**
  - File: `KioskStepSauceComponent.vue:17` emits `('update','sauceOrder',['_skip'])` when catalog empty.
  - Issue: `_skip` is a magic string; downstream consumers (`compositionSummaryChips`, backend payload mapper) must filter it. If a consumer forgets, the order could carry an invalid sauce id.
  - Suggested fix: verify wizard `buildCartItem` and order-creation request strip `'_skip'` from `sauceOrder` before submit (defensive read-through audit, then either keep the sentinel + add a guard or just emit `[]`).

### P3 (low — nice-to-have)

- **K06a-P3-01: Sauce step has no `max_select` cap.** `canAdvance` only checks `length > 0`. A customer can add 50 sauces. Product decision, not a code bug.
- **K06a-P3-02: Viande paid-extras cap is hardcoded `< 9`** (`KioskStepViandeComponent.vue:265`). Magic number; consider exposing via catalog field.
- **K06a-P3-03: Viande `aria-label` template `${name}${price}, ${count}` (line 286)** — comma-only separator; screen reader pause may merge. Consider `'.'` separator and prefix count with `'sélectionnés'` for locale-friendly speech.
- **K06a-P3-04: Garnitures `userInteracted` watch-gate (line 84,98)** is a known mount-race workaround; refactor to a `defineProps` + `defineEmits` v-model is a backlog item (Vue 3 idiom).

## Existing E2E coverage

- `tests/js/kioskStepSauceA11y.spec.js` — locks `role=checkbox`, `tabindex=0`, `aria-checked`, Enter/Space keyboard activation, polite live-region hint. Strong baseline (5 cases).
- `tests/js/kioskViandeCatalog.spec.js` — helper unit coverage (variations/extras/dedup/OOS surfacing). 9 cases, healthy.
- `tests/js/kioskSauceCatalog.spec.js` — helper unit coverage but NOT consumed by step (see P2-01).
- `tests/js/kioskRtl.spec.js` — locale + `dir/lang` switching at document level; does NOT exercise step components.
- `tests/js/KioskWizard.spec.js` — large parent-wizard suite (75 KB) covering navigation, composition, edit-mode restore. Touches steps indirectly.
- `tests/js/kioskAllergenMerge.spec.js` — verifies `mergeAllergens` helper.

**Coverage gaps**: Viande step (zero direct a11y/keyboard spec), Garnitures step (zero), Pain step (zero), RTL × steps (zero), allergen mid-step linkage (zero per-card spec).

## Proposed new E2E tests

- **T-K06a-01 — Viande ARIA contract (spinbutton or refactor)**
  - Steps: mount `KioskStepViandeComponent` with mock item exposing 2 variations + 1 paid extra; press Tab through cards; Enter to select; ± keyboard to increment.
  - Assertions: tile exposes `aria-valuenow`, `aria-valuemax=maxViandes`; `aria-disabled` when filter blocks; nested ± remain operable; SR-announced state matches `localSelections[key]`.

- **T-K06a-02 — Step initial focus moves to first card on mount and on step transition**
  - Steps: mount wizard with Viande as first step → assert `document.activeElement` is the first non-disabled card; navigate Next to Sauce → re-assert.
  - Assertions: `document.activeElement.dataset.testid === 'kiosk-card-first'`; consistent across Pain/Sauce/Garnitures/Viande.

- **T-K06a-03 — Pain action badge respects RTL (`inset-inline-end`)**
  - Steps: `setLocale('ar')`; mount `KioskStepPainComponent`; query `.kiosk-pain-action` computed style.
  - Assertions: under `dir=rtl`, the badge's left coordinate equals `0` + offset (not `right`); badge visually mirrors. (Fails today on hardcoded `right: 20px`.)

- **T-K06a-04 — Per-card allergen badge appears on Sauce/Viande when variation carries allergens**
  - Steps: mount Sauce step with `variations.raw.allergens = ['lait']`; assert `KsAllergenBadge` rendered inside card with `compact=false`; assert `aria-describedby` on card references the badge id.
  - Assertions: per-card disclosure independent of header merge; ensures EU FIC 1169/2011 per-variation traceability.

- **T-K06a-05 — Garnitures & Sauce parent group is labelled**
  - Steps: mount both steps with a non-empty catalog.
  - Assertions: parent `.kiosk-sauce-grid` and `.kiosk-garnitures-list` carry `role="group"` and `aria-label` matching the step title (mirrors Pain's `role="radiogroup"`).

## Risks & open questions

- **R1 (owner gate)**: Per-card allergen surfacing implies adding `KsAllergenBadge` inside frozen-zone-adjacent step files. Steps Viande/Sauce/Garnitures/Pain are **NOT** in the frozen list (only `KioskWizardComponent.vue`, `KioskAppComponent.vue`, `KioskUpsellComponent.vue`), so the fix is in-scope for V1.0.1.
- **R2 (compliance interpretation)**: EAA 2025 + EU FIC 1169/2011 — does the header-merge badge (live-updated with current selections) suffice for "visible identification of allergens before order"? The mid-step header IS visible. A compliance officer review is recommended before declaring P1-04 closed or downgraded.
- **R3 (Sauce sentinel)**: Confirm `_skip` is stripped at order creation (`FrontendOrderController` mapping) — out of K06a scope, route to K18.
- **R4 (Viande paid extras `< 9` cap)**: Owner to confirm whether 8 is the intended max or whether a per-product catalog field is desired (P3-02).
