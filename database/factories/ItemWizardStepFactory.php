<?php

namespace Database\Factories;

use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemWizardStepFactory extends Factory
{
    protected $model = ItemWizardStep::class;

    public function definition(): array
    {
        return [
            'profile_id' => ItemWizardProfile::factory(),
            'step_key' => fake()->unique()->slug(2),
            'label' => fake()->words(2, true),
            'source_type' => 'item_attribute',
            'source_ref' => '',
            'min_select' => 0,
            'max_select' => 1,
            'allow_repeat' => false,
            'visible_on' => ['pos', 'kiosk'],
            'stockable_choices' => false,
            'position' => fake()->numberBetween(0, 10),
            'is_active' => true,
            'addon_role' => null,
        ];
    }
}
