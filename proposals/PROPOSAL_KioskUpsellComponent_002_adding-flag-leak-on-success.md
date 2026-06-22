# PROPOSAL 002 — `_adding` flag never resets on success path (state leak)

**Component**: `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`
**Phase**: B.5 — Frozen-zone audit (no edit, proposal only)
**Severity**: P2 (defensive correctness · race-condition latent risk)
**Reasoning angle**: Cart manipulation correctness · Client-impatient persona

---

## Observation

`addAndContinue` (lines 220–257):

```js
addAndContinue() {
  if (this._adding || this.selectedItems.length === 0) return;
  this._adding = true;
  this.selectedItems.forEach(item => { this.addItem({...}); });
  // ... toast + analytics ...
  this.$router.push({ name: 'kiosk.payment' }).catch(() => {
    this._adding = false;
  });
},
```

`_adding` is only reset in the **`.catch()`** branch. On the happy path the
component unmounts when navigation succeeds, so the leaked flag is harmless
in practice. But:

1. If `router.push` resolves with **the same route** (already on payment for
   some reason, or a guard redirects back without throwing), `_adding`
   remains `true` and the button stays `:disabled`.
2. If a future refactor keeps the component alive (e.g., wraps in
   `<keep-alive>` for back-navigation perf), the bug becomes visible: user
   returns via browser back, sees a permanently disabled CTA.
3. `selectedItems.forEach` performs **N synchronous mutations** with no
   try/catch. If the cart store throws mid-loop (e.g. `MAX_ITEM_QTY` clamp
   logic later evolves to throw), the partial state is committed and
   `_adding` stays `true` — UI looks frozen with no toast.

## Risks

- Latent dead-lock on the CTA if anything downstream changes routing
  semantics or component lifecycle.
- No rollback strategy if mid-loop dispatch fails — partial cart with
  user-visible silence.

## Proposed fix

1. Wrap the body in `try/finally`:
   ```js
   this._adding = true;
   try {
     this.selectedItems.forEach(item => { this.addItem({...}); });
     // toast + analytics
     await this.$router.push({ name: 'kiosk.payment' });
   } catch (err) {
     this.showToast(this.$t('kiosk.upsell_screen.error_generic'), 'error');
   } finally {
     this._adding = false;
   }
   ```
2. Snapshot `selectedItems` length **before** the loop so analytics and the
   toast are stable even if the array mutates mid-loop.
3. Add a Vitest case where `addItem` mock throws on the 2nd item — assert
   the flag is reset, a toast fires, and no navigation happens.

## Scope estimate

- ~10 LOC delta in `KioskUpsellComponent.vue` (frozen — requires LOCK doc).
- 1 new error i18n key (`kiosk.upsell_screen.error_generic`) in 5 lang files
  (fr, en, ar, bn, de).
- 1 new Vitest assertion.

## Acceptance criteria

- Mock `addItem` to throw → component shows error toast, button re-enables.
- No navigation triggered.
- Existing happy-path test still green.

## Rollback

Single-file revert — non-breaking change to error semantics.
