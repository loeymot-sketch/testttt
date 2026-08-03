# Deep Pricing / SSOT Adversarial Audit — 2026-07-11

**Scope:** break `PricingService::calculateOrder` (SSOT backend). Read-only + tinker + PHPUnit.
**HEAD:** `3628c1ccc` · branch `pos/category-first-caisse-2026-06-23` · DB `foodking_e2e` · `PRICING_TAX_INCLUSIVE=true`.
**Verdict:** SSOT total-correctness **HOLDS**. 0 P0/P1. 1 real P2 (data), 1 P3, 1 minor/latent. Frozen `PricingService.php` untouched (audit only).

All numbers below are REAL, computed live via `PricingService` against the live catalog (tax 3 = VAT 10% percentage; TTC mode).

---

## 1. Wizard combinatorics — PASS
Real products, POS rounding. Every combo matched expectation to the cent:

| Combo | Expected | Got |
|---|---|---|
| Tacos M (26) viande(43)+sauce(312), both 0€ | 6.90 | 6.90 |
| + Viande supplémentaire extra (392 = 2.50) | 9.40 | 9.40 |
| + Cheddar extra (250 = 0.90) | 10.30 | 10.30 |
| + Menu formule FULL (addon 37, role menu_full, 2.50) | 12.80 | 12.80 |
| Tacos M ×3 | 20.70 | 20.70 |
| Tacos L (97) 2 viandes (attr1+attr2) + sauce | 7.90 | 7.90 |
| Cayenne (22) pain(450) + sauce | 7.40 | 7.40 |
| Cheese Burger (98) + sauce | 6.00 | 6.00 |

Viande/sauce/pain variations are all priced 0 (free choices, attr min1/max1); paid modifiers are ItemExtras (0.90 toppings, 2.50 "Viande supplémentaire"). Size (M/L/Big) is a distinct item, not a variation. Tax extraction on a 9.40 TTC line = 0.85 (= 9.40 − 9.40/1.1 rounded). Correct.

## 6. Menu formule — PASS (fixed-ratio split, gated)
Single `menu_component` addon (37, addon_item 1 "Menu Frites+Boisson" @2.50) carries the customer's choice via payload `role`; ratio from `config('kiosk.menu_pricing')` (full 1.0 / fries 0.6 / drink 0.4): **full=2.50, frites=1.50, boisson=1.00** — internally consistent (1.50+1.00 = 2.50). Not a naive sum of components.

**Attempted exploit (client-controlled `role`):** send full-menu addon 37 tagged `menu_boisson` → charged 1.00. **Blocked/consistent:** `App\Http\Requests\Concerns\ValidatesAddonRoles` (wired in PosOrderRequest, TableOrderRequest, OrderRequest, Kiosk/PricingPreviewRequest) requires DB `role='menu_component'` for any `menu_*` payload role and rejects it on drink/side/null addons (addons 38=side, 39=drink → 422). `CompositionSnapshotBuilder` re-applies the identical gate and seals the **ratio'd** price (unit_price 1.00, catalog_price 2.50) with the declared role, so price always matches the declared role — no leak. *Residual (not pricing):* snapshot `addon_name` is always the container "Menu (Frites + Boisson)" regardless of role; a KDS reading only the name (not the role) could over-prepare. Labeling nuance, not a price break — out of pricing scope.

## 5. Frontend cannot impose price — PASS
Store paths recompute 100% via `PricingService`; `PricingRequest::forWeb/forKiosk` hardcode `manualDiscount=0.0`; manual discount only honored for `context ∈ {pos,table}`. `ClientTotalWriteForbiddenSentinelTest` (static lint over order-write surfaces) + `PosOrderRequestNoClientTotalsTest` green. No `$order->total = $request->...` anywhere. A forged client total/price is ignored.

## 4. Livraison offerte ≥ 30€ — PASS (boundary correct)
Rule lives in `OrderService.php:860` + mirror `OrderQuoteService.php:323`, keyed on server `accumulatedSubtotal >= 30` (never a client value):
- cart 29.90 (2×Big Tacos 11.50 + Tacos M 6.90) → **frais facturés** (NO).
- cart 30.90 (2×Big Tacos + Tacos L 7.90) → **frais = 0** (YES).
`>=` means 30.00 exactly is free, 29.99 charged. Quote↔order parity confirmed (POS-1 heal 2026-07-10 closed the earlier 409 divergence).

