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
    | When true, `items.price` is treated as TAX-INCLUSIVE (TTC):
    |   - tax_amount per line is EXTRACTED from the TTC line total
    |     (ht = ttc / (1 + rate/100); tax = ttc - ht)
    |   - order.total = sum(TTC lines) + delivery - discount  (NO tax added on top)
    |   - order_items.total_price stays TTC (matches OrderDetailsResource::buildTaxLines
    |     which already documents "Assumes total_price is TTC at the line level").
    |
    | When false (legacy behavior), prices are treated as HT (ex-tax) and tax is
    | ADDED on top to compute the order total. Existing fixtures and tests rely
    | on this semantic, so the default stays false; production opts in via env.
    |
    | Owner mandate (2026-05): FoodKing prices are TTC; production must run with
    | PRICING_TAX_INCLUSIVE=true to fix the user-visible "3€ display → 3.60€ paid" bug.
    */
    'tax_inclusive_prices' => filter_var(env('PRICING_TAX_INCLUSIVE', false), FILTER_VALIDATE_BOOLEAN),
];
