# PROPOSAL — PricingService::calculateOrder correctness & NF525 reconciliation

- **Target**: `app/Services/Pricing/PricingService.php` (FROZEN §7 SSOT pricing)
- **Phase**: B.5 — read-only proposal
- **Status**: PROPOSAL ONLY, zero file edits
- **Verdict**: ATTESTED with caveats — 1 P0 NF525 reconciliation risk, 1 P0 audit-chain consistency bug, 1 P1 perf, 2 P2 UX/contract clarity
- **Findings count**: 5
- **Composition snapshot**: B3.3 INSERT-only DBA attestation accepted as-is (this proposal does NOT contest it; the snapshot generation logic itself is sound — see §3 below).

---

## Scope of audit

Full re-read of `PricingService.php` (815 LOC) plus dependencies:
- `TaxCalculator.php`
- `DiscountCalculator.php`
- `CompositionSnapshotBuilder.php`
- `PricingRequest.php` / `PricingResult.php` / `PricingLineResult.php`
- `config/pricing.php`
- `app/Enums/TaxType.php`
- callers in `OrderService.php`, `FrontendOrderService.php`, `OrderQuoteService.php` (verified persistence of `PricingResult` fields)

Edge cases probed: 0 items, single item, multi-item, multi-quantity, multi-rate tax (10% + 20%), TTC + HT modes, coupon-larger-than-subtotal, manual discount overage, FIXED tax, variations / extras / addons (incl. menu_* role ratios), composer step constraints, attribute repeats.

Mission focus areas covered: `calculateOrder` edge cases, tax computation (NF525), discount stacking, composition_snapshot per-item attribution, N+1.

---

## Finding 1 [P0 — NF525 audit-chain consistency] — Order-level `discount` persisted unclamped while `total` is clamped to ≥ 0

**Location**: `PricingService.php` lines 351-355

```php
if ((bool) config('pricing.tax_inclusive_prices', false)) {
    $rawTotal = $realSubtotal + $delivery - $calculatedDiscount;
} else {
    $rawTotal = $realSubtotal + $totalTax + $delivery - $calculatedDiscount;
}
$finalTotal = $req->roundFinalOrderTotal ? round(max(0.0, $rawTotal), 2) : max(0.0, $rawTotal);
```

**Defect**

`$finalTotal` is clamped to `max(0.0, ...)` but `$calculatedDiscount` is returned to the caller (and persisted into `orders.discount` — verified `OrderService.php:946 $this->order->discount = $posSsotPricingResult->discount;`) without the corresponding clamp.

Consequence: if a coupon (or manual cashier discount) is larger than `subtotal + tax + delivery`, the persisted row has:
- `total = 0.00`
- `discount = X` where `X > subtotal + tax + delivery`
- `total + discount ≠ subtotal + tax + delivery`

This breaks the canonical audit identity `subtotal + tax + delivery − discount = total` for any subsequent NF525 reprint, Z-report reconciliation, or fiscal export consumer. The on-disk row contradicts itself.

**Plausibility of trigger**

- POS cashier manual discount UI accepts a euro amount — a fat-finger on a low-value table is reachable. `DiscountCalculator::manualDiscount` already guards `$requested > $subtotal` (returns 0), but the *coupon* path has no such guard at the line `$calculatedDiscount = $this->discountCalculator->couponDiscount(...)`. `CouponService::calculateDiscountAmount` (not shown — flag for coverage check) caps coupons by `subtotal` typically, but only `subtotalForDiscount` is the cap basis, NOT `subtotal + tax + delivery`. In **TTC mode** (production default, per `config/pricing.php:31`), `subtotalForDiscount === realSubtotal === sum(TTC lines)` so coupon ≤ realSubtotal. Discount ≤ realSubtotal ≤ realSubtotal + delivery ⇒ total ≥ 0 in TTC mode. Safe.
- In **HT mode** (`pricing.tax_inclusive_prices=false`, fixture/test/legacy only), `$rawTotal = subtotal_HT + tax + delivery - discount`. If `discount` is bounded by `subtotal_HT` and tax+delivery ≥ 0, then `rawTotal ≥ tax + delivery ≥ 0`. Also safe — `max(0, ...)` is defensive but should not trigger from a percentage-bounded coupon.
- The unsafe corner is **percentage-coupon-on-HT subtotal with negative delivery_charge** (negative delivery is not a real V1 path but the type system allows it: `public readonly float $deliveryCharge` with no sign guard). Also **future flat-amount coupons** of larger denomination than the cart subtotal.

