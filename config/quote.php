<?php

/**
 * [P0-FIX iter12 2026-05-09] OrderQuoteService TTL configuration.
 *
 * Cashier multi-paiement (split-payment) flow regularly takes 90s–3min
 * for 2–4 tranches with change calculation. Default TTL 60s caused
 * silent quote expiration → "Order quote expired" UX bug visible on
 * POS payment modal.
 *
 * Tuning notes :
 *  - Tests: env('QUOTE_TTL_SECONDS', 60) for fast regression
 *  - Dev/Staging: 300 (5 min, default)
 *  - Production: 300–600 depending on cashier observed median time
 *
 * Quote service: app/Services/Order/OrderQuoteService.php
 */

return [
    'ttl_seconds' => (int) env('QUOTE_TTL_SECONDS', 300),
];
