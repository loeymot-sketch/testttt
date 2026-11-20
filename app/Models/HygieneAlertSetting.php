<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;

/** [CAYENNE HYGIÈNE 2026-10-18] Réglages des alertes sortantes du restaurant (une ligne par branche). Désactivé par défaut. */
class HygieneAlertSetting extends Model
{
    protected $table = 'hygiene_alert_settings';

    protected $fillable = ['branch_id', 'enabled', 'recipients', 'domains', 'send_from', 'send_until', 'repeat_minutes', 'max_sends'];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'enabled' => 'boolean',
        'recipients' => 'array',
        'domains' => 'array',
        'repeat_minutes' => 'integer',
        'max_sends' => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }
}
