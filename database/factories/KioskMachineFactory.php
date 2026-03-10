<?php

namespace Database\Factories;

use App\Models\KioskMachine;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\KioskMachine>
 */
class KioskMachineFactory extends Factory
{
    protected $model = KioskMachine::class;

    public function definition()
    {
        return [
            'machine_id' => fake()->unique()->numerify('KIOSK-###'),
            'branch_id' => \Database\Factories\BranchFactory::new(),
            'user_id' => \Database\Factories\UserFactory::new(),
            'username' => fake()->unique()->userName(),
            'password' => bcrypt('password123'),
            'is_login' => \App\Enums\Ask::NO,
            'status' => \App\Enums\Status::ACTIVE,
        ];
    }

    public function loggedIn()
    {
        return $this->state(fn(array $attributes) => [
            'is_login' => \App\Enums\Ask::YES,
        ]);
    }
}
