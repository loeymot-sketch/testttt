<?php

use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort()
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. If this value is null, personal access tokens do
    | not expire. This won't tweak the lifetime of first-party sessions.
    |
    */

    // [AUDIT-P2] Kiosk/staff tokens should not live forever.
    // Default to 480 minutes (8h) and allow per-environment override.
    'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 480),

    /*
    |--------------------------------------------------------------------------
    | Kiosk Token Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | [SEC-1 / P1-11] Kiosk machines run unattended on dedicated hardware in
    | branches. Their tokens are issued once at boot/login and reused across
    | thousands of guest orders without human intervention. An 8-hour TTL would
    | force a daily re-login that nobody is around to perform — operationally
    | unrealistic.
    |
    | We therefore issue kiosk tokens with a 30-day TTL by default. This is
    | tighter than the previous behaviour (no expiration) and bounds the blast
    | radius of a leaked token: an attacker who siphons a kiosk token gets at
    | most 30 days of access before the token expires, and the kiosk frontend
    | is expected to renew via /api/auth/kiosk-refresh-token on a schedule
    | (V1.x: axios interceptor on 401 → refresh transparent).
    |
    | Revocation: existing kiosk tokens are still revoked on re-login (see
    | KioskMachineLoginController::login) and on logout. Refresh issues a new
    | token and deletes the prior one (single active token per kiosk).
    */
    'kiosk_expiration' => env('SANCTUM_KIOSK_EXPIRATION', 60 * 24 * 30),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'verify_csrf_token' => App\Http\Middleware\VerifyCsrfToken::class,
        'encrypt_cookies' => App\Http\Middleware\EncryptCookies::class,
    ],

];
