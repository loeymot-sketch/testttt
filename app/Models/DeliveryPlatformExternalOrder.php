<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * [PARALLEL-TRACK-1.1 / Delivery Platform Integration — Phase 1]
 *
 * Mapping row between a platform-side external order id and our
 * internal frontend Order. Acts as the dedupe / idempotency key
 * for inbound webhooks (UNIQUE platform+external_id at the DB
 * level) and as the audit record for the original raw payload.
 *
 * `frontend_order_id` is nullable: the row exists from the moment
 * the webhook is accepted (received_at), but the linked Order is
 * only attached once parse + ingest succeed (ingested_at).
 */
class DeliveryPlatformExternalOrder extends Model
{
    use HasFactory;

    protected $table = 'delivery_platform_external_orders';

    protected $fillable = [
        'platform',
        'external_id',
        'frontend_order_id',
        'branch_id',
        'delivery_platform_id',
        'external_status',
        'internal_status',
        'raw_payload',
        'last_pushed_at',
        'last_push_error',
        'push_attempts',
        'received_at',
        'ingested_at',
    ];

    protected $casts = [
        'id'                    => 'integer',
        'platform'              => 'string',
        'external_id'           => 'string',
        'frontend_order_id'     => 'integer',
        'branch_id'             => 'integer',
        'delivery_platform_id'  => 'integer',
        'external_status'       => 'string',
        'internal_status'       => 'integer',
        'raw_payload'           => 'array',
        'last_pushed_at'        => 'datetime',
        'last_push_error'       => 'string',
        'push_attempts'         => 'integer',
        'received_at'           => 'datetime',
        'ingested_at'           => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope());
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(DeliveryPlatform::class, 'delivery_platform_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function frontendOrder(): BelongsTo
    {
        return $this->belongsTo(FrontendOrder::class, 'frontend_order_id')->withTrashed();
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(
            DeliveryWebhookEvent::class,
            'delivery_platform_external_order_id'
        );
    }
}
