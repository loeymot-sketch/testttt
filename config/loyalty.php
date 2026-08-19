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

    /*
    |----------------------------------------------------------------------
    | Orphan pending-redeem reaper window (minutes)
    |----------------------------------------------------------------------
    |
    | The self-service pre-redeem endpoint (POST /api/frontend/loyalty/redeem,
    | LoyaltyController::redeem) debits points immediately and writes a PENDING
    | ledger row with order_id = NULL. When the customer then places an order,
    | FrontendOrderService backfills that row's order_id (attach window: 10 min,
    | FrontendOrderService.php:918). If the order is NEVER placed / is abandoned,
    | the row stays order_id = NULL forever and LoyaltyService::refundPoints
    | (keyed on order_id) can never re-credit it → points stranded.
    |
    | LoyaltyService::reapOrphanRedemptions() re-credits any such unconsumed
    | pending redeem older than this window. It MUST be strictly greater than the
    | 10-minute FrontendOrderService attach window so a legitimate late order can
    | never race the reaper (an order created after 10 min can no longer attach
    | the pending row anyway). Default 30 min. Override: LOYALTY_ORPHAN_REDEEM_REAP_MINUTES.
    */
    'orphan_redeem_reap_minutes' => (int) env('LOYALTY_ORPHAN_REDEEM_REAP_MINUTES', 30),

    /*
    |----------------------------------------------------------------------
    | Conserver l'email saisi à la borne (2026-08-19 — arbitrage propriétaire)
    |----------------------------------------------------------------------
    |
    | CE QUE CE DRAPEAU ARBITRE. Un client s'inscrit à la borne avec son nom, son
    | téléphone et son email. Faut-il ÉCRIRE cet email sur le compte créé ?
    |
    | NON (false) — la position [P1-1 SÉCU 2026-08-04]. Sur cet endpoint public et
    | non authentifié, rien ne prouve que la personne devant la borne possède le
    | numéro qu'elle tape. Un attaquant peut donc enrôler le numéro d'une victime
    | avec SON email à lui ; plus tard, la garde anti-« channel-confusion » de
    | `GuestSignupController` livre le code de connexion à l'email LIÉ AU COMPTE,
    | c'est-à-dire au squatteur. Le prix de cette prudence est lourd et mesuré : la
    | borne annonçait « inscrit » et jetait l'email, si bien qu'un client fidèle
    | n'avait ENSUITE aucun canal de connexion — ni son email (jamais stocké), ni
    | celui qu'il retapait (la même garde, branche 2, refuse de livrer à l'email de
    | l'appelant dès que le compte a de la valeur). Le programme était une impasse.
    |
    | OUI (true, défaut) — l'arbitrage du propriétaire du 2026-08-19, formulé
    | explicitement : « il crée un compte avec son numéro, son e-mail et son prénom
    | […] ensuite il se connecte avec son e-mail et il peut utiliser ses points ».
    | L'email stocké redonne au mécanisme EXISTANT de quoi fonctionner — aucune
    | garde n'est réécrite, on lui fournit la donnée qui lui manquait.
    |
    | CE QUI RESTE VRAI DANS LES DEUX POSITIONS :
    |   - l'email n'est JAMAIS posé sur un compte préexistant (fix hijack 2026-07-02) ;
    |   - un email déjà porté ailleurs → 409, aucune création ;
    |   - `email_verified_at` reste NULL : une adresse déclarée n'est pas une preuve ;
    |   - la réinitialisation de mot de passe refuse les comptes invités
    |     (`ForgotPasswordController`) — on ne pose pas un PREMIER mot de passe sur
    |     le compte d'un autre en appelant ça « réinitialiser ».
    |
    | RISQUE RÉSIDUEL, ASSUMÉ ET CHIFFRÉ : il faut être physiquement devant la borne,
    | connaître le numéro d'une victime, l'enrôler AVANT elle, puis attendre qu'elle
    | cumule assez de points. Enveloppe V1 = un seul restaurant, une borne en salle,
    | gain maximal = les points d'un client (plancher 1000 pts = 10 €). Pour revenir
    | à la position prudente sans toucher au code : LOYALTY_KIOSK_EMAIL_CAPTURE=false.
    |
    | Sentinelle : tests/Feature/Loyalty/LoyaltyRegisterAllowsWebLoginTest.php
    |              (elle éprouve LES DEUX positions, pas seulement celle du défaut)
    |              tests/Feature/Loyalty/KioskRegisterKeepsEmailTest.php
    */
    'kiosk_email_capture' => filter_var(
        env('LOYALTY_KIOSK_EMAIL_CAPTURE', true),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    ) ?? true,
];
