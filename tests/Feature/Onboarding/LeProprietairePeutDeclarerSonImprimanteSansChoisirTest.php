<?php

namespace Tests\Feature\Onboarding;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-10 2026-08-28] Le compte propriétaire déclare son imprimante sans rien choisir.
 *
 * ═══ CE BANC EXISTE PARCE QUE J'AI CASSÉ QUELQUE CHOSE ═══
 *
 * Le même jour, j'ai rendu `branch_id` obligatoire pour tout acteur ayant
 * `branch_id = 0`, afin d'éviter une violation de clé étrangère. Un audit adverse a
 * montré que c'était **pire que le défaut** :
 *
 *   - le compte propriétaire est `admin@lecayenne.fr`, `branch_id = 0`
 *     (`database/seeders/UserTableSeeder.php:39`) ;
 *   - `PrintersComponent.vue` n'envoyait jamais `branch_id` ;
 *   - et le gabarit n'affichait **aucun** message pour ce champ.
 *
 * Le patron cliquait « Enregistrer », le modal restait ouvert, et **rien ne se
 * passait**. Un refus muet sur un champ invisible — précisément ce que le même
 * commit condamnait pour la largeur d'impression.
 *
 * Les six bancs « verts en non-régression » que j'avais invoqués créaient tous leur
 * acteur avec `branch_id > 0` : le `requiredIf` ne s'y déclenchait jamais. **Un banc
 * vert sur le mauvais périmètre**, encore.
 *
 * ═══ LE CORRECTIF ═══
 *
 * On n'exige un choix que s'il y en a vraiment un. Avec un seul établissement — le
 * cas de V1 LOCAL — le serveur le prend d'office : demander de choisir parmi un
 * élément unique est une corvée, pas une protection. Avec plusieurs, le choix est
 * exigé **et l'écran l'offre**, avec son message d'erreur.
 */
class LeProprietairePeutDeclarerSonImprimanteSansChoisirTest extends TestCase
{
    use RefreshDatabase;

    private function proprietaire(): User
    {
        $patron = User::factory()->create(['branch_id' => 0]);
        $patron->assignRole('Admin');

        Permission::findOrCreate('settings', 'sanctum');
        $patron->givePermissionTo('settings');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $patron;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function imprimante(array $ajouts = []): array
    {
        return array_merge([
            'name'        => 'Caisse',
            'type'        => 'escpos_tcp',
            'host'        => 'imprimante.exemple.test',
            'port'        => 9100,
            'station'     => 'receipt',
            'width_chars' => 42,
            'status'      => Status::ACTIVE,
        ], $ajouts);
    }

    public function test_avec_un_seul_etablissement_le_proprietaire_n_a_rien_a_choisir(): void
    {
        // LE SCÉNARIO EXACT DE V1 LOCAL, et celui que ma première version cassait.
        $seule = Branch::factory()->create();

        $this->assertSame(1, Branch::query()->withoutGlobalScopes()->count());

        $reponse = $this->actingAs($this->proprietaire(), 'sanctum')
            ->postJson('/api/admin/printers', $this->imprimante());

        $this->assertContains(
            $reponse->status(),
            [200, 201, 202],
            "Le propriétaire ne peut plus déclarer son imprimante. C'est la régression\n"
            . "que l'audit adverse a trouvée : un refus muet sur un champ que l'écran\n"
            . 'ne montrait pas. ' . mb_substr((string) $reponse->getContent(), 0, 250)
        );

        $this->assertDatabaseHas('printers', [
            'name'      => 'Caisse',
            'branch_id' => $seule->id,
        ]);
    }

    public function test_avec_plusieurs_etablissements_le_choix_est_exige_et_nomme(): void
    {
        Branch::factory()->count(3)->create();

        $reponse = $this->actingAs($this->proprietaire(), 'sanctum')
            ->postJson('/api/admin/printers', $this->imprimante());

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['branch_id']);

        $this->assertStringContainsString(
            'établissement',
            (string) $reponse->json('errors.branch_id.0'),
            "Le refus ne dit pas quoi faire."
        );
    }

    public function test_avec_plusieurs_etablissements_le_choix_fonctionne(): void
    {
        Branch::factory()->count(2)->create();
        $visee = Branch::factory()->create();

        $reponse = $this->actingAs($this->proprietaire(), 'sanctum')
            ->postJson('/api/admin/printers', $this->imprimante(['branch_id' => $visee->id]));

        $this->assertContains($reponse->status(), [200, 201, 202]);

        $this->assertDatabaseHas('printers', [
            'name'      => 'Caisse',
            'branch_id' => $visee->id,
        ]);
    }

    public function test_l_ecran_offre_le_choix_et_affiche_le_refus(): void
    {
        // C'EST L'ASSERTION QUI MANQUAIT LA PREMIÈRE FOIS. Le serveur peut refuser
        // tant qu'il veut : si l'écran n'a ni le champ ni le message, le patron ne
        // voit rien. Les bancs serveur seuls ne l'auraient jamais dit.
        $ecran = file_get_contents(
            resource_path('js/components/admin/settings/Printers/PrintersComponent.vue')
        );

        $this->assertStringContainsString(
            'data-testid="printer-branch"',
            $ecran,
            "L'écran n'offre pas le choix d'établissement que le serveur peut exiger."
        );

        $this->assertStringContainsString(
            'errors.branch_id',
            $ecran,
            "Un refus sur `branch_id` reste INVISIBLE : le modal se ferme sur rien.\n"
            . "C'est exactement le défaut que cet écran corrigeait pour la largeur."
        );

        $this->assertStringContainsString(
            'branch_id: null',
            $ecran,
            "Le formulaire n'initialise pas le champ : il enverrait `undefined`."
        );
    }
}
