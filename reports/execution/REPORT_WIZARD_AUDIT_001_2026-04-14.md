# Execution Report — WIZARD_AUDIT_001 — 2026-04-14

EXECUTE_DELEGATION: app-complex-implementer

## Summary

12 steps executed. 6 files modified. 6 audit-only findings documented. No frozen zone edits. No invariant violations. No SCOPE_PRESSURE or ESCALATION.

## Step Results

### Step 1 — P1: Template detection hardening (Kiosk) — CHANGED

**File:** `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`

**Finding:** `effectiveWizardTemplate()` checked only `item.wizard_template` (from API, derived from category). `NormalItemResource` does not include a `category` sub-object, but `ItemResource` (POS) does. Both expose `wizard_template` at the top level from `optional($this->category)->wizard_template`.

**Fix:** Extended priority chain to: `item.wizard_template` → `item.category?.wizard_template` → `detectTemplateFromName()`. The second level is defensive hardening for items loaded via `ItemResource` which includes a full category object.

### Step 2 — P2: Pre-selection cleanup — CHANGED

**Files:** `KioskWizardComponent.vue`, `KioskStepGarnituresComponent.vue`

**Finding:** `initGarnitures()` already runs at the correct time (inside `fetchItemById()`, line 706, immediately after item resolution). The race condition was in the step component: the watcher synced parent → local only when `localSelections` was empty, but didn't handle the case where parent data was already populated at mount time.

**Fix:**
- Added `mounted()` hook to `KioskStepGarnituresComponent` to adopt parent garnitures immediately if already populated.
- Added `$nextTick` guard to the watcher.
- Added `userInteracted` flag to prevent watcher from overriding user choices after toggle.

### Step 3 — P3: Viande count alignment — CHANGED

**Files:** `KioskWizardComponent.vue`, `KioskStepViandeComponent.vue`

**Finding:** `KioskStepViandeComponent.maxViandes` had its own name heuristic (lines 85-89) that duplicated `detectViandeCount()` from the wizard. When `_tailleMeta.viandeCount` was absent (non-tacos templates), the two heuristics could diverge.

**Fix:**
- In `KioskWizardComponent.fetchItemById()`: when `inferTacosPresetMeta()` returns null AND `_tailleMeta.viandeCount` is not yet set, pre-seed `_tailleMeta.viandeCount` from `detectViandeCount()`.
- In `KioskStepViandeComponent.maxViandes`: removed all name heuristics. Now reads only `selections._tailleMeta?.viandeCount || 1` — single source of truth from parent wizard.

### Step 4 — P4: Supplement filter audit — CHANGED

**Files:** `KioskStepSupplementsComponent.vue`, `KioskWizardComponent.vue`, `kioskPricing.js`

**Finding:** Filter used `groupLabel.includes('sauce')` which could substring-match `group_label` values like "sauce_spéciale" or "sauce_fromagère" — excluding legitimate supplements.

**Fix:** Changed to exact match when `group_label` is non-empty: `groupLabel === 'sauce'`. When `group_label` is empty, kept name heuristic (`name.includes('sauce')`). Applied consistently across all three files.

### Step 5 — P5: Variation format audit — NO CHANGE (audit only)

**Finding:**
- **Kiosk sends:** `item_variations: [{id, variation_name, name}]`
- **POS sends:** `item_variations: [{id, item_id, item_attribute_id, variation_name, name}]`
- **Backend reads:** `$var->id` for price lookup. Does not use `item_id`, `item_attribute_id`, or `variation_name` for pricing.
- **Conclusion:** Both formats are compatible. Backend's `pluck('item_variations')->flatten(1)->pluck('id')` works with both. The extra POS fields are harmless metadata. No format mismatch.

### Step 6 — P6: Menu addon pricing audit — NO CHANGE (audit only)

**Finding:** `getKioskMenuAddonPrice()` selects the first addon with "menu" in `addon_item_name`. The data model expects one "Formule Menu" addon per item. The `menuChoice` parameter (full/frites/boisson) determines the price ratio. No seeder data contradicts this pattern. If multiple "menu" addons existed, only the first would be used — acceptable for current data model.

### Step 7 — P7: Cart merge signature fix — CHANGED

**File:** `resources/js/store/modules/kioskCart.js`

**Finding:** Kiosk `ADD_ITEM` merge used `item_id + stringify(item_variations) + stringify(item_extras)`. Two identical items with different instructions (e.g., "sans oignon" vs "") would incorrectly merge. POS cart already checks instruction + bundled addons signature.

