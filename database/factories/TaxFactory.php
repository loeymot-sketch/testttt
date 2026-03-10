<?php

namespace Database\Factories;

use App\Models\Tax;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tax>
 */
class TaxFactory extends Factory
{
    protected $model = Tax::class;

    public function definition()
    {
        return [
            'name' => 'TVA ' . fake()->numerify('##') . '%',
            'code' => 'TVA',
            'tax_rate' => fake()->randomElement([0, 5, 10, 20]),
            'type' => 5,
            'status' => 1,
        ];
    }
}
