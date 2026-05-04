<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemWizardProfileFactory extends Factory
{
    protected $model = ItemWizardProfile::class;

    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'item_category_id' => null,
            'template' => 'custom',
            'version' => 1,
            'is_published' => false,
            'published_at' => null,
            'branch_id_scope' => null,
        ];
    }

    public function forCategory(ItemCategory $category): self
    {
        return $this->state(fn () => [
            'item_id' => null,
            'item_category_id' => $category->id,
        ]);
    }
}
