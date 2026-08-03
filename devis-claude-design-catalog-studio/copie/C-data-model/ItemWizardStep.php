<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemWizardStep extends Model
{
    use HasFactory;

    public const ADDON_ROLES = ['drink', 'side', 'dessert', 'menu_component', 'upsell'];

    protected $fillable = [
        'profile_id',
        'step_key',
        'label',
        'source_type',
        'source_ref',
        'source_item_attribute_id',
        'min_select',
        'max_select',
        'allow_repeat',
        'visible_on',
        'stockable_choices',
        'position',
        'is_active',
        'addon_role',
    ];

    protected $casts = [
        'id' => 'integer',
        'profile_id' => 'integer',
        'source_item_attribute_id' => 'integer',
        'min_select' => 'integer',
        'max_select' => 'integer',
        'allow_repeat' => 'boolean',
        'visible_on' => 'array',
        'stockable_choices' => 'boolean',
        'position' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (ItemWizardStep $step): void {
            if ((int) $step->min_select > (int) $step->max_select) {
                throw new \InvalidArgumentException('min_select must be lower than or equal to max_select.');
            }

            if ($step->addon_role !== null && ! in_array($step->addon_role, self::ADDON_ROLES, true)) {
                throw new \InvalidArgumentException('Invalid addon role.');
            }
        });
    }

    public function profile()
    {
        return $this->belongsTo(ItemWizardProfile::class, 'profile_id');
    }
}
