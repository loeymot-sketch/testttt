<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;

/**
 * [CAYENNE HYGIÈNE 2026-11-18 · audit E2E P1-4] Un changement de structure d'un équipement de température (seuil, nom, type, masquage…) : qui, quand,
 * ancienne → nouvelle valeur. Journal en AJOUT SEUL : une ligne n'est jamais modifiée ni supprimée (le modèle le refuse). Écrit dans la même
 * transaction que le changement (HygieneTemperatureController).
 */
class HygieneEquipmentChange extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'hygiene_equipment_changes';

    protected $fillable = ['branch_id', 'equipment_id', 'equipment_name', 'field', 'old_value', 'new_value', 'user_id', 'user_name', 'changed_at'];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'equipment_id' => 'integer',
        'user_id' => 'integer',
        'changed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);

        // ajout seul : la trace d'un changement de seuil ne se réécrit pas et ne s'efface pas
        static::updating(function (): void {
            throw new \LogicException('Journal des changements d’équipement : une ligne ne se modifie pas.');
        });
        static::deleting(function (): void {
            throw new \LogicException('Journal des changements d’équipement : une ligne ne se supprime pas.');
        });
    }
}
