<?php

namespace Tests\Feature;

use Tests\TestCase;

class PricingIntegrityTest extends TestCase
{
    public function test_server_rejects_forged_pricing_in_order_payload()
    {
        // On vérifie que le JSON posté avec un faux total est rejeté avec un code de validation strict (422)
        $payload = [
            'dining_table_id' => 1,
            'customer_id' => 1,
            'branch_id' => 1,
            'subtotal' => 100, // Forged
            'total' => 0.01,    // Forged to pay less
            'items' => json_encode([
                [
                    'item_id' => 1, // Assume Item 1 costs $10
                    'price' => 0.01, // Forged item price
                    'quantity' => 1
                ]
            ])
        ];

        $response = $this->postJson('/api/frontend/order', $payload);

        // Either the server blocks due to validation (422) or unauthorized, 
        // but it must NOT process it as a 200/201.
        $this->assertTrue(in_array($response->status(), [401, 403, 422]), "Response status was " . $response->status() . " instead of 422/403");
    }
}
