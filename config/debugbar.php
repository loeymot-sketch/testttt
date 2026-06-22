<?php

/*
|--------------------------------------------------------------------------
| [OPS-2 2026-06-04] FoodKing debugbar override (minimal published file)
|--------------------------------------------------------------------------
|
| The full upstream config lives in
| `vendor/barryvdh/laravel-debugbar/config/debugbar.php` and is merged in
| via the package service provider's `mergeConfigFrom()`. Laravel merges
| config SHALLOW at the top level, so any key we want to override must be
| declared here in full (nested arrays are replaced wholesale, not deep-
| merged). We therefore copy the entire `storage` block, not just one key.
|
| Why this file exists: the box hit 100% disk twice. `storage/debugbar`
| grew to hundreds of MB because request-snapshot persistence ran in an
| environment that should never persist debug dumps. We:
|   1. Hard-gate `enabled` to NON-PRODUCTION (debugbar must never boot in
|      prod regardless of a stray DEBUGBAR_ENABLED / APP_DEBUG=true).
|   2. Hard-gate request-snapshot `storage.enabled` to NON-PRODUCTION so
|      `storage/debugbar` cannot accrue files on a production box.
|
| All other debugbar keys fall back to the vendor defaults via the merge.
|
| Companion cleanup: `php artisan storage:cleanup` purges any
| `storage/debugbar/*` that already accumulated (e.g. on a dev box, or a
| box that ran as `local` before this gate landed).
*/

$isProduction = env('APP_ENV', 'production') === 'production';

return [

    // Never let debugbar boot in production. In non-prod it still honours
    // the usual DEBUGBAR_ENABLED / APP_DEBUG resolution (null => follow
    // APP_DEBUG), so local/staging behaviour is unchanged.
    'enabled' => $isProduction ? false : env('DEBUGBAR_ENABLED', null),

    // Full `storage` block (copied verbatim from the vendor default) so the
    // shallow config merge keeps every key, with one production-hardening
    // change: request-snapshot persistence is forced OFF in production so
    // `storage/debugbar` can never fill the disk on a live box.
    'storage' => [
        'enabled'    => $isProduction ? false : env('DEBUGBAR_STORAGE_ENABLED', true),
        'open'       => env('DEBUGBAR_OPEN_STORAGE'), // bool/callback.
        'driver'     => env('DEBUGBAR_STORAGE_DRIVER', 'file'), // redis, file, pdo, socket, custom
        'path'       => env('DEBUGBAR_STORAGE_PATH', storage_path('debugbar')), // For file driver
        'connection' => env('DEBUGBAR_STORAGE_CONNECTION', null), // Leave null for default connection (Redis/PDO)
        'provider'   => env('DEBUGBAR_STORAGE_PROVIDER', ''), // Instance of StorageInterface for custom driver
        'hostname'   => env('DEBUGBAR_STORAGE_HOSTNAME', '127.0.0.1'), // Hostname to use with the "socket" driver
        'port'       => env('DEBUGBAR_STORAGE_PORT', 2304), // Port to use with the "socket" driver
    ],

];
