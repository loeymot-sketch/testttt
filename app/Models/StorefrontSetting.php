<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;

/** [APP HYBRIDE W2a · 2026-11-03] Brouillon des réglages de la vitrine + version publiée courante (une ligne par branche). */
class StorefrontSetting extends Model
{
    protected $table = 'storefront_settings';

    protected $fillable = ['branch_id', 'version', 'enabled', 'theme', 'delivery_on_message', 'delivery_off_message', 'published_by', 'published_at'];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'version' => 'integer',
        'enabled' => 'boolean',
        'theme' => 'array',
        'published_by' => 'integer',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }
}
