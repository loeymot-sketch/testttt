<?php

namespace App\Models;

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
        'max_daily_qty',
        'daily_consumed_qty',
        'daily_reset_at',
    ];

    protected $casts = [
        'is_available' => 'boolean',
        'unavailable_since' => 'datetime',
        'daily_reset_at' => 'date',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
