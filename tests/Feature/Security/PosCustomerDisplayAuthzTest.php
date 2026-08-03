<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [NUIT-A2 2026-07-03 — P3 authz parity] La route POS customer-display doit exiger `permission:pos`
 * comme toutes les autres routes POS. Sans ça, un staff sans droit POS (Chef/KDS) pouvait pousser un
 * total arbitraire sur l'afficheur client.
 */
class PosCustomerDisplayAuthzTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Permission::firstOrCreate(['name' => 'pos', 'guard_name' => 'sanctum']);
    }

    private function postDisplay(User $actor)
    {
        return $this->actingAs($actor, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos/customer-display', ['mode' => 'total', 'total' => 999]);
    }

    /** @test */
    public function un_staff_sans_permission_pos_ne_peut_pas_pousser_l_afficheur(): void
    {
        $chef = User::factory()->create(['branch_id' => 1]);
        $chef->assignRole('Chef'); // pas de permission pos
        $this->postDisplay($chef)->assertStatus(403);
    }

    /** @test */
    public function un_staff_avec_permission_pos_n_est_pas_bloque(): void
    {
        $operator = User::factory()->create(['branch_id' => 1]);
        $operator->givePermissionTo('pos');
        // Le gate ne doit pas renvoyer 403 (le reste = 200 best-effort {sent:false} si afficheur off).
        $this->assertNotSame(403, $this->postDisplay($operator)->status());
    }
}