**Severity rationale (P0)**

Even if today's call sites never trigger the defect, a frozen-§7 SSOT must guarantee its output is internally consistent. The audit chain HMAC signs persisted columns; a future contributor adding a new coupon flavour (e.g., `flat_amount` coupon larger than cart) will introduce silent NF525 chain inconsistency.

**Proposed fix shape** (DO NOT APPLY — proposal only)

```php
// Clamp discount BEFORE computing rawTotal and BEFORE returning the result.
$discountBasis = (bool) config('pricing.tax_inclusive_prices', false)
    ? $realSubtotal + $delivery
    : $realSubtotal + $totalTax + $delivery;
$calculatedDiscount = min($calculatedDiscount, max(0.0, $discountBasis));

$rawTotal = $discountBasis - $calculatedDiscount;
$finalTotal = $req->roundFinalOrderTotal ? round(max(0.0, $rawTotal), 2) : max(0.0, $rawTotal);
```

Effect: `subtotal + tax + delivery − discount = total` becomes an invariant of the returned `PricingResult` regardless of caller. `max(0.0, ...)` then becomes truly defensive.

**Test addition (for the implementer wave)**

- New test in `PricingServiceTest.php`: a manual discount > subtotal in HT mode — assert `discount + total == subtotal + tax + delivery` (clamped identity).
- A flat-amount coupon > subtotal — same assertion.

---

## Finding 2 [P0 — NF525 tax breakdown drift in TTC mode with order-level discount] — `totalTax` is pre-discount; not redistributed across multi-rate buckets

**Location**: `PricingService.php` lines 322-369 + `OrderDetailsResource.php` `buildTaxLines()`

**Defect (NF525-critical, multi-rate cart only)**

Per-line `tax_amount` is extracted from each TTC line total **before** the order-level discount (`$totalTax` accumulates per-line at line 317). The discount is then subtracted from the order total at line 351 (`$rawTotal = $realSubtotal + $delivery - $calculatedDiscount`).

In TTC mode (production default), `orders.total_tax` is the **un-discounted** tax sum, but `orders.total` reflects the **discounted** TTC. The implicit fiscal identity NF525 expects on each receipt is:

> `sum(HT bases per tax rate) + sum(TVA per tax rate) = total`

Verified consumer: `app/Http/Resources/OrderDetailsResource.php:173 buildTaxLines()` reads `oi.tax_amount` per OrderItem and groups by rate, but that loop ALSO ignores the order-level discount when computing per-bucket HT (`'subtotal_without_tax_currency_price' => AppLibrary::currencyAmountFormat($this->subtotal - $this->total_tax)` line 41). So the resource shows:

- For a cart with 10€ TTC (TVA 10%) + 10€ TTC (TVA 20%) = 20€ subtotal, with a 5€ coupon:
  - `total = 20 - 5 = 15€` (correct)
  - `total_tax = 0.91€ (extracted from 10€ @ 10%) + 1.67€ (from 10€ @ 20%) = 2.58€` (pre-discount sum)
  - `subtotal - total_tax = 17.42€` HT (pre-discount HT)
  - Identity check: `15 ≠ 17.42 + 2.58 = 20`. Off by exactly the coupon amount.

The receipt's tax breakdown is provably wrong by the discount amount.

**Required behaviour (NF525 §V — Loi de Finance FR)**

