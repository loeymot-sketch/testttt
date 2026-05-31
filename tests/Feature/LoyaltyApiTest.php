<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;

class LoyaltyApiTest extends TestCase
{
    use RefreshDatabase, WithoutMiddleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => '123456']);
        // [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-31 Q3] These tests exercise loyalty
        // redeem MECHANICS (success, insufficient-points), not the V1 discount on/off
        // policy. Enable the discretionary-discount master flag so the pre-redeem gate
        // (LoyaltyController::redeem) does not short-circuit before the mechanics. The
        // OFF behaviour is locked by KioskLoyaltyDoubleRedeemRefusedTest::
        // test_pre_redeem_is_refused_when_discounts_disabled_v1.
        config(['pos.manual_discount_enabled' => true]);
        $this->withHeaders([
            'x-api-key' => '123456',
            'Accept' => 'application/json',
        ]);
    }

    public function test_loyalty_register()
    {
        $response = $this->postJson('/api/frontend/loyalty/register', [
            'name' => 'John Doe',
            'phone' => '+33612345678'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => true,
        ]);
        $this->assertDatabaseHas('users', ['phone' => '+33612345678']);
        $this->assertNotNull($response->json('data.loyalty_code'));
    }

    public function test_loyalty_check()
    {
        $user = \App\Models\User::forceCreate([
            'name' => 'Jane Loyalty',
            'username' => 'jane_loyalty',
            'email' => 'jane@example.com',
            'phone' => '1234567890',
            'password' => bcrypt('password'),
            'loyalty_code' => 'XYZ1234',
            'loyalty_points' => 50,
            'status' => 1
        ]);

        $response = $this->postJson('/api/frontend/loyalty/check', [
            'code' => 'XYZ1234'
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['points' => 50]);
    }

    public function test_loyalty_add_points()
    {
        $admin = \App\Models\User::forceCreate([
            'name' => 'Admin Loyalty',
            'username' => 'admin_loyalty',
            'email' => 'admin-loyalty@example.com',
            'phone' => '5234567890',
            'password' => bcrypt('password'),
            'status' => 1,
        ]);
        $admin->assignRole('Admin');

        $user = \App\Models\User::forceCreate([
            'name' => 'Jane Add',
            'username' => 'jane_add',
            'email' => 'jane2@example.com',
            'phone' => '2234567890',
            'password' => bcrypt('password'),
            'loyalty_code' => 'ADD99',
            'loyalty_points' => 10,
            'status' => 1
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/frontend/loyalty/add-points', [
            'code' => 'ADD99',
            'points' => 20
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['loyalty_code' => 'ADD99', 'loyalty_points' => 30]);
    }

    public function test_loyalty_redeem()
    {
        $user = \App\Models\User::forceCreate([
            'name' => 'Jane Redeem',
            'username' => 'jane_redeem',
            'email' => 'jane3@example.com',
            'phone' => '3234567890',
            'password' => bcrypt('password'),
            'loyalty_code' => 'RED55',
            'loyalty_points' => 100,
            'status' => 1
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/frontend/loyalty/redeem', [
            'code' => 'RED55',
            'points' => 100
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['loyalty_code' => 'RED55', 'loyalty_points' => 0]);
    }

    public function test_loyalty_redeem_not_enough_points()
    {
        $user = \App\Models\User::forceCreate([
            'name' => 'Jane Redeem Block',
            'username' => 'jane_redeem_block',
            'email' => 'jane4@example.com',
            'phone' => '4234567890',
            'password' => bcrypt('password'),
            'loyalty_code' => 'RED10',
            'loyalty_points' => 10,
            'status' => 1
        ]);

        $response = $this->postJson('/api/frontend/loyalty/redeem', [
            'code' => 'RED10',
            'points' => 50
        ]);

        $response->assertStatus(400); // Bad Request (points insuffisants)
    }
}
