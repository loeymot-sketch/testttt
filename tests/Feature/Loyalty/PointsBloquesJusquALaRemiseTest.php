<?php

namespace Tests\Feature\Loyalty;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Events\OrderStatusChanged;
use App\Listeners\AwardLoyaltyPointsOnDelivery;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Loyalty\PosCustomerLookupService;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Support\Facades\DB;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [DÉCISION PROPRIÉTAIRE 2026-11-06 · « Bloquer jusqu'à la remise »] Les points d'une commande À DISTANCE (site,
 * application, livraison) sont crédités et visibles dès la validation, mais pas dépensables avant sa remise.
 * Ventes au comptoir : dépensables tout de suite. Filet : 24 h.
 */
class PointsBloquesJusquALaRemiseTest extends TestCase
{
    use RefreshDatabase, WithoutMiddleware;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => '123456', 'pos.manual_discount_enabled' => true]);
        Settings::group('loyalty_setup')->set([
            'loyalty_points_per_euro' => 10, 'loyalty_points_for_1_euro_discount' => 100, 'loyalty_min_redeem_points' => 100,
        ]);
        $this->branch = Branch::factory()->create();
        $this->withHeaders(['x-api-key' => '123456', 'Accept' => 'application/json']);
    }

    private function client(): User
    {
        $u = User::factory()->create(['branch_id' => 0, 'status' => \App\Enums\Status::ACTIVE, 'loyalty_points' => 0,
            'loyalty_code' => 'REM' . strtoupper(substr(uniqid(), -6))]);
        $u->assignRole('Customer');

        return $u;
    }

    private function commandeCreditee(User $u, string $surface, float $total = 100.00): Order
    {
        $o = Order::factory()->create([
            'branch_id' => $this->branch->id, 'user_id' => $u->id, 'order_type' => OrderType::TAKEAWAY,
            'source_surface' => $surface, 'payment_method' => PaymentGateway::CARD, 'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::PENDING, 'total' => $total, 'subtotal' => $total,
        ]);
        DB::table('orders')->where('id', $o->id)->update(['status' => OrderStatus::ACCEPT]);
        (new AwardLoyaltyPointsOnDelivery())->handle(new OrderStatusChanged($o->fresh(), OrderStatus::PENDING, OrderStatus::ACCEPT));

        return $o->fresh();
    }

    private function racheter(User $u, int $points)
    {
        return $this->actingAs($u->fresh(), 'sanctum')->postJson('/api/frontend/loyalty/redeem', ['code' => $u->loyalty_code, 'points' => $points]);
    }

    public function test_les_points_d_une_commande_a_distance_non_remise_ne_se_depensent_pas(): void
    {
        $u = $this->client();
        $this->commandeCreditee($u, 'web');
        $this->assertSame(1000, (int) $u->fresh()->loyalty_points, 'crédités et visibles dès la validation');
        $this->assertSame(1000, LoyaltyService::pointsEnAttenteDeRemise($u->id));
        $this->assertSame(0, LoyaltyService::pointsDepensables($u->id, 1000));

        $r = $this->racheter($u, 1000);

        $r->assertStatus(400);
        $this->assertStringContainsString('remise', (string) $r->json('message') . (string) $r->json('error') . $r->getContent());
        $this->assertSame(1000, (int) $u->fresh()->loyalty_points, 'rien n\'est débité');
    }

    public function test_a_la_remise_les_points_deviennent_depensables(): void
    {
        $u = $this->client();
        $o = $this->commandeCreditee($u, 'web');
        DB::table('orders')->where('id', $o->id)->update(['status' => OrderStatus::DELIVERED]);

        $this->assertSame(0, LoyaltyService::pointsEnAttenteDeRemise($u->id));
        $this->racheter($u, 1000)->assertStatus(200);
        $this->assertSame(0, (int) $u->fresh()->loyalty_points);
    }

    public function test_une_vente_au_comptoir_est_depensable_tout_de_suite(): void
    {
        $u = $this->client();
        $this->commandeCreditee($u, 'pos');

        $this->assertSame(0, LoyaltyService::pointsEnAttenteDeRemise($u->id));
        $this->racheter($u, 1000)->assertStatus(200);
    }

    public function test_filet_de_24_heures_pour_une_commande_jamais_marquee_remise(): void
    {
        $u = $this->client();
        $o = $this->commandeCreditee($u, 'delivery');
        DB::table('loyalty_transactions')->where('order_id', $o->id)->update(['created_at' => now()->subHours(25)]);

        $this->assertSame(0, LoyaltyService::pointsEnAttenteDeRemise($u->id));
    }

    public function test_profil_et_fiche_caisse_disent_ce_qui_attend(): void
    {
        $u = $this->client();
        $this->commandeCreditee($u, 'web');               // 1000 en attente
        $this->commandeCreditee($u, 'pos', 50.00);         // 500 dépensables

        $profil = $this->actingAs($u->fresh(), 'sanctum')->getJson('/api/profile')->assertOk()->json('data');
        $this->assertSame(1500, (int) $profil['loyalty_points']);
        $this->assertSame(1000, (int) $profil['loyalty_points_pending']);

        $fiche = app(PosCustomerLookupService::class)->presenter($u->fresh());
        $this->assertSame(1500, $fiche['balance']);
        $this->assertSame(500, $fiche['usable_points'], 'la caisse ne propose que les points dépensables');
        $this->assertSame(1000, $fiche['pending_points']);
    }
}
