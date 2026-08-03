<?php

namespace Tests\Feature\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * [NUIT-A 2026-07-03 — P3 perf] salesSummary::per_day_sales calculé par UNE requête GROUP BY (au lieu d'un
 * SUM par jour). Test de correction : les sommes par jour doivent être exactes (réalisées = PAID non annulé).
 */
class SalesSummaryPerDayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function seedOrder(int $branchId, string $parisDatetime, float $total): void
    {
        Order::factory()->create([
            'branch_id' => $branchId,
            'user_id' => User::factory()->create(['branch_id' => $branchId])->id,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::PREPARED, // réalisé (non annulé/rejeté/retourné)
            'order_datetime' => $parisDatetime,
            'total' => $total,
        ]);
    }

    /** @test */
    public function les_ventes_par_jour_sont_exactes_via_group_by(): void
    {
        $branch = Branch::factory()->create();
        // Admin (branch_id=0) → bypass BranchScope + dashboardBranchId=null (toutes branches).
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        // Jour A (2 commandes = 30.00), Jour B (1 commande = 12.50), Jour C (aucune = 0).
        $this->seedOrder($branch->id, '2026-06-10 12:00:00', 10.00);
        $this->seedOrder($branch->id, '2026-06-10 19:30:00', 20.00);
        $this->seedOrder($branch->id, '2026-06-11 13:00:00', 12.50);
        // Une commande ANNULÉE le jour A ne doit PAS compter (realizedRevenue l'exclut).
        Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['branch_id' => $branch->id])->id,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::CANCELED,
            'order_datetime' => '2026-06-10 15:00:00',
            'total' => 999.00,
        ]);

        $request = new Request(['first_date' => '2026-06-10', 'last_date' => '2026-06-12']);
        $summary = app(DashboardService::class)->salesSummary($request);

        // per_day_sales = [jourA, jourB, jourC] = [30.00, 12.50, 0.0]
        $this->assertSame([30.0, 12.5, 0.0], $summary['per_day_sales'], 'sommes par jour exactes (annulée exclue)');
    }
}
