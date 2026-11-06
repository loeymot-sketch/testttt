<?php

namespace Tests\Feature\Delivery;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Address;
use App\Models\Branch;
use App\Models\DeliveryStop;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderPayment;
use App\Models\Tax;
use App\Models\User;
use App\Services\Delivery\DeliveryWaveService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Smartisan\Settings\Facades\Settings;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * [VG-2026-10-21 L2 · décision du propriétaire 2026-10-22] Titre-restaurant accepté PARTOUT, y compris sur une
 * livraison. Le livreur le récupère à la porte : la vente n'est PAYÉE (et scellée NF525) qu'à la REMISE, comme les
 * espèces, avec une tranche `order_payments` du montant total en mode titre-restaurant — jamais au départ du livreur.
 *
 * Avant : au départ (PRÊTE → EN LIVRAISON), le flip historique « non-espèces → PAYÉ » scellait la vente avec un
 * numéro fiscal et ZÉRO paiement ; l'écran livreur passait de « Encaisser par carte » à « Déjà payé, 0 € ».
 *
 * @group delivery
 */
class DeliveryMealVoucherDoorstepTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $courier;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedSpatieRoles();
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Delivery Boy', 'guard_name' => 'sanctum']);
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key']);
        Carbon::setTestNow(Carbon::parse('2026-10-20 19:02:00'));
        $this->branch = Branch::factory()->create(['latitude' => '50.4215667', 'longitude' => '2.9549060']);
        $this->courier = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE]);
        $this->courier->assignRole('Delivery Boy');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function http(): self
    {
        return $this->actingAs($this->courier, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid());
    }

    private function readyDelivery(array $over = []): DeliveryStop
    {
        $customer = User::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Client TR', 'phone' => '0612345678']);
        $order = Order::factory()->create($over + [
            'branch_id' => $this->branch->id, 'user_id' => $customer->id,
            'order_type' => OrderType::DELIVERY, 'status' => OrderStatus::PREPARED,
            'payment_status' => PaymentStatus::PENDING_COUNTER, 'payment_method' => PaymentGateway::TICKET_RESTAURANT,
            'source_surface' => 'delivery', 'total' => 23.80, 'delivery_charge' => 3.90, 'fiscal_sequence_no' => null,
        ]);
        OrderAddress::query()->create([
            'order_id' => $order->id, 'user_id' => $customer->id, 'label' => 'x', 'address' => '10 Rue Test, Hénin-Beaumont',
            'latitude' => '50.43', 'longitude' => '2.96', 'city' => 'Hénin-Beaumont', 'postal_code' => '62110',
        ]);

        return app(DeliveryWaveService::class)->assign($order->fresh());
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function unpaidMealVoucherStates(): array
    {
        return [
            'commande du site acceptée (à encaisser)' => [PaymentStatus::PENDING_COUNTER, 'delivery'],
            'commande non encore basculée (impayée)'  => [PaymentStatus::UNPAID, 'delivery'],
        ];
    }

    /** @dataProvider unpaidMealVoucherStates */
    public function test_meal_voucher_delivery_is_not_paid_at_departure_and_is_sealed_with_its_payment_at_handover(int $paymentStatus, string $surface): void
    {
        $stop = $this->readyDelivery(['payment_status' => $paymentStatus, 'source_surface' => $surface]);

        $this->http()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => '2026-10-20 19:30:00'])
            ->assertOk()->assertJsonPath('left.0', $stop->order_id);

        $atDeparture = Order::query()->find($stop->order_id);
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $atDeparture->status);
        $this->assertNotSame(PaymentStatus::PAID, (int) $atDeparture->payment_status,
            'Au départ du livreur, le titre-restaurant n\'est PAS encore remis : la vente ne doit pas être PAYÉE.');
        $this->assertNull($atDeparture->fiscal_sequence_no, 'Aucun numéro fiscal avant l\'encaissement réel.');
        $this->assertSame(0, OrderPayment::query()->where('order_id', $stop->order_id)->count());

        $this->http()->postJson("/api/frontend/delivery-run/stops/{$stop->id}/deliver", ['collected_cents' => 2380])->assertOk();

        $delivered = Order::query()->find($stop->order_id);
        $this->assertSame(OrderStatus::DELIVERED, (int) $delivered->status);
        $this->assertSame(PaymentStatus::PAID, (int) $delivered->payment_status, 'Le titre remis à la porte solde la commande.');
        $this->assertNotNull($delivered->fiscal_sequence_no, 'La vente est scellée (NF525) à la remise.');

        $payments = OrderPayment::query()->where('order_id', $stop->order_id)->get();
        $this->assertCount(1, $payments, 'Exactement une tranche de paiement enregistrée à la remise.');
        $this->assertSame(PosPaymentMethod::TICKET_RESTAURANT, (int) $payments[0]->mode);
        $this->assertEqualsWithDelta(23.80, (float) $payments[0]->amount, 0.001);
        $this->assertSame((int) $this->branch->id, (int) $payments[0]->branch_id);
        $this->assertNotNull($payments[0]->paid_at);

        $audit = \App\Models\AuditLog::query()->where('action', 'order.payment_tranche_persisted')
            ->where('resource_id', $payments[0]->id)->first();
        $this->assertNotNull($audit, 'La tranche titre-restaurant laisse une trace d\'audit chaînée.');
        $this->assertSame(PosPaymentMethod::TICKET_RESTAURANT, (int) $audit->payload['mode']);
        $this->assertSame(0, \App\Models\AuditLog::query()->where('action', 'delivery.cash_collected_escrow')
            ->where('resource_id', $stop->order_id)->count(), 'Un titre-restaurant n\'est pas un encaissement d\'espèces.');
    }

    /**
     * De bout en bout, comme le site : commande de LIVRAISON en titre-restaurant prise par l'API (acceptée, chiffrée
     * par le serveur malgré des montants forgés), puis cuisine, départ (toujours NON payée), remise (payée, scellée,
     * une tranche titre-restaurant du total serveur).
     */
    public function test_web_meal_voucher_delivery_end_to_end_accepted_priced_by_server_and_paid_only_at_handover(): void
    {
        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30, 'order_setup_delivery' => 5, 'order_setup_takeaway' => 5,
        ]);
        $customer = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE, 'phone' => '0612345678']);
        $address = Address::query()->create([
            'user_id' => $customer->id, 'label' => 'Maison', 'address' => '10 Rue de Test',
            'latitude' => '50.43', 'longitude' => '2.96', 'geocode_status' => 'OK',
        ]);
        $tax = Tax::factory()->create(['tax_rate' => 0, 'type' => TaxType::PERCENTAGE, 'status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => ItemCategory::factory()->create(['status' => Status::ACTIVE])->id,
            'tax_id' => $tax->id, 'price' => 10.00, 'status' => Status::ACTIVE,
        ]);

        $created = $this->actingAs($customer, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/order', [
                'branch_id' => $this->branch->id, 'subtotal' => 10, 'discount' => 0,
                'delivery_charge' => 0.01, 'total' => 0.01,
                'order_type' => (string) OrderType::DELIVERY, 'is_advance_order' => Ask::NO,
                'address_id' => $address->id, 'delivery_time' => now()->addHour()->format('Y-m-d H:i:s'),
                'source' => Source::WEB, 'payment_method' => PaymentGateway::TICKET_RESTAURANT,
                'items' => json_encode([['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []]]),
            ]);
        $created->assertCreated();
        $orderId = (int) $created->json('data.id');

        $order = Order::query()->withoutGlobalScopes()->findOrFail($orderId);
        $this->assertSame(14.9, (float) $order->total, 'Total serveur (10 € + 4,90 €), jamais les 0,01 € forgés.');
        $this->assertNull($order->fiscal_sequence_no);

        // Acceptation (bascule « à encaisser ») puis cuisine terminée — ce que font la caisse et l'écran cuisine.
        $order->forceFill(['status' => OrderStatus::PREPARED, 'payment_status' => PaymentStatus::PENDING_COUNTER])->save();
        $stop = DeliveryStop::query()->where('order_id', $orderId)->first() ?? app(DeliveryWaveService::class)->assign($order->fresh());
        $this->assertSame((int) $this->courier->id, (int) $stop->delivery_boy_id, 'Seul livreur actif : rattaché automatiquement.');

        $this->http()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => $stop->wave_at->toDateTimeString()])
            ->assertOk()->assertJsonPath('left.0', $orderId);
        $atDeparture = Order::query()->withoutGlobalScopes()->find($orderId);
        $this->assertNotSame(PaymentStatus::PAID, (int) $atDeparture->payment_status);
        $this->assertNull($atDeparture->fiscal_sequence_no);
        $this->assertSame(0, OrderPayment::query()->withoutGlobalScopes()->where('order_id', $orderId)->count());

        $this->http()->postJson("/api/frontend/delivery-run/stops/{$stop->id}/deliver", ['collected_cents' => 1490])->assertOk();
        $done = Order::query()->withoutGlobalScopes()->find($orderId);
        $this->assertSame(OrderStatus::DELIVERED, (int) $done->status);
        $this->assertSame(PaymentStatus::PAID, (int) $done->payment_status);
        $this->assertNotNull($done->fiscal_sequence_no);
        $payment = OrderPayment::query()->withoutGlobalScopes()->where('order_id', $orderId)->sole();
        $this->assertSame(PosPaymentMethod::TICKET_RESTAURANT, (int) $payment->mode);
        $this->assertEqualsWithDelta(14.9, (float) $payment->amount, 0.001);
    }

    /** Le livreur voit un vrai mode « titre-restaurant » et le montant à récupérer, avant comme après son départ. */
    public function test_courier_is_told_to_collect_a_meal_voucher_and_the_amount_until_handover(): void
    {
        $stop = $this->readyDelivery();
        $this->assertSame('meal_voucher', $stop->collect_method, 'Mode noté à l\'affectation de la tournée.');
        $this->assertSame(2380, (int) $stop->amount_due_cents);

        $s = $this->http()->getJson('/api/frontend/delivery-run')->assertOk()->json('data.0.stops.0');
        $this->assertSame('meal_voucher', $s['collect_method']);
        $this->assertSame('Encaisser en titre-restaurant', $s['collect_label']);
        $this->assertSame(2380, $s['amount_due_cents']);

        $this->http()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => '2026-10-20 19:30:00'])->assertOk();
        $s = $this->http()->getJson('/api/frontend/delivery-run')->assertOk()->json('data.0.stops.0');
        $this->assertSame('Encaisser en titre-restaurant', $s['collect_label'], 'Après le départ, le titre reste À ENCAISSER (plus jamais « Déjà payé »).');
        $this->assertSame(2380, $s['amount_due_cents']);

        $this->http()->postJson("/api/frontend/delivery-run/stops/{$stop->id}/deliver", ['collected_cents' => 2380])
            ->assertOk()->assertJsonPath('data.collect_method', 'meal_voucher')->assertJsonPath('data.amount_due_cents', 2380);
        $this->assertSame(2380, (int) $stop->fresh()->amount_collected_cents);
    }

    /**
     * La caisse accepte déjà un titre-restaurant sur une livraison : vente PAYÉE et scellée AU COMPTOIR, sa tranche
     * déjà enregistrée. Le parcours livreur ne doit rien y changer (aucune 2ᵉ tranche, numéro fiscal intact).
     */
    public function test_meal_voucher_delivery_already_paid_at_the_counter_is_left_untouched(): void
    {
        $stop = $this->readyDelivery([
            'payment_status' => PaymentStatus::PAID, 'source_surface' => 'pos',
            'pos_payment_method' => PosPaymentMethod::TICKET_RESTAURANT, 'fiscal_sequence_no' => 4242,
        ]);
        OrderPayment::query()->create([
            'order_id' => $stop->order_id, 'branch_id' => $this->branch->id, 'mode' => PosPaymentMethod::TICKET_RESTAURANT,
            'amount' => 23.80, 'change_amount' => 0, 'paid_at' => now(),
        ]);

        $this->http()->getJson('/api/frontend/delivery-run')->assertOk()
            ->assertJsonPath('data.0.stops.0.collect_label', 'Déjà payé')
            ->assertJsonPath('data.0.stops.0.amount_due_cents', 0);

        $this->http()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => '2026-10-20 19:30:00'])->assertOk();
        $this->http()->postJson("/api/frontend/delivery-run/stops/{$stop->id}/deliver")->assertOk();

        $order = Order::query()->find($stop->order_id);
        $this->assertSame(OrderStatus::DELIVERED, (int) $order->status);
        $this->assertSame(PaymentStatus::PAID, (int) $order->payment_status);
        $this->assertSame(4242, (int) $order->fiscal_sequence_no);
        $this->assertSame(1, OrderPayment::query()->where('order_id', $stop->order_id)->count());
        $this->assertSame(0, (int) $stop->fresh()->amount_collected_cents);
    }
}
