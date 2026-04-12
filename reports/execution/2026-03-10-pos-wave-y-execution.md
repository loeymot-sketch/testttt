# POS Wave Y — Execution Report
**Date:** 2026-03-10  
**Wave:** Y (Y1–Y6)  
**Triggered by:** Deep audit following Wave X completion

---

## Summary

6 issues fixed across 6 files. 2 HIGH, 4 MEDIUM (1 cancelled — not a real bug).  
Focus: cart-edit quantity sync, null-safety on receipt/KDS, coupon/address error feedback, landing page robustness.

---

## Fixes Applied

### Y1 — HIGH: `itemQuantity` not synced from cart on edit
**Files:** `public/js/pos-wizard.js`, `resources/js/components/admin/pos/ItemComponent.vue`  
**Root cause:** When editing a cart line, `openWizard` restored `selections` but never set `itemQuantity` from the cart line's quantity. The `else` branch (new order) resets to 1, but the `if` branch (edit-restore) left `itemQuantity` at whatever it was from the previous wizard use. `syncAndSubmit` writes `itemQuantity` into the Vue qty input, so saving an edit would overwrite the real cart quantity with the wrong value.  
**Fix:** Added `_cartQuantity` to the restore payload in `buildWizardRestorePayload`. In `openWizard`, `itemQuantity = restored._cartQuantity` is set when restoring.

### Y2 — HIGH: Receipt and KDS crash on null `item_variations` / `item_extras`
**Files:** `resources/js/components/admin/posOrders/PosOrderReceiptComponent.vue`, `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`  
**Root cause:** Both files accessed `.length` directly on `item_variations` and `item_extras` without null/array guards. Older order rows (or addon rows with empty arrays) could have `null` or non-array values, causing runtime errors and crashing the receipt/KDS view.  
**Fix:** Changed all `v-if="item.item_extras.length > 0"` to `v-if="Array.isArray(item.item_extras) && item.item_extras.length > 0"` (and same for `item_variations`). Applied to all 5 occurrences in KDS (items board + dine-in + online + takeaway + kiosk) and 2 in receipt.

### Y3 — CANCELLED: `calculateRunningTotal` omits `boissonChoice` surcharge
**Verdict:** Not a real bug. `boissonChoice` selects which boisson within the menu addon — the boisson is already included in the addon price. No surcharge applies.

### Y4 — MEDIUM: Invalid coupon → silent zero discount
**File:** `app/Services/OrderService.php`  
**Root cause:** If `Coupon::find($request->coupon_id)` returned null (coupon not found or deleted), `$calculatedDiscount` stayed 0 and the order was created without any discount — with no error returned to the cashier. The customer expecting a discount would get none.  
**Fix:** Added `throw new \Exception('Coupon #... introuvable ou expiré.', 422)` in the `else` branch. Applied to both `posOrderStore` and `tableOrderStore`.

### Y5 — MEDIUM: Delivery address mismatch → order created without address
**File:** `app/Services/OrderService.php`  
**Root cause:** The V3 fix added an ownership check but silently skipped `OrderAddress` creation if the address didn't belong to the customer. For delivery orders, this meant the order was created without any delivery address — a critical data integrity issue.  
**Fix:** Added `throw new \Exception('Adresse #... introuvable ou n\'appartient pas au client.', 422)` when the ownership-checked query returns null.

### Y6 — MEDIUM: Landing page `categories.slice(1)` hides first real category
**File:** `resources/js/components/admin/pos/PosComponent.vue`  
**Root cause:** The landing category grid used `categories.slice(1)` to skip the "All" pseudo-category (index 0). If the API changes the order or the "All" category is removed, a real category would be silently hidden.  
**Fix:** Changed to `categories.filter(c => c.id && c.id !== 0)` — explicitly filters out the pseudo-category by its id value, making the code robust to API order changes.

---

## Files Changed

| File | Changes |
|------|---------|
| `public/js/pos-wizard.js` | Y1 (itemQuantity from _cartQuantity) |
| `resources/js/components/admin/pos/ItemComponent.vue` | Y1 (_cartQuantity in restore payload) |
| `resources/js/components/admin/pos/PosComponent.vue` | Y6 (filter vs slice) |
| `resources/js/components/admin/posOrders/PosOrderReceiptComponent.vue` | Y2 (Array.isArray guards) |
| `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` | Y2 (Array.isArray guards — 5 occurrences) |
| `app/Services/OrderService.php` | Y4 (coupon error), Y5 (address error) — both posOrderStore and tableOrderStore |

---

## Risk Assessment

- **Y1**: Low — `_cartQuantity` is a new field in the restore payload; existing code ignores unknown fields. The `itemQuantity` sync is additive.
- **Y2**: Low — `Array.isArray()` guard is strictly more defensive; no behavior change for valid data.
- **Y4**: Medium — Throwing 422 on invalid coupon changes behavior from "silent zero discount" to "order rejected". This is the correct behavior but cashiers must be aware that invalid coupons now block the order. The frontend should show the error message clearly.
- **Y5**: Medium — Same as Y4. Delivery orders with an invalid/mismatched address now fail explicitly instead of silently. This prevents ghost orders with no address.
- **Y6**: Low — Filter logic is equivalent to slice(1) when "All" is always at index 0; more robust when it's not.

---

## Suggested Playwright / E2E verification Tests

1. **Y1**: Add item with qty=3 to cart → edit from cart → verify wizard shows qty=3 and saving doesn't reset to qty=1.
2. **Y2**: Open receipt for an order with addon rows (menu) → verify no crash; extras/variations display correctly.
3. **Y2**: Open KDS with an order that has addon rows → verify all 4 sections (dine-in, online, takeaway, kiosk) display without crash.
4. **Y4**: Submit POS order with an invalid coupon_id (e.g. 99999) → verify 422 error with clear message.
5. **Y5**: Submit delivery order with address_id belonging to a different customer → verify 422 error.
6. **Y6**: Verify landing page shows ALL real categories (not missing the first one).

---

## System State

After Waves P, N, W, V, X, Y — the POS order flow is now at a high level of correctness:
- Wizard restore: complete (viandes, sauce, garnitures, supplements, menuChoice, boissonChoice, viandeSupplItems, quantity)
- Cart deduplication: symmetric, type-safe
- Checkout payload: key-based variation join
- Backend: price recomputation, address ownership, coupon validation, all with explicit errors
- Receipt/KDS: null-safe, pre-line instructions, stable keys
- Landing: admin-configurable best sellers, robust category filter

Recommended: **Playwright / E2E verification full E2E retest** — complex order round-trip (add → edit → checkout → KDS → receipt).
