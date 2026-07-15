<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * [GOAL RUPTURE-CARNET 2026-07-15 / W4] Entrée du Carnet (registre interne).
 *
 * Types : expense (dépense sortie), advance (acompte travailleur), note (mémo).
 * Photo de facture : collection média `invoice-photo` (single file).
 *
 * PAS de BranchScope : V1 LOCAL mono-branche (branch_id=1 fixe), même famille
 * d'exemption single-tenant que les modèles du sentinel EXEMPTED_MODELS —
 * hard-fail V2 SaaS le cas échéant.
 */
class DailyBookEntry extends Model implements HasMedia
{
    use InteractsWithMedia, SoftDeletes;

    public const TYPE_EXPENSE = 'expense';
    public const TYPE_ADVANCE = 'advance';
    public const TYPE_NOTE = 'note';

    public const TYPES = [self::TYPE_EXPENSE, self::TYPE_ADVANCE, self::TYPE_NOTE];

    protected $fillable = [
        'type',
        'label',
        'worker_name',
        'amount',
        'entry_date',
        'note',
        'branch_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'entry_date' => 'date:Y-m-d',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('invoice-photo')->singleFile();
    }

    public function registerMediaConversions(Media $media = null): void
    {
        // Vignette liste + préversion plein écran (photos smartphone lourdes).
        $this->addMediaConversion('thumb')->width(240)->keepOriginalImageFormat()->nonQueued();
        $this->addMediaConversion('preview')->width(1200)->keepOriginalImageFormat()->nonQueued();
    }
}
