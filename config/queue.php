<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue API supports an assortment of back-ends via a single
    | API, giving you convenient access to each back-end using the same
    | syntax for every one. Here you may define a default connection.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'sync'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection information for each server that
    | is used by your application. A default configuration has been added
    | for each back-end shipped with Laravel. You are free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => 'localhost',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => 90,
            // [F-LAT-01 sync heal 2026-06-03] block_for=null made the worker poll
            // with --sleep (default 3s) when the queue was idle, so the FIRST
            // broadcast after a quiet gap waited ~1-3s (measured 2.29s cold,
            // exceeding the <2s SLO). A positive block_for switches to a blocking
            // pop (BRPOP) → sub-second pickup even from idle. retry_after(90) ≫ 5.
            'block_for' => env('REDIS_BLOCK_FOR', 5),
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control which database and table are used to store the jobs that
    | have failed. You may change them to any database / table you wish.
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],


    /*
    |--------------------------------------------------------------------------
    | Files surveillées par les sondes de santé
    |--------------------------------------------------------------------------
    |
    | [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — P0 du 2026-08-25]
    |
    | Les trois sondes de santé du projet comptaient `default` + `high`, écrits en dur. Or
    | `App\Jobs\SendFcmNotificationJob` publie sur `notifications`. Résultat mesuré ce jour-là :
    | 1 490 travaux empilés, `attempts=0` (jamais tentés), pendant que les trois surfaces
    | affichaient « file OK ». Un faux vert.
    |
    | Cette liste est désormais la source unique. `tests/Feature/Health/FilesSurveilleesTest.php`
    | DÉCOUVRE les `onQueue('…')` du code et échoue si l'un d'eux manque ici.
    |
    | ⚠️ Surveiller n'est pas traiter : le worker (`scripts/deploy/supervisor.conf.template`)
    | n'écoute toujours QUE `high,default`. Le débloquer enverrait 1 490 notifications d'un coup
    | sur des commandes vieilles de plusieurs semaines — décision propriétaire, voir
    | `reports/audit/P0_FILE_NOTIFICATIONS_ORPHELINE_2026-08-25.md`.
    |
    */

    'monitored_queues' => ['default', 'high', 'notifications'],

];
