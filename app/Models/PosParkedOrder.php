<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosParkedOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'user_id',
        'label',
        'payload_json',
        'preview_total',
        'items_count',
        'idempotency_token',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'user_id' => 'integer',
        'payload_json' => 'array',
        'preview_total' => 'decimal:2',
        'items_count' => 'integer',
    ];

    /**
     * [ultra-goal A5 heal 2026-05-13] Multi-tenant invariant.
     * Cross-branch leak previously possible: staff with branch_id>0 querying
     * parked orders saw tickets from all branches. Aligns with BranchScope
     * pattern on Order/OrderItem (CLAUDE.md §9).
     */
    protected static function boot(): void
    {
        parent::boot();
        static::addGlobalScope(new BranchScope());
    }
}
