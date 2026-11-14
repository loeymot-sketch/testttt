<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;

/**
 * [CAYENNE HYGIÈNE 2026-10-17] Une ligne du journal des températures. Jamais modifiée ni supprimée : au plus annulée.
 */
class HygieneTemperatureReading extends Model
{
    public const STATUS_OK = 'ok';
    public const STATUS_ALERT = 'alert';

    protected $table = 'hygiene_temperature_readings';

    protected $fillable = [
        'branch_id', 'equipment_id', 'equipment_name', 'equipment_kind', 'min_c', 'max_c', 'value_c', 'status', 'alert_reason',
        'period', 'source', 'recorded_at', 'timezone', 'user_id', 'operator_name', 'corrective_action', 'corrective_note',
        'voided_at', 'voided_by', 'void_reason', 'client_ref',
    ];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'equipment_id' => 'integer',
        'min_c' => 'float',
        'max_c' => 'float',
        'value_c' => 'float',
        'recorded_at' => 'datetime',
        'voided_at' => 'datetime',
        'user_id' => 'integer',
        'voided_by' => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }
}
