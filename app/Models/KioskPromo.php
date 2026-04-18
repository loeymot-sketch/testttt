<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kiosk Design V1 — Phase 1.2
 *
 * Code promo kiosk scoped par branche (distinct des `coupons` globaux
 * existants). Le discount final est toujours recalculé serveur (SSOT).
 *
 * @property int    $id
 * @property int    $branch_id
 * @property string $code
 * @property string $type          'percent'|'amount'
 * @property float  $value
 * @property float  $min_cart
 * @property ?Carbon $valid_from
 * @property ?Carbon $valid_to
 * @property ?int   $max_uses
 * @property int    $uses_count
 * @property bool   $active
 */
class KioskPromo extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const TYPE_PERCENT = 'percent';
    public const TYPE_AMOUNT  = 'amount';

    public const TYPES = [self::TYPE_PERCENT, self::TYPE_AMOUNT];

    protected $table = 'kiosk_promos';

    protected $fillable = [
        'branch_id', 'code', 'type', 'value',
        'min_cart', 'valid_from', 'valid_to',
        'max_uses', 'uses_count', 'active',
    ];

    protected $casts = [
        'id'         => 'integer',
        'branch_id'  => 'integer',
        'code'       => 'string',
        'type'       => 'string',
        'value'      => 'decimal:2',
        'min_cart'   => 'decimal:2',
        'valid_from' => 'datetime',
        'valid_to'   => 'datetime',
        'max_uses'   => 'integer',
        'uses_count' => 'integer',
        'active'     => 'boolean',
    ];

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Matche un code dans la branche courante à l'instant donné, pour un
     * total de panier donné. Retourne null si invalide / expiré / épuisé.
     */
    public static function findValid(
        int $branchId,
        string $code,
        float $cartTotal,
        ?Carbon $at = null
    ): ?self {
        $at ??= now();

        $promo = static::query()
            ->where('branch_id', $branchId)
            ->where('code', $code)
            ->where('active', true)
            ->first();

        if (!$promo) return null;
        if ($promo->valid_from && $promo->valid_from->gt($at)) return null;
        if ($promo->valid_to && $promo->valid_to->lt($at)) return null;
        if ($promo->max_uses !== null && $promo->uses_count >= $promo->max_uses) return null;
        if ($cartTotal < (float) $promo->min_cart) return null;

        return $promo;
    }

    /**
     * Calcule le montant de discount pour un total donné.
     * Retourne toujours un montant ≥ 0 et plafonné au total du panier.
     */
    public function computeDiscount(float $cartTotal): float
    {
        if ($cartTotal <= 0) return 0.0;

        $discount = match ($this->type) {
            self::TYPE_PERCENT => $cartTotal * ((float) $this->value / 100.0),
            self::TYPE_AMOUNT  => (float) $this->value,
            default            => 0.0,
        };

        $discount = round(max(0.0, $discount), 2);
        return min($discount, round($cartTotal, 2));
    }

    public function scopeValidFor(Builder $q, int $branchId, ?Carbon $at = null): Builder
    {
        $at ??= now();
        return $q->where('branch_id', $branchId)
            ->where('active', true)
            ->where(function (Builder $w) use ($at) {
                $w->whereNull('valid_from')->orWhere('valid_from', '<=', $at);
            })
            ->where(function (Builder $w) use ($at) {
                $w->whereNull('valid_to')->orWhere('valid_to', '>=', $at);
            });
    }
}
