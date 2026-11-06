<?php

namespace Tests\Feature\Delivery;

use App\Enums\Activity;
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
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DeliveryStop;
use App\Models\FrontendOrder;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [CARTE À LA LIVRAISON 2026-10-24 · décision propriétaire] Trois façons de payer une livraison du site :
 * en ligne (carte / Apple Pay, payment_method=4), espèces à la porte (payment_method=1, inchangé) et NOUVEAU
 * carte à la porte (payment_method=1 + door_payment='card' → orders.door_payment_mode='card').
 *
 * Le livreur encaisse AU TERMINAL DE PAIEMENT QU'IL PORTE (hors de notre système) ; le logiciel enregistre le mode et
 * le montant à la REMISE, comme pour les espèces et le titre-restaurant : jamais PAYÉ au départ, PAYÉ + numéro fiscal
 * + tranche order_payments CARD + audit à la remise, et AUCUN escrow espèces.
 *
 * @group delivery
 */
class DeliveryCardAtDoorTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $courier;
    private User $customer;
    private Address $address;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
        $this->seedSpatieRoles();
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Delivery Boy', 'guard_name' => 'sanctum']);
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key']);
        Carbon::setTestNow(Carbon::parse('2026-10-20 19:02:00'));
        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30, 'order_setup_delivery' => Activity::ENABLE, 'order_setup_takeaway' => Activity::ENABLE,
        ]);
        $this->branch = Branch::factory()->create(['latitude' => '50.4215667', 'longitude' => '2.9549060']);
        $this->courier = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE]);
        $this->courier->assignRole('Delivery Boy');
        $this->customer = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE, 'phone' => '0612345678']);
        $this->address = Address::query()->create([
            'user_id' => $this->customer->id, 'label' => 'Maison', 'address' => '10 Rue de Test',
            'latitude' => '50.43', 'longitude' => '2.96', 'geocode_status' => 'OK',
        ]);
        $tax = Tax::factory()->create(['tax_rate' => 0, 'type' => TaxType::PERCENTAGE, 'status' => Status::ACTIVE]);
        $this->item = Item::factory()->create([
            'item_category_id' => ItemCategory::factory()->create(['status' => Status::ACTIVE])->id,
            'tax_id' => $tax->id, 'price' => 10.00, 'status' => Status::ACTIVE,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function courierHttp(): self
    {
        return $this->actingAs($this->courier, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid());
    }

    /** Ce que fait le site : POST /api/frontend/order avec le jeton du client. */
    private function placeWebOrder(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->customer, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/order', $over + [
                'branch_id' => $this->branch->id, 'subtotal' => 10, 'discount' => 0,
                'delivery_charge' => 0.01, 'total' => 0.01,
                'order_type' => (string) OrderType::DELIVERY, 'is_advance_order' => Ask::NO,
                'address_id' => $this->address->id, 'delivery_time' => now()->addHour()->format('Y-m-d H:i:s'),
                'source' => Source::WEB, 'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
                'items' => json_encode([['item_id' => $this->item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []]]),
            ]);
    }

    /** Commande web acceptée puis cuisine terminée (ce que font la caisse et l'écran cuisine) → arrêt de tournée. */
    private function acceptedAndReady(int $orderId): DeliveryStop
    {
        $order = Order::query()->withoutGlobalScopes()->findOrFail($orderId);
        $order->forceFill(['status' => OrderStatus::PREPARED, 'payment_status' => PaymentStatus::PENDING_COUNTER])->save();

        return DeliveryStop::query()->where('order_id', $orderId)->first() ?? app(DeliveryWaveService::class)->assign($order->fresh());
    }

    // ── 1. Forgeages ────────────────────────────────────────────────────────────────────

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function forgedDoorPayments(): array
    {
        return [
            'à emporter + carte à la porte'       => [['order_type' => (string) OrderType::TAKEAWAY, 'door_payment' => 'card']],
            'carte EN LIGNE + carte à la porte'   => [['payment_method' => PaymentGateway::CARD, 'door_payment' => 'card']],
            'titre-restaurant + carte à la porte' => [['payment_method' => PaymentGateway::TICKET_RESTAURANT, 'door_payment' => 'card']],
            'valeur « cash »'                     => [['door_payment' => 'cash']],
            'valeur « x »'                        => [['door_payment' => 'x']],
            'valeur « CARD » (casse)'             => [['door_payment' => 'CARD']],
        ];
    }

    /** @dataProvider forgedDoorPayments */
    public function test_forged_door_payment_is_refused_with_a_french_message_and_creates_nothing(array $over): void
    {
        $before = FrontendOrder::withoutGlobalScopes()->count();

        $r = $this->placeWebOrder($over);

        $r->assertStatus(422)->assertJsonValidationErrors('door_payment');
        $message = (string) ($r->json('errors.door_payment.0') ?? '');
        $this->assertNotSame('', $message);
        $this->assertDoesNotMatchRegularExpression('/^validation\./', $message, 'Message traduit, jamais la clé brute.');
        $this->assertSame($before, FrontendOrder::withoutGlobalScopes()->count(), 'Aucune commande créée par un forgeage.');
    }

    public function test_cash_delivery_without_the_field_is_accepted_as_before_and_carries_no_door_mode(): void
    {
        $r = $this->placeWebOrder();

        $r->assertCreated();
        $order = Order::query()->withoutGlobalScopes()->findOrFail((int) $r->json('data.id'));
        $this->assertNull($order->door_payment_mode);
        $this->assertSame(PaymentGateway::CASH_ON_DELIVERY, (int) $order->payment_method);
    }

    public function test_card_at_door_delivery_is_accepted_priced_by_the_server_and_stores_the_mode(): void
    {
        $r = $this->placeWebOrder(['door_payment' => 'card']);

        $r->assertCreated();
        $order = Order::query()->withoutGlobalScopes()->findOrFail((int) $r->json('data.id'));
        $this->assertSame('card', $order->door_payment_mode);
        $this->assertSame(PaymentGateway::CASH_ON_DELIVERY, (int) $order->payment_method);
        $this->assertSame(14.9, (float) $order->total, 'Total serveur (10 € + 4,90 €), jamais les 0,01 € forgés.');
        $this->assertNull($order->fiscal_sequence_no);
        $this->assertNotSame(PaymentStatus::PAID, (int) $order->payment_status);
    }

    // ── 2. Chemin complet carte à la porte ──────────────────────────────────────────────

    public function test_card_at_door_end_to_end_is_unpaid_until_handover_then_sealed_as_card_without_any_cash_trail(): void
    {
        $orderId = (int) $this->placeWebOrder(['door_payment' => 'card'])->assertCreated()->json('data.id');
        $stop = $this->acceptedAndReady($orderId);

        // L'arrêt annonce « carte » et le montant total.
        $this->assertSame(DeliveryStop::COLLECT_CARD, $stop->collect_method);
        $this->assertSame(1490, (int) $stop->amount_due_cents);

        $this->courierHttp()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => $stop->wave_at->toDateTimeString()])
            ->assertOk()->assertJsonPath('left.0', $orderId);

        $atDeparture = Order::query()->withoutGlobalScopes()->find($orderId);
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $atDeparture->status);
        $this->assertNotSame(PaymentStatus::PAID, (int) $atDeparture->payment_status, 'Carte pas encore passée au terminal : jamais PAYÉ au départ.');
        $this->assertNull($atDeparture->fiscal_sequence_no, 'Aucun numéro fiscal avant l\'encaissement.');
        $this->assertSame(0, OrderPayment::query()->withoutGlobalScopes()->where('order_id', $orderId)->count());

        // Après le départ, l'écran livreur demande toujours d'encaisser par carte, montant total.
        $s = $this->courierHttp()->getJson('/api/frontend/delivery-run')->assertOk()->json('data.0.stops.0');
        $this->assertSame('card', $s['collect_method']);
        $this->assertSame('Encaisser par carte', $s['collect_label']);
        $this->assertSame(1490, $s['amount_due_cents']);

        $this->courierHttp()->postJson("/api/frontend/delivery-run/stops/{$stop->id}/deliver", ['collected_cents' => 1490])
            ->assertOk()->assertJsonPath('data.collect_method', 'card')->assertJsonPath('data.amount_due_cents', 1490);

        $done = Order::query()->withoutGlobalScopes()->find($orderId);
        $this->assertSame(OrderStatus::DELIVERED, (int) $done->status);
        $this->assertSame(PaymentStatus::PAID, (int) $done->payment_status);
        $this->assertNotNull($done->fiscal_sequence_no, 'Scellée (NF525) à la remise.');
        $this->assertSame(PosPaymentMethod::CARD, (int) $done->pos_payment_method);
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->where('branch_id', $this->branch->id)
            ->where('fiscal_sequence_no', $done->fiscal_sequence_no)->count(), 'Numéro fiscal posé UNE fois.');

        $payment = OrderPayment::query()->withoutGlobalScopes()->where('order_id', $orderId)->sole();
        $this->assertSame(PosPaymentMethod::CARD, (int) $payment->mode);
        $this->assertEqualsWithDelta(14.9, (float) $payment->amount, 0.001);
        $this->assertSame((int) $this->branch->id, (int) $payment->branch_id);
        $this->assertSame('livraison', $payment->reference);
        $this->assertNotNull($payment->paid_at);

        $audit = AuditLog::query()->where('action', 'order.payment_tranche_persisted')->where('resource_id', $payment->id)->first();
        $this->assertNotNull($audit, 'La tranche carte laisse une trace d\'audit chaînée.');
        $this->assertSame('doorstep_card_collection', $audit->payload['event']);
        $this->assertSame(PosPaymentMethod::CARD, (int) $audit->payload['mode']);

        // Aucune trace d'espèces : ni escrow, ni mouvement de caisse, ni de livreur.
        $this->assertSame(0, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->count());
        $this->assertSame(0, DB::table('cash_movements')->count());
        $this->assertSame(0, DB::table('delivery_boy_cash_movements')->count());

        $this->assertSame(1490, (int) $stop->fresh()->amount_collected_cents, 'Le livreur a confirmé le montant encaissé au terminal.');
    }

    public function test_card_at_door_cannot_be_delivered_twice(): void
    {
        $orderId = (int) $this->placeWebOrder(['door_payment' => 'card'])->assertCreated()->json('data.id');
        $stop = $this->acceptedAndReady($orderId);
        $this->courierHttp()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => $stop->wave_at->toDateTimeString()])->assertOk();
        $this->courierHttp()->postJson("/api/frontend/delivery-run/stops/{$stop->id}/deliver")->assertOk();
        $this->courierHttp()->postJson("/api/frontend/delivery-run/stops/{$stop->id}/deliver")->assertStatus(409);

        $this->assertSame(1, OrderPayment::query()->withoutGlobalScopes()->where('order_id', $orderId)->count(), 'Jamais deux tranches.');
    }

    // ── 3. Régression espèces ───────────────────────────────────────────────────────────

    public function test_cash_at_door_still_writes_the_cash_escrow_and_no_card_line(): void
    {
        $orderId = (int) $this->placeWebOrder()->assertCreated()->json('data.id');
        $stop = $this->acceptedAndReady($orderId);
        $this->assertSame(DeliveryStop::COLLECT_CASH, $stop->collect_method);
        $this->assertSame(1490, (int) $stop->amount_due_cents);

        $this->courierHttp()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => $stop->wave_at->toDateTimeString()])->assertOk();
        $this->assertNotSame(PaymentStatus::PAID, (int) Order::query()->withoutGlobalScopes()->find($orderId)->payment_status);

        $this->courierHttp()->postJson("/api/frontend/delivery-run/stops/{$stop->id}/deliver", ['collected_cents' => 1490])->assertOk();

        $done = Order::query()->withoutGlobalScopes()->find($orderId);
        $this->assertSame(PaymentStatus::PAID, (int) $done->payment_status);
        $this->assertNotNull($done->fiscal_sequence_no);
        $this->assertSame(PosPaymentMethod::CASH, (int) $done->pos_payment_method);
        $this->assertSame(0, OrderPayment::query()->withoutGlobalScopes()->where('order_id', $orderId)->where('mode', PosPaymentMethod::CARD)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'delivery.cash_collected_escrow')->where('resource_id', $orderId)->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'order.payment_tranche_persisted')->count());
    }

    // ── 4. Carte en ligne / titre inchangés ─────────────────────────────────────────────

    private function webOrderRow(array $over): DeliveryStop
    {
        $order = Order::factory()->create($over + [
            'branch_id' => $this->branch->id, 'user_id' => $this->customer->id,
            'order_type' => OrderType::DELIVERY, 'status' => OrderStatus::PREPARED,
            'payment_status' => PaymentStatus::PENDING_COUNTER, 'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'source_surface' => 'delivery', 'total' => 23.80, 'delivery_charge' => 3.90, 'fiscal_sequence_no' => null,
        ]);
        OrderAddress::query()->create([
            'order_id' => $order->id, 'user_id' => $this->customer->id, 'label' => 'x', 'address' => '10 Rue Test, Hénin-Beaumont',
            'latitude' => '50.43', 'longitude' => '2.96', 'city' => 'Hénin-Beaumont', 'postal_code' => '62110',
        ]);

        return app(DeliveryWaveService::class)->assign($order->fresh());
    }

    public function test_online_card_already_paid_is_left_untouched_by_the_courier_run(): void
    {
        $stop = $this->webOrderRow([
            'payment_method' => PaymentGateway::CARD, 'payment_status' => PaymentStatus::PAID,
            'pos_payment_method' => null, 'fiscal_sequence_no' => 5150,
        ]);
        $this->assertSame(DeliveryStop::COLLECT_PAID_ONLINE, $stop->collect_method);
        $this->assertSame(0, (int) $stop->amount_due_cents);

        $this->courierHttp()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => $stop->wave_at->toDateTimeString()])->assertOk();
        $this->courierHttp()->postJson("/api/frontend/delivery-run/stops/{$stop->id}/deliver")->assertOk();

        $order = Order::query()->withoutGlobalScopes()->find($stop->order_id);
        $this->assertSame(5150, (int) $order->fiscal_sequence_no);
        $this->assertNull($order->door_payment_mode);
        $this->assertSame(0, OrderPayment::query()->withoutGlobalScopes()->where('order_id', $stop->order_id)->count());
        $this->assertSame(0, (int) $stop->fresh()->amount_collected_cents);
    }

    public function test_online_card_intent_without_door_mode_keeps_its_historic_card_collect_label(): void
    {
        $stop = $this->webOrderRow(['payment_method' => PaymentGateway::CARD, 'payment_status' => PaymentStatus::UNPAID]);

        $this->assertSame(DeliveryStop::COLLECT_CARD, $stop->collect_method);
        $this->assertNull(Order::query()->withoutGlobalScopes()->find($stop->order_id)->door_payment_mode);
    }

    public function test_door_mode_card_on_a_cash_order_already_paid_is_collected_as_paid_online(): void
    {
        // Le montant relu « en direct » reste prioritaire : déjà PAYÉE (par la caisse) = rien à encaisser.
        $stop = $this->webOrderRow([
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY, 'door_payment_mode' => 'card',
            'payment_status' => PaymentStatus::PAID, 'fiscal_sequence_no' => 777,
        ]);

        $this->assertSame(DeliveryStop::COLLECT_PAID_ONLINE, $stop->collect_method);
        $this->assertSame(0, (int) $stop->amount_due_cents);
    }

    public function test_door_mode_is_ignored_for_a_non_cod_method(): void
    {
        // Défense en profondeur : un marqueur posé sur une commande titre-restaurant n'a aucun effet.
        $stop = $this->webOrderRow(['payment_method' => PaymentGateway::TICKET_RESTAURANT, 'door_payment_mode' => 'card']);

        $this->assertSame(DeliveryWaveService::COLLECT_MEAL_VOUCHER, $stop->collect_method);
    }

    // ── 5. Exposition au site ───────────────────────────────────────────────────────────

    public function test_order_show_exposes_door_payment_mode_for_card_and_null_otherwise(): void
    {
        $cardId = (int) $this->placeWebOrder(['door_payment' => 'card'])->assertCreated()->json('data.id');
        $cashId = (int) $this->placeWebOrder()->assertCreated()->json('data.id');

        $card = $this->actingAs($this->customer, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->getJson("/api/frontend/order/show/{$cardId}")->assertOk();
        $this->assertSame('card', $card->json('data.door_payment_mode'));
        $this->assertSame(PaymentGateway::CASH_ON_DELIVERY, (int) $card->json('data.payment_method'), 'Aucun champ retiré.');

        $cash = $this->actingAs($this->customer, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->getJson("/api/frontend/order/show/{$cashId}")->assertOk();
        $this->assertArrayHasKey('door_payment_mode', $cash->json('data'));
        $this->assertNull($cash->json('data.door_payment_mode'));
    }

    // ── 6. Devis : préparation annoncée ─────────────────────────────────────────────────

    public function test_public_quote_announces_the_preparation_range(): void
    {
        Http::fake(['api-adresse.data.gouv.fr/*' => Http::response(['features' => [[
            'geometry' => ['coordinates' => [2.96, 50.43]],
            'properties' => ['label' => '10 Rue Test 62110 Hénin-Beaumont', 'postcode' => '62110', 'city' => 'Hénin-Beaumont', 'score' => 0.95, 'housenumber' => '10', 'street' => 'Rue Test'],
        ]]])]);

        $r = $this->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/delivery/quote', ['q' => '10 rue test henin beaumont'])->assertOk();

        $this->assertTrue($r->json('allowed'));
        $this->assertSame(15, $r->json('prep_minutes_low'));
        $this->assertSame(20, $r->json('prep_minutes_high'));
    }

    public function test_announced_preparation_never_exceeds_the_minimum_lead_before_the_first_departure(): void
    {
        $low = (int) config('delivery.prep_minutes.low');
        $high = (int) config('delivery.prep_minutes.high');
        $lead = (int) config('delivery.min_lead_minutes');

        $this->assertGreaterThan(0, $low, 'prep_minutes.low doit exister dans config/delivery.php.');
        $this->assertLessThanOrEqual($high, $low, 'La fourchette est ordonnée.');
        $this->assertLessThanOrEqual($lead, $high,
            'Le premier départ proposé (min_lead_minutes) ne peut pas promettre une préparation plus courte que celle annoncée.');
    }
}
