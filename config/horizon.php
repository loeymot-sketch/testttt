<?php

return [
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', env('APP_NAME', 'foodking') . '_horizon:'),
    'middleware' => ['web'],
    'waits' => [
        'redis:high' => 2,
        'redis:default' => 10,
        'redis:notifications' => 30,
    ],
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],
    'silenced' => [],
    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],
    'fast_termination' => false,
    'memory_limit' => 128,
    'environments' => [
        'production' => [
            'supervisor-high' => [
                'connection' => 'redis',
                'queue' => ['high'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 1,
                'maxProcesses' => 8,
                'tries' => 6,
                'timeout' => 90,
                'nice' => 0,
            ],
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['default', 'notifications'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 1,
                'maxProcesses' => 4,
                'tries' => 3,
                'timeout' => 90,
                'nice' => 0,
            ],
        ],
        'local' => [
            'supervisor-local' => [
                'connection' => 'redis',
                'queue' => ['high', 'default', 'notifications'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'tries' => 3,
                'timeout' => 90,
            ],
        ],
    ],
];
