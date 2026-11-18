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

    /**
     * [2026-11-18 · audit E2E P1-3] Masqué (ou supprimé) DEPUIS `$since` : masquer un équipement ne doit pas effacer son écart du jour (tuile, rappels,
     * écran). Le moment du masquage est `updated_at` (KeepsHideMoment le préserve) ; à combiner avec `withTrashed()` pour voir les suppressions du jour.
     */
    public function scopeHiddenSince($query, \Carbon\CarbonInterface $since)
    {
        return $query->where(fn ($q) => $q->where(fn ($h) => $h->where('is_active', false)->where('updated_at', '>=', $since))
            ->orWhere('deleted_at', '>=', $since));
    }

    public function changes()
    {
        return $this->hasMany(HygieneEquipmentChange::class, 'equipment_id');
    }
}
