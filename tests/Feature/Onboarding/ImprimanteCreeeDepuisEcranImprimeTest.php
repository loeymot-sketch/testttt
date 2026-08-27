<?php

namespace Tests\Feature\Onboarding;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Printer;
use App\Models\User;
use App\Services\Hardware\EscPosPrinterService;
use App\Services\Hardware\PrinterTransport\NullPrinterTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [ONB-10 2026-08-27] Le premier geste d'un nouveau commerçant : brancher son imprimante.
 *
 * Trouvé en auditant l'écran Paramètres > Imprimantes, où les deux imprimantes du
 * Cayenne s'affichaient « Archivé » en gris. Vérification en base : elles sont à
 * `status = 5`, c'est-à-dire `App\Enums\Status::ACTIVE`. Elles n'étaient donc pas
 * archivées du tout — l'écran mentait.
 *
 * En remontant, TROIS lectures incompatibles de la même colonne cohabitaient :
 *
 *   - les trois chemins d'impression du produit cherchent `Status::ACTIVE` = **5**
 *     (KitchenTicketAutoPrinter, PosReceiptPrintController, et le listener
 *     d'encaissement comptoir) — et les imprimantes réelles sont à 5 ;
 *   - l'écran testait `status === 1` pour afficher « Actif », et son bouton
 *     « Archivé » écrivait **5** ;
 *   - la validation n'acceptait que **0 ou 1**, et le contrôleur créait à **1**.
 *
 * Les conséquences, toutes atteignables depuis l'écran :
 *
 *   1. une imprimante créée depuis l'écran naissait à 1 — une valeur qu'AUCUN chemin
 *      d'impression ne reconnaît. Le commerçant ajoute son imprimante, l'écran
 *      l'affiche « Actif » en vert, et aucun ticket ne sort jamais ;
 *   2. le commerçant qui voyait ses vraies imprimantes marquées « Archivé » et
 *      cliquait « Actif » pour corriger écrivait 1 : son geste de réparation était
 *      exactement ce qui cassait l'impression, et l'écran l'en félicitait en vert ;
 *   3. le bouton « Archivé » écrivait 5, refusé par la validation — impossible
 *      d'archiver une imprimante depuis l'écran prévu pour ça.
 *
 * La vérité retenue est celle du serveur (5 = actif) : c'est celle des données
 * réelles et des trois chemins d'impression. Basculer l'ensemble sur 1 aurait
 * désactivé les imprimantes en service du Cayenne.
 *
 * Ce n'est pas la première fois sur cet écran : un commentaire de
 * PrintersComponent.vue documente déjà le même motif sur le champ `type`
 * (« escpos_network » écrit par l'écran, refusé par la validation, 422 silencieux).
 * D'où le test de cohérence n°3 ci-dessous, qui compare les deux listes plutôt que
 * de vérifier une valeur à la fois.
 */
class ImprimanteCreeeDepuisEcranImprimeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branche;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // `SafeRemoteHost` bloque la boucle locale et le RFC1918 par défaut. Une
        // imprimante ESC/POS vit sur le réseau local du restaurant : on ouvre la plage
        // pour CETTE classe seulement, comme le fait déjà PrinterControllerTest. La
        // sentinelle dédiée (PrinterHostAllowlistSentinelTest) vérifie le blocage avec
        // une liste vide — ce test-ci parle du statut, pas de l'hôte.
        config(['security.safe_remote_host_allowlist' => [
            '127.0.0.0/8:9100-9103',
            '192.168.0.0/16:9100-9103',
        ]]);

        $this->branche = Branch::factory()->create();
        $this->admin = User::factory()->create(['branch_id' => $this->branche->id]);
        $this->admin->assignRole('Admin');
    }

    /** Le corps exact que l'écran envoie à la création, statut laissé au serveur. */
    private function saisieDeLEcran(array $ecrasements = []): array
    {
        return array_merge([
            'name'        => 'Imprimante Caisse',
            'type'        => 'escpos_tcp',
            'host'        => '127.0.0.1',
            'port'        => 9100,
            'station'     => 'receipt',
            'width_chars' => 48,
        ], $ecrasements);
    }

    /**
     * Le test qui compte. Un commerçant branche son imprimante, puis encaisse : le
     * ticket doit partir. On ne vérifie pas une valeur en base, on exerce le chemin
     * réel jusqu'à l'envoi des octets.
     */
    public function test_une_imprimante_ajoutee_depuis_l_ecran_est_trouvee_par_l_impression(): void
    {
        $reponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/printers', $this->saisieDeLEcran());

        $reponse->assertCreated();

        $transport = new NullPrinterTransport();
        $resultat = (new EscPosPrinterService($transport))->openDrawer(null, (int) $this->branche->id);

        $this->assertTrue(
            $resultat['success'],
            "L'imprimante vient d'être ajoutée depuis l'écran et l'impression ne la trouve\n"
            . "pas. Le commerçant voit « Actif » en vert et aucun ticket ne sort — le tout\n"
            . "premier geste de son installation est sans effet."
        );
        $this->assertCount(1, $transport->sent);
    }

    /**
     * Le statut par défaut doit être celui que les chemins d'impression cherchent.
     * Assertion séparée pour que l'échec nomme la valeur, pas seulement l'effet.
     */
    public function test_le_statut_par_defaut_est_celui_que_l_impression_reconnait(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/printers', $this->saisieDeLEcran())
            ->assertCreated();

        $imprimante = Printer::query()->firstOrFail();

        $this->assertSame(
            Status::ACTIVE,
            (int) $imprimante->status,
            "Une imprimante créée sans statut explicite naît avec une valeur qu'aucun\n"
            . 'chemin d\'impression ne reconnaît (attendu ' . Status::ACTIVE
            . ', obtenu ' . (int) $imprimante->status . ')."'
        );
    }

    /**
     * Le test de cohérence : les valeurs que l'écran peut écrire doivent TOUTES être
     * acceptées par le serveur. C'est celui-ci qui aurait attrapé le défaut d'origine
     * — et aussi celui du champ `type` que l'écran documente déjà.
     */
    public function test_toute_valeur_de_statut_que_l_ecran_peut_ecrire_est_acceptee(): void
    {
        $chemin = resource_path('js/components/admin/settings/Printers/PrintersComponent.vue');
        $this->assertFileExists($chemin);

        preg_match_all(
            '/<input\s+:value="(\d+)"[^>]*v-model\.number="form\.status"/',
            file_get_contents($chemin),
            $m
        );

        $valeursDeLEcran = array_map('intval', $m[1]);

        $this->assertNotEmpty(
            $valeursDeLEcran,
            "Aucun bouton de statut trouvé dans PrintersComponent.vue.\n"
            . "Le formulaire a changé de forme : adapter ce test, pas le supprimer."
        );

        foreach ($valeursDeLEcran as $valeur) {
            $reponse = $this->actingAs($this->admin, 'sanctum')
                ->postJson('/api/admin/printers', $this->saisieDeLEcran([
                    'name'   => 'Imprimante ' . $valeur,
                    'status' => $valeur,
                ]));

            $this->assertTrue(
                $reponse->status() === 201,
                "L'écran propose un bouton qui écrit status={$valeur}, et le serveur le\n"
                . "refuse (HTTP {$reponse->status()}). Le commerçant clique, rien ne se passe,\n"
                . "et l'écran ne lui dit pas pourquoi."
            );
        }
    }

    /**
     * Contrôle négatif : aligner les conventions ne doit pas rendre la colonne libre.
     * Sans cette assertion, on « réparerait » le test ci-dessus en retirant Rule::in.
     */
    public function test_une_valeur_de_statut_hors_convention_reste_refusee(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/printers', $this->saisieDeLEcran(['status' => 1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }
}
