<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;

/** [CAYENNE HYGIÈNE 2026-10-18] Code personnel d'un employé pour signer un relevé (haché ; jamais en clair, jamais rendu par l'API). */
class HygieneOperatorPin extends Model
{
    protected $table = 'hygiene_operator_pins';

    protected $fillable = ['branch_id', 'user_id', 'pin_hash', 'failed_attempts', 'locked_until', 'set_by'];

    protected $hidden = ['pin_hash'];

    protected $casts = ['id' => 'integer', 'branch_id' => 'integer', 'user_id' => 'integer', 'failed_attempts' => 'integer', 'locked_until' => 'datetime', 'set_by' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }
}
