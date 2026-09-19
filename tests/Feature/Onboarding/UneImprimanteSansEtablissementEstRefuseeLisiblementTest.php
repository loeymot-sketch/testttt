<?php

namespace Tests\Feature\Onboarding;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-10 2026-08-28] Déclarer une imprimante sans choisir son établissement.
 *
 * ═══ LE DÉFAUT ═══
 *
 * `PrinterRequest` déclarait `'branch_id' => ['nullable', …]`. Mais
 * `printers.branch_id` porte une **clé étrangère** vers `branches.id`, et
 * `PrinterController::resolveBranchId()` renvoie `validated('branch_id')` dès que
 * l'acteur a `branch_id = 0` — c'est-à-dire l'administrateur, donc le patron.
 *
 * Sans valeur, l'insertion partait avec `branch_id = 0`. Aucune filiale ne porte cet
 * identifiant. Le patron recevait :
 *
 *     SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed
 *
 * ═══ LE JUMEAU ═══
 *
 * C'est le même défaut que `phone`, corrigé la veille : **obligatoire en base,
 * facultatif dans la règle**. La règle de validation et le schéma disaient deux choses
 * différentes, et c'est la base qui tranchait — en langage de base de données.
 *
 * Ce motif est le troisième de la série cette semaine. Il mérite d'être cherché
 * ailleurs : partout où une colonne `NOT NULL` ou porteuse d'une clé étrangère fait
 * face à une règle `nullable`, l'écart finit devant le commerçant sous forme de trace
 * SQL.
 *
 * ═══ CE QUI EST CORRIGÉ ═══
 *
 * `branch_id` devient obligatoire **précisément** quand l'admin crée. En modification,
 * la valeur existante sert de repli, donc rien ne change. Un patron rattaché à un
 * établissement (`branch_id > 0`) n'a jamais à le saisir : le sien s'applique.
 *
 * Trouvé par le parcours de bout en bout d'ONB-14, à l'étape « équipement ».
 */
class UneImprimanteSansEtablissementEstRefuseeLisiblementTest extends TestCase
{
    use RefreshDatabase;

    private User $patron;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // `branch_id = 0` : l'administrateur. C'est LUI qui déclenchait le défaut,
        // parce que lui seul passe par la branche `validated('branch_id')`.
        $this->patron = User::factory()->create(['branch_id' => 0]);
        $this->patron->assignRole('Admin');

        Permission::findOrCreate('settings', 'sanctum');
        $this->patron->givePermissionTo('settings');

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->patron, 'sanctum');
    }

    public function test_sans_etablissement_le_refus_est_un_message_pas_une_trace_sql(): void
    {
        $reponse = $this->postJson('/api/admin/printers', $this->imprimante());

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['branch_id']);

        $corps = (string) $reponse->getContent();

        $this->assertStringNotContainsString(
            'SQLSTATE',
            $corps,
            "Le patron lit encore une trace de base de données. C'était exactement le\n"
            . "symptôme : une violation de clé étrangère présentée comme un message."
        );

        $this->assertStringNotContainsString('FOREIGN KEY', $corps);

        // Et le message doit DIRE QUOI FAIRE, pas seulement que c'est refusé.
        // On lit la valeur DÉCODÉE : dans le corps brut, `é` s'écrit `\u00e9`, et une
        // recherche de sous-chaîne sur le JSON échouerait pour une raison sans rapport.
        $this->assertStringContainsString(
            'établissement',
            (string) $reponse->json('errors.branch_id.0'),
            "Le message ne nomme pas ce qu'il faut choisir."
        );
    }

    public function test_avec_un_etablissement_l_imprimante_est_creee(): void
    {
        $branche = Branch::factory()->create();

        $reponse = $this->postJson('/api/admin/printers', $this->imprimante([
            'branch_id' => $branche->id,
        ]));

        $this->assertContains(
            $reponse->status(),
            [200, 201, 202],
            "La correction ne doit pas empêcher le cas normal : " . mb_substr($reponse->getContent(), 0, 250)
        );

        $this->assertDatabaseHas('printers', [
            'name'      => 'Caisse',
            'branch_id' => $branche->id,
        ]);
    }

    public function test_un_gerant_rattache_n_a_jamais_a_saisir_son_etablissement(): void
    {
        // Contrôle de non-régression : le `requiredIf` ne doit mordre QUE l'admin.
        // Un gérant a déjà son établissement ; le lui redemander serait une régression
        // invisible — un champ nouvellement obligatoire sur un écran qui ne l'affiche pas.
        $branche = Branch::factory()->create();

        $gerant = User::factory()->create(['branch_id' => $branche->id]);
        $gerant->assignRole('Branch Manager');
        $gerant->givePermissionTo('settings');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $reponse = $this->actingAs($gerant, 'sanctum')
            ->postJson('/api/admin/printers', $this->imprimante(['name' => 'Cuisine']));

        $this->assertContains(
            $reponse->status(),
            [200, 201, 202],
            "Un gérant rattaché doit pouvoir déclarer son imprimante sans choisir\n"
            . 'un établissement : ' . mb_substr($reponse->getContent(), 0, 250)
        );

        $this->assertDatabaseHas('printers', [
            'name'      => 'Cuisine',
            'branch_id' => $branche->id,
        ]);
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
}
