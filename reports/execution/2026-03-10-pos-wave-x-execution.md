# POS Wave X — Execution Report
**Date:** 2026-03-10  
**Wave:** X (X1–X8)  
**Triggered by:** Deep audit following Wave V completion

---

## Summary

8 issues fixed across 3 files. 6 HIGH, 2 MEDIUM severity.  
Focus: `buildWizardInstruction` correctness, cart-edit restore completeness, `syncAndSubmit` extra viandes, cart display UX.

---

## Fixes Applied

### X1 — HIGH: `buildWizardInstruction` viande keys — `v_<id>` vs `viande_<key>` mismatch
**File:** `public/js/pos-wizard.js`  
**Root cause:** `buildWizardInstruction` iterated `viandeItemsForInstruction` using `v.key` (`viande_<key>` format). Single-page buttons store counts under `v_<id>` keys. Both are initialised to 0 in `buildSteps` (line ~572), but only one is incremented per path. Result: single-page viandes were always 0 in the recap.  
**Fix:** Check both `selections.viandes['v_' + v.id]` and `selections.viandes[v.key]` and sum them. Since only one path writes to each key, the sum is always the correct count.

### X2 — HIGH: `buildWizardInstruction` missing `addon_N` formule path
**File:** `public/js/pos-wizard.js`  
**Root cause:** The `menuChoice` section handled `'full'`, `'frites'`, `'boisson'`, and `'individual'` but not `'addon_<N>'` — the primary single-page formule format. The recap showed no `FORMULE:` line for the most common case.  
**Fix:** Added `addon_N` match at the top of the `menuChoice` block, resolving the real addon name from `lastItemData.addons`.

### X3 — HIGH: `buildWizardInstruction` sauce frites `sf_<id>` string vs numeric id
**File:** `public/js/pos-wizard.js`  
**Root cause:** `sauceFritesOrder` stores `'sf_<id>'` strings on the single-page path. `sfItems.find(ss => ss.id === id)` compared a string to a number — always false. `SAUCE FRITES:` was always missing from the recap.  
**Fix:** Extract numeric id from `sf_<id>` string before the `find()` comparison (same pattern already used in `buildTicketInstruction`).

### X4 — HIGH: `boissonChoice` not persisted/restored on cart edit
**Files:** `public/js/pos-wizard.js`, `resources/js/components/admin/pos/ItemComponent.vue`  
**Root cause:** `menu_restore` in `addonToPayload` did not include `boissonChoice`. `buildWizardRestorePayload` had no `boissonChoice` field. Editing a menu order lost the boisson selection.  
**Fix:** Added `boissonChoice: selections.boissonChoice || null` to `menu_restore`. Added `boissonChoice: null` to restore object and restored it from `mr.boissonChoice` in the addons section.

### X5 — HIGH: `viandeSupplItems` not persisted/restored on cart edit
**Files:** `public/js/pos-wizard.js`, `resources/js/components/admin/pos/ItemComponent.vue`  
**Root cause:** Same as X4 — `menu_restore` did not include `viandeSupplItems`. Editing an order with paid extra viandes lost all extra viande counts.  
**Fix:** Added `viandeSupplItems: JSON.parse(JSON.stringify(selections.viandeSupplItems))` to `menu_restore`. Added `viandeSupplItems: {}` to restore object and restored from `mr.viandeSupplItems`.

### X6 — HIGH: `syncAndSubmit` had no DOM sync for `viandeSupplItems`
**File:** `public/js/pos-wizard.js`  
**Root cause:** Extra viandes were reflected in `buildTicketInstruction` (instruction text) but not synced to Vue's `item_extras` checkboxes. The structured `item_extras` field on the cart line was missing extra viande IDs.  
**Fix:** Added `viandeSupplItems` to the `allSelectedExtras` map in `syncAndSubmit` — each extra viande id with count > 0 is marked as a selected extra, so it gets included in `item_extras.extras` and `item_extras.names`.

