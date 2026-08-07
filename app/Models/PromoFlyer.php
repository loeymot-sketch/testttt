<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * [FLYER PROMO UBER 2026-08-07] Un ticket promotionnel demandé depuis l'admin
 * et à imprimer sur l'imprimante de la caisse.
 *
 * C'est à la fois la TRACE de ce qui a été offert à qui, et l'ORDRE
 * D'IMPRESSION que la caisse vient réclamer — le serveur ne pouvant pas
 * joindre l'imprimante du restaurant (voir la migration pour la mesure).
 */
class PromoFlyer extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PRINTED = 'printed';
    public const STATUS_FAILED  = 'failed';

    /**
     * Au-delà de ce nombre de tentatives, la caisse cesse de réclamer le
     * ticket. Sans ce plafond, une imprimante en panne ferait boucler la
     * caisse indéfiniment sur le même ordre — le défaut classique des files
     * sans compteur d'échecs.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Une réclamation non confirmée est rendue à la file après ce délai : si
     * l'onglet qui l'a prise est fermé au mauvais moment, le ticket ne doit
     * pas rester bloqué « en cours » pour toujours.
     */
    public const CLAIM_TTL_SECONDS = 90;

    protected $table = 'promo_flyers';

    protected $fillable = [
        'branch_id',
        'customer_name',
        'code',
        'coupon_id',
        'status',
        'claimed_at',
        'claimed_by_device',
        'printed_at',
        'attempts',
        'last_error',
        'created_by_user_id',
        'created_by_device',
        'rendered_payload',
    ];

    protected $casts = [
        'id'                 => 'integer',
        'branch_id'          => 'integer',
        'coupon_id'          => 'integer',
        'attempts'           => 'integer',
        'created_by_user_id' => 'integer',
        'claimed_at'         => 'datetime',
        'printed_at'         => 'datetime',
        'rendered_payload'   => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope(new BranchScope());
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
