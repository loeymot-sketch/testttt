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
 * Per-branch configuration for a single external delivery
 * aggregator (Uber Eats, Deliveroo, Delicity).
 *
 * Sensitive fields:
 *   - credentials : encrypted JSON at rest. Direct DB access
 *                   sees ciphertext only; the model cast is
 *                   the only authorised reader/writer.
 *
 * Multi-tenant scope: BranchScope is applied like every
 * branch-scoped model so that a POS / staff session never
 * sees another branch's adapter configuration.
 */
class DeliveryPlatform extends Model
{
    use HasFactory;

    protected $table = 'delivery_platforms';

    protected $fillable = [
        'branch_id',
        'platform',
        'enabled',
        'external_store_id',
        'credentials',
        'webhook_secret_rotated_at',
        'last_webhook_received_at',
        'last_status_pushed_at',
    ];

    protected $casts = [
        'id'                         => 'integer',
        'branch_id'                  => 'integer',
        'platform'                   => 'string',
        'enabled'                    => 'boolean',
        'external_store_id'          => 'string',
        // Credentials are encrypted at rest; the model cast is
        // the only path that returns plaintext.
        'credentials'                => 'encrypted:json',
        'webhook_secret_rotated_at'  => 'datetime',
        'last_webhook_received_at'   => 'datetime',
        'last_status_pushed_at'      => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope());
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function externalOrders(): HasMany
    {
        return $this->hasMany(DeliveryPlatformExternalOrder::class, 'delivery_platform_id');
    }

    public function webhookEvents(): HasMany
    {
        // Webhook events are linked to platforms only via the
        // external order pivot; this convenience relation walks
        // through the same `platform` enum + branch when needed.
        return $this->hasMany(DeliveryWebhookEvent::class, 'branch_id', 'branch_id')
            ->where('platform', $this->platform);
    }
}
