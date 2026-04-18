# Pricing SSOT (V1)

## Entry point

- `App\Services\Pricing\PricingService::calculateOrder(PricingRequest, CouponService)`
- Value objects: `PricingRequest`, `PricingResult`, `PricingLineResult`
- Subcomponents: `TaxCalculator`, `DiscountCalculator`

## Integration

`OrderService` (`myOrderStore`, `posOrderStore`, `tableOrderStore`) and `FrontendOrderService::myOrderStore` delegate line/tax/coupon/manual pricing when `config('pricing.use_ssot_service')` is **true** (default: `PRICING_USE_SSOT=true` in `.env`).

Legacy inline calculation remains in the `else` branches when SSOT is disabled.

## Kiosk loyalty

Loyalty point lock + ledger updates remain in `FrontendOrderService` after SSOT cart totals; only line/coupon math is centralized.
