# Sprint 21 — Wizard Logic: Sauces, Frites, Supplements

**Date:** 2026-03-10  
**Status:** COMPLETED  
**Executor:** Kimi (Post-Audit Corrections by Claude/Kimi)

---

## Summary

4 bugs corrigés + 1 correction post-audit:

1. BUG-S21-1 — CRITICAL: `individualAddons` jamais écrit dans l'instruction KDS
2. BUG-S21-2 — HIGH: `addonTotal` non multiplié par `itemQuantity`
3. BUG-S21-3 — MEDIUM: Sandwich sans cheddar/grande frites (upsells manquants)
4. BUG-S21-6 — LOW: Carte "Boisson Seule" jamais rendue
5. S21-3a FIX — Post-audit: Extension du sélecteur `.frites-option` → `.frites-option, .frites-opt`

---

## Fixes Applied

### BUG-S21-1: `individualAddons` dans `buildWizardInstruction()`

**File:** `public/js/pos-wizard.js`  
**Lines:** ~2146–2160  
**Change:** Ajout du cas `'individual'` pour capturer les addons sélectionnés individuellement dans `supplements_menu`

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

**Impact:** KDS affiche maintenant `FORMULE: Frites Seules` ou `FORMULE: Frites Seules, Boisson Seule` quand le client choisit des addons individuellement.

---

### BUG-S21-2: `addonTotal × itemQuantity` dans `calculateRunningTotal()`

**File:** `public/js/pos-wizard.js`  
**Line:** ~1000  
**Change:** Le formule prix est maintenant multiplié par la quantité

**Before:**
```javascript
return (basePrice + extra) * itemQuantity + addonTotal;
```

**After:**
```javascript
return (basePrice + extra + addonTotal) * itemQuantity;
```

**Impact:** Un panier avec 2× Sandwich Menu Complet affiche correctement 2× le prix du menu (+€5.00), pas 1× (+€2.50).

---

### BUG-S21-3: Inline cheddar/grande pour sandwich

**File:** `public/js/pos-wizard.js`  
**Lines:** ~1510–1538 (render), ~2767–2779 (visibility toggle)

**Change:** Ajout d'une section inline `frites-options-inline` dans `renderSupplementsMenuStep()` avec:
- Grande Portion (+€1.00) — toggle
- Cheddar Fondu (+€1.00) — toggle

Visible uniquement quand des frites sont sélectionnées (même condition que `sauce-frites-inline`).

**Post-Audit Correction (S21-3a):**

**File:** `public/js/pos-wizard.js`  
**Line:** ~2725

**Change:** Extension du sélecteur pour mettre à jour l'état visuel des éléments inline

**Before:**
```javascript
wizardEl.querySelectorAll('.frites-option').forEach(function (opt) {
```

**After:**
```javascript
wizardEl.querySelectorAll('.frites-option, .frites-opt').forEach(function (opt) {
```

**Impact:** Le client peut maintenant choisir cheddar et grande portion sur les frites d'un sandwich (upsells +€2.00 revenue). L'état visuel se met à jour correctement après chaque clic.

---

### BUG-S21-6: Carte "Boisson Seule" dans `renderMenuChoiceStep()`

**File:** `public/js/pos-wizard.js`  
**Lines:** ~1095–1104  
**Change:** Ajout de la carte "Boisson Seule" (précédemment construite mais jamais rendue)

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

**Impact:** Les clients Tacos peuvent commander une boisson seule sans frites.

---

## Audit Findings

| Fix | Initial Status | Post-Audit Status | Notes |
|-----|----------------|---------------------|-------|
| S21-1 | Implemented | PASS | Correct, null-guarded, pas de duplication |
| S21-2 | Implemented | PASS | Formule correcte, pas de double-comptage |
| S21-3 | Implemented | PASS (après S21-3a) | Visual fix nécessaire (sélecteur étendu) |
| S21-6 | Implemented | PASS | Tous les 10 checks passent |

**S21-3a** était une correction post-audit: le sélecteur `.frites-option` ne mettait pas à jour l'état visuel des éléments inline `.frites-opt`. Le sélecteur étendu résout ce problème.

---

## Test Checklist (pour Playwright / E2E verification)

- [ ] Sandwich + Menu Complet → KDS affiche `FORMULE: Menu Complet (Frites + Boisson)`
- [ ] Sandwich + Frites Seules individuellement → KDS affiche `FORMULE: Frites Seules`
- [ ] Sandwich + Frites + Sauce Frites → KDS affiche `SAUCE FRITES: [noms]`
- [ ] Sandwich qty=2 + Menu Complet → running total montre 2× prix formule
- [ ] Sandwich + Frites → cheddar et grande options apparaissent et sont sélectionnables
- [ ] Sandwich + Frites + Cheddar + Grande → running total inclut +€2.00
- [ ] Cheddar/grande toggle visuel fonctionne (selected state mis à jour après clic)
- [ ] Tacos + Boisson Seule carte clickable et avance correctement
- [ ] Sandwich + 2 sauces → 2ème sauce montre +€0.50 (régression check)

---

## Files Changed

- `public/js/pos-wizard.js` — 5 modifications (S21-1, S21-2, S21-3, S21-3a, S21-6)

Aucun autre fichier modifié.