### X7 — MEDIUM: `buildWizardInstruction` garnitures — default-included crudités not shown
**File:** `public/js/pos-wizard.js`  
**Root cause:** The garnitures section used `cVal === true || numVal === true` as the "included" condition. Default-included crudités initialized to `true` in `buildSteps` but possibly `undefined` after a partial restore were not shown. `buildTicketInstruction` uses the correct `!== false` semantics.  
**Fix:** Changed to "not explicitly false = included" logic, aligning with `buildTicketInstruction`.

### X8 — MEDIUM: Cart instruction truncated at 80 chars — too short for complex orders
**File:** `resources/js/components/admin/pos/PosComponent.vue`  
**Root cause:** The cart line instruction preview was capped at 80 characters. Complex wizard instructions (2 viandes + sauce + garnitures + menu + supplements) routinely exceed 80 chars, making pre-checkout verification difficult.  
**Fix:** Raised cap to 160 characters. Also added `whitespace-pre-line` class so multi-line instructions render with proper line breaks in the cart.

---

## Files Changed

| File | Changes |
|------|---------|
| `public/js/pos-wizard.js` | X1 (viande key dual-check), X2 (addon_N formule), X3 (sf_ id parse), X4+X5 (menu_restore fields), X6 (viandeSupplItems → allSelectedExtras), X7 (garnitures not-false semantics) |
| `resources/js/components/admin/pos/ItemComponent.vue` | X4 (boissonChoice restore), X5 (viandeSupplItems restore) |
| `resources/js/components/admin/pos/PosComponent.vue` | X8 (instruction cap 160, pre-line) |

---

## Risk Assessment

- **X1**: Low — additive check, sum is correct since only one path writes each key.
- **X2**: Low — new branch before existing ones, no existing path affected.
- **X3**: Low — same pattern already used in `buildTicketInstruction`.
- **X4+X5**: Low — additive fields in `menu_restore`; old cart lines without these fields gracefully fall through (null/empty).
- **X6**: Medium — extra viandes now appear in `item_extras`. Backend price recalculation must handle these extras correctly (they should already be in the catalog as extras with a price). If not configured as extras in the catalog, they'll be ignored by the server-side price calc.
- **X7**: Low-medium — "not false = included" may show more garnitures in recap than before. This is the correct behavior matching `buildTicketInstruction`.
- **X8**: Low — cosmetic/UX change only.

---

## Suggested Playwright / E2E verification Tests

1. **X1**: Add Tacos XL (2 viandes: Kefta + Viande Hachée) → verify recap shows `VIANDES: Kefta, Viande Hachée`.
2. **X2**: Add item with menu formule (addon_N) → verify recap shows `FORMULE: <addon name>`.
3. **X3**: Add item with menu + sauce frites → verify recap shows `SAUCE FRITES: <sauce name>`.
4. **X4**: Add menu order with Coca Cola boisson → edit from cart → verify Coca Cola is pre-selected in wizard.
5. **X5**: Add item with 2 extra viandes → edit from cart → verify extra viande counts are restored.
6. **X6**: Add item with extra viandes → checkout → verify extra viande IDs appear in `item_extras`.
7. **X7**: Add sandwich (default crudités: tomate, oignon, cornichon) → verify recap shows all 3 in `GARNITURES:`.
8. **X8**: Add complex order → verify cart instruction shows 160 chars with line breaks.

---

## Next Steps

All known issues through Wave X have been addressed. The POS system now has:
- Correct `buildWizardInstruction` for all single-page paths (viandes, formule, sauce frites, garnitures)
- Complete cart-edit restore for all selection types (boissonChoice, viandeSupplItems)
- Extra viandes properly represented in structured `item_extras`
- Better cart display for complex orders

Recommended: **Playwright / E2E verification full E2E retest** with focus on complex menu orders (2 viandes + menu + extras + sauce frites) and cart edit round-trips.
