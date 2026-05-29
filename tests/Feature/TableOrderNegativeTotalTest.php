<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\DiningTable;
use Database\Factories\ItemFactory;
use Database\Factories\ItemCategoryFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P6 / P7 — TableOrderRequest (QR / dining-order): negative monetary fields must be rejected.
 */
class TableOrderNegativeTotalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_table_dining_order_rejects_negative_total(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $customer = UserFactory::new()->create(['branch_id' => $branch->id]);
        $table = DiningTable::factory()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategoryFactory::new()->create();
        $item = ItemFactory::new()->create([
            'item_category_id' => $category->id,
            'price' => 12.00,
        ]);

        $payload = [
            'order_type' => OrderType::DINING_TABLE,
            'dining_table_id' => $table->id,
            'subtotal' => 12.00,
            'total' => -12.00,
            'source' => Source::POS,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'is_advance_order' => Ask::NO,
            'items' => json_encode([[
                'item_id' => $item->id,
                'price' => 12.00,
                'quantity' => 1,
            ]]),
        ];

        $response = $this->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/table/dining-order', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['total']);
    }

    public function test_table_dining_order_rejects_negative_subtotal(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $customer = UserFactory::new()->create(['branch_id' => $branch->id]);
        $table = DiningTable::factory()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategoryFactory::new()->create();
        $item = ItemFactory::new()->create([
            'item_category_id' => $category->id,
            'price' => 12.00,
        ]);

        $payload = [
            'order_type' => OrderType::DINING_TABLE,
            'dining_table_id' => $table->id,
            'subtotal' => -5.00,
            'total' => 12.00,
            'source' => Source::POS,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'is_advance_order' => Ask::NO,
            'items' => json_encode([[
                'item_id' => $item->id,
                'price' => 12.00,
                'quantity' => 1,
            ]]),
        ];

        $response = $this->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/table/dining-order', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['subtotal']);
    }

    public function test_table_dining_order_rejects_negative_discount(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $customer = UserFactory::new()->create(['branch_id' => $branch->id]);
        $table = DiningTable::factory()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategoryFactory::new()->create();
        $item = ItemFactory::new()->create([
            'item_category_id' => $category->id,
            'price' => 12.00,
        ]);

        $payload = [
            'order_type' => OrderType::DINING_TABLE,
            'dining_table_id' => $table->id,
            'subtotal' => 12.00,
            'discount' => -2,
            'total' => 12.00,
            'source' => Source::POS,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'is_advance_order' => Ask::NO,
            'items' => json_encode([[
                'item_id' => $item->id,
                'price' => 12.00,
                'quantity' => 1,
            ]]),
        ];

        $response = $this->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/table/dining-order', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['discount']);
    }

    /**
     * [GOAL-2026-05-29 SEC-P1 QR-DISCOUNT] The unauthenticated QR dining-order
     * endpoint had NO manual-discount authorization gate (the POS paths do, via
     * assertPosManualDiscountAllowed). An anonymous customer could self-apply an
     * arbitrary manual discount up to 100% of subtotal. The order is now accepted
     * but the self-applied manual discount MUST be neutralized (forced to 0) —
     * only server-validated coupons may reduce the price.
     */
    public function test_table_dining_order_ignores_self_applied_manual_discount(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $customer = UserFactory::new()->create(['branch_id' => $branch->id]);
        $table = DiningTable::factory()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategoryFactory::new()->create();
        $item = ItemFactory::new()->create([
            'item_category_id' => $category->id,
            'price' => 12.00,
        ]);

        $payload = [
            'order_type' => OrderType::DINING_TABLE,
            'dining_table_id' => $table->id,
            'subtotal' => 12.00,
            'discount' => 12.00, // malicious self-applied 100% discount
            'total' => 12.00,
            'source' => Source::POS,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'is_advance_order' => Ask::NO,
            'items' => json_encode([[
                'item_id' => $item->id,
                'price' => 12.00,
                'quantity' => 1,
            ]]),
        ];

        $response = $this->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/table/dining-order', $payload);

        // Accepted (no hard error) — but the fraudulent discount is neutralized.
        $this->assertContains($response->status(), [200, 201], 'Valid table order should be created.');

        $order = \App\Models\FrontendOrder::query()
            ->where('dining_table_id', $table->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($order, 'Table order should be persisted.');
        $this->assertEquals(
            0.0,
            (float) $order->discount,
            'Self-applied manual discount on the unauthenticated QR path MUST be forced to 0 (pricing-SSOT authorization gate).'
        );
        $this->assertGreaterThanOrEqual(
            11.0,
            (float) $order->total,
            'Total must remain the full backend-computed price (~12), not the fraudulently discounted ~0.'
        );
    }
}
