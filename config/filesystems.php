<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been set up for each driver as an example of the required values.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            // [iter15-mega-fix C-004 2026-05-10] Storage URLs were absolute via APP_URL
            // (e.g. http://localhost:8000/storage/...). When the kiosk surface is served
            // from http://127.0.0.1:8000 the resulting cross-origin <img src> triggered
            // CSP `img-src` violations (report-only today, but blocking once enforce
            // mode flips on) and a host mismatch that left product tiles visually blank.
            // Default is now relative (/storage) so images inherit the page origin
            // unconditionally. STORAGE_URL env override is preserved for setups that
            // serve assets from a CDN / dedicated absolute host.
            'url' => env('STORAGE_URL', '/storage'),
            'visibility' => 'public',
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'root'  => env('AWS_ROOT'),
        ],

        // [GOAL-HEAL-SEC-001 2026-05-23] Firebase Admin SDK service-account JSON
        // disk. Stores under storage/app/firebase (NOT symlinked into public/storage,
        // unlike the 'public' disk). Used by NotificationSetting media collection
        // 'notification-file' so that admin-uploaded Firebase Admin private keys
        // never become web-fetchable. Phase B.3 Security RED-team finding B3.2-001.
        'firebase_private' => [
            'driver'     => 'local',
            'root'       => storage_path('app/firebase'),
            'visibility' => 'private',
            'throw'      => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];