## 2. Discounts — PASS on total; **P3 on VAT allocation**
Manual discount bounded: 5.00 on 13.80 → 8.80; 999 on 13.80 → discount ignored (0), total stays 13.80, **never negative** (`DiscountCalculator::manualDiscount` returns 0 if > subtotal; quote path 422s via `assertManualDiscountAllowed`).
**P3 (fiscal reporting):** an order-level discount reduces `total` correctly (13.80 → 8.80) but does **NOT** reallocate/reduce the reported `totalTax` — it stays 1.25 (VAT on the pre-discount 13.80 base) instead of 0.80 (VAT on the collected 8.80). Direction is conservative (over-declares VAT), so no under-payment risk, but the NF525 VAT breakdown on discounted tickets is inaccurate. Low volume V1-local; flag for cloud/accounting hardening.

## 3. Coupons — PASS (bounded)
`CouponService::calculateDiscountAmount` = `round(max(0, min(amount, subtotal)), 2)`:
- 20% cap 5€ on 100 → 5.00 (cap respected)
- 20% no-cap on 13.80 → 2.76
- fixed 50€ on 13.80 → 13.80 (bounded to subtotal, no negative)
`validateCouponForOrder` enforces minimum_order, dates, limit_per_user, max_uses_global, isUsableNow(branch/surface). Solid.
**Minor/latent:** 2 seeded coupons have `discount_type=2` (ADVKIOSKB1, ADVWEBONLY), a value NOT in `DiscountType` (FIXED=5/PERCENTAGE=10). Unreachable via API (`CouponRequest` validates `Rule::in([5,10])`), but if present `calculateDiscountAmount` silently treats type≠10 as FIXED and applies the flat `discount` (5€). Latent robustness gap only; add an explicit type guard when the coupon engine is next touched.

---

## P2 (REAL, DATA) — 7 catalog drinks have `tax_id = NULL` → 0€ VAT declared
Real menu SKUs (category 10 Boissons, price 1.90 each) with `tax_id` NULL:
`119 Coca Cherry 33cl · 120 Tropico 33cl · 121 Ice Tea Pêche 33cl · 122 Fanta Citron 33cl · 123 Fuze Tea 33cl · 124 Hawaï 33cl · 125 Perrier 33cl`.
Proof: item 119 → line tax **0.0000**; sibling item 52 (Coca-Cola, tax_id 3) → line tax **0.1700**.
Impact: customer pays the correct 1.90 TTC (total is right), but the NF525 fiscal VAT breakdown records **0€ VAT** on these 7 SKUs instead of ~0.173€ (10% of HT). Under-declaration whenever one of these 7 drinks is sold.
Not a `PricingService` bug — the service faithfully uses `tax_id` (NULL → rate 0). **Fix = data:** `UPDATE items SET tax_id = 3 WHERE id IN (119,120,121,122,123,124,125);` (aligns with siblings 52–59). Frozen zone untouched.

---

## Evidence
- Harness: `/private/tmp/.../scratchpad/harness.php` (live PricingService calls).
- Baseline suites GREEN: PricingIntegrity, PosPricingSsotProof(6), PosKioskPricingParity(4×24), TaxInclusivePrices(10), MenuRoleAdjustedAddonPrice(11), DiscountCalculator(7), TaxCalculator(10), PosOrderRequestNoClientTotals(4), ClientTotalWriteForbiddenSentinel(2).
- Config: tax 3 = 10% percentage; `kiosk.menu_pricing` full1.0/fries0.6/drink0.4; `delivery.free_delivery_above` = 30.

**Bottom line:** the pricing SSOT is sound — combinatorics, menu-formula (double-gated), coupons, manual discounts, free-delivery boundary and client-total rejection all hold. Two fiscal-accuracy items to fix (P2 data: null-tax drinks; P3: discount VAT reallocation), neither breaks the money-path total.
