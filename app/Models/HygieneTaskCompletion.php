<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;

/** [CAYENNE HYGIÈNE 2026-10-17] Une ligne du journal de nettoyage. Jamais modifiée ni supprimée : au plus annulée. */
class HygieneTaskCompletion extends Model
{
    protected $table = 'hygiene_task_completions';

    protected $fillable = [
        'branch_id', 'template_id', 'task_name', 'zone', 'due_date', 'items', 'items_total', 'items_done', 'complete', 'note', 'late',
        'completed_at', 'timezone', 'user_id', 'operator_name', 'attachment_id', 'voided_at', 'voided_by', 'void_reason', 'client_ref',
    ];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'template_id' => 'integer',
        'due_date' => 'date:Y-m-d',
        'items' => 'array',
        'items_total' => 'integer',
        'items_done' => 'integer',
        'complete' => 'boolean',
        'late' => 'boolean',
        'completed_at' => 'datetime',
        'voided_at' => 'datetime',
        'user_id' => 'integer',
        'attachment_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }
}
