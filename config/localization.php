<?php

/*
|--------------------------------------------------------------------------
| Localization — date/time display patterns (V1 LOCAL Le Cayenne — FR)
|--------------------------------------------------------------------------
|
| [UNI-03 / AUTHZ-CFG-01 heal 2026-06-14] AppLibrary read DATE_FORMAT /
| TIME_FORMAT via env() at request time. They carried FR-correct defaults
| ('d-m-Y' / 'H:i') so they degraded gracefully under config:cache, but a
| .env override (e.g. TIME_FORMAT='H:i:s') was silently IGNORED once config
| was cached. Reading from this config file is config:cache-safe AND honours
| the override. Mirrors features.php STAFF_ONLY_MODE.
|
| Sentinel: tests/Feature/Sentinels/NoRequestTimeEnvSentinelTest.php
|
*/

return [
    'date_format' => env('DATE_FORMAT', 'd-m-Y'),
    'time_format' => env('TIME_FORMAT', 'H:i'),
];
