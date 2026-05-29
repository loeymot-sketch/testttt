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
                // [GOAL-2026-05-29 SYNC-ROBUSTNESS] Without an explicit timeout a
                // Pusher/Soketi network black-hole HANGS the broadcast (the worker
                // blocks until the 120s job timeout) instead of throwing — which
                // silently produces the "clean orphan" class (domain_events row
                // dispatched_at SET + last_error NULL, unreachable by
                // rescue/retry-failed/monitor and pruned at 90d). With a
                // connect/read timeout a dead endpoint THROWS in a few seconds →
                // DispatchDomainEventsJob Phase-3b catches → last_error set → the
                // row is recoverable by the re-queue lanes + surfaced by
                // MonitorOutboxStaleness. Values are well under the 120s job
                // timeout and the [1,5,15,60,300] backoff curve.
                'timeout' => (int) env('PUSHER_TIMEOUT', 5),
                'connect_timeout' => (int) env('PUSHER_CONNECT_TIMEOUT', 3),
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
