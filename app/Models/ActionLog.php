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
     * [POS-9.1.4] Auto-populate branch_id from the authenticated user when absent
     * so every ActionLog row is scopable in DashboardService::auditTrail.
     */
    protected static function booted(): void
    {
        static::creating(function (ActionLog $log) {
            if (empty($log->branch_id)) {
                $user = auth()->user();
                if ($user && !empty($user->branch_id)) {
                    $log->branch_id = $user->branch_id;
                } elseif ($log->user_id) {
                    $owner = User::find($log->user_id);
                    if ($owner && !empty($owner->branch_id)) {
                        $log->branch_id = $owner->branch_id;
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
