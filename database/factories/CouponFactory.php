<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CouponFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'          => $this->faker->words(2, true),
            'code'          => strtoupper($this->faker->lexify('????-????')),
            'discount'      => $this->faker->randomFloat(2, 5, 50),
            'discount_type' => 1, // 1 = percentage
            'minimum_order' => 0,
            'maximum_discount' => 0,
            'start_date'    => now(),
            'end_date'      => now()->addDays(30),
        ];
    }
}
