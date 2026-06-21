# LOCK (DRAFT — awaiting owner countersign) — POS Wizard money FR: 3 missed call-sites

> **Status 2026-06-22 : DRAFT, NOT yet applied.** Requires explicit owner countersign
> (CLAUDE.md §7 frozen-zone + §10 human-gate), exactly as the 2026-06-18 LOCK was granted.
> The frozen-zone SHA256 baseline must be updated alongside (per the baseline update_rule).

- **Frozen-zone target**: `public/js/pos-wizard.js` (CLAUDE.md §7 — POS Vanilla JS wizard, owner "design parfait")
- **Severity**: P2 — en-US money on the FR-locked caisse popup (siblings of the 2026-06-18 fix)
- **Gate**: NEW countersign needed — the prior LOCK (`LOCK_POS_WIZARD_MONEY_FR_2026-06-18.md`)
  was scoped to the `fmtPrice()` function ONLY; these 3 sites BYPASS fmtPrice with their own
  `'€' + toFixed(2)` and were not in that scope.
- **Scope**: 3 display-only string concatenations, math untouched. Verified by audit-360 + the
  backend/visual critics (W1 POS critic + W3 completeness critic both independently found them).

## The 3 sites (all render to escapeHtml'd innerHTML — display-only)
| line | current (en-US) | renders | proposed (FR, via the existing FR `fmtPrice()` @ line 220) |
|---|---|---|---|
| 692 | `currencyPrice: '€' + unitPrice.toFixed(2),` | "€5.00" | `currencyPrice: fmtPrice(unitPrice),` |
| 1873 | `'<div class="menu-price">+€' + menuPrice + '</div>'` | "+€3.00" | `'<div class="menu-price">+' + fmtPrice(parseFloat(menuPrice)) + '</div>'` |
| 2281 | `… '+€' + formulePrice.toFixed(2) + …` | "+€3.00" | `… '+' + fmtPrice(formulePrice) + …` |

(A 4th LOW fallback at ~2961 `sup.currency_price || '€1.00'` — en-US literal only on missing data;
optional, can leave or change to `'1,00 €'`.)

## Why display-only / math-safe (same reasoning as the 2026-06-18 LOCK)
`fmtPrice(val)` takes a number → returns a display string; the wizard's pricing/total arithmetic
operates on the raw numeric values (`unitPrice`, `formulePrice`, `parseFloat(menuPrice)`), NOT these
strings. The strings are only concatenated into innerHTML for rendering; none are parsed back. The
sibling line 661 already uses `fmtPrice(price)` — this just makes 692/1873/2281 consistent.

## Verification plan (post-countersign)
1. Apply the 3 edits. 2. `npm run production` (wizard is non-Mix; ships via the `js/pos-wizard.js`
asset). 3. Update `tests/Feature/Sentinels/frozen-zone-sha256-baseline.json` pos-wizard SHA + re-run
`FrozenZoneSha256BaselineSentinelTest` GREEN. 4. Live: caisse → add a customizable item with an
addon/menu/formule → confirm "5,00 €" / "+3,00 €" (FR). 5. Vitest full GREEN.

## Rollback
Revert the 3 lines to their `'€' + …toFixed(2)` form + restore the baseline SHA. No data/state impact.

---
**OWNER DECISION REQUIRED**: countersign to unlock `pos-wizard.js` for these 3 display-only sites
(mirrors gate G-FROZEN-WIZARD-MONEY 2026-06-18), or keep deferred.