When an order-level discount is applied across a multi-rate cart, the discount MUST be allocated proportionally to each tax bucket (or to a single bucket per the merchant's documented policy) and the **per-line `tax_amount` recomputed**, OR the receipt must explicitly itemize `Discount applied to TVA 10% basis: Xeur`, `Discount applied to TVA 20% basis: Yeur`. Neither is done today.

**Severity rationale (P0)**

This is the exact class of bug NF525 audits target. Single-rate carts are fine (the discount affects only one bucket and the line-level extraction is post-discount-equivalent up to rounding). Multi-rate carts with discounts are broken.

`docs/BUSINESS_RULES.md` may already document FoodKing single-rate-only policy — if so, this finding downgrades to P2 with an enforcement assertion. **Owner clarification needed.**

**Proposed fix shape**

Option A (proportional redistribution, the standard NF525 approach):

```php
// Pseudo — after line 354, before line 355:
if ($calculatedDiscount > 0 && $realSubtotal > 0) {
    $taxBuckets = []; // [rate => [ht_pre, tax_pre, line_count]]
    foreach ($lines as $line) {
        $rateKey = sprintf('%.2f|%d', $line->taxRate, $line->taxType);
        $taxBuckets[$rateKey] ??= ['ttc_pre' => 0.0, 'tax_pre' => 0.0];
        $taxBuckets[$rateKey]['ttc_pre'] += $line->lineSubtotalExTax; // TTC in TTC mode
        $taxBuckets[$rateKey]['tax_pre'] += $line->taxAmount;
    }

    $totalTax = 0.0;
    foreach ($taxBuckets as &$bucket) {
        $share = $bucket['ttc_pre'] / $realSubtotal;
        $discountForBucket = $calculatedDiscount * $share;
        $ttcAfter = $bucket['ttc_pre'] - $discountForBucket;
        $taxAfter = $this->taxCalculator->lineTaxAmountFromTTC($ttcAfter, /*…*/);
        $bucket['tax_after'] = $taxAfter;
        $totalTax += $taxAfter;
    }
    // Also propagate proportional updates to $itemsArray[*]['tax_amount']
    // (per-line redistribution) so OrderDetailsResource::buildTaxLines() sees
    // the post-discount truth.
}
```

Option B (single-bucket policy enforcement):

Add a hard `throw` if `count(distinct tax_rate among lines) > 1 && $calculatedDiscount > 0`, with a setting `pricing.allow_multi_rate_discount` defaulting to `false`. Cleaner but breaks any existing multi-rate carts.

**Owner gate needed** — option A is more invasive; option B is restrictive; status quo is NF525-non-conformant in the documented edge case.

---

## Finding 3 [P1 — Performance / N+1] — `assertVariationConstraints` re-queries `item_attributes` inside the per-item loop while `$dbAttributes` is already preloaded

**Location**: `PricingService.php` line 164 (inside `foreach ($requestItems as $item)`) calling `assertVariationConstraints(…)` lines 412-415:

```php
// PricingService.php line 114-117 (preloaded ONCE, before the loop)
$attributeIds = $dbVariations->pluck('item_attribute_id')->filter()->unique()->values()->all();
$dbAttributes = $attributeIds !== []
    ? ItemAttribute::query()->whereIn('id', $attributeIds)->get()->keyBy('id')
    : collect();

// later, inside foreach:
$this->assertVariationConstraints($item, $dbVariations);

// inside assertVariationConstraints, line 412-415:
$attrs = \App\Models\ItemAttribute::query()
    ->whereIn('id', array_keys($byAttribute))
    ->get()
    ->keyBy('id');
```

**Defect**

The preloaded `$dbAttributes` (line 117) is used only by the snapshot builder (line 274). `assertVariationConstraints` issues a **separate query** per item — N additional `item_attributes` SELECTs per cart with N items carrying variations.

For a typical cart of 1-3 items, this is +1 to +3 queries. For a 10-item POS table, +10 queries. Not catastrophic but unambiguously wasteful and the preloaded data is already in memory.

**Proposed fix shape**

Add `$dbAttributes` to the `assertVariationConstraints` signature and use it directly:

```php
private function assertVariationConstraints(object $item, $dbVariations, $dbAttributes): void
{
    // ... existing logic, but replace lines 412-415:
    foreach ($byAttribute as $attrId => $totalQty) {
        $attr = $dbAttributes[$attrId] ?? null;
        // rest unchanged
    }
}
```

Callsite at line 164 becomes `$this->assertVariationConstraints($item, $dbVariations, $dbAttributes);`.

**Severity rationale (P1)**

Bounded waste, no correctness impact. Worth fixing for free with the next pricing edit; not worth a standalone change.

---

## Finding 4 [P2 — UX / cashier visibility] — `DiscountCalculator::manualDiscount` silently returns 0 on overage

**Location**: `DiscountCalculator.php` lines 22-29

```php
public function manualDiscount(float $requested, float $subtotal): float
{
    if ($requested <= 0) {
        return 0.0;
    }
    return $requested <= $subtotal ? $requested : 0.0;
}
```

**Defect**

When a POS cashier enters a manual discount **larger than the cart subtotal**, the function silently returns `0.0`. No exception, no log, no signal to the controller. From the cashier UI's perspective, the order goes through with `discount = 0` (the cashier intended a non-zero discount).

This is also the behaviour for "legitimate" entries when there's a rounding gap (e.g., manual discount = 10.00€ exactly equal to subtotal — returns 10€, fine; but 10.01€ on a 10.00€ cart silently zeroes).

**Severity rationale (P2)**

Not NF525-critical (the persisted row stays consistent: `discount = 0`, the cashier got *something*). But it's an audit visibility gap and a known foot-gun. The POS V5 currently has client-side caps; the server-side fallback is too quiet.

**Proposed fix shape**

Two options — both backward-compatible:

A) Throw `InvalidArgumentException` (consistent with the rest of the service):

