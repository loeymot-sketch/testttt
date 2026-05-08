<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * [PARALLEL-TRACK-1.1 / Delivery Platform Integration — Phase 1]
 *
 * Raw audit log of every inbound delivery-platform webhook —
 * verified or not. Distinct from DeliveryPlatformExternalOrder
 * because:
 *   - one external order can produce N webhook events (created,
 *     accepted, ready, picked-up, delivered, refunded, ...);
 *   - signature failures must still be persisted for forensic
 *     analysis and rate-limit alerting, even when no order
 *     mapping exists yet.
 *
 * No BranchScope: branch_id is NULL until parse succeeds, which
 * means the BranchScope WHERE clause would silently hide pending
 * / failed events from staff sessions. Branch isolation for this
 * table happens at the application layer (controller filter +
 * AuditLogService write path).
 */
class DeliveryWebhookEvent extends Model
{
    use HasFactory;

    protected $table = 'delivery_webhook_events';

    protected $fillable = [
        'platform',
        'event_type',
        'signature_header',
        'signature_valid',
        'nonce',
        'headers',
        'body',
        'branch_id',
        'delivery_platform_external_order_id',
        'processed_at',
        'processing_error',
        'received_at',
    ];

    protected $casts = [
        'id'                                 => 'integer',
        'platform'                           => 'string',
        'event_type'                         => 'string',
        'signature_header'                   => 'string',
        'signature_valid'                    => 'boolean',
        'nonce'                              => 'string',
        'headers'                            => 'array',
        'body'                               => 'array',
        'branch_id'                          => 'integer',
        'delivery_platform_external_order_id'=> 'integer',
        'processed_at'                       => 'datetime',
        'processing_error'                   => 'string',
        'received_at'                        => 'datetime',
    ];

    public function externalOrder(): BelongsTo
    {
        return $this->belongsTo(
            DeliveryPlatformExternalOrder::class,
            'delivery_platform_external_order_id'
        );
    }
}
