<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P1a] Ligne de recette :
 * sujet vendable (produit/variation/extra, mappé par GROUPE logique via
 * `subject_group`) → matière première + quantité consommée par unité.
 *
 * Pas de BranchScope global : hard-scope explicite par les appelants.
 */
class RawMaterialRecipeLine extends Model
{
    protected $fillable = [
        'branch_id',
        'subject_type',
        'subject_id',
        'subject_group',
        'raw_material_id',
        'qty',
        'note',
    ];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'subject_id' => 'integer',
        'raw_material_id' => 'integer',
        'qty' => 'decimal:3',
    ];

    public function rawMaterial()
    {
        return $this->belongsTo(RawMaterial::class);
    }
}
