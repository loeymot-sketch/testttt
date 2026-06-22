<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockLevel extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'stockable_type',
        'stockable_id',
        'on_hand',
        'reserved',
        'threshold_low',
    ];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'stockable_id' => 'integer',
        'on_hand' => 'integer',
        'reserved' => 'integer',
        'threshold_low' => 'integer',
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
