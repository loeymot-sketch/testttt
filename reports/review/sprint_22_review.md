# Sprint 22 Review — Claude Verdict

**Sprint:** 22 — Safety Lock: Sync & Pricing Integrity  
**Reviewer:** Claude (Architecte)  
**Date:** 2026-03-15  
**Status:** IMPLEMENTED — NEEDS_ANTIGRAVITY E2E

---

## Executive Summary

Sprint 22 addresses 4 critical bugs discovered during post-Sprint-21 audit. These bugs affect:
1. Pricing calculation for `individualAddons` in `supplements_menu` flow
2. Consistency between running total and recap total for qty > 1
3. Robustness of addon synchronization to Vue modal
4. Correct DOM target for boisson card synchronization

**Verdict:** `NEEDS_ANTIGRAVITY` — E2E browser validation mandatory before production.

---

## Code Review Checklist

| Check | Requirement | Status | Notes |
|-------|-------------|--------|-------|
| PATCH 1 — individualAddons field | `menuStep.items \|\| menuStep.menuItems` | ✅ PASS | Both flow paths covered |
| PATCH 2 — Recap formula | `(unitPrice + addonTotal) * qty` | ✅ PASS | Matches `calculateRunningTotal()` |
| PATCH 3 — Addon sync robustness | `||` chain + name fallback | ✅ PASS | Defensive against DOM order |
| PATCH 4 — Boisson DOM target | `originalBody` vs `wizardEl` | ✅ PASS | Correct modal target |
| Syntax validation | No JS errors | ✅ PASS | Verified after patches |
| Git state | Clean worktree at code verification time | ✅ PASS | Before report-chain file updates |
| Scope adherence | Only pos-wizard.js | ✅ PASS | No scope creep |

---

## Risk Analysis

### High Risk Areas

| Area | Risk | Why | Mitigation |
|------|------|-----|------------|
| PATCH 3 — Addon sync | Medium | DOM interaction, index-based matching | Name-based fallback adds redundancy |
| PATCH 4 — Boisson DOM | Medium | Modal DOM target change | `originalBody` is the correct Vue container |

### Low Risk Areas

| Area | Risk | Why |
|------|------|-----|
| PATCH 1 — Pricing | Low | Display-only, backend recalculates from DB |
| PATCH 2 — Recap total | Low | Display-only, backend is authoritative |

---

## Architecture Observations

### Patterns Observed

1. **Dual-flow system:** `full` vs `supplements_menu` paths require consistent `||` chaining
2. **DOM sync complexity:** Wizard state → Vue modal requires careful target selection
3. **Formula consistency:** `renderRecapStep()` and `calculateRunningTotal()` must stay synchronized

### Recommendations

1. **Future refactoring:** Consider unifying `items`/`menuItems` field naming
2. **Testing:** Add unit tests for `calculateRunningTotal()` with various addon combinations
3. **Documentation:** Document the `full` vs `supplements_menu` flow distinction

---

## Mandatory E2E Validation (Anti-Gravity)

The following scenarios MUST be validated in a real browser before marking Sprint 22 complete:

### Scenario 1: Sandwich + Frites Individuel
- **Flow:** Sandwich → No meat → Frites Seules (individual)
- **Verify:** Running total includes +€2.50 for frites
- **Verify:** Recap total matches running total

### Scenario 2: Sandwich qty=2 + Menu Complet
- **Flow:** Sandwich → Menu Complet → Quantity 2
- **Verify:** Running total = 2 × (base + menu price)
- **Verify:** Recap total = running total (not additive)

### Scenario 3: Tacos + Boisson Seule
- **Flow:** Tacos L → Boisson Seule → Select soda
- **Verify:** Boisson card is clicked/selected in Vue modal
- **Verify:** Final order includes boisson

### Scenario 4: Sandwich + Cheddar + Grande
- **Flow:** Sandwich with frites → Grande Portion + Cheddar
- **Verify:** Running total includes +€2.00
- **Verify:** KDS instruction shows "FRITES: Grande portion, Cheddar"

### Scenario 5: Multiple Addons Sync
- **Flow:** Sandwich + Frites + Boisson (individual selection)
- **Verify:** Both frites and boisson cards synced to modal
- **Verify:** No desync between wizard state and modal

---

## Verdict

**Status:** `NEEDS_ANTIGRAVITY`

**Reasoning:**
- 4/4 patches correctly implemented
- Code quality acceptable
- BUT: Patches touch sync logic, pricing display, and DOM interactions
- Browser-based E2E validation is the only way to confirm correct behavior

**Next Actions:**
1. Anti-Gravity runs E2E validation on all 5 scenarios
2. If all PASS → Mark Sprint 22 COMPLETE
3. If any FAIL → Return to Kimi for fixes

**Blocking Issues:** None (all code-level issues resolved)

**Non-Blocking Notes:**
- Consider adding `|| []` to all array accesses for defensive coding
- Document the difference between `items` and `menuItems` fields

---

## Scorecard

| Category | Sprint 21 | Sprint 22 | Trend |
|----------|-----------|-----------|-------|
| Wizard Logic | 6/10 | 8/10 | ⬆️ |
| Pricing Accuracy | 5/10 | 8/10 | ⬆️ |
| Sync Robustness | 4/10 | 7/10 | ⬆️ |
| E2E Validation | 0/10 | 0/10 | ⏳ (Pending) |

**Overall:** Sprint 22 significantly improves wizard reliability. Awaiting E2E validation to confirm.

---

## Sign-off

- [x] Claude review complete
- [ ] Anti-Gravity E2E validation complete
- [ ] All 5 scenarios PASS
- [ ] Sprint 22 marked COMPLETE
