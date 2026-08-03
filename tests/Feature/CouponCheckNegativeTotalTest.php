<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P8 — CouponCheckRequest: negative cart total must be rejected before coupon logic runs.
 */
class CouponCheckNegativeTotalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
    }

    public function test_coupon_checking_rejects_negative_total(): void
    {
        $response = $this->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/frontend/coupon/coupon-checking', [
                'code' => 'ANYCODE',
                'total' => -10,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['total']);
    }
}
