<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Models\Branch;
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
 * [VG-2026-10-21 M4-005] Les tournées s'affichaient sans date : « Départ 23:30 » et « Départ 00:30 » étaient deux
 * tournées FUTURES (≈ 23 h et 24 h), lues comme celles du soir ; « Départ 18:00 parti » était celle du LENDEMAIN.
 * Les réponses serveur (écran livreur et planche « Tournées » de la caisse) portent désormais le jour.
 *
 * @group delivery
 */
class TourneesPortentLeurDateTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $courier;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        Role::firstOrCreate(['name' => 'Delivery Boy', 'guard_name' => 'sanctum']);
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key', 'delivery.horizon_days' => 3, 'kds.scheduled_window_open' => '18:00', 'kds.scheduled_window_close' => '00:30']);
        Carbon::setTestNow(Carbon::parse('2026-10-22 17:10:00'));
        $this->branch = Branch::factory()->create(['latitude' => '50.4215667', 'longitude' => '2.9549060']);
        $this->courier = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE]);
        $this->courier->assignRole('Delivery Boy');
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');
        $this->cashier->givePermissionTo('pos');

        foreach (['2026-10-22 19:30:00', '2026-10-23 00:30:00', '2026-10-24 18:00:00'] as $wave) {
            $c = User::factory()->create(['branch_id' => $this->branch->id]);
            $o = Order::factory()->create([
                'branch_id' => $this->branch->id, 'user_id' => $c->id, 'order_type' => OrderType::DELIVERY,
                'status' => OrderStatus::PREPARED, 'payment_status' => PaymentStatus::PAID, 'payment_method' => PaymentGateway::CARD,
            ]);
            OrderAddress::query()->create(['order_id' => $o->id, 'user_id' => $c->id, 'label' => 'x', 'address' => '1 rue', 'latitude' => '50.43', 'longitude' => '2.96']);
            app(DeliveryWaveService::class)->assign($o->fresh(), Carbon::parse($wave));
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(User $u): self
    {
        return $this->actingAs($u, 'sanctum')->withHeader('x-api-key', 'test-api-key')->withHeader('X-Idempotency-Key', (string) Str::uuid());
    }

    private function assertJours(array $waves): void
    {
        $this->assertSame(['2026-10-22', '2026-10-23', '2026-10-24'], array_column($waves, 'date'));
        $this->assertSame([true, false, false], array_column($waves, 'is_today'));
        $this->assertSame(["aujourd'hui", 'demain', 'sam. 24/10'], array_column($waves, 'day_label'));
        $this->assertSame(['19:30', '00:30', '18:00'], array_column($waves, 'time'), 'L\'heure seule reste servie, inchangée.');
    }

    public function test_l_ecran_livreur_recoit_le_jour_de_chaque_tournee(): void
    {
        $this->assertJours($this->as($this->courier)->getJson('/api/frontend/delivery-run')->assertOk()->json('data'));
    }

    public function test_la_planche_tournees_de_la_caisse_recoit_le_jour_de_chaque_tournee(): void
    {
        $this->assertJours($this->as($this->cashier)->getJson('/api/admin/pos-delivery/board')->assertOk()->json('data'));
    }
}
