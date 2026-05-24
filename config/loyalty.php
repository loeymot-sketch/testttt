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
        | [GOAL-J2-HEAL-03 2026-05-24] Phase J-ADV-1 ADV1-V01 P1:
        | Default was TRUE which allowed attacker with 8-char loyalty_code
        | to POST /api/frontend/loyalty/scan and harvest customer_token +
        | display_name + loyalty_balance_points (PII leak). Flipped to FALSE
        | for security-by-default. Legacy clients can explicitly opt back
        | in via LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT=true during transition,
        | or migrate to signed QR via account self-service.
        |
        | Historical note (pre-flip): the legacy raw `FK:<loyalty_code>`
        | and bare `<loyalty_code>` paths were kept enabled by default to
        | avoid breaking already-deployed mobile clients while the
        | signed-QR mobile cycle (V1.0.X) shipped. The mobile cycle has
        | since landed; defaulting to FALSE here closes the harvest
        | vector without preventing opt-in for environments still
        | running pre-signed-QR clients.
        */
        'accept_legacy_plaintext' => filter_var(
            env('LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT', false),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) ?? false,
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
