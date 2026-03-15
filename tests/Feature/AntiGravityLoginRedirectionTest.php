<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Enums\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AntiGravityLoginRedirectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    /**
     * Test AG-LOGIN-A: POS Operator
     */
    public function test_pos_operator_receives_pos_landing_url()
    {
        $user = User::factory()->create([
            'email' => 'posoperator@example.com',
            'password' => bcrypt('123456'),
        ]);
        $user->assignRole('POS Operator');

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'posoperator@example.com',
            'password' => '123456',
        ]);

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertArrayHasKey('defaultPermission', $data['data']);
        $this->assertEquals('pos', $data['data']['defaultPermission']['url'], "POS Operator should be redirected to /admin/pos");
    }

    /**
     * Test AG-LOGIN-B: Chef Kitchen
     */
    public function test_chef_receives_kds_landing_url()
    {
        $user = User::factory()->create([
            'email' => 'chef@example.com',
            'password' => bcrypt('123456'),
        ]);
        $user->assignRole('Chef');

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'chef@example.com',
            'password' => '123456',
        ]);

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertArrayHasKey('defaultPermission', $data['data']);
        $this->assertEquals('kitchen-display-system', $data['data']['defaultPermission']['url'], "Chef should be redirected to /admin/kitchen-display-system");
    }

    /**
     * Test AG-LOGIN-C: Customer (No defaultPermission url)
     */
    public function test_customer_receives_null_landing_url()
    {
        $user = User::factory()->create([
            'email' => 'customer@example.com',
            'password' => bcrypt('123456'),
        ]);
        $user->assignRole('Customer');

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'customer@example.com',
            'password' => '123456',
        ]);

        $response->assertStatus(200);
        $data = $response->json();

        if (!empty($data['data']['defaultPermission'])) {
            $this->assertTrue(
                !isset($data['data']['defaultPermission']['url']) || empty($data['data']['defaultPermission']['url']) || $data['data']['defaultPermission']['url'] === '#',
                "Customer should NOT have a specific landing URL overriding home"
            );
        } else {
            $this->assertEmpty($data['data']['defaultPermission']);
        }
    }

    /**
     * Test AG-MENU: POS Menu Items have Status::ACTIVE (5)
     */
    public function test_pos_menu_items_are_visible()
    {
        $admin = User::factory()->create(['email' => 'admin@test.com']);
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin)->getJson('/api/admin/item?status=' . Status::ACTIVE);

        $response->assertStatus(200);
        $data = $response->json();

        $itemsCount = count($data['data'] ?? []);
        $this->assertGreaterThan(0, $itemsCount, "POS Menu should return active items, but returned 0");
    }
}
