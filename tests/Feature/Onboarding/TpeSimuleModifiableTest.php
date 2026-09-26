<?php

namespace Tests\Feature\Onboarding;

use App\Models\Branch;
use App\Models\PaymentTerminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [ONB-10 2026-08-27] Le commerçant doit pouvoir modifier son propre terminal.
 *
 * Trouvé à l'écran, pas dans le code : sur /admin/settings/payment-terminals, le
 * tableau affiche correctement « Simulation », mais dès qu'on ouvre « Modifier »,
 * le champ PASSERELLE — obligatoire, astérisque rouge — est VIDE. Le formulaire
 * ne proposait que cinq valeurs (ingenico, verifone, stripe, senangpay, manual)
 * et la passerelle réelle de l'unique TPE du Cayenne, `simulation`, n'en faisait
 * pas partie.
 *
 * Conséquence pour le commerçant : il ne peut ni renommer son terminal, ni en
 * corriger les frais, ni le numéro de série — tout enregistrement part en 422.
 * La seule porte de sortie visible depuis l'écran est de choisir « Ingenico »
 * pour faire passer le formulaire, c'est-à-dire de déclarer un matériel qu'il
 * n'a pas. Ce mensonge ne reste pas à l'écran : la ventilation par terminal du
 * rapport Z recopie `gateway_type` (ZReportCashEnrichmentService).
 *
 * La valeur n'a rien d'accidentel : `SimulatedTpeTerminal20260708Seeder` l'écrit
 * depuis une décision du propriétaire du 2026-07-08. Le produit CRÉAIT donc une
 * valeur que son propre formulaire refusait — c'est cette incohérence que les
 * trois tests ci-dessous verrouillent, chacun par un bout différent.
 *
 * Aucun risque de contournement d'encaissement : `gateway_type` n'est lu par
 * aucune branche de logique (vérifié sur `app/` et `resources/js/` — il est
 * affiché et recopié, jamais testé). Le garde-fou du matériel simulé en
 * production reste `POS_SIMULATION_HARDWARE`, contrôlé au boot.
 */
class TpeSimuleModifiableTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branche;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branche = Branch::factory()->create();
        $this->admin = User::factory()->create(['branch_id' => $this->branche->id]);
        $this->admin->assignRole('Admin');
    }

    /**
     * Bout n°1 — le semoir. Ce que le produit ÉCRIT en base doit être acceptable
     * par son propre formulaire. On lit la valeur dans la source du semoir plutôt
     * que de la recopier ici : si quelqu'un la change là-bas, ce test le voit.
     */
    public function test_la_passerelle_ecrite_par_le_semoir_est_acceptee_par_le_formulaire(): void
    {
        $chemin = database_path('seeders/SimulatedTpeTerminal20260708Seeder.php');
        $this->assertFileExists($chemin);

        $source = file_get_contents($chemin);

        $trouve = preg_match(
            "/'gateway_type'\s*=>\s*'([a-z_]+)'/",
            $source,
            $m
        );

        $this->assertSame(
            1,
            $trouve,
            "Impossible de lire la passerelle écrite par SimulatedTpeTerminal20260708Seeder.\n"
            . "Si le semoir a changé de forme, ce test doit être adapté — pas supprimé :\n"
            . "c'est lui qui garantit que le produit n'écrit pas une valeur que son\n"
            . "propre formulaire refuse."
        );

        $passerelleEcrite = $m[1];

        $this->assertContains(
            $passerelleEcrite,
            PaymentTerminal::GATEWAY_TYPES,
            "Le semoir écrit gateway_type='{$passerelleEcrite}' en base, mais cette valeur\n"
            . "est absente de PaymentTerminal::GATEWAY_TYPES — donc refusée par\n"
            . "PaymentTerminalRequest (Rule::in). Le terminal ainsi créé est IMMODIFIABLE :\n"
            . "le commerçant voit un champ obligatoire vide et se prend un 422 à chaque\n"
            . "enregistrement, y compris pour un simple changement de nom.\n\n"
            . 'Autorisées : ' . implode(', ', PaymentTerminal::GATEWAY_TYPES)
        );
    }

    /**
     * Bout n°2 — le sélecteur. Le formulaire doit proposer TOUTES les valeurs que
     * le serveur accepte, sinon un terminal parfaitement valide devient
     * inaffichable dans son propre écran de modification.
     */
    public function test_le_selecteur_propose_toutes_les_passerelles_autorisees(): void
    {
        $chemin = resource_path(
            'js/components/admin/settings/PaymentTerminals/PaymentTerminalsComponent.vue'
        );
        $this->assertFileExists($chemin);

        $source = file_get_contents($chemin);

        // On accepte les deux écritures : la liste `passerelles` (actuelle) et
        // les `<option value="…">` en dur (l'ancienne). Ainsi, si quelqu'un
        // revient en arrière, l'échec nomme la passerelle manquante au lieu de
        // se plaindre de n'avoir rien trouvé.
        preg_match_all('/valeur:\s*"([a-z_]+)"/', $source, $viaListe);
        preg_match_all('/<option value="([a-z_]+)"/', $source, $viaOption);

        $proposees = array_values(array_unique(array_merge($viaListe[1], $viaOption[1])));

        $this->assertNotEmpty(
            $proposees,
            "Aucune passerelle trouvée dans PaymentTerminalsComponent.vue.\n"
            . "Le sélecteur a changé de forme : adapter ce test, pas le supprimer."
        );

        $manquantes = array_values(array_diff(PaymentTerminal::GATEWAY_TYPES, $proposees));

        $this->assertSame(
            [],
            $manquantes,
            "Le serveur accepte des passerelles que le formulaire ne propose pas : "
            . implode(', ', $manquantes) . ".\n"
            . "Un terminal portant l'une d'elles s'affiche avec un champ obligatoire\n"
            . "VIDE à la modification, et ne peut plus être enregistré du tout."
        );

        $inconnues = array_values(array_diff($proposees, PaymentTerminal::GATEWAY_TYPES));

        $this->assertSame(
            [],
            $inconnues,
            "Le formulaire propose des passerelles que le serveur refusera : "
            . implode(', ', $inconnues) . ".\n"
            . "Le commerçant les choisirait pour se prendre un 422 sans comprendre."
        );
    }

    /**
     * Bout n°3 — la preuve par l'usage. On refait le geste du commerçant : il
     * renomme son terminal simulé et enregistre. Sans le correctif, c'est un 422
     * sur `gateway_type` alors qu'il n'a même pas touché à ce champ.
     */
    public function test_le_commercant_peut_renommer_son_terminal_simule(): void
    {
        $terminal = PaymentTerminal::query()->create([
            'branch_id'     => $this->branche->id,
            'name'          => 'TPE Le Cayenne #1',
            'gateway_type'  => 'simulation',
            'serial_number' => 'SIM-CAYENNE-1',
            'fee_percent'   => 0,
            'fee_fixed'     => 0,
            'status'        => PaymentTerminal::STATUS_ACTIVE,
        ]);

        $reponse = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/payment-terminals/{$terminal->id}", [
                'name'          => 'TPE Comptoir',
                'gateway_type'  => $terminal->gateway_type,
                'fee_percent'   => 0,
                'fee_fixed'     => 0,
                'serial_number' => 'SIM-CAYENNE-1',
                'status'        => PaymentTerminal::STATUS_ACTIVE,
            ]);

        $reponse->assertOk()
            ->assertJsonPath('data.name', 'TPE Comptoir')
            ->assertJsonPath('data.gateway_type', 'simulation');

        $this->assertDatabaseHas('payment_terminals', [
            'id'           => $terminal->id,
            'name'         => 'TPE Comptoir',
            'gateway_type' => 'simulation',
        ]);
    }

    /**
     * Contrôle négatif. Élargir la liste ne doit pas la rendre permissive : une
     * passerelle inventée reste refusée. Sans cette assertion, on pourrait
     * « réparer » les trois tests ci-dessus en retirant la règle Rule::in.
     */
    public function test_une_passerelle_inventee_reste_refusee(): void
    {
        $reponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/payment-terminals', [
                'name'         => 'TPE Fantôme',
                'gateway_type' => 'nexi',
                'fee_percent'  => 1.0,
            ]);

        $reponse->assertStatus(422)->assertJsonValidationErrors(['gateway_type']);
    }
}
