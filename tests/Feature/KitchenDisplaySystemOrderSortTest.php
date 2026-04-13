<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KitchenDisplaySystemOrderSortTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_kds_sort_params_fall_back_without_breaking_the_api(): void
    {
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $branch = Branch::forceCreate([
            'name' => 'KDS Sort Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '30 rue kds',
            'status' => 1,
        ]);

        $chef = User::forceCreate([
            'name' => 'Chef Sort',
            'email' => 'chef-sort@test.local',
            'username' => 'chef_sort',
            'phone' => '0600000020',
            'password' => bcrypt('password123'),
            'branch_id' => $branch->id,
            'status' => 1,
        ]);
        $chef->assignRole('Chef');

        $customer = User::forceCreate([
            'name' => 'Customer Sort',
            'email' => 'customer-sort@test.local',
            'username' => 'customer_sort',
            'phone' => '0600000021',
            'password' => bcrypt('password123'),
            'branch_id' => $branch->id,
            'status' => 1,
        ]);

        Order::forceCreate([
            'order_serial_no' => 'KDS-SORT-001',
            'user_id' => $customer->id,
            'branch_id' => $branch->id,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => 5,
            'payment_method' => 1,
            'order_type' => 10,
            'is_advance_order' => Ask::NO,
            'subtotal' => 15,
            'total' => 15,
            'discount' => 0,
            'delivery_charge' => 0,
            'order_datetime' => now(),
        ]);

        $response = $this
            ->actingAs($chef, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/kds-order?order_column=created_at%20desc,select%201&order_by=sideways');

        $response->assertOk();
        $rows = $response->json('data') ?? $response->json() ?? [];
        $this->assertNotEmpty($rows);
    }
}
