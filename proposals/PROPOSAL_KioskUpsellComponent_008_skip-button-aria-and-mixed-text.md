# PROPOSAL 008 — Skip button mixes accessible name + visible label + live
timer text → screen reader speaks duplicates / spam

**Component**: `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`
**Phase**: B.5 — Frozen-zone audit (no edit, proposal only)
**Severity**: P3 (a11y polish)
**Reasoning angle**: A11y (modal-like overlay)

---

## Observation

Lines 85–95:

```html
<button type="button"
        class="kiosk-upsell-skip"
        @click="skip"
        data-testid="kiosk-upsell-skip"
        :aria-label="$t('kiosk.upsell_screen.skip')">
  {{ $t('kiosk.upsell_screen.skip') }}
  <span class="kiosk-upsell-skip-timer" v-if="autoSkipRemaining < AUTO_SKIP_SECONDS">
    {{ $t('kiosk.upsell_screen.skip_timer', { n: autoSkipRemaining }) }}
  </span>
</button>
```

Issues:

1. `aria-label` is set to the **same string** as the visible text content.
   When a button has both `aria-label` and inner text, the **aria-label
   wins** for accessibility tree → the timer span (visible) is **never
   announced** to screen readers. The fast-food customer using VoiceOver
   has no idea the screen is about to auto-skip.
2. Even if you remove the `aria-label`, the live updating text inside the
   button can cause screen readers to interrupt other speech every time
   `autoSkipRemaining` ticks down. The `<span>` is not marked with
   `aria-live` or `aria-atomic`, so behaviour is reader-dependent.
3. `aria-label="kiosk.upsell_screen.skip"` is also a duplicate of the
   button's visible label — pointless redundancy by ARIA Authoring
   Practices.

## Risks

- Screen reader user cannot tell that the screen will auto-progress (loss
  of control / surprise).
- OR (other extreme) reader speaks "29 seconds, 28 seconds, …" infinitely
  → uncontrollable speech storm.

## Proposed fix

```html
<button type="button"
        class="kiosk-upsell-skip"
        @click="skip"
        data-testid="kiosk-upsell-skip">
  <span>{{ $t('kiosk.upsell_screen.skip') }}</span>
  <span v-if="autoSkipRemaining < AUTO_SKIP_SECONDS"
        class="kiosk-upsell-skip-timer"
        aria-hidden="true">
    {{ $t('kiosk.upsell_screen.skip_timer', { n: autoSkipRemaining }) }}
  </span>
</button>

<!-- Out-of-band, polite, atomic, throttled to every 5s -->
<div class="sr-only" role="status" aria-live="polite" aria-atomic="true">
  <template v-if="shouldAnnounceTimer">
    {{ $t('kiosk.upsell_screen.timer_announce', { n: autoSkipRemaining }) }}
  </template>
</div>
```

Where `shouldAnnounceTimer` is a computed that returns true only at
specific intervals (e.g., at 20s, 10s, 5s remaining) — milestone
announcements, not continuous babble.

New i18n key needed: `kiosk.upsell_screen.timer_announce` →
*"Cette page se fermera dans {n} secondes."* (Arabic, Bengali, German,
English mirrors).

## Scope estimate

- ~15 LOC delta in `KioskUpsellComponent.vue` (frozen — LOCK doc).
- 1 new i18n key × 5 languages.
- 1 SR-only utility class if not already defined (`.sr-only`).

## Acceptance criteria

- VoiceOver announces "Non merci, continuer sans" on focus of the skip
  button (visible text only, no aria-label override).
- At 20s, 10s, 5s remaining, polite announcement *"Cette page se fermera
  dans X secondes"*.
- Visual timer continues unchanged.

## Rollback

Single-file revert. The new i18n key default-falls back to English if
missing in a locale (`$t` returns the key on miss — visible to engineers,
invisible to customers because the locale fallback chain handles it).
