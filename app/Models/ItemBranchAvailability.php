<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemBranchAvailability extends Model
{
    protected $table = 'item_branch_availability';

    protected $fillable = [
        'item_id',
        'branch_id',
        'is_available',
        'unavailable_reason',
        'unavailable_since',
        // [panel-manual-86-reason-collision 2026-07-23] Provenance : non-null => 86
        // posé par un humain (panel/ /m) => sticky (StockService ne le réactive pas
        // au restock). Null => rupture auto stock (réactivable). Miroir du pattern
        // stock_levels.manual_unavailable_* pour le chemin ITEM.
        'manual_unavailable_since',
        'max_daily_qty',
        'daily_consumed_qty',
        'daily_reset_at',
    ];

    protected $casts = [
        'is_available' => 'boolean',
        'unavailable_since' => 'datetime',
        'manual_unavailable_since' => 'datetime',
        'daily_reset_at' => 'date',
    ];

    /**
     * [2026-05-18 PR-D T7 heal] Multi-tenant gap closure. Per ultra-review
     * PR-D F-D2 split: ItemBranchAvailability has `branch_id` and is
     * compatible with the standard `BranchScope` (strict equality on
     * `branch_id` column). Admin (branch_id=0) bypasses. Staff sees only
     * own-branch availability rows — prevents cross-tenant 86-rupture
     * leakage between branches in a future multi-restaurant deploy.
     * Sister test: tests/Feature/Multitenant/ItemBranchAvailabilityScopeTest.php
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope());
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
