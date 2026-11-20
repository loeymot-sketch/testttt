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
use App\Services\OrderTrackingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * [VG-2026-10-21 M4-004 = M2-003] « Je pars » était accepté jusqu'à 25 h avant la tournée : la tournée du
 * 23/10 18:00 est partie le 22/10 à 17:10, ce qui FERMAIT ce départ de 30 min pour toute nouvelle commande,
 * et l'ETA affichée restait calculée sur l'heure théorique (« 18:32 » pour un livreur parti la veille).
 *
 * Règle : départ accepté au plus tôt `delivery.depart_max_advance_minutes` (45 par défaut) avant l'heure de
 * la tournée ; au-delà 422 « Départ possible à partir de HH:MM » ; l'ETA d'un départ réel part de l'heure
 * de départ réelle.
 *
 * @group delivery
 */
class DepartLivreurBorneAvantTourneeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $courier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Delivery Boy', 'guard_name' => 'sanctum']);
        $this->seedMinimalSettings();
        config([
            'app.api_key' => 'test-api-key',
            'delivery.depart_max_advance_minutes' => 45,
            'kds.scheduled_window_open' => '18:00',
            'kds.scheduled_window_close' => '00:30',
        ]);
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

    private function readyDeliveryOn(string $wave): DeliveryStop
    {
        $customer = User::factory()->create(['branch_id' => $this->branch->id]);
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id, 'user_id' => $customer->id,
            'order_type' => OrderType::DELIVERY, 'status' => OrderStatus::PREPARED,
            'payment_status' => PaymentStatus::PAID, 'payment_method' => PaymentGateway::CARD,
            'total' => 20.00, 'delivery_charge' => 3.90,
        ]);
        OrderAddress::query()->create([
            'order_id' => $order->id, 'user_id' => $customer->id, 'label' => 'x', 'address' => '10 Rue Test, Hénin-Beaumont',
            'latitude' => '50.43', 'longitude' => '2.96', 'city' => 'Hénin-Beaumont', 'postal_code' => '62110',
        ]);

        return app(DeliveryWaveService::class)->assign($order->fresh(), Carbon::parse($wave));
    }

    public function test_depart_la_veille_est_refuse_et_rien_ne_part(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 17:10:00'));
        $stop = $this->readyDeliveryOn('2026-10-23 18:00:00');

        $r = $this->http()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => '2026-10-23 18:00:00']);

        $r->assertStatus(422);
        $this->assertSame('Départ possible le 23/10 à partir de 17:15.', $r->json('message'));
        $this->assertNull($stop->fresh()->left_at, 'Aucun arrêt ne doit partir.');
        $this->assertSame(OrderStatus::PREPARED, (int) Order::find($stop->order_id)->status);
        $this->assertFalse(app(DeliveryWaveService::class)->waveHasLeft($this->branch->id, Carbon::parse('2026-10-23 18:00:00')),
            'La tournée ne doit pas être fermée par un départ refusé.');
    }

    public function test_depart_trop_tot_le_jour_meme_dit_l_heure(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 18:40:00'));
        $stop = $this->readyDeliveryOn('2026-10-22 19:30:00');

        $r = $this->http()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => '2026-10-22 19:30:00']);

        $r->assertStatus(422);
        $this->assertSame('Départ possible à partir de 18:45.', $r->json('message'));
        $this->assertNull($stop->fresh()->left_at);
    }

    public function test_depart_a_la_borne_exacte_est_accepte(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 18:40:00'));
        $stop = $this->readyDeliveryOn('2026-10-22 19:30:00');
        Carbon::setTestNow(Carbon::parse('2026-10-22 18:45:00'));

        $this->http()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => '2026-10-22 19:30:00'])
            ->assertOk()->assertJsonPath('left.0', $stop->order_id);
    }

    public function test_l_eta_d_un_depart_reel_part_de_l_heure_de_depart_reelle(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 18:40:00'));
        $stop = $this->readyDeliveryOn('2026-10-22 19:30:00');
        $eta = (int) $stop->fresh()->eta_minutes;
        $this->assertGreaterThan(0, $eta, 'prémisse : une ETA estimée existe.');

        Carbon::setTestNow(Carbon::parse('2026-10-22 18:50:00'));
        $this->http()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => '2026-10-22 19:30:00'])->assertOk();

        $s = $this->http()->getJson('/api/frontend/delivery-run')->json('data.0.stops.0');
        $this->assertSame(Carbon::parse('2026-10-22 18:50:00')->addMinutes($eta)->toDateTimeString(), $s['eta_at'],
            'Parti à 18:50 : l\'ETA se compte depuis 18:50, pas depuis 19:30.');
    }

    public function test_le_suivi_client_n_annonce_plus_le_depart_theorique_une_fois_parti(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 18:40:00'));
        $stop = $this->readyDeliveryOn('2026-10-22 19:30:00');
        Carbon::setTestNow(Carbon::parse('2026-10-22 18:50:00'));
        $this->http()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => '2026-10-22 19:30:00'])->assertOk();

        $t = app(OrderTrackingService::class)->forOrder(Order::find($stop->order_id)->fresh());
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, $t['status']);
        $this->assertNull($t['prevue_pour'], 'Le livreur est parti : « départ prévu 19:30 » serait faux.');
    }

    public function test_la_borne_est_reglable(): void
    {
        config(['delivery.depart_max_advance_minutes' => 10]);
        Carbon::setTestNow(Carbon::parse('2026-10-22 19:10:00'));
        $this->readyDeliveryOn('2026-10-22 19:30:00');

        $r = $this->http()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => '2026-10-22 19:30:00']);
        $r->assertStatus(422);
        $this->assertSame('Départ possible à partir de 19:20.', $r->json('message'));
    }
}
