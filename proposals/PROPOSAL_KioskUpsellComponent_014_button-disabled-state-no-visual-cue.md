# PROPOSAL 014 — `:disabled` button has no visual disabled style, can
mislead during double-tap

**Component**: `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`
**Phase**: B.5 — Frozen-zone audit (no edit, proposal only)
**Severity**: P4 (UX polish)
**Reasoning angle**: Client-impatient persona

---

## Observation

Line 75–84:

```html
<button type="button"
  v-if="selectedIds.length > 0"
  class="kiosk-btn-primary"
  :disabled="_adding"
  @click="addAndContinue"
  ...>
```

`_adding` toggles to `true` while items are being added + router push is
pending. The button is `:disabled` during that window, but the
`.kiosk-btn-primary` CSS rule (line 469–488) has **no `[disabled]`
state**:

```css
.kiosk-btn-primary {
  background: var(--kiosk-primary);
  color: var(--kiosk-text-on-red);
  ...
}
.kiosk-btn-primary:active { transform: scale(0.98); }
```

The browser default `disabled` styling (subtle opacity) **may not be
visible** on the custom-painted button — the red `--kiosk-primary`
background can mask the default disabled effect entirely.

Combined with PROPOSAL 002 (the `_adding` flag may never reset), users
who tap during the pending window see a button that looks identical to
the enabled state but doesn't respond. Impatient customer taps again
+ again + again, sometimes reads "*écran cassé*" and walks away.

## Risks

- Mid-tap double-press feels like the kiosk froze.
- Defeats the analytics signal — customers attribute the freeze to the
  kiosk and abandon, when really the navigation succeeded silently.

## Proposed fix

```css
.kiosk-btn-primary[disabled],
.kiosk-btn-primary:disabled {
  opacity: 0.6;
  cursor: not-allowed;
  pointer-events: none;
  background: var(--kiosk-primary-muted, var(--kiosk-primary));
}
.kiosk-btn-primary[disabled]::after {
  content: '';
  display: inline-block;
  width: 14px;
  height: 14px;
  margin-inline-start: 8px;
  border: 2px solid currentColor;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@media (prefers-reduced-motion: reduce) {
  .kiosk-btn-primary[disabled]::after { animation: none; }
}
```

The mini-spinner gives clear feedback: *"Working… please wait."*

## Scope estimate

- ~10 LOC CSS in `KioskUpsellComponent.vue` (frozen — LOCK doc).
- Vitest: snap render with `_adding=true`, assert class presence.

## Acceptance criteria

- During the pending window (after tap on *Ajouter et continuer*):
  button is visibly faded + shows spinner.
- Customer cannot re-trigger the click while pending.
- No motion when `prefers-reduced-motion: reduce`.

## Rollback

Single-file revert. CSS-only.
