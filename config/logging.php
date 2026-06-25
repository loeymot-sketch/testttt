<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified in this option should match
    | one of the channels defined in the "channels" configuration array.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Out of
    | the box, Laravel uses the Monolog PHP logging library. This gives
    | you a variety of powerful log handlers / formatters to utilize.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog",
    |                    "custom", "stack"
    |
    */

    'channels' => [
        // [OPS-2 2026-06-04] Default stack now writes to the DATE-ROTATED
        // `daily` channel (14-day retention) instead of the unbounded
        // `single` laravel.log. The box hit 100% disk twice because the
        // single-file channel grew without a rotation ceiling. `daily`
        // is self-pruning (Monolog deletes files older than `days`), so
        // even a server pinned to `LOG_CHANNEL=stack` is now bounded.
        'stack' => [
            'driver' => 'stack',
            'channels' => ['daily'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'permission' => 0664, // [PERMS-FIX 2026-06-25] cf. canal fiscal
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
            'permission' => 0664, // [PERMS-FIX 2026-06-25] cf. canal fiscal
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'Laravel Log',
            'emoji' => ':boom:',
            'level' => env('LOG_LEVEL', 'critical'),
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        'hardware' => [
            'driver' => 'daily',
            'path' => storage_path('logs/hardware.log'),
            'level' => 'info',
            'days' => 30,
            'permission' => 0664, // [PERMS-FIX 2026-06-25] cf. canal fiscal
        ],

        // [C6 / K-6] Dedicated security channel — rotated daily, retained
        // 90 days for forensic analysis of `branch_mismatch_claimed`,
        // `forbidden_ability`, `lockdown_violation`. Separate from
        // `hardware` so ops can wire alerts (Sentry/Slack) without
        // hardware noise, and separate from `observability` so SLO
        // evaluators do not drown out security signal. Mirrors the
        // testttt-kiosk-p93 reference worktree.
        //
        // INFRASTRUCTURE PORT ONLY — the actual K-6 enforcement
        // (branch_id mismatch detection in KioskEventController) is a
        // critical-zone change that requires its own audited cycle.
        // Channel is shipped now so any future enforcement / ad-hoc
        // diagnostic can call `Log::channel('security')` immediately.
        'security' => [
            'driver' => 'daily',
            'path' => storage_path('logs/security.log'),
            'level' => 'info',
            'days' => 90,
            'permission' => 0664, // [PERMS-FIX 2026-06-25] cf. canal fiscal
        ],

        'production_json' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => [
                'stream' => storage_path('logs/laravel.json.log'),
            ],
            'formatter' => \App\Logging\JsonFormatter::class,
            'level' => 'info',
        ],

        // [K-9 ADR-4] Dedicated observability channel — rotated daily, retained
        // 90 days. Used by `SloMetricCollector`, `SloEvaluatorJob`, CSP report
        // endpoint, correlation trace debug, heatmap spill-over. JSON formatter
        // for downstream Loki/Logtail ingestion. Separate from `security` to
        // avoid alert fatigue on ops channel routing.
        'observability' => [
            'driver' => 'daily',
            'path' => storage_path('logs/observability.log'),
            'level' => 'info',
            'days' => 90,
            'formatter' => \App\Logging\JsonFormatter::class,
            'permission' => 0664, // [PERMS-FIX 2026-06-25] cf. canal fiscal
        ],

        // [POS-9-H.3.2 / F-C7]
        // Dedicated fiscal channel. Two reasons to isolate it:
        //   1. Retention: NF525 requires 6 years of audit evidence. A
        //      generic `laravel.log` is typically rotated at 14 days,
        //      which would silently throw away the breadcrumbs of a
        //      disputed Z close. 400 days here is an operational floor
        //      that still fits inside the offline archive pipeline
        //      (FiscalArchiveCommand handles the long-tail 6-year tail).
        //   2. Signal-to-noise: fiscal events (open / close / write)
        //      are rare but high-value. Keeping them on their own
        //      stream makes SIEM search + alert-on-silence trivial.
        'fiscal' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/fiscal.log'),
            'level'  => 'info',
            'days'   => 400,
            // [PERMS-FIX 2026-06-25] 0664 = fichier inscriptible par le GROUPE.
            // Sans ça, le log fiscal du jour se crée en 0644 (groupe lecture seule) :
            // si php-fpm (www-data) le crée, le cron (ubuntu) ne peut plus l'écrire
            // (et inversement) → l'ouverture/clôture Z et l'allocation fiscale d'une
            // commande échouent (rollback) → 500 / Z manquant. 0664 + groupe partagé
            // www-data = les deux peuvent écrire.
            'permission' => 0664,
        ],
    ],

];
