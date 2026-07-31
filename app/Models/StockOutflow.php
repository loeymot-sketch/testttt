<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;

/**
 * [OWNER REPAS-PERSONNEL/PERTES 2026-07-31] Sortie de stock hors-vente (repas personnel / perte),
 * append-only + branch-scopée (comme StockMovement). LA trace de tout ce qui part sans vente.
 */
class StockOutflow extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_STAFF_MEAL = 'staff_meal';

    public const TYPE_WASTE = 'waste';

    public const TYPES = [self::TYPE_STAFF_MEAL, self::TYPE_WASTE];

    protected $fillable = [
        'branch_id',
        'item_id',
        'item_name',
        'quantity',
        'type',
        'note',
        'user_id',
        'stock_decremented',
        'created_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'item_id' => 'integer',
        'quantity' => 'integer',
        'user_id' => 'integer',
        'stock_decremented' => 'boolean',
        'created_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope(new BranchScope());
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('stock_outflows is append-only.');
        });
        static::deleting(function (): void {
            throw new \LogicException('stock_outflows is append-only.');
        });
    }
}
