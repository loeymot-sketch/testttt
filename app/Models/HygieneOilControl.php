<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;

/** [CAYENNE HYGIÈNE 2026-10-17] Une ligne du journal de l'huile (test, filtrage, vidange). Jamais modifiée ni supprimée : au plus annulée. */
class HygieneOilControl extends Model
{
    protected $table = 'hygiene_oil_controls';

    protected $fillable = [
        'branch_id', 'equipment_id', 'equipment_name', 'type', 'polar_pct', 'bath_temp_c', 'oil_litres', 'oil_brand', 'status', 'reasons',
        'polar_limit', 'corrective_action', 'corrective_note', 'note', 'attachment_id', 'performed_at', 'timezone', 'user_id', 'operator_name',
        'voided_at', 'voided_by', 'void_reason', 'client_ref',
    ];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'equipment_id' => 'integer',
        'polar_pct' => 'float',
        'bath_temp_c' => 'float',
        'oil_litres' => 'float',
        'polar_limit' => 'float',
        'attachment_id' => 'integer',
        'performed_at' => 'datetime',
        'voided_at' => 'datetime',
        'user_id' => 'integer',
        'voided_by' => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }
}
