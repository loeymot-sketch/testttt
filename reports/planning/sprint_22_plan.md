# Sprint 22 — Safety Lock: Sync & Pricing Integrity

**Status:** IMPLEMENTED (Awaiting Anti-Gravity E2E Validation)  
**Date:** 2026-03-15  
**Agent:** Claude (Planning) / Kimi (Implementation)

---

## Scope

Sprint 22 addresses 4 critical logic gaps discovered during deep audit of Sprint 21. These gaps affect:
- Pricing calculation for individual addons in sandwich flow
- Total consistency between running total and recap
- Addon synchronization to Vue modal
- Boisson selection synchronization target

---

## Bugs Fixed

### BUG-S22-1 — CRITICAL: `individualAddons` pricing uses wrong field

**File:** `public/js/pos-wizard.js` — `calculateRunningTotal()` lines 990–994  
**Problem:** The code checked `menuStep.items` only, but `supplements_menu` uses `menuItems`. Individual addons (Frites, Boisson) selected in sandwich flow were not added to the running total.

**Fix Applied:**
```javascript
} else if (selections.individualAddons && (menuStep.items || menuStep.menuItems)) {
    var addonItems = menuStep.items || menuStep.menuItems;
    addonItems.forEach(function (a) {
        if (selections.individualAddons[a.id]) addonTotal += a.price;
    });
}
```

---

### BUG-S22-2 — CRITICAL: Recap total inconsistent with running total

**File:** `public/js/pos-wizard.js` — `renderRecapStep()` line 1949–1950  
**Problem:** The recap total formula was `(unitPrice * itemQuantity) + addonTotal`, while `calculateRunningTotal()` uses `(basePrice + extra + addonTotal) * itemQuantity`. For qty > 1 with menu/addons, the cashier sees wrong total in recap.

**Fix Applied:**
```javascript
var unitPrice = basePrice + totalExtra;
var total = (unitPrice + addonTotal) * itemQuantity;
```

---

### BUG-S22-3 — HIGH: Addon sync mapping fragile

**File:** `public/js/pos-wizard.js` — `syncAndSubmit()` lines 2364–2401  
**Problem:** 
1. Used `menuStep.items` only — fails for `supplements_menu` which has `menuItems`
2. Index-based matching (`addonCards[index]` vs `menuStep.items[index]`) is brittle if DOM order differs
3. No fallback for `individualAddons` when index matching fails

**Fix Applied:**
```javascript
var menuAddonItems = menuStep.items || menuStep.menuItems || [];
var addon = menuAddonItems[index] || null;
// ...
// Fallback: name-based match for individualAddons when DOM order differs
if (!shouldBeSelected && selections.individualAddons && menuAddonItems.length > 0) {
    var selectedNames = menuAddonItems
        .filter(function (a) { return selections.individualAddons[a.id]; })
        .map(function (a) { return (a.name || '').toLowerCase(); });
    shouldBeSelected = selectedNames.some(function (name) {
        return name && addonName && addonName.includes(name.split(' ')[0]);
    });
}
```

---

### BUG-S22-4 — HIGH: Boisson sync targets wrong DOM

**File:** `public/js/pos-wizard.js` — `syncAndSubmit()` line 2413  
**Problem:** The boisson card query used `wizardEl.querySelectorAll('.addon[data-addon-id]')` but the wizard DOM does not contain the Vue modal's addon cards. The boisson selection was never actually synced to the modal.

**Fix Applied:**
```javascript
var boissonAddonCards = originalBody.querySelectorAll('.addon[data-addon-id]');
boissonAddonCards.forEach(function (card) {
    var addonName = (card.getAttribute('data-addon-name') || '').toLowerCase();
    if (addonName.includes(boissonItemSync.name.toLowerCase().split(' ')[0])) {
        var isSelected = card.closest('.selected, [class*="primary"]') !== null;
        if (!isSelected) card.click();
    }
});
```

---

## Test Strategy

**Type:** Anti-Gravity (E2E/browser validation required)

These patches affect sync logic, pricing display, and DOM interactions that cannot be fully validated by unit tests alone. Browser-based E2E testing is mandatory.

### Validation Checklist

1. **Sandwich + Frites individuel**
   - Select sandwich without meat
   - Choose "Frites Seules" individually (not Menu Complet)
   - Verify running total includes frites price (+€2.50)
   - Verify recap total matches running total

2. **Sandwich qty=2 + Menu Complet**
   - Select sandwich, choose Menu Complet
   - Set quantity to 2
   - Verify running total shows 2× menu price (+€5.00)
   - Verify recap total matches running total

3. **Tacos + Boisson Seule**
   - Select Tacos L
   - Choose "Boisson Seule" in menu step
   - Verify boisson card is clicked/synced in Vue modal
   - Verify final order includes boisson

4. **Sandwich + Frites + Cheddar + Grande**
   - Select sandwich with frites
   - Choose "Grande Portion" (+€1.00) and "Cheddar" (+€1.00)
   - Verify total includes +€2.00 for frites options
   - Verify instruction KDS shows "FRITES: Grande portion, Cheddar"

5. **Sandwich + addons individuels sync**
   - Select sandwich
   - Click multiple addon cards individually (Frites + Boisson)
   - Verify all selected cards are clicked in Vue modal
   - Verify no desync between wizard state and modal state

---

## Files Changed

- `public/js/pos-wizard.js` — 4 patches (lines 990–994, 1949–1950, 2364–2401, 2413–2420)

No other files modified.

---

## Risk Assessment

| Patch | Risk Level | Mitigation |
|-------|-----------|------------|
| S22-1 Pricing fix | Low | Display-only; backend recalculates prices from DB |
| S22-2 Recap total | Low | Display-only; backend is authoritative |
| S22-3 Addon sync | Medium | E2E testing required; name-based fallback adds robustness |
| S22-4 Boisson DOM | Medium | E2E testing required; `originalBody` is correct target |

**Overall:** Patches are localized, backwards-compatible, and do not affect backend logic. Risk is limited to frontend display and sync behavior.

---

## Next Steps

1. **Anti-Gravity E2E validation** — Run all 5 checklist scenarios in browser
2. **Report results** in `reports/antigravity/latest.md`
3. **If all PASS:** Mark Sprint 22 COMPLETE, proceed to Sprint 23
4. **If any FAIL:** Return to Kimi for fixes

---

## Post-Validation Checklist

- [ ] Sandwich + Frites individuel → total correct
- [ ] Sandwich qty=2 + Menu → recap == running total
- [ ] Tacos + Boisson Seule → boisson synced to modal
- [ ] Sandwich + Cheddar + Grande → total +€2.00
- [ ] Multiple addons → all synced correctly
