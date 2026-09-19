<?php

namespace Tests\Feature\Onboarding;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-06 2026-08-28] Un champ obligatoire en base doit l'être aussi dans la règle.
 *
 * ═══ LE DÉFAUT ═══
 *
 * `2026_05_16_140100_make_user_phone_required` rend `users.phone` NOT NULL (avec un
 * remplissage de secours `PENDING_*` pour les lignes existantes). Six FormRequest
 * qui écrivent cette colonne l'avaient pourtant gardée en `'nullable'` :
 * Administrator, Chef, Customer, DeliveryBoy, Employee, Waiter.
 *
 * Ce que ça produit, dès le premier geste d'un nouveau commerçant : il embauche son
 * premier employé, laisse le téléphone vide — **rien à l'écran n'indiquait qu'il
 * était obligatoire** — et reçoit « erreur de base de données ».
 *
 * `QueryExceptionLibrary` a bien cessé de fuiter le SQLSTATE en production, mais il
 * ne peut pas inventer le message utile : la validation aurait dû refuser AVANT, en
 * nommant le champ. Assainir un message d'erreur ne remplace pas une règle juste.
 *
 * `ProfileRequest` portait déjà `'required'` sur la même colonne : l'intention était
 * connue, elle n'avait pas été propagée. C'est le motif du « jumeau oublié ».
 *
 * ⚠️ `BranchRequest` n'est PAS concerné : il écrit `branches.phone`, une autre
 * colonne, qui reste nullable. Les confondre rendrait obligatoire un champ qui ne
 * l'est pas.
 */
class UnChampObligatoireEnBaseLEstAussiDansLaRegleTest extends TestCase
{
    use RefreshDatabase;

    /** Les six requêtes qui écrivent `users.phone`. */
    private const REQUETES = [
        'AdministratorRequest',
        'ChefRequest',
        'CustomerRequest',
        'DeliveryBoyRequest',
        'EmployeeRequest',
        'WaiterRequest',
    ];

    public function test_la_colonne_est_bien_NOT_NULL_sinon_ce_banc_n_a_pas_lieu_d_etre(): void
    {
        // Contrôle de périmètre : si quelqu'un rend un jour la colonne nullable, ce
        // banc doit le dire au lieu d'exiger un `required` devenu injustifié.
        $this->assertTrue(Schema::hasColumn('users', 'phone'));

        $migration = file_get_contents(
            base_path('database/migrations/2026_05_16_140100_make_user_phone_required.php')
        );

        $this->assertNotFalse($migration, 'La migration qui rend le téléphone obligatoire a disparu.');
        $this->assertStringContainsString('NOT NULL', $migration);
    }

    /**
     * @dataProvider requetesQuiEcriventLeTelephone
     */
    public function test_la_regle_exige_le_telephone(string $requete): void
    {
        $source = file_get_contents(base_path('app/Http/Requests/' . $requete . '.php'));

        $this->assertNotFalse($source, "{$requete} est introuvable.");

        // On isole le bloc de la règle : le fichier contient d'autres `nullable`.
        $debut = strpos($source, "'phone'");
        $this->assertNotFalse($debut, "{$requete} n'a plus de règle `phone`.");

        $bloc = substr($source, $debut, 220);

        $this->assertStringContainsString(
            "'required'",
            $bloc,
            "{$requete} déclare le téléphone facultatif, alors que `users.phone` est\n"
            . "NOT NULL depuis mai. Le laisser vide provoque une erreur de base de\n"
            . 'données rendue au commerçant comme « erreur de base de données ».'
        );

        $this->assertStringNotContainsString("'nullable'", $bloc, "{$requete} : `nullable` subsiste.");
    }

    /** @return array<string, array{0:string}> */
    public function requetesQuiEcriventLeTelephone(): array
    {
        $cas = [];

        foreach (self::REQUETES as $requete) {
            $cas[$requete] = [$requete];
        }

        return $cas;
    }

    public function test_un_employe_sans_telephone_est_refuse_avec_un_message_utile(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        if (! Branch::query()->whereKey(1)->exists()) {
            Branch::factory()->create(['id' => 1]);
        }

        $patron = User::factory()->create(['branch_id' => 0]);
        $patron->assignRole('Admin');
        Permission::findOrCreate('employees_create', 'sanctum');
        $patron->givePermissionTo(['employees_create']);

        $reponse = $this->actingAs($patron, 'sanctum')->postJson('/api/admin/employee', [
            'name'         => 'Sami',
            'email'        => 'sami@example.com',
            'username'     => 'sami',
            'password'     => 'MotDePasse!2026',
            'country_code' => '+33',
            'status'       => 5,
            'role_id'      => 1,
            // Téléphone volontairement absent : c'est le geste du commerçant pressé.
        ]);

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['phone']);

        // Le fond du défaut : ce n'est pas seulement qu'il fallait refuser, c'est que
        // le refus arrivait sous forme d'erreur de base de données — un message qui
        // ne dit ni quel champ, ni quoi faire.
        $corps = (string) $reponse->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $corps);
        $this->assertStringNotContainsString('database_error', $corps);
    }

    /**
     * @dataProvider formulairesDuPersonnel
     */
    public function test_le_champ_se_dit_obligatoire_a_l_ecran(string $gabarit): void
    {
        $source = file_get_contents(base_path($gabarit));

        $this->assertNotFalse($source, "{$gabarit} est introuvable.");

        // Rendre la règle `required` sans le dire à l'écran déplacerait le problème :
        // au lieu d'une erreur de base de données, le commerçant aurait un refus sur
        // un champ qu'il croyait facultatif.
        $this->assertMatchesRegularExpression(
            '/<label for="phone" class="db-field-title required">/',
            $source,
            "{$gabarit} : le libellé du téléphone ne porte pas l'astérisque, alors que\n"
            . 'le champ est obligatoire.'
        );
    }

    /** @return array<string, array{0:string}> */
    public function formulairesDuPersonnel(): array
    {
        $base = 'resources/js/components/admin/';

        return [
            'administrateur' => [$base . 'administrators/AdministratorCreateComponent.vue'],
            'cuisinier'      => [$base . 'chefs/ChefCreateComponent.vue'],
            'client'         => [$base . 'customers/CustomerCreateComponent.vue'],
            'livreur'        => [$base . 'deliveryBoys/DeliveryBoyCreateComponent.vue'],
            'employé'        => [$base . 'employees/EmployeeCreateComponent.vue'],
            'serveur'        => [$base . 'waiters/WaiterCreateComponent.vue'],
        ];
    }
}
