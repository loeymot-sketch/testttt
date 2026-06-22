# LOCK — POS Wizard stored-XSS escape (re-apply escapeHtml on goal/wizard-wysiwyg branch)

> **Status 2026-06-19** : Owner countersign GRANTED in-session (AskUserQuestion "XSS wizard gelé"
> → « Oui, déverrouille + corrige (escapeHtml) »). Frozen-zone touch of `public/js/pos-wizard.js`
> authorized per CLAUDE.md §7 + §10 human-gate.

- **Date**: 2026-06-19
- **Frozen-zone target**: `public/js/pos-wizard.js` (CLAUDE.md §7 — POS Vanilla JS wizard)
- **Severity**: P1 — Stored XSS (privilege-boundary): an `items_edit` staff naming an item/extra/option
  `<img src=x onerror=…>` → JS executes in the POS operator's authenticated session (session/credential
  theft, order manipulation).
- **LOCK requestor**: Claude supervisor (security-classes RED sweep 2026-06-19)
- **Owner countersign**: YES — granted in-session
- **Scope**: add an `escapeHtml()` helper + apply it to EVERY user-controlled string interpolated into an
  innerHTML sink. Display-behavior unchanged (names still render, just HTML-escaped).

---

## Section 1 — Justification
This branch (`goal/wizard-wysiwyg-builder-2026-06-14`) does NOT contain the prior
`LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17` heal — verified: **no `escapeHtml` helper exists** in this branch's
`public/js/pos-wizard.js`. User-controlled names (from the items/extras/variations API, set via `items_edit`)
are concatenated RAW into the `h`/`html` string that becomes `wizardEl.innerHTML = renderSinglePage()`
(lines ~1073 `<h2>+name`, ~1205/1256/1353/1369/1385/1401/1534/1578/1602 `option-name`, ~1530 in an `alt=`
attribute, plus the `btn.innerHTML` displayName sinks ~3339/4996/4999). No escaping → stored XSS.

## Section 2 — Change
- Add `escapeHtml(s)` (textContent→innerHTML round-trip, or `String(s).replace(/[&<>"']/g, …)`).
- Wrap EVERY user-controlled interpolation into an innerHTML sink: item/extra/variation/option/sauce/viande/
  pain/boisson `.name`, the `alt="…name…"` attribute, the `displayName` in `btn.innerHTML`, and any other
  request/API-derived string (instruction/note if rendered). Image `src`/`thumb` URLs in attributes → escape
  the attribute context too.
- Pricing/totals/math + the data-flow UNCHANGED — escaping is render-only.

## Section 3 — Baseline + verification
- `tests/Feature/Sentinels/frozen-zone-sha256-baseline.json` `public/js/pos-wizard.js` → new SHA after the edit.
- Re-test: FrozenZoneSha256BaselineSentinelTest GREEN (new baseline), pos-wizard Vitest specs GREEN, a new
  escaping test proving `<img onerror>` in a name renders escaped (no live node), full Vitest GREEN.

## Section 4 — Rollback
Remove the `escapeHtml` helper + unwrap the interpolations; restore the baseline SHA. Render-only change,
no data/schema/state impact.
