<?php

namespace Tests\Feature\Dashboard;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [VG-2026-10-21 M9-104] Le cuisinier (rôle `Chef`) lisait le chiffre d'affaires, les meilleurs
 * clients (noms) et le journal d'audit : le tableau de bord n'exigeait que `permission:dashboard`,
 * que le rôle Chef détient pour son écran d'accueil. `docs/AUTHZ_MATRIX.md` limite le Chef au
 * KDS et à l'écran client.
 *
 * Contrat : les données COMMERCIALES du tableau de bord (argent, clients, audit, répartition par
 * canal) exigent en plus l'accès caisse (`pos`) ou les rapports de vente (`sales-report`). Le
 * chef garde ce que la matrice lui donne (KDS, OSS) et les tuiles d'exploitation (commandes,
 * produits, retards).
 */
class DashboardDonneesCommercialesHorsCuisineTest extends TestCase
{
    use RefreshDatabase;

    private const COMMERCIALES = [
        '/api/admin/dashboard/total-sales',
        '/api/admin/dashboard/sales-summary',
        '/api/admin/dashboard/realtime-report',
        '/api/admin/dashboard/top-customers',
        '/api/admin/dashboard/customer-states',
        '/api/admin/dashboard/total-customers',
        '/api/admin/dashboard/audit-trail',
        '/api/admin/dashboard/channel-statistics',
    ];

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        $this->branch = Branch::factory()->create();
    }

    private function userWithRole(string $role): User
    {
        $u = User::factory()->create(['branch_id' => $this->branch->id]);
        $u->assignRole($role);

        return $u;
    }

    private function lire(User $u, string $url)
    {
        return $this->actingAs($u, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson($url);
    }

    /** @test */
    public function le_chef_ne_lit_ni_le_chiffre_d_affaires_ni_les_clients_ni_l_audit(): void
    {
        $chef = $this->userWithRole('Chef');
        foreach (self::COMMERCIALES as $url) {
            $this->assertSame(403, $this->lire($chef, $url)->status(), "chef@ ne doit pas lire {$url}");
        }
    }

    /** @test */
    public function le_chef_garde_la_cuisine_l_ecran_client_et_les_tuiles_d_exploitation(): void
    {
        $chef = $this->userWithRole('Chef');
        foreach ([
            '/api/admin/kds-order',
            '/api/admin/oss-order',
            '/api/admin/dashboard/total-orders',
            '/api/admin/dashboard/popular-items',
            '/api/admin/dashboard/sla-alerts',
        ] as $url) {
            $this->assertSame(200, $this->lire($chef, $url)->status(), "chef@ doit garder {$url}");
        }
    }

    /** @test */
    public function l_admin_le_gerant_et_le_caissier_gardent_les_donnees_commerciales(): void
    {
        foreach (['Admin', 'Branch Manager', 'POS Operator'] as $role) {
            $u = $this->userWithRole($role);
            foreach (self::COMMERCIALES as $url) {
                $this->assertSame(200, $this->lire($u, $url)->status(), "{$role} doit garder {$url}");
            }
        }
    }
}
