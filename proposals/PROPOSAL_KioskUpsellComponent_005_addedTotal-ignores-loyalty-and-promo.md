# PROPOSAL 005 — `+addedTotal` preview ignores active loyalty/promo
discounts, misleading engaged customers

**Component**: `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`
**Phase**: B.5 — Frozen-zone audit (no edit, proposal only)
**Severity**: P2 (UX truthfulness · loyalty integration drift)
**Reasoning angle**: Loyalty integration · Pricing SSOT respected

---

## Observation

Lines 83 + 148:

```html
<span class="kiosk-btn-price">+{{ formatPrice(addedTotal) }}</span>
```

```js
addedTotal() { return this.selectedItems.reduce(
  (s, i) => s + parseFloat(i.convert_price || 0), 0
); },
```

The preview shows the **gross sum** of selected upsell items at catalogue
price. It does **not** account for:

- `kioskCart.loyaltyDiscount` (state set at `kiosk.loyalty` route earlier
  in the flow, `kioskCart.js:189–190`).
- `kioskCart.promoDiscount` (set by `validatePromo` at
  `kioskCart.js:556+`, may be `percent` type which applies to the whole
  cart including upsell items).
- VAT rounding differences between backend (`PricingService::calculateOrder`,
  SSOT per CLAUDE §8) and frontend's `Intl.NumberFormat` rounding.

A loyalty member with a 10% discount sees *"+3,80 €"* on the CTA while
the actual cart subtotal delta is closer to **3,42 €**. The
backend-authoritative final total will be correct, but the user has been
shown a higher number → either a confusing surprise discount at payment
or a stale anchor that erodes trust if the customer tries to
mental-arithmetic-check.

This **does not** violate Pricing SSOT (CLAUDE §8) because the value is
display-only and the actual order quote regenerates server-side. The
concern is **UX truthfulness**, not fiscal correctness.

## Risks

- Loyalty members feel their discount "doesn't apply to extras" — false
  impression depresses upsell take rate (the opposite of the screen's
  purpose).
- Future regression: if someone copies this aggregation to a place where
  it *does* leak into a backend payload, it would break SSOT.

## Proposed fix

### Option A — Honest preview using existing store getters

Read `kioskCart/subtotal` + `kioskCart/total` getters before and after
projected addition:

```js
addedTotalNet() {
  const projectedSubtotal = this.$store.getters['kioskCart/subtotal']
    + this.addedTotalGross;
  const loyaltyRate = this.loyaltyDiscount && this.subtotal
    ? this.loyaltyDiscount / this.subtotal : 0;
  const promoRate = this.promoMeta?.type === 'percent'
    ? (this.promoMeta.value || 0) / 100 : 0;
  // Best-effort estimate; backend remains SSOT at payment time.
  return Math.max(0, this.addedTotalGross * (1 - loyaltyRate - promoRate));
}
```

Render both gross and net only if they differ:
```html
<span class="kiosk-btn-price">
  +{{ formatPrice(addedTotalNet) }}
  <span v-if="addedTotalNet < addedTotalGross"
        class="kiosk-btn-price-strike">{{ formatPrice(addedTotalGross) }}</span>
</span>
```

### Option B — Drop the preview when discounts active

```html
<span class="kiosk-btn-price"
      v-if="!hasLoyaltyOrPromo">+{{ formatPrice(addedTotal) }}</span>
<span class="kiosk-btn-price-note"
      v-else>{{ $t('kiosk.upsell_screen.preview_estimate_note') }}</span>
```

Show "Total calculé au paiement" hint to set expectations.

### Option C — Quote the cart server-side on every toggle (rejected)

Too chatty for a 30-second client-impatient screen. Each tap would round-
trip; degrades the impatient-customer experience.

**Recommendation**: Option A is best — keep the preview useful, respect
loyalty members. Comment in code: *"DISPLAY ONLY — backend
PricingService remains SSOT (CLAUDE §8). Drift up to 1 cent acceptable
due to rounding."*

## Scope estimate

- ~10 LOC delta in `KioskUpsellComponent.vue` (frozen — LOCK doc).
- Vitest: assert `addedTotalNet` against a fixture with 10% loyalty.
- Optional new i18n key for the strike-through tooltip.

## Acceptance criteria

- With `loyaltyDiscount = 1.50, subtotal = 15.00, selectedItems = [3.80]`,
  preview reads `+3,42 €` (or close enough within rounding).
- Backend final order quote (via `quoteOrder`) matches within 0,01 €.
- Without active loyalty/promo, preview matches the current behavior
  (`+3,80 €`).

## Rollback

Single-file revert. Display-only change; SSOT contract unaffected.
