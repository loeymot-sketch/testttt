<?php

namespace Database\Factories;

use App\Enums\Status;
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
            // [ultra-goal A3 heal 2026-05-13] align test fixture with Status::ACTIVE enum.
            // Production DB has legacy `1` value that pre-dates the enum (=5);
            // factory was the only place still emitting literal 1, breaking listener
            // sentinels that expect Branch::factory()->create() to be discoverable
            // via where('status', Status::ACTIVE). Prod data migration TODO (owner).
            'status' => Status::ACTIVE,
        ];
    }
}
