# HEAL — A11y / WCAG modal focus-trap + icon-button accessible names (round4)

Status: **GREEN** · NON-frozen · NOT committed · no `npm run` (disk-economical).

Heals the class flagged in `a11y-wcag.md` + `adversary-a11y-kiosk-clearmodal-focustrap.md`:
modals announce `aria-modal="true"` but never confine/restore focus; icon-only
close buttons have no accessible name.

## Pattern reused (no reinvention)
Factored the canonical focus-trap from `resources/js/components/frontend/kiosk/ds/KsModal.vue:133-180`
(focusables filter = non-disabled + non-aria-hidden; Tab/Shift+Tab cycle first↔last
with `e.preventDefault()`; save/restore `document.activeElement`) into a **shared
mixin** so the bespoke modals keep their own open/close signal and markup (zero
structural rewrite, zero frozen risk):

- **NEW** `resources/js/mixins/focusTrap.js` — `activateFocusTrap(panelEl, {initialFocus})`
  / `deactivateFocusTrap()`; `beforeUnmount` auto-releases + restores. Internals are
  plain instance props (not `data()`) to avoid wrapping DOM nodes in a reactive proxy
  (mirrors KsModal's `_modalTrapListener`).

## Files touched (7 source, all NON-frozen)
| File | Change |
|---|---|
| `mixins/focusTrap.js` | NEW shared mixin |
| `frontend/kiosk/KioskCartComponent.vue` | **P2** — `ref="clearPanel"`+`tabindex="-1"` on dialog, `ref="clearCancelBtn"` on « Non », `mixins:[…,focusTrap]`, `watch.showClearConfirm` activate/restore. (Promo block l.275+ is an inline form, **not** a dialog → no jumeau.) |
| `admin/pos/PosRefundModal.vue` | **P3** — `ref="panel"`, mixin, activate(initialFocus=reason)/deactivate in `order` watcher (added `else` to release on close); corrected the misleading « focus trap » comment. |
| `admin/pos/PosCounterCollectModal.vue` | **P3** — added missing `aria-modal="true"`, `ref="panel"`, mixin, activate/deactivate in `order` watcher. |
| `admin/pos/PosLoyaltyRedeemModal.vue` | **P3** — `ref="panel"`, mixin, activate/deactivate in `open` watcher; corrected comment. |
| `admin/pos/ItemComponent.vue:56,91` | **P3** — `:aria-label="$t('button.close')"` on both `.modal-close` (+`type="button"`). |
| `admin/pos/CreateCustomerAddressComponent.vue:7` | **P3** — `:aria-label="$t('button.close')"` on `.modal-close`. (The literal `frontend/account/address/CreateCustomerAddress…` path does not exist; the cited file is the POS one.) |

No new i18n keys — `button.close` ("Fermer") already exists (proven `PosComponent.vue:67`),
`pos.refund.close` / `pos.loyalty.redeem.close` already present.

## TDD specs (red → green) — `tests/js/a11y/modalFocusTrap.spec.js` (NEW, 18 tests)
- Mixin unit (Host, `attachTo:document.body`): focus→first; Tab from last→first (`defaultPrevented`); Shift+Tab from first→last; restore on deactivate.
- KioskCart: focus→« Non » on open; restore→trigger on close; `tabindex="-1"` present.
- PosLoyaltyRedeemModal (representative POS modal): focus→code input; Tab last→first; restore→trigger.
- ItemComponent (runtime mount): every `.modal-close` has non-empty `aria-label`.
- Source sentinels: 3 POS modals import mixin + wire activate/deactivate + `ref="panel"`; CounterCollect `aria-modal`; ItemComponent + POS address aria-labels.

Red proof (pre-heal): 14 fail / 4 pass. Green (post-heal): **18/18**.
(Fix vs first run: the activate chain is up to 3 nextTicks deep — host `$nextTick`→
mixin `$nextTick`→focus — so the `settle()` helper drains via `flushPromises()`.)

## Gates
- `npx vitest run tests/js/a11y/modalFocusTrap.spec.js` → **18/18**.
- Affected specs (`posRefundModalSentinel`, `posCounterCollectModalSentinel`,
  `counterCollect*`, `posLoyaltyRedeemModal`, `KioskCartRestyle`, `kioskCartPromo*`,
  `kioskCartAriaLive`, `posComponentA11y`, `posA11y`, `posKioskCashEncaisser`,
  `KeyboardNavigationSentinel`) → **150/151** (the 1 fail = pre-existing).
- `studioFrontendI18nParity` + `labelKeyParityFrontend` → **9/9** (no key drift).
- Sentinels dir (`tests/js/sentinels/`) → 4 fail = **1 pre-existing** (`KeyboardNavigationSentinel`,
  reads compiled `public/css/app.css` → minified `[role=button]` vs regex `[role="button"]`)
  + **3 freshness** (`app`/`pos-app`/`kds` BundleFreshness — gate-excluded "hors freshness";
  `kdsBundleFreshness` red despite 0 KDS files touched ⇒ pre-existing/by-design, no rebuild run).
  - **Pre-existing proof**: `git stash` of only my 8 files → `KeyboardNavigationSentinel`
    fails **identically** → not my regression (also flagged pre-existing in `a11y-wcag.md`).
- **Frozen-diff = 0** — verified `git status --porcelain` against the full §7 frozen list
  (pos-wizard.js/css, admin-pos-v4.blade, PaymentComponent, PosV5TrancheRow, Kiosk
  Wizard/App/Upsell, fiscal services, PricingService, BranchScope) → empty.
