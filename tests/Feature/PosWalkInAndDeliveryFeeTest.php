<?php

namespace Tests\Feature;

use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\OrderQuote;
use App\Models\Tax;
use App\Models\User;
use App\Services\Pos\WalkInCustomerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosWalkInAndDeliveryFeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_quote_resolves_walk_in_customer_and_authoritative_delivery_fee(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create([
            'latitude' => '48.8566',
            'longitude' => '2.3522',
        ]);
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo('pos');

        $tax = Tax::factory()->create([
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 10.00,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create([
            'status' => Status::ACTIVE,
            'wizard_template' => 'tacos',
            'has_menu' => true,
        ]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 10.00,
            'status' => Status::ACTIVE,
        ]);

        $payload = [
            'branch_id' => $branch->id,
            'discount' => 0,
            'delivery_charge' => 999,
            'delivery_distance_km' => 5.01,
            'order_type' => OrderType::DELIVERY,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'items' => json_encode([
                [
                    'item_id' => $item->id,
                    'quantity' => 1,
                    'item_variations' => [],
                    'item_extras' => [],
                ],
            ]),
        ];

        $response = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payload);

        $this->assertTrue($response->isOk(), $response->getContent());
        $response->assertJsonPath('data.delivery_charge', 10);

        $walkIn = User::where('email', WalkInCustomerResolver::EMAIL)->firstOrFail();
        $quote = OrderQuote::firstOrFail();

        $this->assertSame($walkIn->id, $quote->canonical_payload['order']['customer_id']);
        $this->assertSame(10.0, (float) $quote->canonical_payload['fees']['delivery_charge']);
    }
}
