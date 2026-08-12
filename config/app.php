<?php

use Illuminate\Support\Facades\Facade;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application. This value is used when the
    | framework needs to place the application's name in a notification or
    | any other location as required by the application or its packages.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | your application so that it is used when running Artisan tasks.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    'asset_url' => env('ASSET_URL'),

    // [SEC-FIX] API key for x-api-key header validation — use config() not env() in middleware
    // MIX_API_KEY is canonical (Mix + runtime Blade). API_KEY kept for backward compat with old .env copies.
    'api_key' => trim((string) (env('MIX_API_KEY') ?: env('API_KEY', ''))),

    // [ROTATION 2026-08-08] Clé PRÉCÉDENTE, acceptée le temps d'une rotation seulement.
    // La même clé vit dans le `.env`, dans les bundles compilés (`MIX_` → app.js/pos-app.js
    // pour la borne et la caisse) et dans le meta `api-key` du site : ces trois surfaces ne
    // peuvent pas basculer au même instant. On renseigne cette valeur avec l'ancienne clé
    // pendant la bascule, puis on la VIDE dès que les trois sont vérifiées.
    // Vide = une seule clé acceptée, ce qui est l'état normal.
    'api_key_previous' => trim((string) env('API_KEY_PREVIOUS', '')),

    // POST /api/auth/login — fenêtre 10 min (voir RouteServiceProvider::login-lockout). Défaut 10 prod.
    // Playwright enchaîne plusieurs specs avec le même email → surcharger en CI (LOGIN_LOCKOUT_MAX_ATTEMPTS).
    'login_lockout_max_attempts' => max(1, (int) env('LOGIN_LOCKOUT_MAX_ATTEMPTS', 10)),

    // throttle:api — global /api/* cap per user id or IP (RouteServiceProvider::api).
    // Playwright SPA peut dépasser 120 req/min sur une fenêtre glissante; CI augmente via API_THROTTLE_PER_MINUTE.
    'api_throttle_per_minute' => max(1, (int) env('API_THROTTLE_PER_MINUTE', 120)),

    // throttle:admin-mutation non-GET cap — env-configurable to absorb owner
    // manual-test bursts (rapid Livré clicks on online-order list, Cancel
    // chains, etc.). Default 60/min (doubled from prior hardcoded 30/min)
    // for production; local dev raises to 1000/min for parity with
    // POS_RATE_LIMIT_* knobs. NF525 fiscal chain UNAFFECTED — chain insert
    // happens inside controller transactions, not in the throttle bucket.
    // [Wave Y RATE-LIMIT 2026-05-21]
    'admin_mutation_rate_limit' => max(1, (int) env('ADMIN_MUTATION_RATE_LIMIT', 60)),

    // [GOAL Phase F.1 2026-05-23] throttle:menu-availability dedicated bucket
    // for /admin/menu/availability/{toggle,extra/toggle,variation/toggle}
    // (routes/api.php:256-268). Sibling-group structure preserved so this
    // bucket doesn't share with admin-mutation — keeps bulk-86 fan-out from
    // StockRuptureDashboard isolated from cashier admin-CRUD ops. Default
    // 60/min for backwards compatibility (prior hardcoded ceiling); local
    // dev raises via MENU_AVAILABILITY_RATE_LIMIT=1000 to absorb manager
    // bulk-toggle bursts. NF525 chain unaffected — toggle is not a fiscal
    // write.
    'menu_availability_rate_limit' => max(1, (int) env('MENU_AVAILABILITY_RATE_LIMIT', 60)),

    // Full /api/health IP gate — read via config() so tests can override and config:cache works in prod.
    'health_ips_allowed' => env('HEALTH_IPS_ALLOWED', ''),

    // Exposé au Blade pour le SPA (évite env() dans les vues + aligne clé API sans rebuild npm)
    'demo_mode' => filter_var(env('DEMO', false), FILTER_VALIDATE_BOOLEAN),

    // [GAP-32-6] Demo credentials via config() — env() in Blade fails after config:cache in production.
    // These are only injected into the page when demo_mode is true (see master.blade.php).
    // Defaults align with database/seeders/UserTableSeeder.php (non-production).
    // Branch manager + chef rows exist only when DEMO=true at seed time; use env overrides if needed.
    'demo_credentials' => [
        'admin_email' => env('DEMO_ADMIN_EMAIL', 'admin@lecayenne.fr'),
        'admin_password' => env('DEMO_ADMIN_PASSWORD', '123456'),
        'customer_email' => env('DEMO_CUSTOMER_EMAIL', 'walkingcustomer@example.com'),
        'customer_password' => env('DEMO_CUSTOMER_PASSWORD', '123456'),
        'branch_manager_email' => env('DEMO_BRANCH_MANAGER_EMAIL', 'branchmanager@example.com'),
        'branch_manager_password' => env('DEMO_BRANCH_MANAGER_PASSWORD', '123456'),
        'pos_operator_email' => env('DEMO_POS_OPERATOR_EMAIL', 'pos@lecayenne.fr'),
        'pos_operator_password' => env('DEMO_POS_OPERATOR_PASSWORD', '123456'),
        'chef_email' => env('DEMO_CHEF_EMAIL', 'chef@example.com'),
        'chef_password' => env('DEMO_CHEF_PASSWORD', '123456'),
    ],

    'google_map_key' => env('MIX_GOOGLE_MAP_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. We have gone
    | ahead and set this to a sensible default for you out of the box.
    |
    */

    /*
     * [W9-AUDIT B3-OPS] NF525 fiscal pipeline (Z reports, fiscal:archive J-1
     * scheduled at 02:00, audit_logs canonization) MUST run in the merchant's
     * legal timezone. UTC default would shift the J-1 archive window by 1-2h
     * and produce evidence with timestamps off by the same amount, which is
     * acceptable cryptographically (signature canonizes to UTC) but confusing
     * legally for French operators.
     *
     * Default is now Europe/Paris (the merchant base of operations); override
     * via TIMEZONE env variable for non-FR deployments.
     */
    'timezone' => env('TIMEZONE') ?: 'Europe/Paris',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by the translation service provider. You are free to set this value
    | to any of the locales which will be supported by the application.
    |
    | ⚠️  WARNING: DO NOT CHANGE - French locale required for FoodKing project
    | This application is specifically designed for the French market.
    | Changing this will break menu seeding and French localization.
    |
    */

    'locale' => 'fr', // DO NOT CHANGE - French locale required

    /*
    |--------------------------------------------------------------------------
    | Application Fallback Locale
    |--------------------------------------------------------------------------
    |
    | The fallback locale determines the locale to use when the current one
    | is not available. You may change the value to correspond to any of
    | the language folders that are provided through your application.
    |
    */

    'fallback_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Faker Locale
    |--------------------------------------------------------------------------
    |
    | This locale will be used by the Faker PHP library when generating fake
    | data for your database seeds. For example, this will be used to get
    | localized telephone numbers, street address information and more.
    |
    */

    'faker_locale' => 'en_US',

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is used by the Illuminate encrypter service and should be set
    | to a random, 32 character string, otherwise these encrypted strings
    | will not be safe. Please do this before deploying an application!
    |
    */

    'key' => env('APP_KEY'),

    'cipher' => 'AES-256-CBC',

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => 'file',
        // 'store'  => 'redis',
    ],

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically loaded on the
    | request to your application. Feel free to add your own services to
    | this array to grant expanded functionality to your applications.
    |
    */

    'providers' => [

        /*
         * Laravel Framework Service Providers...
         */
        Illuminate\Auth\AuthServiceProvider::class,
        Illuminate\Broadcasting\BroadcastServiceProvider::class,
        Illuminate\Bus\BusServiceProvider::class,
        Illuminate\Cache\CacheServiceProvider::class,
        Illuminate\Foundation\Providers\ConsoleSupportServiceProvider::class,
        Illuminate\Cookie\CookieServiceProvider::class,
        Illuminate\Database\DatabaseServiceProvider::class,
        Illuminate\Encryption\EncryptionServiceProvider::class,
        Illuminate\Filesystem\FilesystemServiceProvider::class,
        Illuminate\Foundation\Providers\FoundationServiceProvider::class,
        Illuminate\Hashing\HashServiceProvider::class,
        Illuminate\Mail\MailServiceProvider::class,
        Illuminate\Notifications\NotificationServiceProvider::class,
        Illuminate\Pagination\PaginationServiceProvider::class,
        Illuminate\Pipeline\PipelineServiceProvider::class,
        Illuminate\Queue\QueueServiceProvider::class,
        Illuminate\Redis\RedisServiceProvider::class,
        Illuminate\Auth\Passwords\PasswordResetServiceProvider::class,
        Illuminate\Session\SessionServiceProvider::class,
        Illuminate\Translation\TranslationServiceProvider::class,
        Illuminate\Validation\ValidationServiceProvider::class,
        Illuminate\View\ViewServiceProvider::class,

        /*
         * Package Service Providers...
         */
        Spatie\Permission\PermissionServiceProvider::class,
        /*
         * Application Service Providers...
         */
        App\Providers\AppServiceProvider::class,
        App\Providers\AuthServiceProvider::class,
        App\Providers\BroadcastServiceProvider::class,
        App\Providers\EventServiceProvider::class,
        App\Providers\RouteServiceProvider::class,
        // [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3b] Binding lecteur factures IA (mock↔OpenAI).
        App\Providers\PurchasingServiceProvider::class,
        // [UBER-PHOTO 2026-08-10] Lecteur de ticket Uber photographié (doublure locale par défaut).
        App\Providers\UberVisionServiceProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Class Aliases
    |--------------------------------------------------------------------------
    |
    | This array of class aliases will be registered when this application
    | is started. However, feel free to register as many as you wish as
    | the aliases are "lazy" loaded so they don't hinder performance.
    |
    */

    'aliases' => Facade::defaultAliases()->merge([
        // 'ExampleClass' => App\Example\ExampleClass::class,
    ])->toArray(),

];

// smoke-test: run-cycle validated 2026-04-14
