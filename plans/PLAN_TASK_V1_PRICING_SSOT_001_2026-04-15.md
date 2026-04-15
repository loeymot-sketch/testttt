# Plan – TASK_V1_PRICING_SSOT_001 – 2026-04-15

## TASK_ID
TASK_V1_PRICING_SSOT_001

## PRIMARY_MODEL
GPT-5.4 (complex — frozen zones, pricing SSOT, algorithmic)

## TEST_STRATEGY
`local-validation` — PHPUnit fixtures (50+ baskets), parity test, API snapshot.

## PRIOR_CONTEXT
- Outbox pattern (OUTBOX_001) and event contract (EVENT_CONTRACT_001) are live — OrderService and FrontendOrderService dispatch events via listeners, not via `ShouldBroadcastNow`. No change to dispatch flow required here.
- Safety rule §1 ("Prix = SSOT Serveur") is the founding invariant this task enforces.
- `kioskPricing.js` is a display-only helper; the server recalculates at order time. No frontend pricing logic is removed in this cycle (display helpers stay read-only).

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `app/Services/Pricing/PricingService.php` | New core service — single entry point `calculateOrder()` | Write | Yes (branch tax config) | No |
| `app/Services/Pricing/PricingRequest.php` | Immutable value object (items, branch, customer, promos, context) | Write | No | No |
| `app/Services/Pricing/PricingResult.php` | Immutable value object (lines, subtotal, taxes, discounts, total) | Write | No | No |
| `app/Services/Pricing/PricingLineResult.php` | Per-line result VO (unit_price, extras, variations, tax, line_total) | Write | No | No |
| `app/Services/Pricing/TaxCalculator.php` | Tax sub-component — fixed vs percentage, branch-aware | Write | Yes | No |
| `app/Services/Pricing/DiscountCalculator.php` | Discount sub-component — coupon + manual + loyalty | Write | No | No |
| `app/Services/OrderService.php` | **FROZEN — controlled refactor**: replace inline pricing in `myOrderStore`, `posOrderStore`, `tableOrderStore` with `PricingService::calculateOrder()` calls | Write | Yes | Yes (existing, untouched) |
| `app/Services/FrontendOrderService.php` | **FROZEN — controlled refactor**: replace inline pricing in `myOrderStore` with `PricingService::calculateOrder()` call | Write | Yes | Yes (existing, untouched) |
| `config/pricing.php` | New config: `use_ssot_service` feature flag (default true) | Write | No | No |
| `tests/Unit/Services/Pricing/*` | Exhaustive unit tests for PricingService | Write | No | No |
| `tests/Integration/PricingParityTest.php` | Parity: same basket → same result POS vs Kiosk | Write | No | No |

## SUBSYSTEMS_OFF_LIMITS
- `app/Enums/OrderStatus.php` — V1_STATUS_MACHINE_001
- Events / broadcast layer — V1 vagues 1 (OUTBOX_001, EVENT_CONTRACT_001, SYNC_BACKBONE_001)
- Vue components — no UI changes this cycle
- Database migrations — no schema changes
- Auth / middleware — out of scope

## INVARIANTS_AT_RISK
- **Backend pricing SSOT** — this is the invariant being enforced. Risk: during migration, if PricingService diverges from the existing inline logic, monetary amounts change. Mitigation: feature-flag + snapshot tests comparing old path vs new path.
- **OrderService / FrontendOrderService symmetry** — central invariant. Both services switch to the same `PricingService`. Symmetry becomes structural, not just reviewed.
- **branch_id data isolation** — tax rules can vary per branch. `TaxCalculator` must receive and respect `branch_id` context from `PricingRequest`.
- **Frozen zone** — both OrderService and FrontendOrderService are frozen. Gate brief required and written at `docs/gates/GATE_V1_PRICING_SSOT_001_2026-04-15.md`.
- **Dispatch after DB commit** — existing dispatch logic is NOT modified. Only pricing calculation code is extracted; event dispatch stays in place.

## GATE_CONDITIONS
- **Gate required: YES**
- Gate brief: `docs/gates/GATE_V1_PRICING_SSOT_001_2026-04-15.md`
- Gate must be cleared by human before EXECUTE begins.
- Gate covers: frozen zone edits to OrderService + FrontendOrderService, pricing algorithm extraction, feature-flag rollback plan.

## Execution Steps

### E1 — Create greenfield Pricing domain (no frozen zone contact)

1. Create `app/Services/Pricing/PricingRequest.php` — immutable value object:
   - `items`: array of item descriptors (product_id, quantity, variations, extras, wizard_state)
   - `branch`: Branch model (for tax config)
   - `customer`: nullable Customer model
   - `promotions`: array (coupon, manual discount amount, loyalty points)
   - `context`: string `'pos'` | `'kiosk'` | `'web'` | `'table'`

2. Create `app/Services/Pricing/PricingLineResult.php` — immutable per-line result:
   - `item_id`, `quantity`, `unit_price`, `variation_total`, `extra_total`, `line_subtotal`, `tax_id`, `tax_name`, `tax_rate`, `tax_type`, `tax_amount`, `line_total`

3. Create `app/Services/Pricing/PricingResult.php` — immutable order-level result:
   - `lines`: array of `PricingLineResult`
   - `subtotal`, `total_tax`, `discount`, `delivery_charge`, `total`

4. Create `app/Services/Pricing/TaxCalculator.php`:
   - `calculateLineTax(float $lineSubtotal, Tax $tax): float` — handles fixed vs percentage, applies `round(..., 2)`.