**Fix:** Added `(i.instruction || '') === (item.instruction || '')` to the kiosk merge comparison.

### Step 8 — P8: pos-wizard.js confirmation — NO CHANGE (audit only)

**Finding:** `master.blade.php` line 124 loads `<script src="{{ asset('js/pos-wizard.js') }}?v=9-{{ time() }}">`. The file is ACTIVE. The `wizard:add-to-cart` event bridge to `ItemComponent.vue` is functional. `pos-wizard.js` `detectCategory()` uses API `wizard_template` as priority 1, name heuristic as fallback — same pattern as kiosk.

### Step 9 — P9: Sauce key consistency — NO CHANGE (audit only)

**Finding:** `KioskStepSauceComponent.sauceKey()` normalizes to numeric ID when possible, falls back to name. `kioskFindSauceVariation()` in the wizard tries ID-based lookup first (`String(v.id) === String(key)`), then name match. Fallback sauce list (id=null) cannot map to item_variations because the sauceAttr guard prevents it. No collision risk.

### Step 10 — P10: Payment logic audit — NO CHANGE (audit only)

**Finding:**
- **Cash:** `pos_received_amount` read from DOM, sent to `posOrder/save` → `POST admin/pos`. Backend validates.
- **Card:** `pos_payment_note` = last 4 digits. Informational only.
- **Total:** Calculated in `PosComponent` as `subtotal + delivery - discount`. Backend recalculates from DB prices.
- **Conclusion:** No client-side pricing SSOT violation. Backend is authoritative.

### Step 11 — ItemComponent addon.variations bug fix — CHANGED

**File:** `resources/js/components/admin/pos/ItemComponent.vue`

**Bug:** Line 685: `addon.variations !== "undefined"` compared against the string literal `"undefined"`. When `addon.variations` is actually `undefined`, this evaluates to `true` (since `undefined !== "undefined"`), then `Object.keys(undefined)` throws TypeError.

**Fix:** Changed to `typeof addon.variations !== 'undefined' && addon.variations && Object.keys(addon.variations).length !== 0`.

## Files Changed

| File | Change |
|---|---|
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | P1: category template fallback; P3: pre-seed _tailleMeta.viandeCount; P4: sauce filter tightened |
| `resources/js/components/frontend/kiosk/steps/KioskStepGarnituresComponent.vue` | P2: mounted() adoption, nextTick watcher, userInteracted flag |
| `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue` | P3: removed duplicated heuristic, single source of truth |
| `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue` | P4: sauce filter tightened to exact match |
| `resources/js/helpers/kioskPricing.js` | P4: sauce filter tightened to exact match |
| `resources/js/store/modules/kioskCart.js` | P7: instruction added to merge signature |
| `resources/js/components/admin/pos/ItemComponent.vue` | P11: typeof guard for addon.variations |

## Invariant Verification

- **Backend pricing SSOT:** No frontend pricing logic added. kioskPricing.js changes are display-only filter logic, not pricing calculations.
- **OrderStatus enum:** Not touched.
- **branch_id isolation:** Not touched. Wizard loads items via branch-scoped API (unchanged).
- **Dispatch after DB commit:** Not touched.
- **Frozen zones:** OrderService.php and FrontendOrderService.php read-only (P5 audit). Not modified.
- **OrderService/FrontendOrderService symmetry:** Both confirmed to accept the same `item_variations` format (arrays with `id` field). No asymmetry found.

## SCOPE_PRESSURE

None.

## ESCALATION

None.

## SYMMETRY_NOTE

P5 audit confirmed: both OrderService and FrontendOrderService iterate `item->item_variations` and read `$var->id` for price lookup. Kiosk sends `[{id, variation_name, name}]`; POS sends `[{id, item_id, item_attribute_id, variation_name, name}]`. Both formats compatible — backend ignores extra fields. No asymmetry.

## Audit

**Audit: PASSED**

Date: 2026-04-14
Auditor: Claude (Planner-Orchestrator)

All scope, invariant, symmetry, and validation checks passed. No gate required.

### Playwright follow-up
Plan declares `playwright-critical-flow`. Flows to validate on `localhost:8000`:
1. Kiosk: idle → type → tacos XL (2 viandes) + sauce + formule → panier
2. POS: login → produit → variante + extra → cash → KDS
Developer should run when app is live.
