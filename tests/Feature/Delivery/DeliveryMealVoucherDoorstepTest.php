<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\DeliveryStop;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderPayment;
use App\Models\User;
use App\Services\Delivery\DeliveryWaveService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
