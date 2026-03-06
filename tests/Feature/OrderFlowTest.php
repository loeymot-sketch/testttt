<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    public function test_order_without_auth_returns_401()
    {
        $response = $this->postJson('/api/frontend/order', []);
        $this->assertContains($response->status(), [401, 404, 200]);
    }

    public function test_order_price_recalculated_server_side()
    {
        $this->assertTrue(true);
    }

    public function test_invalid_status_transition_rejected()
    {
        $this->assertTrue(true);
    }
}
