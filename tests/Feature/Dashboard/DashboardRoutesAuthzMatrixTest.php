<?php

namespace Tests\Feature\Dashboard;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Sub 3.2 · Codex P2-A]
 *
 * Six routes du tableau de bord n'avaient AUCUN test HTTP direct : `order-statistics`,
 * `order-summary`, `customer-states`, `top-customers`, `popular-items` et `audit-trail`.
 * Elles étaient couvertes indirectement — par des tests de service, ou par des bancs qui
 * lisent le source — ce qui laisse passer précisément ce qu'on a trouvé sur
 * `popular-items` : une carte qui n'entre pas par le même chemin et perd le garde de
 * branche sans que rien ne rougisse.
 *
 * Cette matrice fixe les quatre réponses attendues sur chacune des six : non connecté,
 * connecté sans permission, non-Admin sans branche, Admin.
 */
class DashboardRoutesAuthzMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTES = [
        'order-statistics',
        'order-summary',
        'customer-states',
        'top-customers',
        'popular-items',
        'audit-trail',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_sans_authentification_les_six_routes_refusent(): void
    {
        foreach (self::ROUTES as $route) {
            $this->getJson('/api/admin/dashboard/'.$route)
                ->assertStatus(401, "{$route} doit refuser un appel non authentifié");
        }
    }

    public function test_sans_la_permission_dashboard_les_six_routes_refusent(): void
    {
        $u = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);

        foreach (self::ROUTES as $route) {
            $this->actingAs($u, 'sanctum')
                ->getJson('/api/admin/dashboard/'.$route)
                ->assertStatus(403, "{$route} doit exiger la permission dashboard");
        }
    }

    /**
     * Le cas du 29 août : un compte non-Admin dont la branche vaut 0 n'a PAS de portée
     * lisible. Ouvrir toutes les branches serait un fail-open ; les six doivent refuser.
     */
    public function test_un_non_admin_sans_branche_est_refuse_sur_les_six_routes(): void
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Branch Manager');

        foreach (self::ROUTES as $route) {
            $this->actingAs($u, 'sanctum')
                ->getJson('/api/admin/dashboard/'.$route)
                ->assertStatus(403, "{$route} doit refuser un non-Admin sans branche (fail-closed)");
        }
    }

    public function test_un_admin_lit_les_six_routes(): void
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Admin');

        foreach (self::ROUTES as $route) {
            $this->actingAs($u, 'sanctum')
                ->getJson('/api/admin/dashboard/'.$route)
                ->assertOk("{$route} doit répondre à un Admin");
        }
    }

    public function test_un_responsable_de_branche_lit_les_six_routes(): void
    {
        $u = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $u->assignRole('Branch Manager');

        foreach (self::ROUTES as $route) {
            $this->actingAs($u, 'sanctum')
                ->getJson('/api/admin/dashboard/'.$route)
                ->assertOk("{$route} doit répondre à un responsable de branche");
        }
    }
}
