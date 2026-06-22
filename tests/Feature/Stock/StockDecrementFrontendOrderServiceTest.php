<?php

namespace Tests\Feature\Stock;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\StockLevel;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

class StockDecrementFrontendOrderServiceTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    public function test_frontend_order_store_decrements_item_stock_inside_kiosk_flow(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$kioskUser, $branch, $item, $payload] = $this->fixture();

        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => Item::class,
            'stockable_id' => $item->id,
            'on_hand' => 1,
            'reserved' => 0,
        ]);

        // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
        $response = $this->actingAs($kioskUser, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order', $this->payloadWithKioskQuote($kioskUser, $payload));

        $this->assertContains($response->status(), [200, 201], $response->getContent());
        $this->assertSame(0, (int) StockLevel::where('stockable_id', $item->id)->value('on_hand'));
        $this->assertNotNull(FrontendOrder::find((int) $response->json('data.id')));
    }

    private function fixture(): array
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $kioskUser->id,
        ]);

        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 8.25,
            'status' => Status::ACTIVE,
        ]);

        $payload = [
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'is_advance_order' => Ask::NO,
            'source' => Source::WEB,
            'payment_method' => PaymentGateway::CARD,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ];

        return [$kioskUser, $branch, $item, $payload];
    }
}
