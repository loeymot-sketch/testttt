<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3a] Fournisseur (achats/factures).
 *
 * Domaine NEUF, ADDITIF, HORS NF525. Pas de BranchScope global : hard-scope
 * explicite par les appelants (pattern DailyBookEntry / RawMaterial,
 * mono-branche V1 — exemption déclarée dans BranchScopeCoverageSentinelTest).
 */
class Supplier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id',
        'name',
        'contact',
    ];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
    ];

    public function purchaseDocuments()
    {
        return $this->hasMany(PurchaseDocument::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
