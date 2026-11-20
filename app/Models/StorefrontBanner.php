<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** [APP HYBRIDE W2a · 2026-11-03] Bandeau de la vitrine (BROUILLON : n'atteint l'application qu'à la publication). */
class StorefrontBanner extends Model
{
    use SoftDeletes;

    protected $table = 'storefront_banners';

    protected $fillable = ['branch_id', 'type', 'title', 'body', 'button_label', 'button_link', 'coupon_code', 'starts_at', 'ends_at', 'priority', 'active'];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'priority' => 'integer',
        'active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }
}
