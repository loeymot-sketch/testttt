<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockLevel extends Model
{
    use HasFactory;

    protected static function boot()
    {
        parent::boot();

        // [iter12 P0 STOCK 2026-05-09] BranchScope global scope.
        //
        // Closes cross-tenant leak risk identified in Ultra-Review #4 STOCK :
        // unscoped queries (e.g. `StockLevel::where('on_hand', 0)->count()` for
        // a global rupture banner) would aggregate counts across all branches.
        // Admin (branch_id=0) bypass + kiosk routing preserved by BranchScope.
        // Existing access via Order/OrderItem aggregates remains unchanged.
        static::addGlobalScope(new BranchScope());
    }

    /**
     * [F-016a-BIS] Whitelist of accepted manual rupture reasons.
     *
     * Centralized so FormRequests, AvailabilityService and any future admin UI
     * stay in sync. Free-form strings are rejected at the validator boundary
     * to keep dashboard buckets clean.
     */
    public const MANUAL_UNAVAILABLE_REASONS = [
        'out_of_stock_manual',
        'seasonal',
        'recipe_change',
        'supplier_issue',
        'quality_issue',
    ];

    protected $fillable = [
        'branch_id',
        'stockable_type',
        'stockable_id',
        'on_hand',
        'reserved',
        'threshold_low',
        'manual_unavailable_reason',
        'manual_unavailable_since',
    ];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'stockable_id' => 'integer',
        'on_hand' => 'integer',
        'reserved' => 'integer',
        'threshold_low' => 'integer',
        'manual_unavailable_since' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (StockLevel $level): void {
            if ((int) $level->on_hand < 0 || (int) $level->reserved < 0) {
                throw new \InvalidArgumentException('Stock quantities must be non-negative.');
            }

            if ((int) $level->reserved > (int) $level->on_hand) {
                throw new \InvalidArgumentException('Reserved stock cannot exceed on-hand stock.');
            }
        });
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function movements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }
}
