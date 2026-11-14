<?php

namespace Tests\Feature\Delivery;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [LIVRAISON 2026-10-20] Le serveur dérive TOUJOURS distance + frais de l'adresse enregistrée du client
 * de la commande (`customer_id`) ; `delivery_charge` / `delivery_distance_km` du navigateur ne sont
 * jamais crus. Géométrie : +0,054° de latitude ≈ 6,0 km (palier ≤ 8 km = 5,90 €).
 */
class DeliveryFeeForgePosTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_quote_ignores_forged_delivery_charge_and_recomputes_from_saved_address(): void
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
        $customer = User::factory()->create(['branch_id' => $branch->id, 'status' => Status::ACTIVE]);
        $address = Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Domicile',
            'address' => '10 Rue de Test',
            'latitude' => '48.9106',   // ≈ 6,0 km du restaurant
            'longitude' => '2.3522',
            'city' => 'Oignies',
            'postal_code' => '62590',
            'geocode_status' => 'OK',
        ]);

        $tax = Tax::factory()->create([
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 0,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 10.00,
            'status' => Status::ACTIVE,
        ]);

        $response = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', [
                'branch_id' => $branch->id,
                'discount' => 0,
                'delivery_charge' => 999,
                'delivery_distance_km' => 0.1, // forgé : ignoré, le serveur mesure l'adresse enregistrée
                'customer_id' => $customer->id,
                'address_id' => $address->id,
                'order_type' => OrderType::DELIVERY,
                'source' => 1,
                'pos_payment_method' => PosPaymentMethod::CASH,
                'items' => json_encode([[
                    'item_id' => $item->id,
                    'quantity' => 1,
                    'item_variations' => [],
                    'item_extras' => [],
                ]]),
            ]);

        $response->assertOk()->assertJsonPath('data.delivery_charge', 5.9);

        $quote = OrderQuote::firstOrFail();

        $this->assertSame($customer->id, $quote->canonical_payload['order']['customer_id']);
        $this->assertSame(5.9, (float) $quote->canonical_payload['fees']['delivery_charge']);
    }

    public function test_pos_quote_delivery_without_customer_address_is_refused_and_never_uses_client_charge(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create(['latitude' => '48.8566', 'longitude' => '2.3522']);
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo('pos');

        $tax = Tax::factory()->create(['type' => TaxType::PERCENTAGE, 'tax_rate' => 0, 'status' => Status::ACTIVE]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 10.00,
            'status' => Status::ACTIVE,
        ]);

        // Client walk-in sans adresse : pas de frais inventés, pas de 999 forgé facturé.
        $response = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', [
                'branch_id' => $branch->id,
                'discount' => 0,
                'delivery_charge' => 999,
                'delivery_distance_km' => 5.01,
                'order_type' => OrderType::DELIVERY,
                'source' => 1,
                'pos_payment_method' => PosPaymentMethod::CASH,
                'items' => json_encode([[
                    'item_id' => $item->id,
                    'quantity' => 1,
                    'item_variations' => [],
                    'item_extras' => [],
                ]]),
            ]);

        // Aucune adresse enregistrée → frais neutralisés à 0 (le commit sera refusé par la validation address_id) ;
        // surtout, ni le 999 forgé ni un montant inventé ne sont scellés dans le devis.
        $response->assertOk()->assertJsonPath('data.delivery_charge', 0);
        $this->assertSame(0.0, (float) OrderQuote::firstOrFail()->canonical_payload['fees']['delivery_charge']);
    }
}
