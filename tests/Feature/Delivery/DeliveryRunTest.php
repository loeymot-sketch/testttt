<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\DeliveryStop;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\User;
use App\Services\Delivery\DeliveryWaveService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Parcours du livreur : tournée → départ → arrivée (photo) → remise avec encaissement.
 *
 * @group delivery
 */
class DeliveryRunTest extends TestCase
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

    private function http(?User $as = null): self
    {
        return $this->actingAs($as ?? $this->courier, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid());
    }

    /** Commande de livraison PRÊTE (cuisine finie), rattachée à sa tournée. */
    private function readyDelivery(array $over = [], float $lat = 50.43, float $lng = 2.96): DeliveryStop
    {
        $customer = User::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Karim Test', 'phone' => '0612345678']);
        $order = Order::factory()->create($over + [
            'branch_id' => $this->branch->id, 'user_id' => $customer->id,
            'order_type' => OrderType::DELIVERY, 'status' => OrderStatus::PREPARED,
            'payment_status' => PaymentStatus::PAID, 'payment_method' => PaymentGateway::CARD,
            'total' => 25.00, 'delivery_charge' => 3.90,
        ]);
        OrderAddress::query()->create([
            'order_id' => $order->id, 'user_id' => $customer->id, 'label' => 'x', 'address' => '10 Rue Test, Hénin-Beaumont',
            'apartment' => '2B', 'latitude' => (string) $lat, 'longitude' => (string) $lng, 'city' => 'Hénin-Beaumont', 'postal_code' => '62110',
        ]);

        return app(DeliveryWaveService::class)->assign($order->fresh());
    }

    public function test_courier_sees_his_wave_with_waze_link_collection_and_eta(): void
    {
        $stop = $this->readyDelivery(['payment_status' => PaymentStatus::UNPAID, 'payment_method' => PaymentGateway::CASH_ON_DELIVERY, 'total' => 27.5]);

        $r = $this->http()->getJson('/api/frontend/delivery-run')->assertOk();

        $this->assertCount(1, $r->json('data'));
        $this->assertSame('19:30', $r->json('data.0.time'));
        $s = $r->json('data.0.stops.0');
        $this->assertSame($stop->id, $s['id']);
        $this->assertStringStartsWith('https://waze.com/ul?ll=50.43,2.96&navigate=yes', $s['waze_url']);
        $this->assertSame('cash', $s['collect_method']);
        $this->assertSame('Encaisser en espèces', $s['collect_label']);
        $this->assertEquals(27.5, $s['amount_due']);
        $this->assertSame('Karim Test', $s['customer_name']);
        $this->assertTrue($s['ready']);
    }

    public function test_full_run_depart_arrive_with_photo_then_deliver_and_cash_is_settled(): void
    {
        $stop = $this->readyDelivery(['payment_status' => PaymentStatus::UNPAID, 'payment_method' => PaymentGateway::CASH_ON_DELIVERY, 'total' => 27.5]);

        $this->http()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => '2026-10-20 19:30:00'])
            ->assertOk()->assertJsonPath('left.0', $stop->order_id);
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) Order::find($stop->order_id)->status);

        $this->http()->post("/api/frontend/delivery-run/stops/{$stop->id}/arrive", [
            'photo' => UploadedFile::fake()->image('porte.jpg', 800, 600), 'lat' => 50.4301, 'lng' => 2.9601,
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.has_photo', true);
        $stop->refresh();
        $this->assertNotNull($stop->arrived_at);
        Storage::disk('local')->assertExists($stop->proof_photo_path);

        $this->http()->postJson("/api/frontend/delivery-run/stops/{$stop->id}/deliver", ['collected_cents' => 2750])
            ->assertOk()->assertJsonPath('data.delivered_at', '2026-10-20 19:02:00');

        $order = Order::find($stop->order_id);
        $this->assertSame(OrderStatus::DELIVERED, (int) $order->status);
        $this->assertSame(PaymentStatus::PAID, (int) $order->payment_status, 'Le cash encaissé à la porte solde la commande.');
        $this->assertSame(2750, (int) $stop->fresh()->amount_collected_cents);
    }

    public function test_depart_leaves_orders_not_yet_prepared_behind_and_says_so(): void
    {
        $ready = $this->readyDelivery();
        $cooking = $this->readyDelivery(['status' => OrderStatus::PREPARING], 50.44, 2.97);

        $r = $this->http()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => '2026-10-20 19:30:00'])->assertOk();

        $this->assertSame([$ready->order_id], $r->json('left'));
        $this->assertSame([$cooking->order_id], $r->json('not_ready'));
        $this->assertNull($cooking->fresh()->left_at);
    }

    public function test_cannot_arrive_or_deliver_before_leaving(): void
    {
        $stop = $this->readyDelivery();

        $this->http()->postJson("/api/frontend/delivery-run/stops/{$stop->id}/arrive")->assertStatus(409);
        $this->http()->postJson("/api/frontend/delivery-run/stops/{$stop->id}/deliver")->assertStatus(409);
    }

    public function test_another_courier_cannot_act_on_or_see_my_stop_or_photo(): void
    {
        $stop = $this->readyDelivery();
        $this->http()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => '2026-10-20 19:30:00'])->assertOk();
        $this->http()->post("/api/frontend/delivery-run/stops/{$stop->id}/arrive", ['photo' => UploadedFile::fake()->image('p.jpg')], ['Accept' => 'application/json'])->assertOk();

        $other = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE]);
        $other->assignRole('Delivery Boy');

        $this->http($other)->postJson("/api/frontend/delivery-run/stops/{$stop->id}/deliver")->assertStatus(403);
        $this->http($other)->get("/api/frontend/delivery-run/stops/{$stop->id}/photo")->assertStatus(403);
        $this->assertCount(0, $this->http($other)->getJson('/api/frontend/delivery-run')->json('data'));
        $this->http()->get("/api/frontend/delivery-run/stops/{$stop->id}/photo")->assertOk();
    }

    public function test_photo_must_be_an_image(): void
    {
        $stop = $this->readyDelivery();
        $this->http()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => '2026-10-20 19:30:00'])->assertOk();

        $this->http()->post("/api/frontend/delivery-run/stops/{$stop->id}/arrive", [
            'photo' => UploadedFile::fake()->create('virus.php', 10, 'text/x-php'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertNull($stop->fresh()->arrived_at);
    }

    // ── relecture adverse ─────────────────────────────────────────────────────────────────────

    public function test_depart_is_all_or_nothing_when_one_order_is_assigned_to_someone_else(): void
    {
        $mine = $this->readyDelivery();
        $other = $this->readyDelivery([], 50.44, 2.97);
        $stranger = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE]);
        Order::query()->whereKey($other->order_id)->update(['delivery_boy_id' => $stranger->id]); // réattribuée côté commande

        $this->http()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => '2026-10-20 19:30:00'])->assertStatus(409);

        $this->assertSame(OrderStatus::PREPARED, (int) Order::find($mine->order_id)->status, 'Aucune commande n\'est partie.');
        $this->assertNull($mine->fresh()->left_at);
    }

    public function test_the_amount_to_collect_follows_the_live_payment_status(): void
    {
        $stop = $this->readyDelivery(['payment_status' => PaymentStatus::UNPAID, 'payment_method' => PaymentGateway::CARD, 'total' => 18.0]);
        $before = $this->http()->getJson('/api/frontend/delivery-run')->json('data.0.stops.0');
        $this->assertSame('card', $before['collect_method']);
        $this->assertEquals(18.0, $before['amount_due']);

        // Le client paie en ligne après la création de la commande.
        Order::query()->whereKey($stop->order_id)->update(['payment_status' => PaymentStatus::PAID]);

        $after = $this->http()->getJson('/api/frontend/delivery-run')->json('data.0.stops.0');
        $this->assertSame('paid_online', $after['collect_method']);
        $this->assertSame(0, $after['amount_due_cents'], 'Le livreur ne doit plus lire « Encaisser par carte ».');
    }

    public function test_an_svg_cannot_be_uploaded_as_the_arrival_photo(): void
    {
        $stop = $this->readyDelivery();
        $this->http()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => '2026-10-20 19:30:00'])->assertOk();

        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->http()->post("/api/frontend/delivery-run/stops/{$stop->id}/arrive", ['photo' => $svg], ['Accept' => 'application/json'])->assertStatus(422);
    }
}
