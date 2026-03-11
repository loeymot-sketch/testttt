<?php

namespace Database\Factories;

use App\Models\DiningTable;
use Illuminate\Database\Eloquent\Factories\Factory;

class DiningTableFactory extends Factory
{
    protected $model = DiningTable::class;

    public function definition(): array
    {
        $name = 'Table ' . $this->faker->numberBetween(1, 50);
        return [
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . $this->faker->unique()->numerify('###'),
            'size' => $this->faker->numberBetween(2, 8),
            'status' => \App\Enums\Status::ACTIVE,
            'branch_id' => 1,
        ];
    }
}
