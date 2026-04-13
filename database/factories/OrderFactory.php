<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition()
    {
        return [
            'order_serial_no' => strtoupper(fake()->unique()->bothify('ORD-####-??')),
            'user_id' => \Database\Factories\UserFactory::new(),
            'branch_id' => \Database\Factories\BranchFactory::new(),
            'order_type' => 5, // Takeaway
            'subtotal' => fake()->randomFloat(2, 5, 50),
            'total' => fake()->randomFloat(2, 5, 50),
            'discount' => 0,
            'delivery_charge' => 0,
            'order_datetime' => now(),
            'payment_method' => 1, // Cash
            'payment_status' => 5, // Unpaid
            'status' => 5, // PENDING
        ];
    }

    // Convenience states for test readability
    public function accepted()
    {
        return $this->state(fn(array $attributes) => [
            'payment_status' => 10,
            'status' => 10, // ACCEPT
        ]);
    }

    public function preparing()
    {
        return $this->state(fn(array $attributes) => [
            'payment_status' => 10,
            'status' => 12, // PREPARING
        ]);
    }

    public function delivered()
    {
        return $this->state(fn(array $attributes) => [
            'payment_status' => 10,
            'status' => 14, // DELIVERED
        ]);
    }
}
