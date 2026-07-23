<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P1a] Stock théorique courant d'une
 * matière première par branche.
 *
 * `on_hand` est SIGNÉ (decimal 12,3) — il PEUT passer négatif : la conso
 * théorique décrémente librement, l'inventaire correcteur mensuel réaligne.
 * Volontairement AUCUN garde non-négatif (contrairement à StockLevel).
 *
 * Pas de BranchScope global : hard-scope explicite par les appelants.
 */
class RawMaterialStock extends Model
{
    protected $fillable = [
        'raw_material_id',
        'branch_id',
        'on_hand',
    ];

    protected $casts = [
        'id' => 'integer',
        'raw_material_id' => 'integer',
        'branch_id' => 'integer',
        'on_hand' => 'decimal:3',
    ];

    public function rawMaterial()
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
