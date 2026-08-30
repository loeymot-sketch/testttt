<?php

$gatewayId = (string) env('VOICE_ORDER_GATEWAY_ID', 'restaurant-main');

return [
    'enabled' => (bool) env('VOICE_ORDER_ENABLED', false),
    'live_ttl_seconds' => max(300, (int) env('VOICE_ORDER_LIVE_TTL_SECONDS', 7200)),
    'recent_limit' => max(5, min(100, (int) env('VOICE_ORDER_RECENT_LIMIT', 30))),
    'retention_days' => max(1, (int) env('VOICE_ORDER_RETENTION_DAYS', 30)),
    'transcript_chunk_bytes' => max(10000, min(50000, (int) env('VOICE_ORDER_TRANSCRIPT_CHUNK_BYTES', 40000))),
    'gateway' => [
        'max_payload_bytes' => max(4096, min(262144, (int) env('VOICE_ORDER_GATEWAY_MAX_PAYLOAD_BYTES', 65536))),
        'timestamp_tolerance_seconds' => max(30, (int) env('VOICE_ORDER_GATEWAY_TIMESTAMP_TOLERANCE_SECONDS', 300)),
        'replay_ttl_seconds' => max(60, (int) env('VOICE_ORDER_GATEWAY_REPLAY_TTL_SECONDS', 600)),
        'id_header' => 'X-Voice-Gateway-Id',
        'timestamp_header' => 'X-Voice-Timestamp',
        'event_header' => 'X-Voice-Event-Id',
        'signature_header' => 'X-Voice-Signature',
        'gateways' => [
            $gatewayId => [
                'branch_id' => (int) env('VOICE_ORDER_BRANCH_ID', 0),
                'secret' => (string) env('VOICE_ORDER_GATEWAY_SECRET', ''),
            ],
        ],
    ],
    'openai' => [
        'enabled' => (bool) env('VOICE_ORDER_OPENAI_ENABLED', false),
        'model' => (string) env('VOICE_ORDER_OPENAI_MODEL', 'gpt-5.6-luna'),
        'timeout_seconds' => max(2, min(30, (int) env('VOICE_ORDER_OPENAI_TIMEOUT_SECONDS', 8))),
        'minimum_interval_seconds' => max(1, (int) env('VOICE_ORDER_OPENAI_MIN_INTERVAL_SECONDS', 3)),
    ],
];
