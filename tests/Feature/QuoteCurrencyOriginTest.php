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
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\OrderQuote;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteCurrencyOriginTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_currency_comes_from_backend_settings_and_is_signed(): void
    {
        [$operator, $payload] = $this->fixture();

        $data = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payload)
            ->assertOk()
            ->json('data');

        $quote = OrderQuote::where('quote_token', $data['quote_token'])->firstOrFail();

        $this->assertSame('EUR', $data['currency']);
        $this->assertSame('EUR', $quote->canonical_payload['currency']);
        $this->assertArrayHasKey('taxes', $quote->canonical_payload);
        $this->assertArrayHasKey('fees', $quote->canonical_payload);
    }

    public function test_kiosk_quote_resolves_branch_from_machine(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $foreignBranch = Branch::factory()->create();
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
            'price' => 4.00,
            'status' => Status::ACTIVE,
        ]);

        $payload = [
            'branch_id' => $foreignBranch->id,
            'order_type' => OrderType::KIOSK,
            'is_advance_order' => Ask::NO,
            'source' => Source::WEB,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 2,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ];

        $data = $this->actingAs($kioskUser, 'sanctum')
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');

        $this->assertSame($branch->id, OrderQuote::where('quote_token', $data['quote_token'])->value('branch_id'));
        $this->assertSame($branch->id, OrderQuote::where('quote_token', $data['quote_token'])->firstOrFail()->canonical_payload['branch_id']);
        $this->assertEqualsWithDelta(8.00, $data['total_ttc'], 0.001);
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
        $customer = User::factory()->create(['branch_id' => $branch->id]);
        $customer->assignRole('Customer');

        $tax = Tax::factory()->create([
            'tax_rate' => 10,
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
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ]];
    }
}
