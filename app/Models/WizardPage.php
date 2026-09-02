<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Page de wizard réutilisable : une liste de choix avec prix, partagée par la bibliothèque
 * (`owner_category_id` NULL) ou privée à une catégorie qui l'a personnalisée.
 *
 * `kind` pilote l'écran rendu par la caisse et la borne : les kinds connus (pain, taille, viande, sauce,
 * garnitures, supplements, menu) gardent leurs écrans dédiés via le `step_key` ; tout autre kind passe par
 * l'écran générique (`generic_choices`) déjà supporté par les deux surfaces.
 */
class WizardPage extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const KINDS = ['pain', 'taille', 'viande', 'sauce', 'garnitures', 'supplements', 'menu', 'generic'];

    public const SOURCE_TYPES = ['item_attribute', 'extra_group', 'addon'];

    /** step_key attendu par la caisse et la borne pour chaque kind connu. */
    public const KIND_STEP_KEYS = [
        'pain' => 'pain',
        'taille' => 'taille',
        'viande' => 'viande',
        'sauce' => 'sauce',
        'garnitures' => 'garnitures',
        'supplements' => 'supplements',
        'menu' => 'menu',
    ];

    protected $fillable = [
        'key',
        'label',
        'kind',
        'source_type',
        'item_attribute_id',
        'extra_group_label',
        'addon_role',
        'min_select',
        'max_select',
        'allow_repeat',
        'visible_on',
        'stockable_choices',
        'is_active',
        'owner_category_id',
        'description',
        'sort',
    ];

    protected $casts = [
        'id' => 'integer',
        'item_attribute_id' => 'integer',
        'owner_category_id' => 'integer',
        'min_select' => 'integer',
        'max_select' => 'integer',
        'allow_repeat' => 'boolean',
        'visible_on' => 'array',
        'stockable_choices' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    public function choices(): HasMany
    {
        return $this->hasMany(WizardPageChoice::class)->orderBy('sort')->orderBy('id');
    }

    public function activeChoices(): HasMany
    {
        return $this->choices()->where('status', \App\Enums\Status::ACTIVE);
    }

    public function itemAttribute(): BelongsTo
    {
        return $this->belongsTo(ItemAttribute::class, 'item_attribute_id');
    }

    public function ownerCategory(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'owner_category_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ItemWizardStep::class, 'wizard_page_id');
    }

    public function scopeLibrary(Builder $query): Builder
    {
        return $query->whereNull('owner_category_id');
    }

    public function scopeVisibleFor(Builder $query, ?int $categoryId): Builder
    {
        return $query->where(function (Builder $q) use ($categoryId): void {
            $q->whereNull('owner_category_id');
            if ($categoryId !== null) {
                $q->orWhere('owner_category_id', $categoryId);
            }
        });
    }

    public function isLibrary(): bool
    {
        return $this->owner_category_id === null;
    }

    /**
     * Valeur de `source_ref` que l'étape doit porter pour que la projection retrouve les choix.
     */
    public function effectiveSourceRef(): string
    {
        return match ((string) $this->source_type) {
            'item_attribute' => $this->item_attribute_id ? (string) $this->item_attribute_id : '',
            'extra_group' => (string) ($this->extra_group_label ?: $this->key),
            'addon' => (string) ($this->addon_role ?: ''),
            default => '',
        };
    }

    /**
     * step_key projeté : le kind connu impose la clé que les surfaces reconnaissent ; sinon la clé de la page.
     */
    public function effectiveStepKey(): string
    {
        $kind = (string) $this->kind;
        if ($kind !== 'generic' && isset(self::KIND_STEP_KEYS[$kind])) {
            // Les pages « viande_2 » / « viande_3 » gardent leur propre clé (écran générique par
            // construction, comme aujourd'hui) : seule la page dont la clé EST le kind prend la clé dédiée.
            return (string) $this->key === self::KIND_STEP_KEYS[$kind] ? self::KIND_STEP_KEYS[$kind] : (string) $this->key;
        }

        return (string) $this->key;
    }
}
