<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * [NFC / NOTIFICATIONS 2026-11-20] Un appareil de CLIENT abonné aux notifications Web Push.
 *
 * Pas de BranchScope : un client n'appartient à aucune branche (même exemption que `Customer`, voir
 * BranchScopeCoverageSentinelTest::EXEMPTED_MODELS). L'accès est borné par `user_id` à chaque requête.
 * `endpoint`, `p256dh` et `auth` sont des secrets d'appareil : jamais sérialisés.
 */
class WebPushSubscription extends Model
{
    protected $table = 'web_push_subscriptions';

    protected $fillable = [
        'user_id', 'endpoint_hash', 'endpoint', 'p256dh', 'auth', 'content_encoding', 'device_label', 'origin',
        'last_success_at', 'last_failure_at', 'failure_count',
    ];

    protected $hidden = ['endpoint', 'p256dh', 'auth'];

    protected $casts = [
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'failure_count' => 'integer',
    ];

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
