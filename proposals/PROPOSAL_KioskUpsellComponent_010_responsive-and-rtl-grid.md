# PROPOSAL 010 — Fixed 3-column grid + no RTL flow check on smaller kiosk
hardware or Arabic locale

**Component**: `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`
**Phase**: B.5 — Frozen-zone audit (no edit, proposal only)
**Severity**: P3 (responsive + i18n RTL)
**Reasoning angle**: Client-impatient persona · A11y

---

## Observation

Line 339–348:

```css
.kiosk-upsell-grid {
  flex: 1;
  overflow-y: auto;
  padding: 26px 30px 12px;
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 22px;
  align-content: start;
  scrollbar-width: none;
}
```

`repeat(3, ...)` is hardcoded. Six suggestions on a 3-column grid → exactly
2 rows. Reasonable on a tall kiosk screen. But:

1. **Portrait kiosks** at ~1080×1920 with very tall headers can leave the
   second row scrolled off-screen; cards have `height: 176px` image +
   ~60px text = ~250px, two rows = 500px, OK in most layouts but no
   safeguard if the header grows.
2. **Landscape kiosks** at 1920×1080 — 3 columns at the same width
   become absurdly wide cards. Better would be `repeat(auto-fit,
   minmax(280px, 1fr))`.
3. **RTL (Arabic locale)** — CSS Grid in RTL layouts: items normally
   flow right-to-left, but the `.kiosk-upsell-check` and
   `.kiosk-upsell-add` are positioned with `right: 10px` (lines 428,
   443). In RTL these should switch to `left: 10px`. With current code,
   the checkmark visually clings to the *right* of the card in both
   directions → semantically wrong (action chrome typically follows
   reading direction).
4. The `.kiosk-btn-primary` uses `justify-content: space-between` (line
   482) with label left + price right. In RTL, price should be left and
   label right (it likely auto-flips because the component is inside a
   `dir="rtl"` root, but the `.kiosk-btn-price` `background:
   rgba(255,255,255,0.18); padding: 6px 14px; border-radius: 10px` is
   rounded equally — fine. Confirm by visual capture.

## Risks

- Empty / cropped grid on certain kiosk hardware → either suggestions
  off-screen or oversized cards eat the action button area.
- RTL kiosks (Arabic locale) ship with check/add badges on the wrong
  side — minor visual incoherence with overall RTL grammar.

## Proposed fix

1. **Auto-fit columns**:
   ```css
   .kiosk-upsell-grid {
     grid-template-columns: repeat(auto-fit, minmax(min(280px, 100%), 1fr));
     grid-auto-rows: minmax(0, max-content);
   }
   ```
   Maintains 3-col at standard widths, gracefully degrades to 2 on narrow,
   4 on wide.

2. **Logical positioning for chrome**:
   ```css
   .kiosk-upsell-check,
   .kiosk-upsell-add {
     inset-block-start: 10px;
     inset-inline-end: 10px;
     /* drop top/right */
   }
   ```
   `inset-inline-end` mirrors correctly in RTL.

3. **Visual regression test** via Playwright capture at:
   - 1080×1920 (portrait kiosk)
   - 1920×1080 (landscape kiosk)
   - 1024×768 (older hardware)
   - Each in `lang=fr` and `lang=ar`.
   Add to `tests/captures/<timestamp>/kiosk-upsell-{viewport}-{lang}.png`.

## Scope estimate

- ~10 LOC CSS in `KioskUpsellComponent.vue` (frozen — LOCK doc).
- 8 new Playwright capture cases.

## Acceptance criteria

- 1024px width: 2 columns visible.
- 1920px width: 4 columns visible.
- Arabic locale: check + add badges on the **left** side of each card
  (visually) without code changes per locale.
- All 8 captures pass visual review (no overflow, no cropping).

## Rollback

Single-file revert. CSS-only change with no behaviour impact.
