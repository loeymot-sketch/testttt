<?php

return [
    /*
    | When true, order creation paths in OrderService and FrontendOrderService
    | delegate line/tax/discount math to App\Services\Pricing\PricingService.
    | Set PRICING_USE_SSOT=false to fall back to legacy inline calculations.
    */
    'use_ssot_service' => filter_var(env('PRICING_USE_SSOT', true), FILTER_VALIDATE_BOOLEAN),
];
