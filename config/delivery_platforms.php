<?php

/**
 * [PARALLEL-TRACK-1.2 / Delivery Platform Integration — Phase 2]
 *
 * Per-platform tunables for the inbound webhook + outbound status push
 * pipeline. Keys map onto the `delivery_platforms.platform` enum
 * registered in the DeliveryServiceProvider.
 *
 * Contract:
 *   - signature_header   : HTTP header carrying the per-platform HMAC.
 *   - timestamp_window   : max |now - signedTimestamp| accepted (s).
 *   - base_url           : platform API root, used for outbound pushStatus.
 *   - webhook_url_pattern: doc-only — what the platform should call us back on.
 *
 * `replay_cache_ttl` covers the per-nonce dedupe key the middleware writes
 * into the cache to short-circuit replay attacks. Keep it ≥ timestamp_window
 * so a replayed envelope cannot slip through after the cache eviction but
 * before the timestamp window closes.
 */

return [

    'platforms' => [

        'uber_eats' => [
            'signature_header'    => 'X-Uber-Signature',
            'timestamp_window'    => 300,
            'base_url'            => env('UBER_EATS_API_BASE_URL', 'https://api.uber.com'),
            'webhook_url_pattern' => 'https://api.uber.com/v1/eats/orders/{order_id}/status',
        ],

        // [PARALLEL-TRACK-1.3 / Delivery Platform Integration — Phase 3]
        //
        // Deliveroo Restaurant Hub. Signature scheme is a flat HMAC-SHA256
        // over the raw body (no timestamp prefix), so `timestamp_window` is
        // null — replay defense rides on the `X-Deliveroo-Sequence-Guid`
        // header instead, which the VerifyDeliverySignature middleware
        // already converts into a per-platform per-nonce cache key.
        'deliveroo' => [
            'signature_header'    => 'X-Deliveroo-Hmac-Sha256',
            'sequence_header'     => 'X-Deliveroo-Sequence-Guid',
            'timestamp_window'    => null,
            'base_url'            => env('DELIVEROO_API_BASE_URL', 'https://api.deliveroo.com'),
            'webhook_url_pattern' => 'https://api.deliveroo.com/orders/{order_id}/sync_status',
        ],

        // [PARALLEL-TRACK-1.3 / Delivery Platform Integration — Phase 3]
        //
        // Delicity uses a Stripe-style signature header
        // (`X-Delicity-Signature: t=<ts>,v1=<hex>`). HMAC is computed over
        // "{timestamp}.{rawBody}" with the per-branch webhook secret, and
        // the timestamp window matches Stripe's recommended default (5min).
        'delicity' => [
            'signature_header'    => 'X-Delicity-Signature',
            'timestamp_window'    => 300,
            'base_url'            => env('DELICITY_API_BASE_URL', 'https://api.delicity.com'),
            'webhook_url_pattern' => 'https://api.delicity.com/v1/orders/{order_id}/status',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | The named limiter `delivery-webhooks` is registered by the
    | RouteServiceProvider. Format `<max>,<minutes>`. 1000 req/min is
    | a generous ceiling — Uber Eats peak load on a 50-restaurant
    | tenant rarely exceeds 200/min.
    */
    'rate_limit' => [
        'webhooks' => '1000,1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Replay defense
    |--------------------------------------------------------------------------
    |
    | TTL (seconds) for the per-nonce cache key. The middleware refuses any
    | webhook whose nonce is already present in the cache within this window.
    | 600s ≥ Uber Eats max signature window (300s) so we cover the worst-case
    | replay timing.
    */
    'replay_cache_ttl' => 600,

];
