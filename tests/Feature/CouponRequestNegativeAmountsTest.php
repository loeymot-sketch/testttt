<?php

namespace Tests\Feature;

use App\Enums\DiscountType;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * P9 — CouponRequest (admin coupon CRUD): reject negative amounts on create.
 */
class CouponRequestNegativeAmountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'P9NEG',
            'code' => 'P9NEG' . uniqid(),
            'discount' => 10,
            'discount_type' => DiscountType::PERCENTAGE,
            'start_date' => now()->subDay()->format('Y-m-d H:i:s'),
            'end_date' => now()->addDay()->format('Y-m-d H:i:s'),
            'minimum_order' => 0,
            'maximum_discount' => 50,
            'image' => UploadedFile::fake()->image('coupon.png'),
        ], $overrides);
    }

    public function test_admin_coupon_store_rejects_negative_minimum_order(): void
    {
        $admin = UserFactory::new()->create([]);
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/admin/coupon', $this->basePayload([
                'minimum_order' => -1,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['minimum_order']);
    }

    public function test_admin_coupon_store_rejects_negative_discount(): void
    {
        $admin = UserFactory::new()->create([]);
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/admin/coupon', $this->basePayload([
                'discount' => -5,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['discount']);
    }
}
