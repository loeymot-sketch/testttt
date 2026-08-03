<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'stock_level_id',
        'branch_id',
        'delta',
        'reason',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'created_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'stock_level_id' => 'integer',
        'branch_id' => 'integer',
        'delta' => 'integer',
        'reference_id' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('stock_movements is append-only.');
        });

        static::deleting(function (): void {
            throw new \LogicException('stock_movements is append-only.');
        });
    }

    public function stockLevel()
    {
        return $this->belongsTo(StockLevel::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
