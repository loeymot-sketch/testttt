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
}
