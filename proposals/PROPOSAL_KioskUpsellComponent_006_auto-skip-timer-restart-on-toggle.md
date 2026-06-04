# PROPOSAL 006 — Auto-skip timer restarts on every toggle, defeating its
purpose for indecisive customers (and analytics)

**Component**: `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`
**Phase**: B.5 — Frozen-zone audit (no edit, proposal only)
**Severity**: P3 (UX nuance · analytics integrity)
**Reasoning angle**: Client-impatient persona

---

## Observation

`toggleItem` (lines 208–218):

```js
toggleItem(item) {
  // Reset countdown on any interaction
  this.clearAutoSkip();
  this.startAutoSkip();
  // ... toggle logic ...
},
```

Every card tap **resets** the 30-second auto-skip countdown to 30s. The
intent is reasonable (don't auto-skip while the user is actively
selecting). But:

1. **A perpetually-undecided user** (toggles 5+ times deliberating) blocks
   the auto-skip indefinitely. The line *"Skip si chargement trop long"*
   (line 3 comment) reveals the original design intent for the impatient
   customer — but the toggle reset path inverts it.
2. **Analytics**: `upsell_rejected{reason: 'auto_timer'}` will be
   under-emitted because any interaction defers it; the data
   under-represents customer hesitation patterns.
3. The CSS `.kiosk-upsell-autoskip-fill` (line 529–534) animates back to
   100% width via `transition: width 0.1s linear` — visually the bar
   jumps backwards each tap, which can look glitchy at high tap rates.

## Risks

- Soft DoS: a confused customer with a friend kibitzing taps 20 times
  → 10 minutes of upsell screen blocking the kiosk.
- Skewed analytics → product decisions based on under-counted
  abandonment.

## Proposed fix

Two combinable refinements:

### A — Cap the reset to first N toggles, then let timer run

```js
data() { return { ..., _toggleResets: 0, MAX_RESETS: 3 }; },
toggleItem(item) {
  if (this._toggleResets < this.MAX_RESETS) {
    this.clearAutoSkip();
    this.startAutoSkip();
    this._toggleResets += 1;
  }
  // ... toggle logic ...
},
```

After 3 toggles, the timer keeps running. Customer can still tap *Ajouter
et continuer* at any point, or the kiosk progresses.

### B — Pause-on-engagement instead of restart

```js
toggleItem(item) {
  // Pause but don't restart — give 10s extra each interaction
  if (this.autoSkipRemaining < 10) {
    this.autoSkipRemaining = 10;
    this.autoSkipPct = 10 / AUTO_SKIP_SECONDS * 100;
  }
  // ... toggle logic ...
},
```

Smoother visual; impatient kiosk still progresses on its own without
ever rewinding to 30s.

### C — Hard ceiling on total screen lifetime

Independent of resets, after 120s on the upsell screen, force-skip with
analytics reason `auto_timer_hard_cap`. Belt-and-suspenders against the
soft-DoS case.

**Recommendation**: combine B + C. Smooth UX + hard upper bound.

## Scope estimate

- ~15 LOC in `KioskUpsellComponent.vue` (frozen — LOCK doc).
- Vitest: simulate 5 toggles, assert timer reaches 0 within 60s wall
  clock (via fake timers).
- Analytics whitelist may need `auto_timer_hard_cap` reason — backend
  controller `KioskEventController::ALLOWED_ANALYTICS_EVENTS` mirror.

## Acceptance criteria

- 5 rapid toggles + idle → screen auto-skips within 60s.
- Single toggle + idle → screen auto-skips at 30s (slight pause is OK).
- Hard cap fires after 120s regardless of interaction.

## Rollback

Single-file revert. Constants `AUTO_SKIP_SECONDS` / `MAX_RESETS` /
`HARD_CAP_SECONDS` can be tuned without code change if exposed via
`globalState.lists` config.

## Related

This pairs with **PROPOSAL 004** (a11y) — if the timer is shortened or
hard-capped, the progressbar babble fix in #004 becomes even more
important.
