<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use App\Services\OrderTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [E2E stores · revue adverse B2-R2-02 · 2026-10-01] Le suivi n'affiche un temps précis que si le
 * CAISSIER l'a choisi.
 *
 * Toute commande reçoit à sa création le temps de préparation par défaut des réglages (30 min en
 * production, mesuré le 01/10). Le suivi le prenait pour un temps fixé par la caisse : dès
 * l'acceptation, le client passait de « ~10-15 min » (décision propriétaire du 2026-09-23) à
 * « Prête dans ~30 min » — vu en E2E sur une commande prête 100 s plus tard. Le défaut était
 * masqué en production par un autre : le suivi répondait 500 pendant la préparation (1f33aef6e).
 */
class SuiviTempsDuCaissierSeulementTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Permission::firstOrCreate(['name' => 'online-orders', 'guard_name' => 'sanctum']);
        Permission::firstOrCreate(['name' => 'pos', 'guard_name' => 'sanctum']);
        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct() {}
            public function write(array $data): \App\Models\AuditLog { return new \App\Models\AuditLog(); }
        });
        $this->app->instance(FiscalSequenceService::class, new class(7000) extends FiscalSequenceService {
            private int $c;
            public function __construct(int $s) { $this->c = $s; }
            public function next(int $branchId): int { return ++$this->c; }
        });
        $this->branch = Branch::factory()->create(['status' => Status::ACTIVE]);
    }

    private function caissier(): User
    {
        $u = User::factory()->create(['branch_id' => $this->branch->id]);
        $u->assignRole('POS Operator');
        $u->givePermissionTo('online-orders');

        return $u->fresh();
    }

    private function commandeWeb(): Order
    {
        return Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'order_type'         => OrderType::TAKEAWAY,
            'source'             => Source::WEB,
            'source_surface'     => 'web',
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status'     => PaymentStatus::UNPAID,
            'pos_payment_method' => null,
            'status'             => OrderStatus::PENDING,
            // Le défaut des réglages, stampé à la création comme en production.
            'preparation_time'   => 30,
            'total'              => 9.70,
            'subtotal'           => 9.70,
        ]);
    }

    private function accepter(Order $commande, array $extra = []): void
    {
        $this->actingAs($this->caissier(), 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'acc-' . bin2hex(random_bytes(6))])
            ->postJson("/api/admin/online-order/change-status/{$commande->id}", ['status' => OrderStatus::ACCEPT] + $extra)
            ->assertStatus(200);
    }

    /** @test */
    public function acceptee_sans_temps_choisi_le_client_garde_la_fourchette_10_15(): void
    {
        $commande = $this->commandeWeb();
        $this->accepter($commande);
        $commande->refresh();

        $this->assertNull($commande->preparation_time_confirmed_at, 'Aucun temps choisi par le caissier.');
        $suivi = app(OrderTrackingService::class)->forOrder($commande);
        $this->assertSame(10, $suivi['wait_low'], 'Le défaut des réglages (30) ne doit pas devenir « ~30 min ».');
        $this->assertSame(15, $suivi['wait_high']);
    }

    /** @test */
    public function acceptee_avec_un_temps_choisi_le_client_voit_ce_temps(): void
    {
        $commande = $this->commandeWeb();
        $this->accepter($commande, ['preparation_time' => 25]);
        $commande->refresh();

        $this->assertNotNull($commande->preparation_time_confirmed_at);
        $suivi = app(OrderTrackingService::class)->forOrder($commande);
        $this->assertSame(25, $suivi['wait_low']);
        $this->assertSame(25, $suivi['wait_high']);
    }
}
