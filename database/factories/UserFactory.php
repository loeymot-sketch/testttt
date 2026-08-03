<?php

namespace Database\Factories;

use App\Enums\Status;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            // [Sprint 2B / DEL-4] users.phone is now NOT NULL (see
            // 2026_05_16_140100_make_user_phone_required migration). Every
            // factory-created user must therefore have a unique, well-formed
            // phone — 10 digits matches the ValidPhone rule (FR / configured
            // default phone length). Without this default ~200+ tests fail
            // with `NOT NULL constraint failed: users.phone` on factory
            // create() because the column is now mandatory.
            'phone' => fake()->unique()->numerify('06########'),
            'username' => fake()->unique()->userName(),
            'email_verified_at' => now(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'remember_token' => Str::random(10),
            'branch_id' => null,
            'status' => Status::ACTIVE,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     *
     * @return static
     */
    public function unverified()
    {
        return $this->state(fn(array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
