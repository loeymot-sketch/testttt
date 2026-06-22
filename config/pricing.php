<?php

return [
    /*
    | When true, order creation paths in OrderService and FrontendOrderService
    | delegate line/tax/discount math to App\Services\Pricing\PricingService.
    | Set PRICING_USE_SSOT=false to fall back to legacy inline calculations.
    */
    'use_ssot_service' => filter_var(env('PRICING_USE_SSOT', true), FILTER_VALIDATE_BOOLEAN),

    /*
    | NF525 / TTC pricing mode.
    | When true (DEFAULT — fr restaurant/POS legal mandate), `items.price` is
    | treated as TAX-INCLUSIVE (TTC):
    |   - tax_amount per line is EXTRACTED from the TTC line total
    |     (ht = ttc / (1 + rate/100); tax = ttc - ht)
    |   - order.total = sum(TTC lines) + delivery - discount  (NO tax added on top)
    |   - order_items.total_price stays TTC (matches OrderDetailsResource::buildTaxLines
    |     which already documents "Assumes total_price is TTC at the line level").
    |
    | When false (legacy/test fixtures opt-in), prices are treated as HT (ex-tax)
    | and tax is ADDED on top. Set PRICING_TAX_INCLUSIVE=false explicitly to
    | restore legacy behavior (e.g. for fixture-bound regression tests).
    |
    | Owner mandate (iter15-BUG-NF525 2026-05-10): FoodKing prices are TTC;
    | the visible "3€ display → 3.60€ paid" bug was caused by the previous
    | default of `false`, which silently re-introduced HT-add semantics in any
    | environment without the env var set. Default flipped to `true` so a
    | missing env line in prod cannot reintroduce the NF525-breaking bug.
    */
    'tax_inclusive_prices' => filter_var(env('PRICING_TAX_INCLUSIVE', true), FILTER_VALIDATE_BOOLEAN),
];
