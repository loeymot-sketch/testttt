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
 * Règle d'upsell administrable par branche. 100 % statique (config admin),
 * invariant §1.5 : jamais pilotée par des stats / ML / historique de vente.
 *
 * @property int    $id
 * @property int    $branch_id
 * @property string $trigger_type        'category_in_cart'|'item_in_cart'|'cart_total_gte'
 * @property array  $trigger_value
 * @property int    $suggested_item_id
 * @property bool   $active
 * @property int    $priority
 * @property ?Carbon $starts_at
 * @property ?Carbon $ends_at
 */
class UpsellRule extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const TRIGGER_CATEGORY_IN_CART = 'category_in_cart';
    public const TRIGGER_ITEM_IN_CART     = 'item_in_cart';
    public const TRIGGER_CART_TOTAL_GTE   = 'cart_total_gte';

    public const TRIGGER_TYPES = [
        self::TRIGGER_CATEGORY_IN_CART,
        self::TRIGGER_ITEM_IN_CART,
        self::TRIGGER_CART_TOTAL_GTE,
    ];

    protected $table = 'upsell_rules';

    protected $fillable = [
        'branch_id',
        'trigger_type',
        'trigger_value',
        'suggested_item_id',
        'active',
        'priority',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'id'                => 'integer',
        'branch_id'         => 'integer',
        'trigger_type'      => 'string',
        'trigger_value'     => 'array',
        'suggested_item_id' => 'integer',
        'active'            => 'boolean',
        'priority'          => 'integer',
        'starts_at'         => 'datetime',
        'ends_at'           => 'datetime',
    ];

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function suggestedItem(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Item::class, 'suggested_item_id');
    }

    /**
     * Sélectionne les règles actives pour une branche à un instant donné.
     * Utilisée par `UpsellRuleService`.
     */
    public function scopeActiveForBranch(Builder $q, int $branchId, ?Carbon $at = null): Builder
    {
        $at ??= now();
        return $q->where('branch_id', $branchId)
            ->where('active', true)
            ->where(function (Builder $w) use ($at) {
                $w->whereNull('starts_at')->orWhere('starts_at', '<=', $at);
            })
            ->where(function (Builder $w) use ($at) {
                $w->whereNull('ends_at')->orWhere('ends_at', '>=', $at);
            })
            ->orderByDesc('priority');
    }

    /**
     * Évalue si cette règle matche un panier courant.
     */
    public function matches(array $cartItems, float $cartTotal): bool
    {
        $value = is_array($this->trigger_value) ? $this->trigger_value : [];

        switch ($this->trigger_type) {
            case self::TRIGGER_CATEGORY_IN_CART:
                $categoryId = (int) ($value['category_id'] ?? 0);
                if ($categoryId <= 0) return false;
                foreach ($cartItems as $line) {
                    if ((int) ($line['category_id'] ?? 0) === $categoryId) return true;
                }
                return false;

            case self::TRIGGER_ITEM_IN_CART:
                $itemId = (int) ($value['item_id'] ?? 0);
                if ($itemId <= 0) return false;
                foreach ($cartItems as $line) {
                    if ((int) ($line['item_id'] ?? 0) === $itemId) return true;
                }
                return false;

            case self::TRIGGER_CART_TOTAL_GTE:
                $threshold = (float) ($value['amount'] ?? 0);
                if ($threshold <= 0) return false;
                return $cartTotal >= $threshold;
        }

        return false;
    }
}
