<?php

namespace Tests\Feature\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [T-5.2 CUMUL-NON-DATE 2026-08-15 · GOAL_CONFORT_MAX] `DashboardService::
 * totalSales()`/`totalOrders()` sommaient TOUTE la table `orders` sans aucun
 * filtre de date — un propriétaire au jour 500 d'exploitation lisait un
 * "Total ventes" qui ne disait rien de la journée en cours (le label "Total
 * ventes" était honnête, mais aucune tuile ne donnait le chiffre du JOUR).
 *
 * Fix additif : `period='today'` scope sur `business_date` (jour fiscal, pas
 * minuit UTC — Le Cayenne sert jusqu'à 00h30). `period` par défaut reste
 * `'all'` = comportement historique 100% inchangé pour tout appelant qui ne
 * passe pas ce paramètre (non-régression des tests Dashboard existants).
 */
class DashboardTilesArePeriodScopedTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->branch = Branch::factory()->create();
        $this->admin = User::factory()->create(['branch_id' => 0]);
        $this->admin->assignRole('Admin');
    }

    private function order(string $businessDate, float $total, array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'business_date' => $businessDate,
            'total' => $total,
            'status' => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'parent_order_id' => null,
        ], $overrides));
    }

    public function test_period_all_par_defaut_reste_le_cumul_historique_inchange(): void
    {
        $this->order(now()->toDateString(), 20.00);
        $this->order(now()->subDays(10)->toDateString(), 30.00);
        $this->order(now()->subDays(400)->toDateString(), 50.00);

        $resp = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/total-orders')
            ->assertOk();

        $this->assertSame(3, $resp->json('data.total_orders'), 'sans period=..., le cumul doit rester TOTAL (comportement historique)');
    }

    public function test_period_today_ne_compte_que_le_jour_fiscal_courant(): void
    {
        $this->order(now()->toDateString(), 20.00);
        $this->order(now()->toDateString(), 15.00);
        $this->order(now()->subDay()->toDateString(), 999.00);
        $this->order(now()->subDays(400)->toDateString(), 999.00);

        $ordersResp = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/total-orders?period=today')
            ->assertOk();
        $this->assertSame(2, $ordersResp->json('data.total_orders'), "period=today doit exclure hier et le passé lointain");

        $salesResp = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/total-sales?period=today')
            ->assertOk();
        // AppLibrary::currencyAmountFormat renvoie une chaîne formatée — on vérifie
        // que le vieux total (999+999=1998) n'y apparaît PAS et que 35 (20+15) est présent.
        $formatted = (string) $salesResp->json('data.total_sales');
        $this->assertStringNotContainsString('1998', $formatted, 'period=today ne doit JAMAIS inclure les commandes hors du jour fiscal courant');
        $this->assertStringContainsString('35', $formatted);
    }

    public function test_period_invalide_ou_absent_retombe_sur_all_jamais_une_erreur(): void
    {
        $this->order(now()->toDateString(), 10.00);
        $this->order(now()->subDays(5)->toDateString(), 10.00);

        $resp = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/total-orders?period=n-importe-quoi')
            ->assertOk();

        $this->assertSame(2, $resp->json('data.total_orders'), 'une valeur period inconnue doit retomber silencieusement sur all, pas planter');
    }
}
