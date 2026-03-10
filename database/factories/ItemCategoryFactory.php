<?php

namespace Database\Factories;

use App\Models\ItemCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ItemCategory>
 */
class ItemCategoryFactory extends Factory
{
    protected $model = ItemCategory::class;

    public function definition()
    {
        $name = fake()->words(1, true);
        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name) . '-' . fake()->unique()->numerify('###'),
            'status' => 1,
        ];
    }
}
