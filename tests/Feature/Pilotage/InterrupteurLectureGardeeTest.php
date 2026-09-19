<?php

namespace Tests\Feature\Pilotage;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [ONB-05 T-1.2.1 2026-08-27] La lecture des interrupteurs est gardée.
 *
 * `update()` exigeait le rôle Admin depuis l'origine ; `index()` n'exigeait rien
 * au-delà de l'authentification. Un caissier, un cuisinier ou un livreur pouvait
 * donc lire l'état de pilotage de l'établissement.
 *
 * Ce n'est pas une fuite de données clients — c'est la configuration
 * opérationnelle, et elle renseigne sur ce qui est activé, donc sur ce qui est
 * contournable. Le principe suffit : une écriture réservée à l'Admin ne doit pas
 * avoir une lecture ouverte à tous.
 */
class InterrupteurLectureGardeeTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(array $permissions = [], ?string $role = null): User
    {
        $u = User::factory()->create();

        foreach ($permissions as $nom) {
            Permission::findOrCreate($nom, 'sanctum');
        }
        if ($permissions !== []) {
            $u->givePermissionTo($permissions);
        }
        if ($role !== null) {
            Role::findOrCreate($role, 'sanctum');
            $u->assignRole($role);
        }

        return $u->fresh();
    }

    public function test_un_compte_sans_droit_de_reglages_ne_lit_pas_les_interrupteurs(): void
    {
        $caissier = $this->utilisateur(['pos']);

        $this->actingAs($caissier, 'sanctum')
            ->getJson('/api/admin/observability/interrupteurs')
            ->assertForbidden();
    }

    public function test_un_compte_avec_le_droit_de_reglages_les_lit(): void
    {
        $gerant = $this->utilisateur(['settings']);

        $this->actingAs($gerant, 'sanctum')
            ->getJson('/api/admin/observability/interrupteurs')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_un_administrateur_les_lit(): void
    {
        $admin = $this->utilisateur([], 'Admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/observability/interrupteurs')
            ->assertOk();
    }

    public function test_l_ecriture_reste_reservee_a_l_administrateur(): void
    {
        // On vérifie que la garde d'écriture, elle, n'a pas bougé : le correctif
        // ajoute une protection, il n'en retire aucune.
        $gerant = $this->utilisateur(['settings']);

        $this->actingAs($gerant, 'sanctum')
            ->putJson('/api/admin/observability/interrupteurs/split_payment', ['actif' => true])
            ->assertForbidden();
    }
}
