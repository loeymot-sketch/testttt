<?php

namespace Tests\Feature;

use App\Enums\Activity;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P10 — OrderSetupRequest: admin order-setup update must reject negative numeric settings.
 */
class OrderSetupRequestNegativeValuesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function validPayload(): array
    {
        return [
            'order_setup_food_preparation_time' => 30,
            'order_setup_schedule_order_slot_duration' => 30,
            'order_setup_takeaway' => Activity::ENABLE,
            'order_setup_delivery' => Activity::ENABLE,
            'order_setup_free_delivery_kilometer' => 2,
            'order_setup_basic_delivery_charge' => 1,
            'order_setup_charge_per_kilo' => 1,
        ];
    }

    public function test_order_setup_update_rejects_negative_basic_delivery_charge(): void
    {
        $admin = UserFactory::new()->create([]);
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->putJson('/api/admin/setting/order-setup', array_merge($this->validPayload(), [
                'order_setup_basic_delivery_charge' => -1,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['order_setup_basic_delivery_charge']);
    }

    public function test_order_setup_update_rejects_negative_food_preparation_time(): void
    {
        $admin = UserFactory::new()->create([]);
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->putJson('/api/admin/setting/order-setup', array_merge($this->validPayload(), [
                'order_setup_food_preparation_time' => -5,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['order_setup_food_preparation_time']);
    }

    public function test_order_setup_update_accepts_valid_non_negative_payload(): void
    {
        $admin = UserFactory::new()->create([]);
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->putJson('/api/admin/setting/order-setup', $this->validPayload());

        $response->assertOk();
    }
}