```php
if ($requested > $subtotal) {
    throw new \InvalidArgumentException(
        "Remise manuelle {$requested}€ supérieure au sous-total {$subtotal}€.",
        422
    );
}
return $requested;
```

B) Cap and return the capped value (silent but predictable):

```php
return min($requested, $subtotal);
```

Option B matches the clamping suggested in Finding 1 and is non-breaking. Option A is louder and probably the V1.0.x preference given the "fail-fast" tone of the rest of `PricingService`.

---

## Finding 5 [P2 — contract clarity] — `TaxCalculator::lineTaxAmount(*From*TTC)` FIXED tax does not scale with quantity, undocumented for callers

**Location**: `TaxCalculator.php` lines 15-19 and 34-37

```php
public function lineTaxAmount(float $lineSubtotalExTax, int $taxType, float $taxRate, bool $round): float
{
    $raw = $taxType === TaxType::FIXED
        ? $taxRate                                 // <-- IGNORES $lineSubtotalExTax (and implicit quantity)
        : ($lineSubtotalExTax * $taxRate) / 100.0;
    return $round ? round($raw, 2) : $raw;
}
```

**Observation**

For `TaxType::FIXED` (constant `5`), the tax amount is the flat `$taxRate` — independent of line subtotal AND quantity. A line of 1 burger and a line of 10 burgers carrying the same fixed-amount tax both see `tax_amount = $taxRate`.

This may be intentional (e.g., a per-line eco-tax) but it is **not what most operators expect** from "fixed tax" — typical fixed-amount taxes (e.g., per-bottle excise, eco-participation per unit) are **per-item**, not per-line. The current implementation can underbill quantity-scaling fixed taxes by `(quantity-1) × rate`.

Same pattern in `lineTaxAmountFromTTC` line 34-37 (returns `$taxRate` verbatim). The block comment there says "preserve legacy semantic" so the call is *deliberate*, but the contract is documented internally to `TaxCalculator`, not to its callers.

**Severity rationale (P2)**

If FoodKing's V1 catalogue only uses PERCENTAGE taxes (highly likely for restaurant TVA 10%/20%), this is dead code in practice. If a future catalogue adds a per-bottle excise (e.g., "redevance bouteille +0.15€") and configures it as FIXED, the system silently underbills. Should be:
- Either documented loudly in `TaxCalculator` PHPDoc that FIXED is **per-line**, not per-item.
- Or changed to `$taxRate * quantity` (requires passing `$quantity` to `TaxCalculator` — `PricingService.php:258` only passes `$verifiedTotalPrice`, so quantity is *not* known to TaxCalculator today).

