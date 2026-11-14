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
 * [AUDIT ABUSIF 2026-11-06 · tour 3 · R3-ADV-002] W4-P2 (« sessions fermées quand un tiers change le mot de passe ») ne
 * couvrait que la fiche employé : par la fiche livreur ou chef, le compte gardait ses sessions (un livreur congédié gardait
 * l'application livreur jusqu'à 8 h). Même règle sur toutes les fiches du personnel ; un simple renommage ne déconnecte pas.
 */
class StaffCredentialChangeRevokesSessionsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        foreach ([1 => 'Admin', 2 => 'Customer', 3 => 'Delivery Boy', 4 => 'Waiter', 5 => 'Chef', 6 => 'Branch Manager', 7 => 'POS Operator', 8 => 'Stuff'] as $id => $nom) {
            DB::table('roles')->insert(['id' => $id, 'name' => $nom, 'guard_name' => 'sanctum', 'created_at' => now(), 'updated_at' => now()]);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $perms = ['delivery-boys', 'delivery-boys_edit', 'chefs', 'chefs_edit', 'dashboard'];
        foreach ($perms as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'sanctum']);
        }
        Role::findById(6, 'sanctum')->syncPermissions($perms);
        $this->branch = Branch::factory()->create();
    }

    private function staff(int $roleId, string $email, string $phone): User
    {
        $u = User::factory()->create(['branch_id' => $this->branch->id, 'email' => $email, 'phone' => $phone, 'status' => Status::ACTIVE, 'is_guest' => 10]);
        $u->assignRole($roleId);

        return $u;
    }

    private function payload(User $c): array
    {
        return ['name' => $c->name, 'email' => $c->email, 'phone' => $c->phone, 'country_code' => '+33', 'status' => Status::ACTIVE,
            'branch_id' => $this->branch->id, 'password' => 'NouveauMotDePasse123', 'password_confirmation' => 'NouveauMotDePasse123'];
    }

    public function test_livreur_mot_de_passe_change_par_un_tiers_ferme_ses_sessions(): void
    {
        $bm = $this->staff(6, 'bm@example.test', '0611111111');
        $livreur = $this->staff(3, 'livreur@example.test', '0622222222');
        $livreur->createToken('auth_token');
        Sanctum::actingAs($bm, ['*']);
        $r = $this->putJson('/api/admin/delivery-boy/' . $livreur->id, $this->payload($livreur));
        $r->assertSuccessful();
        $this->assertSame(0, $livreur->tokens()->count(), 'sessions du livreur fermées');
    }

    public function test_chef_mot_de_passe_change_par_un_tiers_ferme_ses_sessions(): void
    {
        $bm = $this->staff(6, 'bm2@example.test', '0633333333');
        $chef = $this->staff(5, 'chef@example.test', '0644444444');
        $chef->createToken('auth_token');
        Sanctum::actingAs($bm, ['*']);
        $r = $this->putJson('/api/admin/chef/' . $chef->id, $this->payload($chef));
        $r->assertSuccessful();
        $this->assertSame(0, $chef->tokens()->count(), 'sessions du chef fermées');
    }

    public function test_renommer_un_livreur_ne_le_deconnecte_pas(): void
    {
        $bm = $this->staff(6, 'bm3@example.test', '0655555555');
        $livreur = $this->staff(3, 'livreur2@example.test', '0666666666');
        $livreur->createToken('auth_token');
        Sanctum::actingAs($bm, ['*']);
        $p = $this->payload($livreur);
        unset($p['password'], $p['password_confirmation']);
        $p['name'] = 'Livreur renommé';
        $this->putJson('/api/admin/delivery-boy/' . $livreur->id, $p)->assertSuccessful();
        $this->assertSame(1, $livreur->tokens()->count(), 'rien de sensible changé : la session reste ouverte');
    }
}
