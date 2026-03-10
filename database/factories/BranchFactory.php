<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition()
    {
        return [
            'name' => fake()->company() . ' Branch',
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'zip_code' => fake()->postcode(),
            'address' => fake()->streetAddress(),
            'status' => 1,
        ];
    }
}
