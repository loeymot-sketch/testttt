<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\OrderQuote;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

class QuoteReplayIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOpenCashDrawerSession;

    public function test_quote_consume_replay_is_idempotent(): void
    {
        [$operator, $payload] = $this->fixture();

        $quote = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payload)
            ->assertOk()
            ->json('data');

        $consumePayload = $payload + [
            'quote_token' => $quote['quote_token'],
            'quote_signature' => $quote['signature'],
            'consume' => true,
        ];

        $firstConsume = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $consumePayload)
            ->assertOk()
            ->json('data');

        $secondConsume = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $consumePayload)
            ->assertOk()
            ->json('data');

        $this->assertSame($firstConsume['quote_token'], $secondConsume['quote_token']);
        $this->assertSame(1, OrderQuote::where('quote_token', $quote['quote_token'])->count());
        $this->assertNotNull(OrderQuote::where('quote_token', $quote['quote_token'])->value('consumed_at'));
    }

    public function test_pos_commit_consumes_quote_with_order_id(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$operator, $payload] = $this->fixture();

        $quote = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payload)
            ->assertOk()
            ->json('data');

        $commitPayload = array_merge($payload, [
            'quote_token' => $quote['quote_token'],
            'quote_signature' => $quote['signature'],
            'total' => $quote['total_ttc'],
            'pos_received_amount' => $quote['total_ttc'],
        ]);

        // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
        $response = $this->actingAs($operator, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos', $commitPayload);

        $this->assertContains($response->status(), [200, 201], $response->getContent());
        $orderId = (int) $response->json('data.id');

        $this->assertDatabaseHas('order_quotes', [
            'quote_token' => $quote['quote_token'],
            'consumed_order_id' => $orderId,
            'consumed_by_user_id' => $operator->id,
        ]);
        $this->assertNotNull(OrderQuote::where('quote_token', $quote['quote_token'])->value('consumed_at'));
    }

    public function test_kiosk_commit_consumes_quote_with_frontend_order_id(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$kioskUser, $payload] = $this->kioskFixture();

        $quote = $this->actingAs($kioskUser, 'sanctum')
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');

        $response = $this->actingAs($kioskUser, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order', $payload + [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
                'total' => $quote['total_ttc'],
            ]);

        $this->assertContains($response->status(), [200, 201], $response->getContent());
        $orderId = (int) $response->json('data.id');

        $this->assertTrue(FrontendOrder::whereKey($orderId)->exists());
        $this->assertDatabaseHas('order_quotes', [
            'quote_token' => $quote['quote_token'],
            'consumed_order_id' => $orderId,
            'consumed_by_user_id' => $kioskUser->id,
        ]);
    }

    /**
     * @return array{0: User, 1: array<string, mixed>}
     */
    private function fixture(): array
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo('pos');
        // [Sprint H6 TEST-DEBT-001 2026-05-17] Sprint 1B requires an OPEN cash session for CASH.
        $this->seedOpenSessionFor($operator, $branch);
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
            'price' => 7.00,
            'status' => Status::ACTIVE,
        ]);

        return [$operator, [
            'token' => null,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'subtotal' => 0,
            'discount' => 0,
            'coupon_id' => 0,
            'total' => 0,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::POS,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 0,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 3,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ]];
    }

    /**
     * @return array{0: User, 1: array<string, mixed>}
     */
    private function kioskFixture(): array
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
            'price' => 6.50,
            'status' => Status::ACTIVE,
        ]);

        return [$kioskUser, [
            'branch_id' => $branch->id,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::WEB,
            'payment_method' => PaymentGateway::CARD,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 2,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ]];
    }
}
