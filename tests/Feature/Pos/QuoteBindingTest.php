<?php

namespace Tests\Feature\Pos;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderQuote;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

class QuoteBindingTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOpenCashDrawerSession;

    public function test_pos_commit_requires_quote_token_and_signature(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$operator, $payload] = $this->fixture();

        // [HEAL dispute-r1 A-RED-2] missing token/signature pair → 422 (integrity
        // precondition), not 401 (auth) — a 401 forced the POS interceptor to
        // log the cashier out and destroy the cart.
        $this->actingAs($operator, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/admin/pos', $payload)
            ->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    public function test_pos_commit_consumes_quote_bound_to_branch_actor_and_items(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$operator, $payload, $branch, $item] = $this->fixture();

        $quote = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payload)
            ->assertOk()
            ->json('data');

        $quoteRow = OrderQuote::where('quote_token', $quote['quote_token'])->firstOrFail();
        $this->assertSame($branch->id, $quoteRow->branch_id);
        $this->assertSame($operator->id, $quoteRow->actor_id);
        $this->assertSame($item->id, (int) $quoteRow->canonical_payload['items'][0]['item_id']);
        $this->assertSame(2.0, (float) $quoteRow->canonical_payload['items'][0]['quantity']);

        $response = $this->actingAs($operator, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/admin/pos', array_merge($payload, [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
                'total' => $quote['total_ttc'],
                'pos_received_amount' => $quote['total_ttc'],
            ]));

        $this->assertContains($response->status(), [200, 201], $response->getContent());
        $orderId = (int) $response->json('data.id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'branch_id' => $branch->id,
            'total' => $quote['total_ttc'],
        ]);
        $this->assertDatabaseHas('order_quotes', [
            'quote_token' => $quote['quote_token'],
            'branch_id' => $branch->id,
            'actor_id' => $operator->id,
            'consumed_order_id' => $orderId,
            'consumed_by_user_id' => $operator->id,
        ]);
    }

    public function test_pos_commit_rejects_quote_from_different_actor(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$operator, $payload, $branch] = $this->fixture();
        $otherOperator = User::factory()->create(['branch_id' => $branch->id]);
        $otherOperator->assignRole('POS Operator');
        $otherOperator->givePermissionTo('pos');
        // [Sprint H6 TEST-DEBT-001 2026-05-17] Sprint 1B requires an OPEN cash session for CASH.
        $this->seedOpenSessionFor($otherOperator, $branch);

        $quote = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payload)
            ->assertOk()
            ->json('data');

        $this->actingAs($otherOperator, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/admin/pos', array_merge($payload, [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
                'total' => $quote['total_ttc'],
                'pos_received_amount' => $quote['total_ttc'],
            ]))
            // [HEAL dispute-r1 A-RED-2] actor-bound intent mismatch → 409
            // (integrity conflict), not 401 (auth) — guard itself unchanged.
            ->assertStatus(409);

        $this->assertSame(0, Order::count());
        $this->assertNull(OrderQuote::where('quote_token', $quote['quote_token'])->value('consumed_at'));
    }

    public function test_pos_commit_rejects_already_committed_quote_replay(): void
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

        $first = $this->actingAs($operator, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/admin/pos', $commitPayload);
        $this->assertContains($first->status(), [200, 201], $first->getContent());

        $this->actingAs($operator, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/admin/pos', $commitPayload)
            ->assertStatus(409);

        $this->assertSame(1, Order::count());
    }

    /**
     * @return array{0: User, 1: array<string, mixed>, 2: Branch, 3: Item}
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
            'price' => 8.00,
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
                'quantity' => 2,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ], $branch, $item];
    }
}
