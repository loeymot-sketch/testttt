# LOCK — POS Wizard money display FR (fmtPrice "€0.90" → "0,90 €")

> **Status 2026-06-18** : Owner countersign GRANTED in-session (AskUserQuestion
> "Wizard gelé" → « Oui, déverrouille + corrige (FR) »). Frozen-zone touch of
> `public/js/pos-wizard.js` authorized per CLAUDE.md §7 + §10 human-gate.
> Implemented + baseline updated this session.

- **Date**: 2026-06-18
- **Frozen-zone target**: `public/js/pos-wizard.js` (CLAUDE.md §7 — POS Vanilla JS wizard, owner "design parfait")
- **Severity**: P2 — FR i18n/affichage defect (en-US money on the FR-locked caisse product-add popup)
- **LOCK requestor**: Claude orchestrator (UX-perfection goal, deep micro-detail pass 2026-06-18)
- **Owner countersign**: YES — granted in-session (gate G-FROZEN-WIZARD-MONEY)
- **Scope**: 1 function, display-only, math untouched

---

## Section 1 — Justification
The frozen wizard's `fmtPrice(val)` (line 218) returned `'€' + num.toFixed(2)` = **"€0.90"**
(en-US: € prefix, dot decimal), shown on every product-add popup in the caisse, while the
*entire rest of the app* renders FR **"0,90 €"** (comma decimal, NBSP, € suffix via
`appService.currencyFormat`). This was the last standing UX imperfection after the deep
micro-detail convergence (the only one inside a frozen zone); surfaced as owner-gate
**G-FROZEN-WIZARD-MONEY** and approved for unlock.

## Section 2 — Change (display-only, math untouched)
`fmtPrice(val)` now returns `Intl.NumberFormat('fr-FR',{min/maxFractionDigits:2}).format(num) + ' €'`
(with a `try/catch` fallback to `num.toFixed(2).replace('.', ',') + ' €'` for ancient runtimes) →
**"0,90 €"**, matching `appService.currencyFormat`.

**Why display-only / math-safe**: `fmtPrice` takes a number, returns a *display string*. All 74
call-sites concatenate it for rendering (`'…' + fmtPrice(x) + '…'`); none parse it back
(verified: 0 `parseFloat(fmtPrice` / `Number(fmtPrice` / `.replace` on its output). The wizard's
pricing/total arithmetic operates on the raw numeric values, NOT this string — unchanged.

## Section 3 — Baseline + verification
- `tests/Feature/Sentinels/frozen-zone-sha256-baseline.json` `public/js/pos-wizard.js`:
  `896db50c…` → `cd98663e…` (new SHA after this edit; updated alongside this LOCK per the baseline `update_rule`).
- Re-test: `FrozenZoneSha256BaselineSentinelTest` GREEN (new baseline), pos-wizard specs GREEN, Vitest full GREEN, live visual = "0,90 €".

## Section 4 — Rollback
Revert the `fmtPrice` body to `return '€' + num.toFixed(2);` and restore the baseline SHA to
`896db50c…`. Single-function, no data/schema/state impact.
