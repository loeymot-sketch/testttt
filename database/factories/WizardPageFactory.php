<?php

namespace Database\Factories;

use App\Models\WizardPage;
use Illuminate\Database\Eloquent\Factories\Factory;

class WizardPageFactory extends Factory
{
    protected $model = WizardPage::class;

    public function definition(): array
    {
        $label = fake()->unique()->words(2, true);

        return [
            'key' => preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower($label)),
            'label' => ucfirst($label),
            'kind' => 'generic',
            'source_type' => 'item_attribute',
            'item_attribute_id' => null,
            'extra_group_label' => null,
            'addon_role' => null,
            'min_select' => 0,
            'max_select' => 1,
            'allow_repeat' => false,
            'visible_on' => ['pos', 'kiosk'],
            'stockable_choices' => false,
            'is_active' => true,
            'owner_category_id' => null,
            'description' => null,
            'sort' => 0,
        ];
    }

    public function extraGroup(string $label): self
    {
        return $this->state(fn () => [
            'source_type' => 'extra_group',
            'extra_group_label' => $label,
        ]);
    }

    public function addon(string $role = 'drink'): self
    {
        return $this->state(fn () => [
            'source_type' => 'addon',
            'addon_role' => $role,
        ]);
    }
}
