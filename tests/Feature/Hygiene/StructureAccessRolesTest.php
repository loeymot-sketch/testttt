<?php

namespace Tests\Feature\Hygiene;

use App\Models\Branch;
use App\Models\User;
use App\Services\Hygiene\StructureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [2026-11-18 · relecture adverse P1-1] La structure de l'hygiène (plan, friteuses, équipements) est réservée aux
 * responsables — et le rôle « Branch Manager » EN EST UN, bien qu'il n'ait pas le droit `settings`. Les tests du
 * premier jet donnaient `settings` à la main au responsable : ils ne voyaient pas qu'un vrai Branch Manager était
 * refusé (403) sur son propre plan de nettoyage. Ici les rôles sont ceux du seeder, sans aucun droit ajouté.
 */
class StructureAccessRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        config(['hygiene.branch_id' => 1]);
    }

    private function actingWithRole(string $role): User
    {
        $u = User::factory()->create(['branch_id' => 1]);
        $u->assignRole($role);
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    public function test_un_branch_manager_sans_droit_settings_gere_la_structure(): void
    {
        $u = $this->actingWithRole('Branch Manager');
        $this->assertFalse($u->can('settings'), 'le rôle du seeder ne porte pas « settings » : c\'est tout l\'objet du test');
        $this->assertTrue(StructureAccess::allows($u));

        $this->postJson('/api/admin/hygiene/tasks/templates', ['name' => 'Sols cuisine', 'zone' => 'Cuisine', 'frequency' => 'daily', 'checklist' => ['Balayer', 'Laver']])
            ->assertStatus(201);
        $this->getJson('/api/admin/hygiene/tasks/templates')->assertOk()->assertJsonPath('meta.can_manage', true);
        $this->postJson('/api/admin/hygiene/temperature/equipment/quick-setup', ['fridges' => 1, 'freezers' => 0])
            ->assertSuccessful();
        $this->getJson('/api/admin/hygiene/temperature/today')->assertOk()->assertJsonPath('meta.can_manage', true);
    }

    public function test_un_caissier_reste_refuse_avec_un_message_clair(): void
    {
        $u = $this->actingWithRole('POS Operator');
        $this->assertFalse(StructureAccess::allows($u));

        $this->postJson('/api/admin/hygiene/tasks/templates', ['name' => 'Sols cuisine', 'zone' => 'Cuisine', 'frequency' => 'daily', 'checklist' => ['Balayer']])
            ->assertStatus(403)->assertJsonPath('message', StructureAccess::REFUSAL);
        $this->postJson('/api/admin/hygiene/temperature/equipment/quick-setup', ['fridges' => 1, 'freezers' => 0])->assertStatus(403);
        $this->getJson('/api/admin/hygiene/tasks/templates')->assertOk()->assertJsonPath('meta.can_manage', false);
    }

    public function test_sans_compte_personne_ne_gere_rien(): void
    {
        $this->assertFalse(StructureAccess::allows(null));
    }
}
