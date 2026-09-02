<?php

namespace Tests\Feature\Dashboard;

use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Sub 3.2 · Codex P1-F]
 *
 * Le 29 août, `DashboardService::dashboardBranchId()` est passé en fail-closed : un
 * utilisateur non-Admin dont le `branch_id` vaut 0 est refusé (403) au lieu de voir
 * TOUTES les branches. Une carte du tableau de bord a été oubliée : `popular-items` ne
 * passe pas par le service, elle appelle directement `ItemService::mostPopularItems()`.
 *
 * Or `BranchScope::apply()` sort SANS filtrer dès que la branche de l'utilisateur vaut 0
 * (`BranchScope.php:33-35`, commentaire « Admin: no filter applied »). Le même compte qui
 * reçoit 403 sur les huit autres cartes recevait donc, sur celle-ci, le classement des
 * ventes de toutes les branches. Un trou d'isolation qui n'existe que sur une carte est
 * le plus difficile à voir : les autres écrans rassurent.
 *
 * En V1 mono-branche, l'effet est nul — il n'y a qu'une branche. C'est le jour d'un vrai
 * multi-succursales que ça compte, et ce jour-là personne ne relira cette ligne.
 */
class PopularItemsFailClosedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function commande(Branch $branche, Item $article, int $combien): void
    {
        for ($i = 0; $i < $combien; $i++) {
            $order = Order::factory()->create([
                'branch_id' => $branche->id,
                'payment_status' => PaymentStatus::PAID,
                'order_datetime' => now(),
            ]);
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'item_id' => $article->id,
                'branch_id' => $branche->id,
            ]);
        }
    }

    public function test_un_non_admin_sans_branche_est_refuse_comme_sur_les_autres_cartes(): void
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Branch Manager');

        $this->actingAs($u, 'sanctum')
            ->getJson('/api/admin/dashboard/popular-items')
            ->assertStatus(403);
    }

    public function test_un_admin_voit_le_classement_global(): void
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Admin');

        $this->actingAs($u, 'sanctum')
            ->getJson('/api/admin/dashboard/popular-items')
            ->assertOk();
    }

    public function test_un_responsable_de_branche_garde_l_acces_a_sa_branche(): void
    {
        $branche = Branch::factory()->create();
        $u = User::factory()->create(['branch_id' => $branche->id]);
        $u->assignRole('Branch Manager');

        $this->actingAs($u, 'sanctum')
            ->getJson('/api/admin/dashboard/popular-items')
            ->assertOk();
    }

    public function test_sans_la_permission_dashboard_l_acces_reste_refuse(): void
    {
        $branche = Branch::factory()->create();
        $u = User::factory()->create(['branch_id' => $branche->id]);

        $this->actingAs($u, 'sanctum')
            ->getJson('/api/admin/dashboard/popular-items')
            ->assertStatus(403);
    }
}
