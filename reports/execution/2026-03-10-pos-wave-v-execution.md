# POS Wave V — Execution Report
**Date:** 2026-03-10  
**Wave:** V (V1–V9)  
**Triggered by:** Deep audit following Wave W completion

---

## Summary

9 issues fixed across 4 files. 2 HIGH, 4 MEDIUM, 3 LOW severity.

---

## Fixes Applied

### V1 — HIGH: Viande supplémentaire `+` buttons incorrectly disabled by main quota
**File:** `public/js/pos-wizard.js`  
**Root cause:** `updateSinglePageUI` used `.viande-btn.plus` selector which matched BOTH main viande `+` buttons AND supplementary viande `+` buttons (class `viande-suppl-btn viande-btn plus`). When main quota was full, all `+` buttons were disabled — including paid extras.  
**Fix:** Changed selector to `.viande-btn.plus:not(.viande-suppl-btn)` so supplementary viande buttons remain enabled regardless of main quota.

### V2 — HIGH: `buildPosCheckoutOrderRow` variation/name zip by index
**Files:** `resources/js/components/admin/pos/ItemComponent.vue`, `PosComponent.vue`  
**Root cause:** `variations` is keyed by `attrId`, `names` by `attrName`. Zipping by index works in normal flow but diverges after partial restores or out-of-order attribute insertion.  
**Fix:** `changeVariation` now also populates `item_variations.names_by_id[attrId] = {attrName, varName}`. `buildPosCheckoutOrderRow` uses this map for a true key-based join, falling back to index-zip for legacy cart lines.

### V3 — MEDIUM: Address IDOR — no ownership check in `posOrderStore`
**File:** `app/Services/OrderService.php`  
**Root cause:** `Address::find($request->address_id)` did not verify the address belongs to `$request->customer_id`. A manipulated payload could copy another customer's address onto an order.  
**Fix:** Changed to `Address::where('id', ...)->where('user_id', $request->customer_id)->first()`.

### V4 — MEDIUM: `cartQuantityUp` stores string quantity from DOM input
**File:** `resources/js/components/admin/pos/PosComponent.vue`  
**Root cause:** `e.target.value` is always a string; it was passed directly to Vuex `quantity` mutation which stored it as-is in the `else` branch.  
**Fix:** `parseInt(e.target.value, 10)` with `isNaN` guard before dispatching.

### V5 — MEDIUM: Instruction length cap 190 vs API 500
**File:** `resources/js/components/admin/pos/ItemComponent.vue`  
**Root cause:** The `temp.instruction` watcher capped at 190 chars; the backend `ValidJsonOrder` rule allows 500. Complex wizard-generated instructions (multi-viande + sauce + garnitures + menu) can exceed 190.  
**Fix:** Raised cap to 500 to match the API rule.

### V6 — MEDIUM: Garniture chip emoji corrupted by `charAt(0)` on multi-unit emojis
**File:** `public/js/pos-wizard.js`  
**Root cause:** `btn.textContent.trim().charAt(0)` returns only the first UTF-16 code unit; multi-unit emojis (🥬, 🧅, 🥒) produce a lone surrogate, corrupting the chip label after each toggle.  
**Fix:** `Array.from(btn.textContent.trim())[0]` which correctly handles full grapheme clusters.

### V7 — MEDIUM: `openEditFromCart` failure is silent
**File:** `resources/js/components/admin/pos/ItemComponent.vue`  
**Root cause:** The `.catch()` block reset internal state but showed no feedback. Cashier would see nothing happen when the edit modal failed to load.  
**Fix:** Added `alertService.error(...)` in the catch block.

### V8 — LOW: Best sellers hard-coded by name substring
**File:** `resources/js/components/admin/pos/PosComponent.vue`  
**Root cause:** `bestSellerItems` computed property used a hard-coded name list. Renaming items in admin would silently break the landing page best sellers section.  
**Fix:** Now checks `item.is_featured == 1` first (already returned by `ItemResource`). Falls back to hard-coded names only if no featured items are configured.

### V9 — LOW: `resetCart` has no success feedback
**File:** `resources/js/components/admin/pos/PosComponent.vue`  
**Root cause:** Cart was cleared silently; cashier had no confirmation.  
**Fix:** Added `alertService.success(...)` in the `.then()` callback.

---

## Files Changed

| File | Changes |
|------|---------|
| `public/js/pos-wizard.js` | V1 (suppl button selector), V6 (emoji charAt fix) |
| `resources/js/components/admin/pos/ItemComponent.vue` | V2 (names_by_id), V5 (instruction cap 500), V7 (error toast) |
| `resources/js/components/admin/pos/PosComponent.vue` | V2 (key-based join), V4 (parseInt qty), V8 (is_featured), V9 (reset toast) |
| `app/Services/OrderService.php` | V3 (address ownership) |

---

## Risk Assessment

- **V1**: Low risk — only changes CSS class selector, no data flow change.
- **V2**: Low-medium risk — `names_by_id` is additive; fallback preserved for legacy lines.
- **V3**: Low risk — adds a constraint that was always semantically required.
- **V4**: Low risk — `parseInt` is idempotent for valid numeric input.
- **V5**: Low risk — raises a cap, does not change logic.
- **V6**: Low risk — cosmetic fix for emoji rendering.
- **V7**: Low risk — adds feedback only.
- **V8**: Low risk — `is_featured` check is additive; fallback preserved.
- **V9**: Low risk — adds feedback only.

---

## Suggested Playwright / E2E verification Tests

1. **V1**: Open a Tacos XL (2 viandes), fill both main slots → verify "Viande Supplémentaire" `+` buttons remain clickable and add extras correctly.
2. **V2**: Add item with 3 variation attributes (e.g. pain + sauce + accompagnement) → verify all 3 variation names appear correctly on receipt and KDS instruction.
3. **V3**: Attempt to submit a POS delivery order with an `address_id` belonging to a different customer → verify order is created without that address (or returns error).
4. **V4**: Type `5` manually in a cart quantity input → verify quantity stored as integer `5`, not string `"5"`.
5. **V5**: Generate a complex instruction (2 viandes + 2 sauces + 4 garnitures + menu + 2 supplements) → verify it is not truncated in the cart or on the KDS.
6. **V6**: Toggle a garniture chip multiple times → verify emoji remains intact and label reads correctly.
7. **V7**: Simulate a network failure during "Edit from cart" → verify error toast appears.
8. **V8**: Mark items as `is_featured` in admin → verify POS landing shows those items as best sellers instead of the hard-coded list.
9. **V9**: Click "Reset Cart" → verify success toast appears.

---

## Next Steps

All known issues through Wave V have been addressed. The POS order flow (cashier → wizard → cart → checkout → KDS → receipt) is now at a high level of correctness, security, and UX quality.

Recommended next action: **Playwright / E2E verification full E2E retest** covering:
- New order with complex menu (2 viandes + menu + supplements)
- Edit from cart and re-submit
- Delivery order with address
- Manual quantity edit
- Reset cart
