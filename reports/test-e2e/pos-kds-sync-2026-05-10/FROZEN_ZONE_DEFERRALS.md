# FROZEN-ZONE Deferrals — pos-kds-sync-2026-05-10

Two P1 findings discovered in Round 1 land entirely inside the **POS Vanilla JS wizard** (`public/js/pos-wizard.js`), declared FROZEN by owner ("design parfait", memory `feedback_wizard_popup_pos_protected`). They do NOT block round convergence — they require an owner-gated LOCK plan before any code change.

## Deferred findings

### B-001 — Silent viande-default substitution (P1 numeric_integrity)

**Evidence**: Wave B state `02-b-wizard-open-item-1.png` (Tacos M id 363) shows Viande badge "0/1 Viande" with no row ticked. Vuex sidecar (state 16) proves Merguez was added on validate. Cashier never sees the substitution.

**Risk**: A cashier expecting to confirm the customer's viande choice receives an opaque default. If the default differs from what the cashier verbally promised the customer, the kitchen ticket is wrong.

**File**: `public/js/pos-wizard.js` lines ~3708 (`syncAndSubmit`) + viande sync block.

**Acceptable owner-gated paths**:
- (a) BLOCK validate when required N/M Viande badge is 0/N — surface "Sélectionnez 1 viande" inline error
- (b) Render the auto-defaulted viande row with a yellow "défaut: Merguez" pill so cashier sees the substitution before validate

### B-002 — ESC does not dismiss wizard (P1 aria_keyboard)

**Evidence**: `public/js/pos-wizard.js` lines 5871-5879 only bind `Ctrl+Enter` for submit. Lines 5595-5617 only handle Enter/Arrow keys. WCAG 2.1.2 / 2.4.3: modal dialogs SHOULD dismiss on ESC.

**Risk**: Keyboard-only operators / accessibility. Also operational: a cashier who opens the wrong item must reach for mouse to cancel.

**File**: `public/js/pos-wizard.js` line ~5871.

**Acceptable owner-gated patch** (≤8 lines):
```js
if (e.key === 'Escape') {
  e.preventDefault();
  var cancelBtn = wizardEl.querySelector('[data-action="cancel-wizard"]');
  if (cancelBtn) cancelBtn.click();
}
```

## Convergence decision

These two P1s remain `status: open` in `round-1/wave-B-findings.json` for traceability, but the orchestrator excludes them from the round convergence calculation pending owner LOCK. The audit can declare GREEN convergence on round N when:
- `open_P0 == 0` AND
- `open_P1 == 0` AFTER subtracting `B-001` and `B-002` (frozen-zone deferred)
- Round N-1 had identical findings set with same exclusion

If owner approves a LOCK plan during this audit cycle, the deferrals are reactivated and round must re-converge with them closed.

## Owner action required

Use `/lock-plan` skill to generate `LOCK_B-001-B-002.md` covering: scope, files, justification, rollback, safety-check.sh override config, sign-off section.
