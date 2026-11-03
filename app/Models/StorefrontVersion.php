<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;

/**
 * [APP HYBRIDE W2a · 2026-11-03] Instantané PUBLIÉ de la vitrine (ajout seul). La lecture publique ne lit que cette table ;
 * un retour en arrière republie un ancien instantané sous un NOUVEAU numéro, l'historique ne se réécrit jamais.
 */
class StorefrontVersion extends Model
{
    protected $table = 'storefront_versions';

    protected $fillable = ['branch_id', 'version', 'action', 'source_version', 'snapshot', 'published_by', 'published_at'];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'version' => 'integer',
        'source_version' => 'integer',
        'snapshot' => 'array',
        'published_by' => 'integer',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
        // Ajout seul : une version publiée a été montrée aux clients, on ne la réécrit pas.
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }
}
