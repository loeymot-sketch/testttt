<?php

namespace Tests\Feature\Onboarding;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-10 2026-08-28] Un écran ne doit pas proposer un choix que le serveur refuse.
 *
 * ═══ LE DÉFAUT ═══
 *
 * `PrintersComponent.vue:134` proposait trois largeurs de ticket :
 *
 *     32 (58 mm) · **42 (80 mm SAGA)** · 48 (80 mm)
 *
 * `PrinterRequest.php:57` n'en acceptait que deux : `Rule::in([32, 48])`.
 *
 * Choisir « 42 (80 mm SAGA) » — c'est-à-dire le modèle que l'écran NOMME — renvoyait
 * un 422. Et le champ n'avait **aucun affichage d'erreur**, alors que tous ses
 * voisins en ont un : le refus était invisible. Le commerçant choisissait la largeur
 * de son imprimante réelle, cliquait, et rien ne se passait.
 *
 * ═══ POURQUOI CE BANC LIT LE GABARIT ═══
 *
 * Vérifier « 42 est accepté » figerait un nombre. Ce qui compte est la RELATION :
 * toute valeur que l'écran propose doit être acceptée. Le banc lit donc les
 * `<option>` du gabarit et les soumet une par une. Si quelqu'un ajoute « 64 » à
 * l'écran sans toucher la règle, il rougit — et inversement.
 *
 * C'est la même classe de défaut que l'export→import dont les en-têtes ne bouclaient
 * pas, et que la station de cuisine inconnue qui rendait une erreur SQL brute : deux
 * bouts d'une même conversation qui ne se sont pas mis d'accord.
 */
class LEcranNeProposePasCeQueLeServeurRefuseTest extends TestCase
{
    use RefreshDatabase;

    private User $karim;

    private const GABARIT = 'resources/js/components/admin/settings/Printers/PrintersComponent.vue';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        if (! Branch::query()->whereKey(1)->exists()) {
            Branch::factory()->create(['id' => 1]);
        }

        $this->karim = User::factory()->create(['branch_id' => 1]);
        $this->karim->assignRole('Admin');
        Permission::findOrCreate('settings', 'sanctum');
        $this->karim->givePermissionTo(['settings']);
        $this->actingAs($this->karim, 'sanctum');
    }

    /** @return list<int> les largeurs que l'écran propose réellement */
    private function largeursProposeesParLEcran(): array
    {
        $gabarit = file_get_contents(base_path(self::GABARIT));

        $this->assertNotFalse($gabarit, 'Le gabarit des imprimantes est introuvable.');

        // On borne au bloc du sélecteur de largeur : le fichier contient d'autres
        // `<option>` (station, statut), et les confondre ferait mesurer autre chose.
        $debut = strpos($gabarit, 'id="p_width"');
        $this->assertNotFalse($debut, 'Le sélecteur de largeur a disparu du gabarit.');

        $bloc = substr($gabarit, $debut, (int) (strpos($gabarit, '</select>', $debut) - $debut));

        preg_match_all('/:value="(\d+)"/', $bloc, $captures);

        return array_map('intval', $captures[1]);
    }

    public function test_le_releve_mord_sinon_ce_banc_serait_vert_en_ne_lisant_rien(): void
    {
        $largeurs = $this->largeursProposeesParLEcran();

        $this->assertGreaterThanOrEqual(
            3,
            count($largeurs),
            "Le sélecteur de largeur ne rend plus ses options : ce banc ne mesure plus rien."
        );

        // Témoin : la largeur qui a révélé le défaut.
        $this->assertContains(42, $largeurs, "L'option « 42 (80 mm SAGA) » a disparu du gabarit.");
    }

    /**
     * @dataProvider largeursDuGabarit
     */
    public function test_chaque_largeur_proposee_par_l_ecran_est_acceptee(int $largeur): void
    {
        // La route est `admin/printers` — PAS `admin/settings/printers`. Ma première
        // version postait dans le vide et recevait un 404.
        $reponse = $this->postJson('/api/admin/printers', [
            'name'        => 'Imprimante ' . $largeur,
            // `Rule::in(['escpos_tcp','escpos_usb','browser_html'])` — la fixture
            // doit passer la validation SUR LES AUTRES CHAMPS, sinon on mesure leur
            // refus et pas celui de la largeur.
            'type'        => 'escpos_tcp',
            'host'        => 'imprimante.example.com',
            'port'        => 9100,
            'station'     => 'receipt',
            'width_chars' => $largeur,
            'status'      => Status::ACTIVE,
        ]);

        /*
         * ⚠️ ON EXIGE UN VRAI SUCCÈS, pas « autre chose que 422 ».
         *
         * Ma première version faisait `assertNotSame(422, ...)` — et elle passait sur
         * un 404, parce que j'avais écrit la mauvaise URL. Le banc était vert en
         * mesurant une route qui n'existe pas : la sentinelle au mauvais périmètre
         * que cette session traque partout, cette fois de ma main.
         *
         * Une assertion négative est presque toujours trop faible : elle est
         * satisfaite par tous les échecs sauf un.
         */
        $this->assertContains(
            $reponse->status(),
            [200, 201, 202],
            "L'écran propose « {$largeur} » et le serveur ne l'accepte pas.\n"
            . "Le commerçant choisit la largeur de son imprimante réelle, clique, et\n"
            . "rien ne se passe — d'autant que ce champ n'affichait aucune erreur.\n"
            . 'Réponse ' . $reponse->status() . ' : ' . mb_substr((string) $reponse->getContent(), 0, 250)
        );
    }

    /** @return array<string, array{0:int}> */
    public function largeursDuGabarit(): array
    {
        // Le fournisseur de données tourne AVANT `setUp()` : on relit le gabarit
        // directement, sans dépendre de l'application.
        $chemin = dirname(__DIR__, 3) . '/' . self::GABARIT;
        $gabarit = @file_get_contents($chemin);

        if ($gabarit === false) {
            return ['gabarit introuvable' => [0]];
        }

        $debut = strpos($gabarit, 'id="p_width"');

        if ($debut === false) {
            return ['sélecteur introuvable' => [0]];
        }

        $bloc = substr($gabarit, $debut, (int) (strpos($gabarit, '</select>', $debut) - $debut));
        preg_match_all('/:value="(\d+)"/', $bloc, $captures);

        $cas = [];
        foreach ($captures[1] as $largeur) {
            $cas[$largeur . ' caractères'] = [(int) $largeur];
        }

        return $cas === [] ? ['aucune option' => [0]] : $cas;
    }

    public function test_le_refus_de_largeur_est_visible_a_l_ecran(): void
    {
        $gabarit = file_get_contents(base_path(self::GABARIT));

        $debut = strpos($gabarit, 'id="p_width"');
        $bloc = substr($gabarit, (int) $debut, 900);

        // Tous les champs voisins affichent leur erreur ; celui-ci ne le faisait pas.
        // Un refus muet est indiscernable d'un écran cassé.
        $this->assertStringContainsString(
            'errors.width_chars',
            $bloc,
            "Le champ de largeur n'affiche pas son erreur : un refus serveur y est\n"
            . 'invisible, et le commerçant conclut que l\'écran ne marche pas.'
        );
    }
}
