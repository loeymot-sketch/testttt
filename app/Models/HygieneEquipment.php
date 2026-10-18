<?php

namespace App\Models;

use App\Models\Concerns\KeepsHideMoment;
use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * [CAYENNE HYGIÈNE 2026-10-17] Un équipement froid à relever chaque jour (frigo, congélateur, chambre froide, vitrine), avec
 * ses seuils en °C. Voir config/hygiene.php pour les types et les seuils par défaut.
 */
class HygieneEquipment extends Model
{
    use SoftDeletes, KeepsHideMoment;

    protected $table = 'hygiene_equipments';

    protected $fillable = ['branch_id', 'name', 'kind', 'location', 'min_c', 'max_c', 'sort_order', 'is_active'];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'min_c' => 'float',
        'max_c' => 'float',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }
}
