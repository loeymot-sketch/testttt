<?php

use App\Enums\CurrencyPosition;

/*
|--------------------------------------------------------------------------
| Currency display (V1 LOCAL Le Cayenne — EUR)
|--------------------------------------------------------------------------
|
| [UNI-03 / AUTHZ-CFG-01 heal 2026-06-14] These were read via env() at REQUEST
| time (AppLibrary money formatters + OrderItemResource tax_type). Under
| `php artisan config:cache` (run by the OVH deploy at deploy.sh [9/12]),
| env() returns NULL at request time — so env('CURRENCY') with no default
| rendered a NULL currency on FIXED-tax line items LIVE. Reading from a config
| file (env() resolved here at cache-build time, then config() at request time)
| is config:cache-safe AND still honours a .env override. Mirrors the
| features.php STAFF_ONLY_MODE migration (ST-W2-ENV-1-LEGACY).
|
| Sentinel: tests/Feature/Sentinels/NoRequestTimeEnvSentinelTest.php
|
*/

return [
    'code'          => env('CURRENCY', 'EUR'),
    'symbol'        => env('CURRENCY_SYMBOL', '€'),
    'position'      => (int) (env('CURRENCY_POSITION') ?? CurrencyPosition::RIGHT),
    'decimal_point' => (int) (env('CURRENCY_DECIMAL_POINT') ?? 2),
];
