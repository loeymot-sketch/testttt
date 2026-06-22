<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DiningTable extends Model
{
    use HasFactory;
    protected $table = "dining_tables";
    protected $fillable = ['name', 'slug', 'size', 'status', 'branch_id', 'qr_code', 'occupancy_status', 'occupied_order_id', 'occupied_at'];
    protected $casts = [
        'id'        => 'integer',
        'name'      => 'string',
        'slug'      => 'string',
        'qr_code'   => 'string',
        'size'      => 'integer',
        'branch_id' => 'integer',
        'status'    => 'integer',
        'occupancy_status'  => 'string',
        'occupied_order_id' => 'integer',
        'occupied_at'       => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::addGlobalScope(new BranchScope());
    }


    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function getQrAttribute(): ?string
    {
        if (!empty($this->qr_code)) {
            return asset($this->qr_code);
        }
        return null;
    }

    public function isFree(): bool
    {
        return $this->occupancy_status === 'free' || $this->occupancy_status === null;
    }

    public function isOccupied(): bool
    {
        return $this->occupancy_status === 'occupied';
    }
}