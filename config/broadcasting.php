<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "pusher", "ably", "redis", "log", "null"
    |
    */

    'default' => env('BROADCAST_DRIVER'),

    // [HEAL B.3 2026-05-19] `polling_fallback` PHP config block removed —
    // had 0 PHP-side readers (no config('broadcasting.polling_fallback')
    // call anywhere in app/). The actual polling cadence is owned per-surface:
    //   - POS:   MIX_BROADCAST_POLLING_FALLBACK_MS webpack env (default 30000ms)
    //            -> resources/js/store/modules/posOrder.js:59-64
    //   - KDS:   hardcoded 5000ms (WS down) / 60000ms (WS up) — intentional tuning
    //            -> resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1759-1761
    //   - Kiosk: hardcoded 15000ms (always) — intentional tuning
    //            -> resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:152-154
    // Per-surface values are deliberate (operator-density vs kitchen-staleness
    // budget vs customer wait-time UX). Single SoT wire is V1.0.2 backlog;
    // V1 LOCAL ships with documented divergence. RED-Z3 §B-6 P1 closed.

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over websockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
