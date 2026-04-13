# Execution Report — POS Wave W Fixes (W1–W11)
**Date:** 2026-03-10  
**Agent:** Claude  
**Status:** COMPLETED (11/11 tasks done)

---

## Summary

Third wave of POS fixes targeting critical wizard restore bugs, price sync integrity, customer data safety, and a range of medium/low UX and correctness issues.

---

## Tasks Completed

### W1 — CRITICAL: totalViandes out of sync after restore (pos-wizard.js)
**Problem:** After `Object.assign(selections, restored)`, `selections.totalViandes` stayed at 0 because `buildWizardRestorePayload` fills `selections.viandes` counts but never sets `totalViandes`. This caused:
1. `buildTicketInstruction` to skip the entire viande block (gated on `totalViandes > 0`) → wrong KDS/ticket text
2. `renderSinglePage` canAdd logic to allow over-selection (quota based on `totalViandes`)

**Fix:**
- After `Object.assign`, recompute `totalViandes` by summing all values in `selections.viandes`
- Changed `buildTicketInstruction` gate from `totalViandes > 0` to `Object.keys(viandes).some(count > 0)` — more robust, doesn't depend on a derived counter

### W2 — CRITICAL: No updateSinglePageUI() after restore (pos-wizard.js)
**Problem:** `bindSinglePageEvents()` was called after `renderSinglePage()` but `updateSinglePageUI()` was never called after edit-restore. Any dynamic UI state (button disabled states, selected classes, quantity counters) was not synced to the restored `selections`.

**Fix:** Added `isEditRestore` flag; call `updateSinglePageUI()` after `bindSinglePageEvents()` when restoring from edit.

### W3 — HIGH: submitWhenSynced silent fallthrough on price mismatch (pos-wizard.js)
**Problem:** When retries were exhausted and `modalDisplayedTotal` still didn't match `wizardTotalBeforeSubmit`, the code fell through and clicked "Add" anyway — risking cart line price inconsistency.

**Fix:** Added hard-fail: when `deltaBad && remainingTry <= 0`, show error message and return instead of submitting.

### W4 — HIGH: Wrong default customer (PosComponent.vue)
**Problem:** `|| res.data.data[0]` fallback assigned the first user in the list (a real customer) to anonymous POS orders when no walking customer was found — leaking order history to wrong accounts.

**Fix:** Removed the `[0]` fallback. If no walking customer found by email or name keyword, `customer_id` stays null and the cashier must select manually.

### W5 — HIGH: Viande `<select>` fuzzy match collision (pos-wizard.js)
**Problem:** `normalizeStr(opt.textContent).includes(normalizeStr(viandeName))` could match "Kefta" against "Kefta Épicée", selecting the wrong option.

**Fix:** Try exact match first (`=== normalizedName`); fall back to substring only if no exact match found.

### W6 — MEDIUM: `addons !== "undefined"` string check (ItemComponent.vue)
**Problem:** `this.addons !== "undefined"` compared to the string literal instead of using a proper type check — could fail to detect null/undefined addons correctly.

**Fix:** Replaced with `this.addons && typeof this.addons === 'object' && Object.keys(this.addons).length !== 0`.

### W7 — MEDIUM: changeVariation ID type mismatch (ItemComponent.vue)
**Problem:** `element.id === attributeId` — `element.id` is a number from the API, `attributeId` from DOM events is a string. Strict `===` silently fails → `names` object not updated → variation label missing from checkout.

**Fix:** `String(element.id) === String(attributeId)` — coerces both to string before comparison.

### W8 — MEDIUM: OSS classList.remove null guard (OrderStatusScreenComponent.vue)
**Problem:** `document?.querySelector('.db-header').classList.remove(...)` — optional chain only covers `querySelector`, not `.classList`. If `.db-header` is absent, throws runtime error.

**Fix:** `document?.querySelector('.db-header')?.classList?.remove(...)` — full optional chain.

### W9 — MEDIUM: Category swiper unstable `:key` (PosComponent.vue)
**Problem:** `:key="category"` used object reference — unstable when list is replaced, causing unnecessary DOM re-renders and potential Vue reconciliation issues.

**Fix:** `:key="category.id || index"` — stable numeric key.

### W10 — MEDIUM: Receipt row unstable `:key` (PosOrderReceiptComponent.vue)
**Problem:** `:key="item"` used object reference — same issue as W9 on the print receipt.

**Fix:** `:key="item.id || item.item_name"` — stable key using DB id with name fallback.

### W11 — LOW: Add-customer password visible (PosComponent.vue)
**Problem:** `type="text"` on the password field — visible in plain text on shared POS terminals (shoulder-surfing risk).

**Fix:** `type="password"` with `autocomplete="new-password"`.

---

## Reverse Audit

### W1 + W2 interaction check
`renderSinglePage()` is called *after* `Object.assign(selections, restored)` and *after* `totalViandes` recompute. So the static HTML already reflects restored state. `updateSinglePageUI()` then handles any dynamic state (button states, counters). No double-render issue.

### W3 edge case: free items (wizardTotalBeforeSubmit === 0)
The price sync guard is only entered when `wizardTotalBeforeSubmit > 0`. Free items skip the guard entirely and proceed directly to `addBtn.click()`. Correct behavior preserved.

### W4 edge case: customer_id stays null
If no walking customer is found, `customer_id` is null. The `orderSubmit` validation checks token/table/address but not customer_id — this is by design for POS (anonymous orders are valid). No regression.

### No other regressions found.

---

## Risk Assessment

| Fix | Risk | Notes |
|-----|------|-------|
| W1 totalViandes recompute | LOW | Strictly more correct; recompute is idempotent |
| W2 updateSinglePageUI after restore | LOW | `updateSinglePageUI` is designed to be called multiple times safely |
| W3 hard-fail on price mismatch | LOW | Only affects edge case where DOM price never syncs after retries |
| W4 no array[0] fallback | LOW | Null customer_id is valid for POS anonymous orders |
| W5 exact viande match | LOW | Strictly more correct; fallback preserves old behavior |
| W6 typeof check | NONE | Pure correctness fix |
| W7 String coercion | LOW | Strictly more correct; coercion is safe for IDs |
| W8 optional chain | NONE | Pure null safety fix |
| W9 stable key | NONE | Better Vue rendering, no logic change |
| W10 stable key | NONE | Better Vue rendering, no logic change |
| W11 password type | NONE | Security improvement, no logic change |

---

## Next Steps for Playwright / E2E verification Testing

1. **W1+W2**: Edit a Tacos XL (2 viandes) from cart → verify both viandes pre-selected, ticket shows both
2. **W3**: Force a price mismatch (e.g. slow DOM) → verify error message shown instead of silent submit
3. **W4**: Remove walking customer from DB → verify POS still works (customer_id null)
4. **W5**: Add "Kefta" and "Kefta Épicée" to catalog → verify correct option selected in modal
5. **W7**: Add item with multiple variation attributes → verify all variation names appear on receipt
