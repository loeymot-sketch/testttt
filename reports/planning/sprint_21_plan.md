# Sprint 21 — Wizard Logic: Sauces, Frites, Supplements

**Status:** IMPLEMENTED  
**Date:** 2026-03-10  
**Agent:** Claude (Planning) / Kimi (Implementation)

---

## Scope

Sprint 21 addresses 4 confirmed bugs/gaps identified during deep audit of the sandwich order flow, specifically around multi-sauce selection, frites options (cheddar/grande), and KDS instruction completeness.

---

## Bugs Fixed

### BUG-S21-1 — CRITICAL: `individualAddons` never written to KDS instruction

**File:** `public/js/pos-wizard.js` — `buildWizardInstruction()`  
**Problem:** When a customer selects individual addons in `supplements_menu` (e.g., clicks "Frites Seules" card), `menuChoice` is set to `'individual'` but the instruction builder had no case for it. The KDS instruction showed **no mention** of the frites/boisson ordered.

**Fix Applied (lines ~2107-2124):**
```javascript
} else if (selections.menuChoice === 'individual' && selections.individualAddons) {
    var indNames = [];
    var menuStep = steps.find(function (s) { return s.type === 'supplements_menu' || s.type === 'menu'; });
    var addonItems = menuStep ? (menuStep.menuItems || menuStep.items) : null;
    if (addonItems) {
        addonItems.forEach(function (a) {
            if (selections.individualAddons[a.id]) {
                indNames.push(a.name);
            }
        });
    }
    if (indNames.length > 0) {
        parts.push('FORMULE: ' + indNames.join(', '));
    }
}
```

**Verification:**
- Sandwich + Frites Seules individually → KDS shows `FORMULE: Frites Seules`
- Sandwich + Menu Complet → KDS shows `FORMULE: Menu Complet (Frites + Boisson)`

---

### BUG-S21-2 — HIGH: `addonTotal` not multiplied by `itemQuantity`

**File:** `public/js/pos-wizard.js` — `calculateRunningTotal()` line ~999  
**Problem:** The formule price (e.g., +€2.50 for Menu Complet) was added **once** regardless of quantity. A cashier ordering 2× Sandwich Menu Complet saw wrong running total.

**Before:**
```javascript
return (basePrice + extra) * itemQuantity + addonTotal;
```

**After:**
```javascript
return (basePrice + extra + addonTotal) * itemQuantity;
```

**Note:** Server-side price recalculation in `OrderService.php` remains the authoritative source. This fix only corrects the frontend display.

---

### BUG-S21-3 — MEDIUM: Sandwich cannot get cheddar/grande frites upgrade

**File:** `public/js/pos-wizard.js` — `renderSupplementsMenuStep()` + `updateWizardUI()`  
**Problem:** The `frites_options` step (cheddar + grande portion) was only added for the Tacos/`menu_choice` flow. Sandwiches using `supplements_menu` had no access to these €1.00 upsells.

**Fix Applied:**
1. Added inline frites options section in `renderSupplementsMenuStep()` (lines ~1505-1527):
   - Grande Portion toggle (+€1.00)
   - Cheddar Fondu toggle (+€1.00)
   - Visible only when frites are selected

2. Added visibility toggle in `updateWizardUI()` (lines ~2757-2770):
   - `.frites-options-inline` visibility synced with frites selection

**Reuses existing:**
- Click handlers for `data-action="frites-size"` and `data-action="frites-cheddar"`
- `selections.fritesGrande` / `selections.fritesCheddar` state
- Price calculation in `calculateRunningTotal()`

---

### BUG-S21-6 — LOW: `boissonSeule` card never rendered

**File:** `public/js/pos-wizard.js` — `renderMenuChoiceStep()`  
**Problem:** The `boissonSeule` step object was built in `buildSteps()` but never rendered as a card. Tacos customers could not order "Boisson Seule" without frites.

**Fix Applied (lines ~1102-1112):**
```javascript
if (step.boissonSeule) {
    var selBoisson = selections.menuChoice === 'boisson' ? ' selected' : '';
    h += '<div class="menu-choice-card' + selBoisson + '" data-action="menu-choice" data-value="boisson">';
    h += '<div class="menu-card-icon">' + renderOptionIcon(step.boissonSeule.thumb, '🥤') + '</div>';
    h += '<div class="menu-card-name">' + step.boissonSeule.name + '</div>';
    h += '<div class="menu-card-price">+' + fmtPrice(step.boissonSeule.price) + '</div>';
    h += '<div class="menu-card-desc">Juste la boisson</div>';
    h += '</div>';
}
```

**Note:** `hasBoissonSelected()` already supported `'boisson'` (line 303). This fix only adds the missing UI.

---

## What Was Already Working (Confirmed)

| Feature | Status |
|---------|--------|
| Multi-sauce for sandwiches (2nd sauce +€0.50) | ✅ Confirmed working |
| Sauce frites for sandwiches (inline, multi-select, 1st free) | ✅ Confirmed working (W-7/W-8 fixes) |
| `hasFritesSelected()` for sandwich flow | ✅ Confirmed working |
| Paid supplements (Œuf, Fromage, etc.) | ✅ Confirmed working |
| `SANS:` / `GARNITURES:` / `SAUCE:` / `SAUCES SUPPL:` in KDS | ✅ Confirmed working (Sprint 20) |
| `SUPPLÉMENTS:` in KDS instruction | ✅ Confirmed working |

---

## Test Checklist

- [ ] Sandwich + Menu Complet → KDS instruction shows `FORMULE: Menu Complet (Frites + Boisson)`
- [ ] Sandwich + Frites Seules individually → KDS instruction shows `FORMULE: Frites Seules`
- [ ] Sandwich + Frites + Sauce Frites → KDS shows `SAUCE FRITES: [sauce names]`
- [ ] Sandwich qty=2 + Menu Complet → running total shows 2× formule price (not 1×)
- [ ] Sandwich + Frites → inline cheddar and grande options appear and are selectable
- [ ] Sandwich + Frites + Cheddar + Grande → running total includes +€2.00
- [ ] Tacos + Boisson Seule card is clickable and advances correctly
- [ ] Sandwich + 2 sauces → 2nd sauce shows +€0.50 in running total (regression check)

---

## Out of Scope (Documented for Future Sprints)

### BUG-S21-4 — MEDIUM: `detectViandeCount` is Tacos-only
- Path A sandwich flow (`['pain', 'viande_sauce', 'perso', 'menu', 'recap']`) is unreachable for all non-tacos items
- Sandwiches cannot use dedicated viande selection step regardless of name
- **Deferred:** Requires product decision on sandwich viande flow architecture

### BUG-S21-5 — LOW: Pain variation price delta not in `calculateRunningTotal()`
- Current DB: both Pain and Galette at €0.00 — no impact today
- **Deferred:** Low priority

### BUG-S21-7 — LOW: Viande-named `ItemExtra` could appear as supplement
- Theoretical risk: "Poulet Supplémentaire" at €1.50 would appear in `perso` step supplements
- No such DB records exist today
- **Deferred:** Add name-based guard if needed in future

---

## Files Changed

- `public/js/pos-wizard.js` — All fixes (no other files touched)

---

## Next Steps

1. Anti-Gravity E2E validation of all 8 test checklist items
2. Human validation in staging environment
3. If all tests pass: mark Sprint 21 COMPLETE and proceed to Sprint 22 (Kiosk deep audit per user request)
