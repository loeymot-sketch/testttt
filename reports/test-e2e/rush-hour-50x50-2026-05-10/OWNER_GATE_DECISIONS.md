# Owner-gate decisions — rush-hour-50x50-2026-05-10

These findings surfaced during the audit but require **owner architectural direction** before code change. Per CLAUDE.md §10 ("architecture direction uncertain → escalate"), the audit surfaces them and stops short of patching.

## A-003 — KDS 50-card cap during 100-order rush (P1 loading_state_missing)

### Evidence
- Wave A round 1, state `17-A-kds-pile-from-A.png` and `01-C-kds-pile-100-orders.png` (Wave C planned)
- KDS shows banner: « Liste pleine (50 affichée(s), max. serveur). Filtrez par statut pour réduire le bruit. »
- 50 of 100 orders are hidden behind a "Voir plus" affordance during peak rush.

### Why owner-gate
The 50-card cap is a deliberate server-side limit (not a render bug). Three architectural directions exist; only the owner can choose:

| Direction | Trade-off |
|---|---|
| **A. Raise the cap** (e.g. 100/150) | Simple, but degrades chef perception when even 100 orders queue (fast-food worst-case). |
| **B. Virtual scrolling** (windowed render of all N orders) | Best UX, but adds DOM virtualization complexity to KDS Vue components. |
| **C. Auto-archive on status change** (PREPARED orders move out of pile after Xs) | Keeps the pile bounded by definition. Requires defining "auto-archive" semantics with kitchen workflow. |

The audit cannot pick — direction depends on owner's read of how kitchens actually work during rush.

### What the audit did
- Captured the visual evidence
- Logged the finding as P1 loading_state_missing in `round-1/wave-A-findings.json`
- Excluded from convergence-blocking calculation per this owner-gate doc

### Recommended next action
Owner picks A/B/C → orchestrator opens a separate work item (out of scope for this rush-hour audit) → audit re-runs after the fix to verify pile UX.

---

## E-003 deeper — pos-wizard.js supplement `is_available` not rendered (Wave E round 2)

### Evidence
- `public/js/pos-wizard.js` has NO rendering of `extra.is_available` anywhere — grep finds only viande `+/-` count toggling
- Wave E round 2 spec re-opened POS Tacos M wizard (commit `7aea3962f`); supplement 175 (Jambon de dinde, marked unavailable in DB) renders identically to available supplements (no ÉPUISÉ overlay, no dimming, no `is-unavailable` class)
- Backend correctly blocks the order at finalize (E5b 422 verified)

### Why owner-gate
`public/js/pos-wizard.js` is in CLAUDE.md §7 FROZEN ZONES list ("design parfait", per memory feedback_wizard_popup_pos_protected). Any UI change to render `is_available` requires LOCK_<id>.md + owner sign-off (CLAUDE.md §10 human gate).

### User-facing impact (audit-discovered)
A cashier opening a Tacos M wizard sees ALL supplements identically. They can tap a ruptured supplement; the wizard validates locally and adds the line to cart. At order finalize, the backend rejects with 422 "Supplément ID 175 indisponible pour cette branche". The cashier must then identify which supplement was the cause, remove it, and re-validate. **UX friction during rush hour**.

Kiosk wizard renders `is_available` correctly (Wave E round 2 state-09 confirms ÉPUISÉ pink badge + dimmed CHOISIR + opacity 0.906). **POS-side parity gap**.

### Recommended next action
Owner decides:
1. Authorize a LOCK_E-003.md scope-minimal patch to `pos-wizard.js` adding `is_available` rendering (parity with kiosk)
2. Defer (cashier workaround acceptable)
3. Wider redesign (out of scope for V1)

The audit cannot pick — pos-wizard is owner's design call.

(More owner-gate decisions added below as they surface.)
