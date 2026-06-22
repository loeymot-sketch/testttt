<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KioskMachine extends Model
{
    use HasFactory;
    protected $table = "kiosk_machines";
    protected $fillable = ['user_id', 'branch_id', 'machine_id', 'username', 'password', 'is_login', 'status'];
    protected $casts = [
        'id'         => 'integer',
        'user_id'    => 'integer',
        'branch_id'  => 'integer',
        'machine_id' => 'string',
        'username'   => 'string',
        'password'   => 'string',
        'is_login'   => 'integer',
        'status'     => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        // [iter12 P1 KIOSK 2026-05-09] BranchScope global scope.
        //
        // Closes cross-tenant leak: post-auth queries to KioskMachine
        // (logout, ValidateKioskLocale, MenuController, FrontendOrderService,
        // OrderPushNotificationBuilder) now scope to user's branch automatically.
        // BranchScope skips when Auth::check()=false, so PRE-AUTH login lookup
        // (KioskMachineLoginController:48) and CLI commands (EnsureKioskMachineCommand)
        // continue to work but MUST use ::withoutGlobalScope(BranchScope::class)
        // explicitly to make scope-bypass intent visible (defense in depth).
        static::addGlobalScope(new BranchScope());
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}