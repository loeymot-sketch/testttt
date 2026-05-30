<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature flags (V1 LOCAL Le Cayenne)
    |--------------------------------------------------------------------------
    */

    /*
    | offers_enabled — the admin "Offres" promo module.
    |
    | [GOAL-GOLIVE-VAT10 / S1 2026-05-30] DISABLED for V1. The Offers feature
    | DISPLAYS a discounted price to the customer/cashier (ItemResource
    | convert_price, rendered on kiosk + POS wizard) but PricingService
    | (FROZEN, SSOT) does NOT apply the discount — it charges full price. So an
    | active offer = "shows X, charges Y" (consumer-law/dispute risk, receipt
    | mismatch). Dormant today (0 offers exist). Until PricingService is wired
    | to apply offers server-side under a lock-plan, creating an offer is
    | blocked (offer create/update/item-assign routes → 403) and the nav entry
    | is hidden. Read routes stay open so existing data is never orphaned.
    |
    | Re-enable (FEATURE_OFFERS_ENABLED=true) ONLY after PricingService applies
    | active offers + a behavioral test proves charged == displayed.
    */
    'offers_enabled' => filter_var(env('FEATURE_OFFERS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

];
