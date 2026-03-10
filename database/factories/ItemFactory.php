<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition()
    {
        $name = fake()->words(2, true);
        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . fake()->unique()->numerify('###'),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 3, 25),
            'discount_price' => null,
            'item_category_id' => \Database\Factories\ItemCategoryFactory::new(),
            'tax_id' => \Database\Factories\TaxFactory::new(),
            'status' => 1,
        ];
    }
}
