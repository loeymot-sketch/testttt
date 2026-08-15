<?php

namespace Tests\Feature\Settings;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [T-5.3 D13 2026-08-15 · GOAL_CONFORT_MAX] Verrou de non-régression.
 *
 * Le registre des dangers du GOAL marquait D13 « OUVERT » — « payment-gateway
 * index expose la valeur secrète à tout utilisateur authentifié » — en citant
 * un audit du 2026-08-13. Vérification par lecture de code (2026-08-15) :
 * FAUX à l'état actuel. `PaymentGatewayController::__construct()` porte déjà
 * `$this->middleware(['permission:settings'])->only('index', 'update')`,
 * introduit par un heal antérieur (commentaire "[SET-01 heal 2026-06-01]").
 * Soit l'audit du 2026-08-13 a vérifié un état périmé, soit une lecture
 * erronée — dans les deux cas, le danger n'est PAS reproductible aujourd'hui.
 *
 * Ce test transforme la vérification ponctuelle en garde permanente : aucun
 * utilisateur sans la permission `settings` ne doit jamais pouvoir lire (ou
 * modifier) `admin/setting/payment-gateway`, qui expose des secrets bruts
 * (clés API Stripe/Mollie/SumUp…) via GatewayOptionsResource::value.
 */
class PaymentGatewaySecretExposureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_un_utilisateur_sans_la_permission_settings_ne_peut_pas_lire_les_secrets(): void
    {
        // Utilisateur de branche, sans rôle Admin — même patron que
        // InterrupteurTest::test_un_editeur_de_branche_ne_bascule_rien.
        $u = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);

        $this->actingAs($u, 'sanctum')
            ->getJson('/api/admin/setting/payment-gateway')
            ->assertForbidden();
    }

    public function test_un_utilisateur_sans_la_permission_settings_ne_peut_pas_modifier_les_gateways(): void
    {
        $u = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);

        $this->actingAs($u, 'sanctum')
            ->putJson('/api/admin/setting/payment-gateway', ['payment_type' => 'stripe'])
            ->assertForbidden();
    }

    public function test_un_admin_peut_lire_la_liste_des_gateways(): void
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Admin');

        $this->actingAs($u, 'sanctum')
            ->getJson('/api/admin/setting/payment-gateway')
            ->assertOk();
    }
}
