<?php

namespace Tests\Feature\Admin;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [AUDIT ABUSIF 2026-11-06 · W4] Trous de droits sur les comptes employés, prouvés par l'audit et fermés :
 *   P1-1 un employé SUPPRIMÉ gardait l'accès admin avec ses jetons ouverts ;
 *   P1-2 un Branch Manager prenait le compte d'un autre Branch Manager (rétrogradé en Stuff + nouvel e-mail / mot de passe) ;
 *   P2   un mot de passe changé par un tiers laissait les sessions du compte ouvertes.
 */
class EmployeeAbusGuardTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        // Rôles aux identifiants EXACTS de App\Enums\Role (EmployeeService compare des identifiants).
        foreach ([1 => 'Admin', 2 => 'Customer', 3 => 'Delivery Boy', 4 => 'Waiter', 5 => 'Chef', 6 => 'Branch Manager', 7 => 'POS Operator', 8 => 'Stuff'] as $id => $nom) {
            DB::table('roles')->insert(['id' => $id, 'name' => $nom, 'guard_name' => 'sanctum', 'created_at' => now(), 'updated_at' => now()]);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $perms = ['employees', 'employees_create', 'employees_edit', 'employees_delete', 'employees_show', 'dashboard', 'pos'];
        foreach ($perms as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'sanctum']);
        }
        Role::findById(6, 'sanctum')->syncPermissions($perms);
        Role::findById(8, 'sanctum')->syncPermissions(['dashboard']);
        $this->branch = Branch::factory()->create();
    }

    private function staff(int $roleId, string $email): User
    {
        $u = User::factory()->create(['branch_id' => $this->branch->id, 'email' => $email, 'status' => Status::ACTIVE, 'is_guest' => 10]);
        $u->assignRole($roleId);

        return $u;
    }

    private function payload(User $cible, int $roleId, array $plus = []): array
    {
        return array_merge([
            'name' => $cible->name, 'email' => $cible->email, 'phone' => '0612345678', 'country_code' => '+33',
            'status' => Status::ACTIVE, 'role_id' => $roleId, 'branch_id' => $this->branch->id,
        ], $plus);
    }

    public function test_un_branch_manager_ne_prend_pas_le_compte_d_un_pair(): void
    {
        $attaquant = $this->staff(6, 'bm1@example.test');
        $pair = $this->staff(6, 'bm2@example.test');
        Sanctum::actingAs($attaquant, ['*']);

        $this->putJson('/api/admin/employee/' . $pair->id, $this->payload($pair, 8, [
            'email' => 'pris@example.test', 'password' => 'NouveauMotDePasse123', 'password_confirmation' => 'NouveauMotDePasse123',
        ]))->assertStatus(422);

        $pair->refresh();
        $this->assertSame('bm2@example.test', $pair->email, 'le compte du pair est intact');
        $this->assertTrue($pair->hasRole(6));

        // Il garde le droit de gérer un employé de rang inférieur.
        $stuff = $this->staff(8, 'stuff@example.test');
        $this->putJson('/api/admin/employee/' . $stuff->id, $this->payload($stuff, 8, ['name' => 'Renommé']))->assertSuccessful();
    }

    public function test_mot_de_passe_change_par_un_tiers_ferme_les_sessions_du_compte(): void
    {
        $manager = $this->staff(6, 'bm@example.test');
        $stuff = $this->staff(8, 'stuff2@example.test');
        $stuff->createToken('auth_token');
        $this->assertSame(1, $stuff->tokens()->count());
        Sanctum::actingAs($manager, ['*']);

        $this->putJson('/api/admin/employee/' . $stuff->id, $this->payload($stuff, 8, [
            'password' => 'NouveauMotDePasse123', 'password_confirmation' => 'NouveauMotDePasse123',
        ]))->assertSuccessful();
        $this->assertSame(0, $stuff->tokens()->count(), 'les sessions ouvertes sont fermées');
    }

    public function test_un_employe_supprime_n_a_plus_acces_meme_avec_un_jeton_ouvert(): void
    {
        $manager = $this->staff(6, 'bm3@example.test');
        $stuff = $this->staff(8, 'stuff3@example.test');
        $jeton = $stuff->createToken('auth_token')->plainTextToken;
        Sanctum::actingAs($manager, ['*']);
        $this->deleteJson('/api/admin/employee/' . $stuff->id)->assertSuccessful();
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $stuff->id)->count(), 'jetons révoqués à la suppression');

        // Défense en profondeur : un compte supprimé sans révocation (autre chemin) n'authentifie plus personne.
        $autre = $this->staff(6, 'bm4@example.test');
        $jeton2 = $autre->createToken('auth_token')->plainTextToken;
        $autre->delete();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $jeton2)->getJson('/api/admin/employee')->assertStatus(401);
    }
}
