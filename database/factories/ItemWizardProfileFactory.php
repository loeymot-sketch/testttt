<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemWizardProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemWizardProfileFactory extends Factory
{
    protected $model = ItemWizardProfile::class;

    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'template' => 'custom',
            'version' => 1,
            'is_published' => false,
            'published_at' => null,
            'branch_id_scope' => null,
        ];
    }
}
