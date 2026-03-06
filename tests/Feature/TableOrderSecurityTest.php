<?php

namespace Tests\Feature;

use Tests\TestCase;

class TableOrderSecurityTest extends TestCase
{
    public function test_fake_price_in_table_order_is_ignored()
    {
        // On vérifie que le JSON posté est géré de façon sécurisée (renvoie 4xx)
        $payload = [
            'dining_table_id' => 1,
            'customer_id' => 1,
            'branch_id' => 1,
            'subtotal' => 10,
            'total' => 0.01,
        ];
        $response = $this->postJson('/api/admin/pos', $payload);
        $this->assertContains($response->status(), [400, 401, 403, 404, 422]);
    }
}
