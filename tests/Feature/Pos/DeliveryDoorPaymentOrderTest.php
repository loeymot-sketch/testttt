<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Address;
use App\Models\Branch;
use App\Models\DeliveryStop;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * [CAISSE 2026-10-23 · demande propriétaire] Bouton « Commande livraison » : la caisse prend une livraison par téléphone
 * (adresse + téléphone du client), SANS encaisser maintenant — le livreur encaisse à la porte.
 *
 * Marqueur dédié `delivery_door_payment`, JAMAIS `phone_order` : une commande téléphone est tagguée `source_surface='phone'`
 * et tombe dans la file « À encaisser au comptoir », que `counter-collect/purge-phone-today` (client non venu) annule
 * (cf. PhoneOrderDeliveryRefusedTest). Ici la commande porte `source_surface='delivery'`, comme une livraison du site payée
 * à la porte : OrderService::deliveryBoyOrderChangeStatus la scelle (numéro fiscal) à la remise.
 */
class DeliveryDoorPaymentOrderTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    private Branch $branch;
    private User $customer;
    private User $operator;
    private Item $item;
    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        \Smartisan\Settings\Facades\Settings::group('order_setup')->set(['order_setup_delivery' => \App\Enums\Activity::ENABLE]);

        Config::set('pos.simulation_hardware', true);
        Config::set('pos.walkin_route_to_counter', false);
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-' . str_repeat('a', 40));

        $this->branch = Branch::factory()->create(['latitude' => '50.4215667', 'longitude' => '2.9549060']);

        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id, 'password' => Hash::make('password'), 'phone' => '0612345678',
        ]);
        $this->customer->assignRole('Customer');

        $this->operator = User::factory()->create([
            'branch_id' => $this->branch->id, 'password' => Hash::make('password'), 'phone' => '0102030401',
        ]);
        $this->operator->assignRole('POS Operator');

        $this->address = Address::query()->create([
            'user_id' => $this->customer->id, 'label' => 'Domicile', 'address' => '10 Rue de Test',
            'latitude' => '50.43', 'longitude' => '2.96', 'city' => 'Hénin-Beaumont', 'postal_code' => '62110',
            'geocode_status' => 'OK',
        ]);

        $tax = Tax::factory()->create([
            'name' => 'TVA 0%', 'code' => 'TVA0', 'type' => TaxType::PERCENTAGE, 'tax_rate' => 0.00, 'status' => Status::ACTIVE,
        ]);
        $cat = ItemCategory::factory()->create(['name' => 'Boissons', 'wizard_template' => 'simple', 'has_menu' => false]);
        $this->item = Item::factory()->create([
            'item_category_id' => $cat->id, 'tax_id' => $tax->id, 'name' => 'Eau Plate', 'price' => 1.00, 'status' => Status::ACTIVE,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(int $orderType = OrderType::DELIVERY, array $override = []): array
    {
        return array_merge([
            'token' => '7',
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'discount' => 0,
            'order_type' => $orderType,
            'address_id' => $orderType === OrderType::DELIVERY ? $this->address->id : null,
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'pos_received_amount' => null,
            'delivery_door_payment' => true,
            'pos_customer_name' => 'Client Livraison',
            'pos_customer_phone' => '06 12 34 56 78',
            'items' => json_encode([[
                'item_id' => $this->item->id, 'item_price' => 1.00, 'quantity' => 1, 'total_price' => 1.00,
                'item_variations' => [], 'item_extras' => [],
            ]]),
        ], $override);
    }

    private function postPos(array $payload)
    {
        $payload = $this->payloadWithPosQuote($this->operator, $payload);
        $this->actingAs($this->operator, 'sanctum');

        return $this->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'door-liv-' . uniqid())
            ->postJson('/api/admin/pos', $payload);
    }

    /** @test */
    public function a_delivery_to_be_paid_at_the_door_is_created_deferred_with_the_server_fee_and_no_fiscal_number(): void
    {
        $response = $this->postPos($this->payload());

        $response->assertSuccessful();
        $order = Order::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertSame(OrderType::DELIVERY, (int) $order->order_type);
        $this->assertSame(PaymentStatus::PENDING_COUNTER, (int) $order->payment_status, 'Non payée : le livreur encaisse à la porte.');
        $this->assertNull($order->fiscal_sequence_no, 'Pas de numéro fiscal avant l\'encaissement (NF525 : pas de trou).');
        $this->assertSame('delivery', strtolower((string) $order->source_surface), 'JAMAIS « phone » : la purge « client non venu » l\'annulerait.');
        $this->assertSame($this->operator->id, (int) $order->creator_id);
        $this->assertEqualsWithDelta(3.90, (float) $order->delivery_charge, 0.001, 'Frais Hénin-Beaumont posés par le SERVEUR.');
        $this->assertEqualsWithDelta(4.90, (float) $order->total, 0.001);
        $this->assertNotNull(\App\Models\OrderAddress::query()->where('order_id', $order->id)->first(), 'L\'adresse suit la commande.');
        $this->assertSame('0612345678', preg_replace('/\D/', '', (string) $order->pos_customer_phone), 'Le téléphone est noté pour le livreur.');
    }

    /** @test */
    public function the_door_payment_delivery_joins_a_wave_so_the_courier_sees_it(): void
    {
        $this->postPos($this->payload())->assertSuccessful();
        $order = Order::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertNotNull(DeliveryStop::query()->where('order_id', $order->id)->first(), 'Un arrêt de tournée doit exister.');
    }

    /** @test */
    public function the_door_payment_marker_is_refused_outside_a_delivery(): void
    {
        $before = Order::withoutGlobalScopes()->count();
        $response = $this->postPos($this->payload(OrderType::TAKEAWAY));

        $response->assertStatus(422)->assertJsonValidationErrors(['delivery_door_payment']);
        $this->assertSame($before, Order::withoutGlobalScopes()->count());
    }

    /** @test */
    public function a_door_payment_delivery_without_a_phone_number_is_refused(): void
    {
        $before = Order::withoutGlobalScopes()->count();
        $response = $this->postPos($this->payload(OrderType::DELIVERY, ['pos_customer_phone' => '']));

        $response->assertStatus(422)->assertJsonValidationErrors(['pos_customer_phone']);
        $this->assertSame($before, Order::withoutGlobalScopes()->count());
    }

    /** @test — non-régression : « Commande téléphone » reste refusée en livraison (purge « client non venu »). */
    public function phone_order_in_delivery_stays_refused(): void
    {
        $response = $this->postPos($this->payload(OrderType::DELIVERY, ['delivery_door_payment' => false, 'phone_order' => true]));

        $response->assertStatus(422)->assertJsonValidationErrors(['phone_order']);
    }
}
