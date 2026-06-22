# PROPOSAL 004 — A11y: weak landmark/role semantics + no initial focus
+ progressbar babble + alt-text bypasses sanitization

**Component**: `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`
**Phase**: B.5 — Frozen-zone audit (no edit, proposal only)
**Severity**: P1 (a11y · WCAG 2.1 AA gaps)
**Reasoning angle**: A11y (modal-like overlay)

---

## Observations (cluster of 4 sub-issues)

### 4.1 — No landmark / no announced page change

The root is `<div class="kiosk-upsell">` (line 2). When the SPA navigates
from cart → upsell, **screen readers receive no automatic announcement**
(SPA routing does not fire native page-load events). Combined with no
`<main>` landmark, NVDA/VoiceOver users land here with no context.

### 4.2 — Cards have wrong role semantics

Lines 30–42:
```html
<div class="kiosk-upsell-grid" role="list" aria-label="...">
  <div role="listitem" tabindex="0" :aria-pressed="..." @click="..." @keydown.enter ...>
```

`role="listitem"` is **incompatible** with the toggle-button behavior:
- `aria-pressed` is meaningless on `listitem`.
- ARIA Authoring Practices says toggles should use `role="button"` (or
  native `<button>`) with `aria-pressed`.
- Screen readers may not announce activation state changes correctly.

### 4.3 — Initial focus is never moved

`mounted()` (line 151) starts `loadSuggestions()`. When the suggestions
render, no `nextTick` + `focus()` runs. Keyboard users must Tab from
whatever element had focus before navigation (often the previous page's
"Continuer" button, which is now unmounted → focus falls to `<body>`,
forcing several Tabs to reach the first card).

### 4.4 — `progressbar` re-renders `aria-label` 10× per second

Lines 98–109:
```html
<div role="progressbar"
     :aria-valuenow="Math.round(autoSkipPct)"
     :aria-label="$t('kiosk.upsell_screen.skip_timer', { n: autoSkipRemaining })">
```

`autoSkipPct` updates every 100ms (line 191 setInterval). `aria-valuenow`
changes constantly; some screen readers (notably VoiceOver) announce each
change → continuous babble *"99%, 98%, 98%, 97%…"* drowning user
interaction speech. The `aria-label` re-binds when `autoSkipRemaining`
ticks (once per second) — also chatty.

### 4.5 — Image `alt` text bypasses sanitization

Line 46:
```html
<img v-if="item.thumb || item.image" :src="..." :alt="item.name" />
```

All other display sites use `sanitizeItemName(item.name)` (lines 38, 52).
Raw `alt` leaks unsanitized strings to assistive tech. Per
`kioskDisplayText.js`, sanitization strips raw label keys like `Label.X`,
`kiosk.foo`, `0undefined` — exactly the artefacts the FoodKing Visual
Test Mandate (CLAUDE §6) wants to never reach customers.

### 4.6 — `prefers-reduced-motion` not honoured

- `.kiosk-spinner` rotates infinitely (line 313).
- `.pop-enter-active` animation (line 536).
- `.kiosk-upsell-img` transforms on `:active` (line 387).
- `.kiosk-upsell-autoskip-fill` linear transition (line 533).

None gated by `@media (prefers-reduced-motion: reduce)`. Vestibular
disorder users get unwanted motion.

---

## Risks

- **WCAG 2.1 AA failure** on multiple criteria (2.4.3 Focus Order, 4.1.2
  Name/Role/Value, 2.3.3 Animation from Interactions).
- Accessibility complaint exposure for a French public-facing kiosk
  (RGAA equivalent applies, especially in a restaurant-public space).
- Bug-class screen reader users effectively cannot use the kiosk without
  staff assistance — anti-pattern for self-order.

## Proposed fix

1. **Landmark + announce**:
   ```html
   <main class="kiosk-upsell" role="main" :aria-labelledby="'kiosk-upsell-title'">
   ```
   Add `aria-live="polite"` ephemeral region announcing the page title on
   mount (or use Vue Router's `afterEach` global handler).

2. **Card roles**:
   ```html
   <button type="button" role="button"
           class="kiosk-upsell-card" :aria-pressed="..." :aria-label="...">
   ```
   Or keep `<div>` with `role="button"` (consistent with the keyboard
   handlers already in place); drop `role="list"`/`role="listitem"`.

3. **Initial focus**:
   ```js
   mounted() {
     this.loadSuggestions().then(() => {
       this.$nextTick(() => {
         this.$el.querySelector('[data-testid^="kiosk-upsell-card-"]')?.focus();
       });
     });
   }
   ```
   Optional: a `tabindex="-1"` heading focus pattern instead, for
   non-grabbing initial focus.

4. **Progressbar throttling**:
   - Move `aria-valuenow` to integer steps coarser than 100ms (e.g. only
     update aria attrs once per second; keep visual bar at 100ms via CSS
     transition).
   - Drop the dynamic `aria-label` and use a static
     `aria-label="$t('kiosk.upsell_screen.skip_timer_static')"`. Or hide
     the progressbar from assistive tech entirely (`aria-hidden="true"`)
     since the skip button already exposes the same timer text in its
     accessible name.

5. **Sanitize alt**:
   ```html
   :alt="sanitizeItemName(item.name)"
   ```

6. **Reduced motion**:
   ```css
   @media (prefers-reduced-motion: reduce) {
     .kiosk-spinner { animation: none; }
     .pop-enter-active, .pop-leave-active { animation: none; }
     .kiosk-upsell-img, .kiosk-upsell-card { transition: none; transform: none !important; }
     .kiosk-upsell-autoskip-fill { transition: none; }
   }
   ```

## Scope estimate

- ~30 LOC delta in `KioskUpsellComponent.vue` (frozen — requires LOCK doc).
- Optional new i18n key `kiosk.upsell_screen.skip_timer_static` x 5 langs.
- Vitest assertions for role/aria; axe-core run in Playwright.

## Acceptance criteria

- `axe-core` violations on `/kiosk/upsell` route drop to zero.
- Manual VoiceOver test: page change announced once, no progressbar babble.
- `prefers-reduced-motion: reduce` user-agent: spinner, pop, hover scale,
  bar all static.
- Vitest: cards have `role="button"`, `aria-pressed` toggles correctly.

## Rollback

Single-file revert. CSS changes are progressive enhancement under media
query — older browsers unaffected.
