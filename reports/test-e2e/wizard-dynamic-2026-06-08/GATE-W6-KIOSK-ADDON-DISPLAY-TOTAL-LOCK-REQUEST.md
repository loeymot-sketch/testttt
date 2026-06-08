# LOCK Request — Wave 6 box: addon-role component display total (frozen KioskWizardComponent)

**Status**: PENDING owner countersign. **Frozen file**: `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (CLAUDE.md §7).
**Date**: 2026-06-08. **Branch**: `heal/pre-cloud-exec-2026-06-05`.

## Context

Owner decision **G-0b = A** (box = full-price bundle, each component billed at its `addonItem.price`
full, no menu ratio). Two ways to model a box's components on a generic (non-frozen) wizard page:

| Model | Server price | Kiosk DISPLAY price | Frozen edit? |
|---|---|---|---|
| **`extra_group` full-price components** | full ✅ | full ✅ (`composerExtraTotal`) | **none** — SHIPPED |
| **`addon` role='upsell' components** | full ✅ | **under-displayed ✗** | **yes** (this gate) |

## The finding (verified in the frozen file)

`KioskWizardComponent.vue buildCartItem` sums into the displayed line total:
`itemVariationTotal = … + composerVariationTotal` and `itemExtraTotal += composerExtraTotal`
(lines ~1968, 1982). A generic composer **addon** selection is pushed to `normalizedAddons`
(line ~1906-1914) and reaches the server (`item_addons` → PricingService → full price), **but there
is no `composerAddonTotal`** — so the kiosk shows a total missing the box components while the server
charges the full amount. This is the exact display/server mismatch class the code already warns about
at lines 1917-1931 (E-001). Pinned by `tests/js/kioskBoxEscapeHatchSentinel.spec.js`.

## What is already SHIPPED (non-frozen, no gate)

A box modelled as **full-price `extra_group` components** displays AND bills correctly with zero
frozen edits — proven by `tests/Feature/Composer/BoxFullPriceBundleTest.php` (subtotal 9.50 = base +
full component prices) and the Wave 5 `personal-page` builder action creates exactly these pages.
**Recommendation: ship the `extra_group` box now.** This fully satisfies "box = full-price bundle".

## The frozen edit requested (only if owner wants addon-product-linked box components)

If box components must be **addons** (to link to a real component Item — stock, product page) rather
than extras, add a `composerAddonTotal` to `buildCartItem`, mirroring `composerExtraTotal`:

```js
// inside the composerChoiceEntries() loop, alongside the extra branch:
if (entry.source_type === 'addon') {
  normalizedAddons.push({ /* unchanged */ });
  const addon = this.findItemAddonById(item, entry.id); // resolve full price by id
  composerAddonTotal += (parseFloat(addon?.convert_price || addon?.price || 0) || 0) * count;
}
// …then:
itemExtraTotal += composerExtraTotal + composerAddonTotal; // OR a dedicated line in itemVariationTotal
```

- Scope: ~4 lines, display-only (server pricing already correct via PricingService SSOT).
- Risk: touches the frozen kiosk cart-total path → triple-green regression + visual gate required.
- Acceptance: `tests/js` kiosk wizard suite + a new test asserting an `addon` generic step adds its
  full price to the displayed total == server total (no E-001 mismatch).

## Decision needed from owner

- **A (recommended)**: ship `extra_group` full-price box (done, non-frozen). Defer addon boxes.
- **B**: countersign this LOCK to add `composerAddonTotal` for addon-linked box components.

---

# Related gate — POS generic-choices renderer (frozen pos-wizard.js, flag-gated)

**Status**: PENDING — blocks the `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED` flip. **Frozen file**:
`public/js/pos-wizard.js` (CLAUDE.md §7). Surfaced by the W5/W6 adversarial audit (P2).

**Finding (verified)**: `pos-wizard.js buildStepsFromComposerProfile` pushes projected choices as
`step.options` and the render dispatch (~lines 1131-1152) has **no `generic_choices` case**, while
`renderSauceStep`/`renderSupplementsStep`/`renderMenuStep` read `step.items` (undefined here). So a
personal page or box step on POS would render **blank** (no generic branch) or **throw** (a
registry-collided legacy type reading `step.items`). **Gated**: this whole path is behind
`isComposerAwareEnabled()` (`pos-wizard.js:436-441`), **default-OFF** — the flag flip is already an
owner gate. So it is **NOT reachable in V1 default config**; the personal-page/box features are
**kiosk-correct today**.

**Required before flipping `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED`**: add a `generic_choices` render
branch to the POS dispatch that reads `step.options` (and route composer steps to it rather than the
legacy `step.items` renderers). ~10-20 lines in the frozen file → owner LOCK + triple-green + visual.

**Mitigation already in place (non-frozen)**: the kiosk render is fully correct (this session's W6
sentinel + BoxFullPriceBundleTest). The PHP `RESERVED_KIOSK_STEP_KEYS` guard also prevents the
registry-collision crash class on the legacy POS renderers. Treat the POS generic renderer as a
hard checklist item on the composer-aware flag flip.
