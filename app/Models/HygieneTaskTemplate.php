<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** [CAYENNE HYGIÈNE 2026-10-17] Une fiche du plan de nettoyage : checklist + fréquence + horaire facultatif. */
class HygieneTaskTemplate extends Model
{
    use SoftDeletes;

    protected $table = 'hygiene_task_templates';

    protected $fillable = [
        'branch_id', 'catalog_key', 'name', 'zone', 'how_to', 'checklist', 'frequency', 'weekday', 'month_day', 'due_time', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'checklist' => 'array',
        'weekday' => 'integer',
        'month_day' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }
}
