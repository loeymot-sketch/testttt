<?php

namespace Tests\Feature\Onboarding;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [ONB-06 2026-08-28 · P0] Cocher une case ne doit pas VIDER le rôle.
 *
 * CE QUI SE PASSAIT, mesuré sur la base réelle : « POS Operator » passait de
 * **10 permissions à 0**, et tous les caissiers perdaient l'accès à la caisse.
 *
 * La chaîne, en trois maillons :
 *
 * 1. Deux semoirs créent leur permission sur les DEUX gardes
 *    (`foreach (['sanctum','web'] as $guard)`). Quatre noms existent donc en double :
 *    `availability_toggle`, `ingredients_manage`, `kitchen-display-system`,
 *    `pos-flyer-print`.
 * 2. `PermissionController::index()` faisait `Permission::get()` — SANS filtre de
 *    garde. L'écran affichait quatre paires de lignes rigoureusement identiques :
 *    même libellé, rien pour les distinguer.
 * 3. Cocher la mauvaise jumelle sur un rôle `sanctum` fait lever `GuardDoesNotMatch`
 *    par Spatie — mais `syncPermissions()` fait `detach()` PUIS `givePermissionTo()`,
 *    HORS TRANSACTION (`HasPermissions.php:405-410`). Le détachement est déjà commis
 *    quand l'exception part.
 *
 * Le commerçant cliquait sur une ligne que rien ne distinguait de sa jumelle, lisait
 * un message anglais brut, et son équipe était dehors.
 *
 * DEUX CORRECTIFS, et le second compte autant que le premier : filtrer par garde
 * ferme le chemin CONNU ; la transaction ferme tous les autres, y compris ceux qu'on
 * n'a pas encore trouvés.
 */
class UnRoleNeSeVidePasSurUnClicTest extends TestCase
{
    use RefreshDatabase;

    private Role $caissier;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->caissier = Role::firstOrCreate(['name' => 'POS Operator', 'guard_name' => 'sanctum']);

        // Dix permissions légitimes, comme en production.
        $legitimes = collect(range(1, 10))->map(
            fn ($i) => Permission::firstOrCreate(['name' => 'droit_' . $i, 'guard_name' => 'sanctum'])
        );
        $this->caissier->syncPermissions($legitimes);

        // La jumelle piégeuse : MÊME NOM, autre garde. C'est exactement ce que les
        // deux semoirs produisent sur une installation neuve.
        Permission::firstOrCreate(['name' => 'ingredients_manage', 'guard_name' => 'sanctum']);
        Permission::firstOrCreate(['name' => 'ingredients_manage', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['branch_id' => 0]);
        $this->admin->assignRole('Admin');
        Permission::findOrCreate('settings', 'sanctum');
        $this->admin->givePermissionTo('settings');
    }

    private function jumelleWeb(): Permission
    {
        return Permission::query()
            ->where('name', 'ingredients_manage')
            ->where('guard_name', 'web')
            ->firstOrFail();
    }

    public function test_l_ecran_ne_propose_que_les_permissions_de_la_garde_du_role(): void
    {
        // LE CHEMIN CONNU. Si l'écran ne montre pas la jumelle, le commerçant ne peut
        // pas la cocher par erreur.
        $reponse = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/setting/permission/' . $this->caissier->id);

        // ⚠️ `PermissionController::index()` répond **201 sur un GET** — inhabituel,
        // mais préexistant et attendu par le front. On ne le change pas ; on
        // l'accepte.
        $this->assertContains($reponse->status(), [200, 201], 'La liste des permissions doit répondre.');

        // L'invariant qui compte n'est pas la garde exposée — la réponse est
        // aplatie et ne la porte pas forcément — mais l'ABSENCE DE DOUBLON. Deux
        // lignes de même nom sont indistinguables à l'écran, et c'est en cochant
        // la mauvaise que le commerçant vidait son rôle.
        $brut = $reponse->getContent();
        $occurrences = substr_count($brut, '"ingredients_manage"');

        $this->assertSame(
            1,
            $occurrences,
            "« ingredients_manage » apparaît {$occurrences} fois dans la réponse.\n"
            . "Deux lignes de MÊME NOM sont rigoureusement indistinguables à l'écran :\n"
            . "le commerçant coche la mauvaise, Spatie lève GuardDoesNotMatch, et le\n"
            . 'rôle finit vide.'
        );
    }

    public function test_une_permission_d_une_autre_garde_ne_vide_PAS_le_role(): void
    {
        // LE FILET. Même si une jumelle arrive par un chemin qu'on n'a pas prévu —
        // requête forgée, écran obsolète en cache, futur appelant — le rôle doit
        // rester exactement comme il était.
        $avant = $this->caissier->permissions()->count();
        $this->assertSame(10, $avant);

        $charge = $this->caissier->permissions->pluck('id')->all();
        $charge[] = $this->jumelleWeb()->id;

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/admin/setting/permission/' . $this->caissier->id, [
                'permissions' => $charge,
            ]);

        $apres = $this->caissier->fresh()->permissions()->count();

        $this->assertSame(
            $avant,
            $apres,
            "LE RÔLE A ÉTÉ VIDÉ (avant : {$avant}, après : {$apres}).\n"
            . "`syncPermissions()` détache PUIS attribue, hors transaction : si la\n"
            . "seconde étape lève, le détachement est déjà commis. Tous les caissiers\n"
            . 'se retrouvent dehors.'
        );
    }

    public function test_une_synchro_legitime_fonctionne_toujours(): void
    {
        // Contrôle négatif : le filet ne doit pas empêcher le geste normal.
        $troisSeulement = $this->caissier->permissions->take(3)->pluck('id')->all();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/admin/setting/permission/' . $this->caissier->id, [
                'permissions' => $troisSeulement,
            ]);

        $this->assertSame(
            3,
            $this->caissier->fresh()->permissions()->count(),
            'Retirer des permissions doit continuer de fonctionner.'
        );
    }

    public function test_la_transaction_est_bien_en_place(): void
    {
        // Le filet doit être VISIBLE dans le code : c'est lui qui protège des chemins
        // qu'on n'a pas encore trouvés, et il est facile à retirer par mégarde en
        // « simplifiant » le service.
        $source = file_get_contents(app_path('Services/PermissionService.php'));

        $this->assertStringContainsString(
            'DB::transaction',
            $source,
            "La synchronisation des permissions doit être transactionnelle : sans ça,\n"
            . "un échec à mi-parcours laisse le rôle vide."
        );
    }
}
