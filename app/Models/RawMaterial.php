<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P1a] Matière première (raw material / BOM).
 *
 * Domaine NEUF, distinct de `Ingredient*` (availability virtuelle) et de
 * `StockLevel` (unsigned). Pas de BranchScope global : hard-scope explicite
 * par les appelants (pattern DailyBookEntry, mono-branche V1 — exemption
 * déclarée dans BranchScopeCoverageSentinelTest).
 */
class RawMaterial extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id',
        'name',
        'unit',
        'piece_weight_g',
        'avg_cost',
        'threshold_low',
        'is_active',
    ];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'piece_weight_g' => 'decimal:2',
        'avg_cost' => 'decimal:4',
        'threshold_low' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function stocks()
    {
        return $this->hasMany(RawMaterialStock::class);
    }

    public function movements()
    {
        return $this->hasMany(RawMaterialMovement::class);
    }

    public function recipeLines()
    {
        return $this->hasMany(RawMaterialRecipeLine::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
