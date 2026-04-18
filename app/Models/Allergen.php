<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Kiosk Design V1 — Phase 1.2
 *
 * Référentiel des 14 allergènes de l'Annexe II du Règlement UE 1169/2011
 * (Information du consommateur sur les denrées alimentaires, FIC).
 *
 * Table seedée par `AllergensSeeder` (idempotent sur `code`).
 *
 * @property int    $id
 * @property string $code
 * @property string $name_key
 * @property ?string $icon
 * @property int    $sort
 */
class Allergen extends Model
{
    use HasFactory;

    protected $table = 'allergens';

    protected $fillable = ['code', 'name_key', 'icon', 'sort'];

    protected $casts = [
        'id'       => 'integer',
        'code'     => 'string',
        'name_key' => 'string',
        'icon'     => 'string',
        'sort'     => 'integer',
    ];

    /**
     * Relation inverse du pivot. Utile pour l'UI admin CRUD allergènes.
     */
    public function items(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'item_allergen')
            ->withPivot('is_trace')
            ->withTimestamps();
    }
}
