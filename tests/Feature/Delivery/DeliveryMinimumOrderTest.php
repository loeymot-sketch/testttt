<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderType;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [Sprint H3 DEL-8 2026-05-17] DELIVERY orders below the branch-configured
 * delivery_minimum_order threshold must be rejected at the OrderRequest
 * validation layer. NULL threshold = no minimum (V1 legacy default).
 * Closes Sister Sprint 4 carryover DEL-8. CLAUDE.md §9.
 */
class DeliveryMinimumOrderTest extends TestCase
{
    use RefreshDatabase;

    private function makeRequest(Branch $branch, float $subtotal): array
    {
        return [
            'order_type'      => OrderType::DELIVERY,
            'branch_id'       => $branch->id,
            'subtotal'        => $subtotal,
            'total'           => $subtotal,
            'items'           => json_encode([
                ['item_id' => 1, 'quantity' => 1, 'price' => $subtotal],
            ]),
            // Other required fields will fail validation independently —
            // we focus on the DEL-8 rule firing or not.
        ];
    }

    /** @test */
    public function test_minimum_order_null_does_not_block_delivery(): void
    {
        $branch = Branch::factory()->create([
            'status' => Status::ACTIVE,
            'delivery_minimum_order' => null,
        ]);
        $user = User::factory()->create([
            'status' => Status::ACTIVE,
            'phone' => '0612345678',
            'branch_id' => $branch->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/frontend/order', $this->makeRequest($branch, 1.00));

        // We don't assert 200 (the full request likely fails on other unrelated
        // fields). We assert specifically that there's NO "Le montant minimum"
        // error for the subtotal field.
        $errors = $response->json('errors.subtotal') ?? [];
        $hasMinimumError = collect($errors)->contains(fn ($msg) => str_contains((string) $msg, 'montant minimum'));
        $this->assertFalse($hasMinimumError, 'No minimum-order error should fire when threshold is NULL');
    }

    /** @test */
    public function test_minimum_order_blocks_subtotal_below_threshold(): void
    {
        $branch = Branch::factory()->create([
            'status' => Status::ACTIVE,
            'delivery_minimum_order' => 15.00,
        ]);
        $user = User::factory()->create([
            'status' => Status::ACTIVE,
            'phone' => '0612345678',
            'branch_id' => $branch->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/frontend/order', $this->makeRequest($branch, 10.00));

        $errors = $response->json('errors.subtotal') ?? [];
        $hasMinimumError = collect($errors)->contains(fn ($msg) => str_contains((string) $msg, 'montant minimum'));
        $this->assertTrue($hasMinimumError, 'Minimum-order error MUST fire when subtotal < threshold');
    }

    /** @test */
    public function test_minimum_order_allows_subtotal_at_or_above_threshold(): void
    {
        $branch = Branch::factory()->create([
            'status' => Status::ACTIVE,
            'delivery_minimum_order' => 15.00,
        ]);
        $user = User::factory()->create([
            'status' => Status::ACTIVE,
            'phone' => '0612345678',
            'branch_id' => $branch->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/frontend/order', $this->makeRequest($branch, 15.00));

        $errors = $response->json('errors.subtotal') ?? [];
        $hasMinimumError = collect($errors)->contains(fn ($msg) => str_contains((string) $msg, 'montant minimum'));
        $this->assertFalse($hasMinimumError, 'No minimum-order error at threshold (>=)');
    }
}
