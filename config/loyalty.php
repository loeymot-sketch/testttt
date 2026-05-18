<?php

/**
 * [LCS-S-001 / 2026-05-19] Loyalty configuration.
 *
 * QR signing secrets MUST be provided via environment in production.
 * Defaults below are ONLY safe for local development / automated tests.
 * In production (APP_ENV=production), AppServiceProvider::boot() refuses
 * to start when `qr.secret` is empty, matches a dev sentinel, or is
 * shorter than `min_secret_length`.
 *
 * Pattern mirrors config/fiscal.php (FISCAL_AUDIT_SECRET / FISCAL_Z_REPORT_SECRET).
 */
return [

    /*
    |----------------------------------------------------------------------
    | Loyalty QR HMAC secret
    |----------------------------------------------------------------------
    |
    | Used by `LoyaltyQrSigner` to sign and verify the loyalty QR token
    | issued by the mobile / kiosk authentication surfaces.
    |
    | Generation : `openssl rand -hex 32` (≥32 chars / 256 bits).
    | Rotation   : safe — old plaintext (legacy backward-compat) path is
    | accepted in parallel during the transition window. New signed tokens
    | minted post-rotation will simply fail verification under the old key.
    |
    | Env: LOYALTY_QR_SECRET (required in production)
    */
    'qr' => [
        'secret' => env('LOYALTY_QR_SECRET', ''),

        /*
        | Token time-to-live in seconds. 300s = 5 minutes — matches the
        | mobile-side rotation cosmetic UX in `mobile/components/LoyaltyQR.jsx`.
        | Servers issuing fresh tokens MUST use this TTL.
        */
        'ttl_seconds' => (int) env('LOYALTY_QR_TTL_SECONDS', 300),

        /*
        | Skew tolerance for clock drift when verifying `exp`. 30s is the
        | industry default (matches Sanctum / JWT libraries).
        */
        'leeway_seconds' => (int) env('LOYALTY_QR_LEEWAY_SECONDS', 30),

        /*
        |------------------------------------------------------------------
        | Legacy plaintext acceptance window
        |------------------------------------------------------------------
        |
        | Until the mobile app ships the signed-QR client (deferred to
        | mobile cycle V1.0.X), the server MUST still accept raw
        | `FK:<loyalty_code>` and bare `<loyalty_code>` strings to avoid
        | breaking already-deployed mobile clients.
        |
        | When the mobile cycle ships and field roll-out confirms zero
        | plaintext volume in `loyalty.qr.legacy_plaintext` logs, set
        | LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT=false in production to
        | disable the legacy path and refuse plaintext scans.
        */
        'accept_legacy_plaintext' => filter_var(
            env('LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT', true),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) ?? true,
    ],

    /*
    |----------------------------------------------------------------------
    | Dev sentinels (refused in production)
    |----------------------------------------------------------------------
    |
    | Any QR secret matching one of these strings is REJECTED in production
    | even if env() explicitly set it. Prevents the shipped-default leaking
    | into a live loyalty trail.
    */
    'dev_sentinels' => [
        'dev-loyalty-qr-secret-do-not-use-in-prod',
        'changeme',
        'change-me',
        'test',
        'dev',
        'secret',
    ],

    /*
    |----------------------------------------------------------------------
    | Minimum secret length (bytes/characters)
    |----------------------------------------------------------------------
    */
    'min_secret_length' => 32,
];
