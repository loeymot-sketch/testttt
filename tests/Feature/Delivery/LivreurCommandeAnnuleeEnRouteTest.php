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
use Tests\TestCase;

/**
 * [VG-2026-10-21 M4-102] Une commande annulée en caisse alors que le livreur est EN ROUTE restait affichée
 * « En route · Déjà payé » avec « Remis au client » (et « Encaisser en espèces » pour une commande à payer à
 * la porte) : le livreur remettait un repas remboursé, ou encaissait une commande annulée.
 *
 * Attendu : l'écran livreur reçoit `cancelled` et rien à encaisser ; le serveur refuse « Remis » (422).
 *
 * @group delivery
 */
class LivreurCommandeAnnuleeEnRouteTest extends TestCase
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

    /** Livraison à payer à la porte, partie, puis annulée en caisse (statut relu ensuite). */
    private function stopAnnuleEnRoute(int $statutFinal = OrderStatus::CANCELED): DeliveryStop
    {
        $customer = User::factory()->create(['branch_id' => $this->branch->id]);
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id, 'user_id' => $customer->id,
            'order_type' => OrderType::DELIVERY, 'status' => OrderStatus::PREPARED,
            'payment_status' => PaymentStatus::UNPAID, 'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'total' => 18.50, 'delivery_charge' => 3.90,
        ]);
        OrderAddress::query()->create([
            'order_id' => $order->id, 'user_id' => $customer->id, 'label' => 'x', 'address' => '10 Rue Test, Hénin-Beaumont',
            'latitude' => '50.43', 'longitude' => '2.96', 'city' => 'Hénin-Beaumont', 'postal_code' => '62110',
        ]);
        $stop = app(DeliveryWaveService::class)->assign($order->fresh());
        $this->http()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => $stop->wave_at->toDateTimeString()])->assertOk();
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $order->fresh()->status, 'prémisse : en route.');

        Order::query()->whereKey($order->id)->update(['status' => $statutFinal]); // annulée à la caisse pendant la tournée

        return $stop->fresh();
    }

    public function test_l_ecran_livreur_marque_la_commande_annulee_et_n_encaisse_rien(): void
    {
        $this->stopAnnuleEnRoute();

        $s = $this->http()->getJson('/api/frontend/delivery-run')->assertOk()->json('data.0.stops.0');

        $this->assertTrue($s['cancelled'], 'Le livreur doit savoir que la commande est annulée.');
        $this->assertSame(0, $s['amount_due_cents'], 'Rien à encaisser sur une commande annulée.');
        $this->assertSame('ANNULÉE — ne pas remettre', $s['collect_label']);
    }

    public function test_une_commande_refusee_est_traitee_comme_annulee(): void
    {
        $this->stopAnnuleEnRoute(OrderStatus::REJECTED);

        $s = $this->http()->getJson('/api/frontend/delivery-run')->json('data.0.stops.0');
        $this->assertTrue($s['cancelled']);
    }

    public function test_le_serveur_refuse_remis_au_client_sur_une_commande_annulee(): void
    {
        $stop = $this->stopAnnuleEnRoute();

        $r = $this->http()->postJson("/api/frontend/delivery-run/stops/{$stop->id}/deliver", ['collected_cents' => 1850]);

        $r->assertStatus(422);
        $this->assertSame('Commande annulée : ne la remettez pas, rapportez-la au restaurant.', $r->json('message'));
        $stop->refresh();
        $this->assertNull($stop->delivered_at);
        $this->assertSame(0, (int) $stop->amount_collected_cents, 'Aucun encaissement noté.');
        $this->assertSame(OrderStatus::CANCELED, (int) Order::find($stop->order_id)->status);
    }

    public function test_une_commande_en_route_non_annulee_n_est_pas_marquee(): void
    {
        $customer = User::factory()->create(['branch_id' => $this->branch->id]);
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id, 'user_id' => $customer->id,
            'order_type' => OrderType::DELIVERY, 'status' => OrderStatus::PREPARED,
            'payment_status' => PaymentStatus::PAID, 'payment_method' => PaymentGateway::CARD, 'total' => 12.0,
        ]);
        OrderAddress::query()->create([
            'order_id' => $order->id, 'user_id' => $customer->id, 'label' => 'x', 'address' => '1 Rue A, Hénin-Beaumont',
            'latitude' => '50.43', 'longitude' => '2.96', 'city' => 'Hénin-Beaumont', 'postal_code' => '62110',
        ]);
        $stop = app(DeliveryWaveService::class)->assign($order->fresh());
        $this->http()->postJson('/api/frontend/delivery-run/depart', ['wave_at' => $stop->wave_at->toDateTimeString()])->assertOk();

        $s = $this->http()->getJson('/api/frontend/delivery-run')->json('data.0.stops.0');
        $this->assertFalse($s['cancelled']);
        $this->assertSame('Déjà payé', $s['collect_label']);
    }
}
