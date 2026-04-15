<?php

namespace App\Observers;

use App\Models\DeletionLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SoftDeleteAuditObserver
{
    public function deleted(Model $model): void
    {
        if (!in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            return;
        }
        /** @var Model&\Illuminate\Database\Eloquent\SoftDeletes $model */
        if ($model->isForceDeleting()) {
            return;
        }

        try {
            DeletionLog::query()->create([
                'model_type' => $model::class,
                'model_id' => (int) $model->getKey(),
                'actor_id' => auth()->id(),
                'actor_type' => auth()->check() ? 'user' : null,
                'reason' => null,
                'deleted_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[SoftDeleteAudit] ' . $e->getMessage());
        }
    }
}
