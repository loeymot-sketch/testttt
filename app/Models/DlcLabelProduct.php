<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * [ETIQUETTES 2026-10-16] Un produit à étiqueter pour l'hygiène (oignons, tomates, olives…) et sa
 * durée de conservation après ouverture, saisie par le restaurant.
 */
class DlcLabelProduct extends Model
{
    use SoftDeletes;

    public const UNIT_HOURS = 'hours';
    public const UNIT_DAYS = 'days';
    public const UNITS = [self::UNIT_HOURS, self::UNIT_DAYS];

    protected $table = 'dlc_label_products';

    protected $fillable = [
        'branch_id',
        'name',
        'shelf_life_value',
        'shelf_life_unit',
        'storage',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'shelf_life_value' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    /**
     * Date limite de consommation pour une ouverture à `$openedAt`.
     * Heures : ajout exact ; jours : même heure, N jours plus tard.
     */
    public function expiresFrom(CarbonInterface $openedAt): CarbonInterface
    {
        $expires = $openedAt->copy();

        return $this->shelf_life_unit === self::UNIT_HOURS
            ? $expires->addHours($this->shelf_life_value)
            : $expires->addDays($this->shelf_life_value);
    }
}
