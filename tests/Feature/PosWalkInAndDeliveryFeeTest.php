<?php

namespace Tests\Feature;

use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Address;
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

        // 1) Client de passage (aucun customer_id) : résolu en walk-in ; aucune adresse enregistrée →
        //    frais neutralisés à 0, jamais le 999 forgé par le navigateur.
        $response = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payload);

        $this->assertTrue($response->isOk(), $response->getContent());
        $response->assertJsonPath('data.delivery_charge', 0);

        $walkIn = User::where('email', WalkInCustomerResolver::EMAIL)->firstOrFail();
        $quote = OrderQuote::firstOrFail();

        $this->assertSame($walkIn->id, $quote->canonical_payload['order']['customer_id']);
        $this->assertSame(0.0, (float) $quote->canonical_payload['fees']['delivery_charge']);

        // 2) Client identifié avec adresse enregistrée à ≈ 6 km (Oignies) : frais = barème serveur
        //    (5,90 €), malgré le 999 € et les 5,01 km forgés.
        $customer = User::factory()->create(['branch_id' => $branch->id, 'status' => Status::ACTIVE]);
        $address = Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Domicile',
            'address' => '10 Rue de Test',
            'latitude' => '48.9106',
            'longitude' => '2.3522',
            'city' => 'Oignies',
            'postal_code' => '62590',
            'geocode_status' => 'OK',
        ]);

        $response = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payload + ['customer_id' => $customer->id, 'address_id' => $address->id]);

        $this->assertTrue($response->isOk(), $response->getContent());
        $response->assertJsonPath('data.delivery_charge', 5.9);

        $quote = OrderQuote::query()->latest('id')->firstOrFail();
        $this->assertSame($customer->id, $quote->canonical_payload['order']['customer_id']);
        $this->assertSame(5.9, (float) $quote->canonical_payload['fees']['delivery_charge']);
    }
}
