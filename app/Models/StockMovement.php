<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected static function boot()
    {
        parent::boot();

        // [iter12 P0 STOCK 2026-05-09] BranchScope global scope.
        //
        // Movements are append-only audit rows (UPDATED_AT=null) — but listing
        // queries from admin dashboards must remain branch-scoped. Without the
        // scope, a multi-branch-merchant admin user (Spatie role with
        // permission:reports) could accidentally aggregate movements across
        // tenants. BranchScope respects admin (branch_id=0) bypass.
        static::addGlobalScope(new BranchScope());
    }

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
