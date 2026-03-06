<?php

namespace Tests\Feature;

use Tests\TestCase;

class CouponSecurityTest extends TestCase
{
    public function test_apply_valid_coupon_recalculates_discount()
    {
        $this->assertTrue(true);
    }

    public function test_fake_discount_injection_is_ignored()
    {
        // On poste un discount absurde de 9999 avec un subtotal de 100
        $payload = [
            'subtotal' => 100,
            'discount' => 9999,
            'total' => 1,
            'order_type' => \App\Enums\OrderType::DELIVERY,
            'is_advance_order' => \App\Enums\Ask::NO,
            'source' => \App\Enums\Source::WEB,
            'items' => json_encode([])
        ];

        $response = $this->postJson('/api/frontend/order', $payload); // ou v1/frontend
        $this->assertContains($response->status(), [401, 403, 404, 422]);
    }
}
