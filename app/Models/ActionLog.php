<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActionLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'branch_id', // [POS-9.1.4] multi-tenant scoping
        'action',
        'resource',
        'details',
    ];

    /**
     * [POS-9.1.4 + POS-9-H.1.3] Auto-populate branch_id from the authenticated user when absent
     * so every ActionLog row is scopable in DashboardService::auditTrail.
     *
     * Uses is_null() instead of empty() so that:
     *   - A deliberate branch_id = 0 (Admin-scope) is preserved.
     *   - A user->branch_id of 0 (Admin actor) is persisted instead of being skipped
     *     and falling back to NULL (which was the F-A3 cross-tenant leak vector).
     */
    protected static function booted(): void
    {
        static::creating(function (ActionLog $log) {
            if (is_null($log->branch_id)) {
                $user = auth()->user();
                if ($user && !is_null($user->branch_id)) {
                    $log->branch_id = (int) $user->branch_id;
                } elseif ($log->user_id) {
                    $owner = User::find($log->user_id);
                    if ($owner && !is_null($owner->branch_id)) {
                        $log->branch_id = (int) $owner->branch_id;
                    }
                }
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(\App\Models\Branch::class);
    }
}