**Proposed fix shape**

Minimum-invasive: PHPDoc clarification on both methods + a `@see PricingService::calculateOrder` reverse-pointer + a regression test asserting the per-line semantic.

More-invasive: add `int $quantity` parameter, propagate from `calculateOrder` line 232 (`$verifiedQuantity`), apply `$raw = $taxRate * $quantity` in the FIXED branch.

Owner gate on which interpretation is correct.

---

## Cross-cutting positive observations (not findings)

These confirmed correct during the audit:

1. **0-items cart**: `if ($requestItems !== []) {…}` line 125 short-circuits cleanly; `$realSubtotal=0`, `$totalTax=0`, `$finalTotal=max(0, 0 + delivery - 0) = delivery`. Coupon code path is unreachable because `$couponId > 0 → couponDiscount → CouponService::resolveCouponById($couponId, 0, $userId)` would throw or return zero (depends on CouponService — separate proposal scope).

2. **Cross-item guards** (`enforceCrossItemGuards=true` in all 4 factory methods: PricingRequest.php lines 41, 61, 81, 101) — verified that a variation/extra/addon attached to item X with `item_id=Y` is rejected at lines 152-156, 182-186, 207-211. Defends against payload forgery.

3. **Composer step constraints** (`assertComposerStepConstraints` lines 557-657) — verifies min/max/allow_repeat at the **profile** layer (post-projection through `ComposerProfileProjection`), independent of and complementary to the **attribute** layer in `assertVariationConstraints`. Two defensive layers; double the breakage if either gets a regression.

4. **`menuRoleAdjustedAddonPrice` defense-in-depth** — `PricingService.php` line 793 (line price application) and `CompositionSnapshotBuilder.php` line 185 (snapshot effective price) both use `config('kiosk.menu_pricing')` ratios via two independent functions. Intentional duplication per `HEAL-PLAN-D.1 / RED-Z4 P0-Z4-01 2026-05-19`. Confirmed sound: if a future internal caller bypasses the FormRequest, the snapshot still seals the catalog price when the DB role isn't `menu_component`. Not a finding.

5. **Composition snapshot per-item attribution completeness**:
   - `lines[]` (variations): variation_id, attribute_id, attribute_name, variation_name, quantity, unit_price, line_total ✓
   - `extras[]`: extra_id, extra_name, quantity, unit_price, line_total ✓
   - `addons[]`: addon_id, addon_item_id, addon_name, role, quantity, unit_price, line_total, catalog_price ✓
   - `schema_version=1` + `captured_at` ISO 8601 ✓
   - INSERT-only confirmed by B3.3 DBA attestation.
   - No `instruction` snapshot (line 292 persists `instruction` on the OrderItem row but not inside `composition_snapshot`). Defensible since reprint reads from `order_items.instruction` directly. Not a finding.

6. **Variation/extra/addon ID isolation** — `dbVariations`, `dbExtras`, `dbAddons` are all `whereIn(...)` preloaded once before the per-item loop (lines 90-98). No N+1 there. Only the attribute layer (Finding 3) has the gap.

7. **Item ID isolation** — `dbItems` line 57-61 preloaded once with `select('id', 'price', 'tax_id')` — minimal columns, no N+1, projection-clean.

8. **Composer profile resolution** — `assertComposerStepConstraints` uses `with(['steps' => fn ($query) => $query->where('is_active', true)->orderBy('position')])` + grouped/sorted profile selection. Branch-scoped (line 569) + version-precedence (line 753 spaceship) — robust against profile drift.

---

## Returned data

- **Path**: `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/proposals/PROPOSAL_PricingService_003_calculate-order-correctness.md`
- **Findings**: 5 (2 P0, 1 P1, 2 P2)
- **Verdict**: PROPOSAL-ONLY, ZERO file edits. Frozen §7 SSOT honoured. Owner gate REQUIRED for any of the 2 P0s. P1 and P2s can ship in a routine pricing-touching wave if owner consents.
