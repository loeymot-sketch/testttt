<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option controls the default authentication "guard" and password
    | reset options for your application. You may change these defaults
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => 'sanctum',
        'passwords' => 'users',
    ],

    /*
    |--------------------------------------------------------------------------
    | Appareils simultanés par compte
    |--------------------------------------------------------------------------
    |
    | [MULTI-DEVICE 2026-08-07] Nombre de terminaux pouvant rester connectés
    | EN MÊME TEMPS sur un même compte (caisse, tablettes de salle, poste
    | bureau, téléphone). La reconnexion d'un appareil ne révoque que son
    | propre jeton précédent ; ce plafond n'intervient qu'au-delà, et évince
    | alors le terminal le MOINS RÉCEMMENT actif — jamais un poste en service.
    |
    | Mettre 0 désactive le plafond (déconseillé : plus aucun garde-fou contre
    | la prolifération de jetons valides 8h).
    |
    | ⚠️ Une valeur vide ou non numérique retombe sur 10, PAS sur 0 : `(int) ''`
    | vaut 0, et 0 désactive le plafond. Un réglage mal saisi ne doit jamais
    | ouvrir un garde-fou en grand (constaté en audit adversarial 2026-08-07).
    |
    */

    'max_devices_per_user' => (static function () {
        $raw = env('AUTH_MAX_DEVICES_PER_USER', 10);

        // Seule une valeur explicitement numérique est prise en compte ; « 0 »
        // reste un choix valide et volontaire (plafond désactivé).
        return is_numeric($raw) ? (int) $raw : 10;
    })(),

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | here which uses session storage and the Eloquent user provider.
    |
    | All authentication drivers have a user provider. This defines how the
    | users are actually retrieved out of your database or other storage
    | mechanisms used by this application to persist your user's data.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users'
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication drivers have a user provider. This defines how the
    | users are actually retrieved out of your database or other storage
    | mechanisms used by this application to persist your user's data.
    |
    | If you have multiple user tables or models you may configure multiple
    | sources which represent each model / table. These sources may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | You may specify multiple password reset configurations if you have more
    | than one user table or model in the application and you want to have
    | separate password reset settings based on the specific user types.
    |
    | The expire time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_resets',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the amount of seconds before a password confirmation
    | times out and the user is prompted to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => 10800,

    /*
    |--------------------------------------------------------------------------
    | Login brute-force lockout (RouteServiceProvider login-lockout limiter)
    |--------------------------------------------------------------------------
    |
    | Window = decay_minutes; max_attempts allowed per email|ip in that window.
    | Defaults match historical prod behavior (10 / 10 minutes).
    |
    */

    'login_lockout' => [
        'max_attempts' => max(1, (int) env('LOGIN_LOCKOUT_MAX_ATTEMPTS', 10)),
        'decay_minutes' => max(1, (int) env('LOGIN_LOCKOUT_DECAY_MINUTES', 10)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compte de revue App Store / Google Play
    |--------------------------------------------------------------------------
    | [APP MOBILE 2026-09-02 — GOAL_APP_MOBILE_APPSTORE §A3] Le réviseur Apple doit
    | pouvoir se connecter, et il ne peut pas lire nos e-mails. Pour CE SEUL e-mail
    | (un compte invité sans valeur, créé pour la revue), le code de connexion est
    | FIXE et n'est pas envoyé. Vide par défaut = fonctionnalité éteinte. Ne
    | s'applique qu'aux comptes invités (jamais staff) — GuestSignupController::emailLogin.
    */
    'app_review' => [
        'email' => env('APP_REVIEW_EMAIL', ''),
        'otp'   => env('APP_REVIEW_OTP', ''),
    ],

];
