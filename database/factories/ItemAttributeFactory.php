<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Models\ItemAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ItemAttribute>
 */
class ItemAttributeFactory extends Factory
{
    protected $model = ItemAttribute::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'status' => Status::ACTIVE,
            'min_select' => 0,
            'max_select' => 1,
            'allow_repeat' => false,
            'is_available' => true,
        ];
    }
}
