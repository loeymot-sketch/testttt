<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * [LIVRAISON QR 2026-11-01] Jeton de remise à usage unique d'un arrêt de livraison.
 *
 * Seul le HACHAGE du jeton est stocké (`token_hash`). La recherche avant connexion (le livreur n'est pas encore
 * authentifié quand il scanne) passe par `withoutGlobalScope(BranchScope::class)` PUIS vérifie
 * `user.branch_id == token.branch_id` : voir {@see \App\Services\Delivery\DeliveryHandoverService}.
 */
class DeliveryHandoverToken extends Model
{
    protected $table = 'delivery_handover_tokens';

    protected $fillable = [
        'branch_id', 'order_id', 'delivery_stop_id', 'token_hash', 'generation', 'expires_at', 'wave_at', 'revoked_at',
        'used_at', 'used_by_user_id', 'chosen_mode', 'declared_mode', 'mode_mismatch', 'amount_due_cents',
        'amount_collected_cents', 'device_id', 'ip', 'user_agent', 'lat', 'lng', 'accuracy_m',
    ];

    protected $casts = [
        'branch_id'              => 'integer',
        'order_id'               => 'integer',
        'delivery_stop_id'       => 'integer',
        'generation'             => 'integer',
        'expires_at'             => 'datetime',
        'wave_at'                => 'datetime',
        'revoked_at'             => 'datetime',
        'used_at'                => 'datetime',
        'used_by_user_id'        => 'integer',
        'mode_mismatch'          => 'boolean',
        'amount_due_cents'       => 'integer',
        'amount_collected_cents' => 'integer',
        'lat'                    => 'float',
        'lng'                    => 'float',
        'accuracy_m'             => 'integer',
    ];

    protected $hidden = ['token_hash'];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(DeliveryStop::class, 'delivery_stop_id');
    }
}
