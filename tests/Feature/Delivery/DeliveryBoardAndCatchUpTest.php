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
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tableau des tournées de la caisse, désignation du livreur, et filet de rattrapage.
 *
 * @group delivery
 */
class DeliveryBoardAndCatchUpTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        Role::firstOrCreate(['name' => 'Delivery Boy', 'guard_name' => 'sanctum']);
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key']);
        Carbon::setTestNow(Carbon::parse('2026-10-20 19:02:00'));
        $this->branch = Branch::factory()->create(['latitude' => '50.4215667', 'longitude' => '2.9549060']);
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');
        $this->cashier->givePermissionTo('pos');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function courier(string $name): User
    {
        $u = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE, 'name' => $name]);
        $u->assignRole('Delivery Boy');

        return $u;
    }

    private function order(): Order
    {
        $c = User::factory()->create(['branch_id' => $this->branch->id]);
        $o = Order::factory()->create([
            'branch_id' => $this->branch->id, 'user_id' => $c->id, 'order_type' => OrderType::DELIVERY,
            'status' => OrderStatus::PREPARED, 'payment_status' => PaymentStatus::PAID, 'payment_method' => PaymentGateway::CARD,
        ]);
        OrderAddress::query()->create(['order_id' => $o->id, 'user_id' => $c->id, 'label' => 'x', 'address' => '1 rue', 'latitude' => '50.43', 'longitude' => '2.96']);

        return $o->fresh();
    }

    private function pos()
    {
        return $this->actingAs($this->cashier, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid());
    }

    public function test_the_single_courier_is_attached_automatically(): void
    {
        $kiki = $this->courier('Kiki');
        $stop = app(DeliveryWaveService::class)->assign($this->order());

        $this->assertSame($kiki->id, $stop->delivery_boy_id);
        $this->assertSame($kiki->id, (int) Order::find($stop->order_id)->delivery_boy_id);
    }

    public function test_with_two_couriers_nobody_is_chosen_silently_and_the_cashier_assigns(): void
    {
        $this->courier('Kiki');
        $sam = $this->courier('Sam');
        $stop = app(DeliveryWaveService::class)->assign($this->order());
        $this->assertNull($stop->delivery_boy_id, 'Deux livreurs : jamais de choix silencieux.');

        $this->pos()->postJson('/api/admin/pos-delivery/waves/assign', ['wave_at' => $stop->wave_at->toDateTimeString(), 'delivery_boy_id' => $sam->id])
            ->assertOk()->assertJsonPath('assigned', 1);

        $this->assertSame($sam->id, $stop->fresh()->delivery_boy_id);
        $this->assertSame($sam->id, (int) Order::find($stop->order_id)->delivery_boy_id);
    }

    public function test_assigning_a_non_courier_is_refused(): void
    {
        $stop = app(DeliveryWaveService::class)->assign($this->order());
        $this->pos()->postJson('/api/admin/pos-delivery/waves/assign', ['wave_at' => $stop->wave_at->toDateTimeString(), 'delivery_boy_id' => $this->cashier->id])
            ->assertStatus(422);
    }

    public function test_board_lists_waves_with_courier_and_stops(): void
    {
        $this->courier('Kiki');
        app(DeliveryWaveService::class)->assign($this->order());

        $r = $this->pos()->getJson('/api/admin/pos-delivery/board')->assertOk();

        $this->assertSame('19:30', $r->json('data.0.time'));
        $this->assertSame('Kiki', $r->json('data.0.courier'));
        $this->assertCount(1, $r->json('data.0.stops'));
    }

    /** [ÉTAT DES LIEUX 2026-10-23 P3] Un départ dont l'heure est passée sans être parti n'est plus « à venir » : `overdue`. */
    public function test_a_wave_past_its_hour_and_not_left_is_flagged_overdue(): void
    {
        $this->courier('Kiki');
        $stop = app(DeliveryWaveService::class)->assign($this->order()); // départ 19:30, « maintenant » = 19:02

        $this->assertFalse($this->pos()->getJson('/api/admin/pos-delivery/board')->assertOk()->json('data.0.overdue'));

        Carbon::setTestNow(Carbon::parse('2026-10-20 19:41:00'));
        $this->assertTrue($this->pos()->getJson('/api/admin/pos-delivery/board')->json('data.0.overdue'), 'Parti à 19:30 ? non : en retard.');

        $stop->forceFill(['left_at' => Carbon::now()])->save();
        $this->assertFalse($this->pos()->getJson('/api/admin/pos-delivery/board')->json('data.0.overdue'), 'Une tournée partie n\'est jamais en retard.');
        $this->assertTrue($this->pos()->getJson('/api/admin/pos-delivery/board')->json('data.0.left'));
    }

    public function test_catch_up_command_attaches_orphan_delivery_orders_only(): void
    {
        $orphan = $this->order();
        $already = $this->order();
        app(DeliveryWaveService::class)->assign($already);
        $takeaway = Order::factory()->create(['branch_id' => $this->branch->id, 'order_type' => OrderType::TAKEAWAY]);

        $this->artisan('delivery:assign-pending')->assertExitCode(0);

        $this->assertTrue(DeliveryStop::query()->where('order_id', $orphan->id)->exists());
        $this->assertSame(1, DeliveryStop::query()->where('order_id', $already->id)->count());
        $this->assertFalse(DeliveryStop::query()->where('order_id', $takeaway->id)->exists());
    }

    // ── relecture adverse ─────────────────────────────────────────────────────────────────────

    public function test_a_cancelled_order_releases_its_place_in_the_wave(): void
    {
        config(['delivery.max_stops_per_wave' => 2]);
        $svc = app(DeliveryWaveService::class);
        $a = $this->order();
        $b = $this->order();
        $svc->assign($a);
        $svc->assign($b);

        $a->update(['status' => OrderStatus::CANCELED]);
        \App\Events\OrderStatusChanged::dispatch($a->fresh(), OrderStatus::PREPARED, OrderStatus::CANCELED);

        $this->assertFalse(DeliveryStop::query()->where('order_id', $a->id)->exists(), 'La place est libérée.');
        $c = $svc->assign($this->order());
        $this->assertSame('19:30', $c->wave_at->format('H:i'), 'La tournée n\'est plus pleine : la commande suivante la rejoint.');
    }

    public function test_the_order_canceled_event_alone_also_releases_the_place(): void
    {
        $o = $this->order();
        app(DeliveryWaveService::class)->assign($o);

        \App\Events\OrderCanceled::dispatch($o->fresh()); // voie d'annulation de la caisse, sans changement de statut annoncé

        $this->assertFalse(DeliveryStop::query()->where('order_id', $o->id)->exists());
    }

    public function test_a_stop_whose_courier_already_left_is_never_deleted_by_a_cancellation(): void
    {
        $o = $this->order();
        $stop = app(DeliveryWaveService::class)->assign($o);
        $stop->update(['left_at' => now()]);

        \App\Events\OrderCanceled::dispatch($o->fresh());

        $this->assertTrue(DeliveryStop::query()->where('order_id', $o->id)->exists());
    }

    public function test_catch_up_skips_an_unpaid_online_card_order_and_cleans_cancelled_stops(): void
    {
        $unpaidCard = $this->order();
        $unpaidCard->update(['payment_status' => PaymentStatus::UNPAID, 'payment_method' => PaymentGateway::CARD]);
        $cod = $this->order();
        $cod->update(['payment_status' => PaymentStatus::UNPAID, 'payment_method' => PaymentGateway::CASH_ON_DELIVERY]);
        $cancelled = $this->order();
        $svc = app(DeliveryWaveService::class);
        $svc->assign($cancelled);
        $cancelled->update(['status' => OrderStatus::CANCELED]);

        $this->artisan('delivery:assign-pending')->assertExitCode(0);

        $this->assertFalse(DeliveryStop::query()->where('order_id', $unpaidCard->id)->exists(), 'Carte en ligne non payée : pas avant paiement.');
        $this->assertTrue(DeliveryStop::query()->where('order_id', $cod->id)->exists());
        $this->assertFalse(DeliveryStop::query()->where('order_id', $cancelled->id)->exists(), 'Auto-nettoyage d\'une commande annulée.');
    }

    public function test_the_legacy_assignment_screen_keeps_the_stop_and_the_order_in_sync(): void
    {
        $kiki = $this->courier('Kiki');
        $sam = $this->courier('Sam');
        $stop = app(DeliveryWaveService::class)->assign($this->order());
        $this->assertNull($stop->delivery_boy_id);

        $this->cashier->givePermissionTo(['pos-orders']);
        $this->pos()->postJson('/api/admin/pos-order/select-delivery-boy/' . $stop->order_id, ['delivery_boy_id' => $sam->id])
            ->assertSuccessful();

        $this->assertSame($sam->id, (int) Order::find($stop->order_id)->delivery_boy_id);
        $this->assertSame($sam->id, (int) $stop->fresh()->delivery_boy_id, 'Le livreur B choisi dans l\'ancien écran doit voir son arrêt.');
    }
}