5. Create `app/Services/Pricing/DiscountCalculator.php`:
   - `calculateDiscount(float $subtotal, ?Coupon $coupon, float $manualDiscount, ?array $loyaltyRedemption): float`
   - Delegates coupon validation to existing `CouponService::calculateDiscountAmount()`.
   - Loyalty: uses settings `loyalty_points_for_1_euro_discount`, `loyalty_min_redeem_points`.
   - Returns total discount, capped at subtotal floor 0.

6. Create `app/Services/Pricing/PricingService.php`:
   - `calculateOrder(PricingRequest $req): PricingResult`
   - For each item: load DB price (`Item::find`), load variations + extras from DB, compute line_subtotal, apply tax via `TaxCalculator`, produce `PricingLineResult`.
   - Sum lines → subtotal, total_tax.
   - Apply `DiscountCalculator` → discount.
   - `total = max(0, round(subtotal + total_tax + delivery_charge - discount, 2))`.
   - **All `round(..., 2)` applied consistently** — resolves the existing POS vs Kiosk rounding divergence.

7. Create `config/pricing.php` with `'use_ssot_service' => env('PRICING_SSOT', true)`.

### E2 — Unit tests for PricingService (before frozen zone contact)

1. Create `tests/Unit/Services/Pricing/PricingServiceTest.php`:
   - 50+ fixture baskets covering: single item, multi-line, variations, extras, wizard menu items, fixed tax, percentage tax, multi-tax-rates, coupon (percentage), coupon (fixed), coupon with max cap, manual discount, loyalty redemption, zero-total floor, delivery charge, rounding edge cases (0.005 → 0.01), empty basket.
2. Create `tests/Unit/Services/Pricing/TaxCalculatorTest.php`.
3. Create `tests/Unit/Services/Pricing/DiscountCalculatorTest.php`.
4. All tests must pass green **before** E3 begins.

### E3 — Bascule OrderService (FROZEN ZONE — gate required)

For each pricing path (`myOrderStore`, `posOrderStore`, `tableOrderStore`):

1. Guard with feature flag:
   ```php
   if (config('pricing.use_ssot_service', true)) {
       $pricingResult = $this->pricingService->calculateOrder(
           new PricingRequest(
               items: $verifiedItems,
               branch: $this->order->branch,
               customer: $this->order->user ?? null,
               promotions: ['coupon' => $coupon, 'manual_discount' => $manualDiscount],
               context: 'pos', // or 'web', 'table'
               deliveryCharge: $this->order->delivery_charge ?? 0,
           )
       );
       $this->order->subtotal = $pricingResult->subtotal;
       $this->order->total_tax = $pricingResult->total_tax;
       $this->order->discount = $pricingResult->discount;
       $this->order->total = $pricingResult->total;
   } else {
       // ... existing inline pricing logic preserved for rollback ...
   }
   ```
2. Existing item-level data (OrderItem creation with verified prices) continues to use PricingLineResult for consistency.
3. Event dispatch, notification dispatch, and all non-pricing logic remain untouched.

### E4 — Bascule FrontendOrderService (FROZEN ZONE — gate required)

1. Same pattern as E3 with `context: 'kiosk'`.
2. Loyalty redemption flows through `PricingRequest.promotions.loyalty_points`.
3. Feature flag same as E3 — single flag controls both paths.

### E5 — Parity test

1. Create `tests/Integration/PricingParityTest.php`:
   - For each of 50+ fixture baskets, build `PricingRequest` with context `'pos'` and `'kiosk'`.
   - Assert `$posResult->total === $kioskResult->total` (bit-exact).
   - Assert line-level parity for subtotal, tax, discount.

### E6 — API snapshot test

1. Verify existing PHPUnit tests that hit `/api/order/*` still pass with identical JSON shape.
2. If no existing snapshot tests: create minimal tests confirming response keys and value types are unchanged.

### E7 — Documentation

1. Update `docs/BUSINESS_RULES.md`: pricing section points to `App\Services\Pricing\*`.

## SYMMETRY_NOTE
This task **structurally resolves** the OrderService / FrontendOrderService symmetry concern for pricing. After this cycle:
- Both services call `PricingService::calculateOrder()` with the same algorithm.
- Rounding, tax, and discount divergences are eliminated by construction.
- The `context` field in `PricingRequest` allows branch-specific behavior (e.g., loyalty only available for kiosk/web) without code duplication.

Asymmetry risks remaining post-cycle:
- Item validation logic (variation/extra ownership checks) — differs slightly between POS and Kiosk. Not in scope; documented for future task.
- Delivery charge calculation — currently POS-only UI logic. Passed as input to PricingService; not calculated by it.

## KNOWN_DIVERGENCES_RESOLVED
1. **Rounding**: POS `posOrderStore` used `round()` on line totals and tax; web `myOrderStore` did not. → PricingService applies `round(..., 2)` uniformly.
2. **Loyalty**: Only in FrontendOrderService. → Handled via `DiscountCalculator` with `promotions.loyalty_points` in PricingRequest.
3. **Manual discount**: Only in POS. → Handled via `promotions.manual_discount` in PricingRequest.
4. **delivery_charge null safety**: FrontendOrderService used bare `$this->frontendOrder->delivery_charge` without `?? 0`. → PricingService receives explicit `deliveryCharge` parameter.

## SCOPE_PRESSURE


## ESCALATION


## Audit Status
[ ] Pending
[ ] Passed — cycle closed
[ ] Gate opened — `docs/gates/GATE_V1_PRICING_SSOT_001_2026-04-15.md`
