<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\OrderQuote;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

class QuoteTamperTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOpenCashDrawerSession;

    public function test_quote_token_replay_with_changed_intent_is_rejected(): void
    {
        [$operator, $payload] = $this->fixture();

        $first = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payload)
            ->assertOk()
            ->json('data');

        $tampered = $payload;
        $tampered['items'] = json_encode([[
            'item_id' => json_decode($payload['items'])[0]->item_id,
            'quantity' => 2,
            'item_variations' => [],
            'item_extras' => [],
        ]]);
        $tampered['quote_token'] = $first['quote_token'];
        $tampered['quote_signature'] = $first['signature'];

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $tampered)
            ->assertStatus(401);
    }

    public function test_pos_commit_with_tampered_quote_intent_is_rejected(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$operator, $payload] = $this->fixture();

        $first = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payload)
            ->assertOk()
            ->json('data');

        $tampered = $payload;
        $tampered['items'] = json_encode([[
            'item_id' => json_decode($payload['items'])[0]->item_id,
            'quantity' => 2,
            'item_variations' => [],
            'item_extras' => [],
        ]]);
        $tampered['quote_token'] = $first['quote_token'];
        $tampered['quote_signature'] = $first['signature'];
        $tampered['total'] = $first['total_ttc'];
        $tampered['pos_received_amount'] = $first['total_ttc'];

        $this->actingAs($operator, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/admin/pos', $tampered)
            ->assertStatus(401);

        $this->assertNull(OrderQuote::where('quote_token', $first['quote_token'])->value('consumed_at'));
    }

    public function test_pos_commit_rejects_cross_branch_quote_replay(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$operatorA, $payloadA] = $this->fixture();
        [$operatorB, $payloadB] = $this->fixture();

        $first = $this->actingAs($operatorA, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payloadA)
            ->assertOk()
            ->json('data');

        $payloadB['quote_token'] = $first['quote_token'];
        $payloadB['quote_signature'] = $first['signature'];
        $payloadB['total'] = $first['total_ttc'];
        $payloadB['pos_received_amount'] = $first['total_ttc'];

        $this->actingAs($operatorB, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/admin/pos', $payloadB)
            ->assertStatus(401);
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
            'price' => 10.00,
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
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ]];
    }
}
