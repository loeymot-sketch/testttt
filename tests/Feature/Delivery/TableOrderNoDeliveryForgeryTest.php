<?php

namespace Tests\Feature\Delivery;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\DeliveryStop;
use App\Models\DiningTable;
use App\Models\Order;
use Database\Factories\BranchFactory;
use Database\Factories\ItemCategoryFactory;
use Database\Factories\ItemFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [LIVRAISON 2026-10-20 · relecture adverse P0] La commande de TABLE (QR, publique, sans connexion) lisait
 * `delivery_charge` tel quel : un appelant anonyme pouvait fabriquer une « livraison gratuite » sans adresse.
 * Le serveur ne dérive un prix de livraison que depuis une adresse enregistrée ; ici il n'y en a jamais.
 *
 * @group delivery
 * @group sentinel
 */
class TableOrderNoDeliveryForgeryTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $over = []): array
    {
        $branch = BranchFactory::new()->create();
        $customer = UserFactory::new()->create(['branch_id' => $branch->id]);
        $table = DiningTable::factory()->create(['branch_id' => $branch->id, 'status' => Status::ACTIVE]);
        $item = ItemFactory::new()->create(['item_category_id' => ItemCategoryFactory::new()->create()->id, 'price' => 12.00]);

        return $over + [
            'order_type' => OrderType::DINING_TABLE, 'dining_table_id' => $table->id, 'subtotal' => 12.00, 'total' => 12.00,
            'source' => Source::POS, 'customer_id' => $customer->id, 'branch_id' => $branch->id, 'is_advance_order' => Ask::NO,
            'items' => json_encode([['item_id' => $item->id, 'price' => 12.00, 'quantity' => 1]]),
        ];
    }

    private function postTable(array $payload)
    {
        return $this->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))->postJson('/api/table/dining-order', $payload);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Settings::group('pos')->set(['pos_dine_in_enabled' => true]);
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Settings::group('order_setup')->set(['order_setup_delivery' => 5]); // livraison ACTIVE : le cas dangereux
    }

    public function test_a_table_order_can_never_be_a_delivery(): void
    {
        $this->postTable($this->payload(['order_type' => OrderType::DELIVERY, 'delivery_charge' => 0, 'address_id' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('order_type');

        $this->assertSame(0, Order::query()->withoutGlobalScopes()->where('order_type', OrderType::DELIVERY)->count());
        $this->assertSame(0, DeliveryStop::query()->withoutGlobalScopes()->count());
    }

    public function test_a_forged_delivery_charge_on_a_table_order_is_zeroed(): void
    {
        $r = $this->postTable($this->payload(['delivery_charge' => 99.0, 'address_id' => 12345]));
        $this->assertContains($r->status(), [200, 201], $r->getContent());

        $order = Order::query()->withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(0.0, (float) $order->delivery_charge, 'Aucun montant de livraison venu du client sur une commande de table.');
        $this->assertSame(12.0, (float) $order->total);
    }
}
