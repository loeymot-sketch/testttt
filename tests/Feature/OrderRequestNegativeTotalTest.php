<?php

namespace Tests\Feature;

use App\Enums\Status;
use Database\Factories\BranchFactory;
use Database\Factories\ItemFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P5 / P7 — OrderRequest (kiosk / frontend order): reject bogus negative monetary fields.
 */
class OrderRequestNegativeTotalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_frontend_order_rejects_negative_total(): void
    {
        $branch = BranchFactory::new()->create();
        $user = UserFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);
        $item = ItemFactory::new()->create(['price' => 10]);

        $payload = [
            'order_type' => 10,
            'branch_id' => $branch->id,
            'subtotal' => 10,
            'total' => -50,
            'delivery_charge' => 0,
            'is_advance_order' => 0,
            'source' => 1,
            'items' => json_encode([['item_id' => $item->id, 'price' => 10, 'quantity' => 1]]),
        ];

        // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
        $response = $this->actingAs($user)
            ->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['total']);
    }

    public function test_frontend_order_rejects_negative_subtotal(): void
    {
        $branch = BranchFactory::new()->create();
        $user = UserFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);
        $item = ItemFactory::new()->create(['price' => 10]);

        $payload = [
            'order_type' => 10,
            'branch_id' => $branch->id,
            'subtotal' => -10,
            'total' => 10,
            'delivery_charge' => 0,
            'is_advance_order' => 0,
            'source' => 1,
            'items' => json_encode([['item_id' => $item->id, 'price' => 10, 'quantity' => 1]]),
        ];

        // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
        $response = $this->actingAs($user)
            ->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['subtotal']);
    }

    public function test_frontend_order_rejects_negative_discount(): void
    {
        $branch = BranchFactory::new()->create();
        $user = UserFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);
        $item = ItemFactory::new()->create(['price' => 10]);

        $payload = [
            'order_type' => 10,
            'branch_id' => $branch->id,
            'subtotal' => 10,
            'discount' => -3,
            'total' => 10,
            'delivery_charge' => 0,
            'is_advance_order' => 0,
            'source' => 1,
            'items' => json_encode([['item_id' => $item->id, 'price' => 10, 'quantity' => 1]]),
        ];

        // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
        $response = $this->actingAs($user)
            ->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['discount']);
    }
}
