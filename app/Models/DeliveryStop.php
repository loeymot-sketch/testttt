<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * [LIVRAISON 2026-10-20] Un arrêt de la tournée du livreur (une commande à livrer).
 * Le cycle de LIVRAISON (parti → arrivé → remis) vit ici, JAMAIS dans la machine à états de la
 * commande (gelée). Voir la migration `create_delivery_stops_table` pour le raisonnement.
 */
class DeliveryStop extends Model
{
    public const COLLECT_CASH = 'cash';
    public const COLLECT_CARD = 'card';
    public const COLLECT_PAID_ONLINE = 'paid_online';

    protected $table = 'delivery_stops';

    protected $fillable = [
        'branch_id', 'order_id', 'delivery_boy_id', 'wave_at', 'sequence', 'slot_fixed', 'distance_km', 'eta_minutes',
        'collect_method', 'amount_due_cents', 'amount_collected_cents',
        'left_at', 'arrived_at', 'delivered_at', 'arrival_lat', 'arrival_lng', 'proof_photo_path',
    ];

    protected $casts = [
        'branch_id'              => 'integer',
        'order_id'               => 'integer',
        'delivery_boy_id'        => 'integer',
        'wave_at'                => 'datetime',
        'sequence'               => 'integer',
        'slot_fixed'             => 'boolean',
        'distance_km'            => 'float',
        'eta_minutes'            => 'integer',
        'amount_due_cents'       => 'integer',
        'amount_collected_cents' => 'integer',
        'left_at'                => 'datetime',
        'arrived_at'             => 'datetime',
        'delivered_at'           => 'datetime',
        'arrival_lat'            => 'float',
        'arrival_lng'            => 'float',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }
}
