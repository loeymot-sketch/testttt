<?php

namespace Tests\Feature\Stock;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

class StockDecrementOrderServiceTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    public function test_pos_order_store_decrements_item_stock_inside_order_flow(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$operator, $customer, $branch, $item] = $this->fixture();

        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => Item::class,
            'stockable_id' => $item->id,
            'on_hand' => 1,
            'reserved' => 0,
        ]);

        $payload = $this->payload($branch, $customer, $item);
        $response = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($operator, $payload, 10.00));

        $this->assertContains($response->status(), [200, 201], $response->getContent());
        $this->assertSame(0, (int) StockLevel::where('stockable_id', $item->id)->value('on_hand'));
        $this->assertSame(-1, (int) StockMovement::where('reason', 'order_created')->sum('delta'));
    }

    public function test_pos_order_store_rejects_when_tracked_item_stock_is_empty(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$operator, $customer, $branch, $item] = $this->fixture();

        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => Item::class,
            'stockable_id' => $item->id,
            'on_hand' => 0,
            'reserved' => 0,
        ]);

        $payload = $this->payload($branch, $customer, $item);
        $response = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($operator, $payload, 10.00));

        $this->assertSame(409, $response->status(), $response->getContent());
        $this->assertSame(0, StockMovement::count());
    }

    private function fixture(): array
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create([
            'branch_id' => $branch->id,
            'password' => Hash::make('password123'),
        ]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo('pos');

        $customer = User::factory()->create(['branch_id' => $branch->id]);
        $customer->assignRole('Customer');

        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 10.00,
            'status' => Status::ACTIVE,
        ]);

        return [$operator, $customer, $branch, $item];
    }

    private function payload(Branch $branch, User $customer, Item $item): array
    {
        return [
            'token' => null,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'subtotal' => 10.00,
            'discount' => 0,
            'coupon_id' => 0,
            'total' => 10.00,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 10.00,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ];
    }
}
