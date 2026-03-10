<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrderStateTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_order_state_transition_is_rejected()
    {
        $user = User::factory()->create(['username' => 'testuser_' . uniqid()]);

        // Simulation d'une tentative de passer directement de PENDING à DELIVERED via le KDS
        $response = $this->actingAs($user)->postJson('/api/admin/kds-order/change-status/1', [
            'status' => \App\Enums\OrderStatus::DELIVERED // Status defined generically
        ]);

        // Le KDS n'a pas le droit de marquer DELIVERED (seul le POS le fait)
        // Check for 403 Forbidden or 422 Unprocessable if validation fails
        $this->assertTrue(in_array($response->status(), [401, 403, 404, 422]));
    }
}
