<?php

namespace Tests\Feature\Onboarding;

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [ONB-01 2026-08-28] La borne imprimait l'identité d'un autre restaurant.
 *
 * `resources/views/master.blade.php` livre au pont d'impression le téléphone et
 * l'adresse à imprimer en en-tête du ticket borne. Le commentaire posé le 2026-07-03
 * annonçait « Fallback config si branche vide » — mais le code ne lisait QUE
 * `config('printing.receipt.*')` et ne consultait jamais la filiale. Le repli était
 * le seul chemin.
 *
 * Conséquence pour un nouveau commerçant : il remplit consciencieusement son adresse
 * et son téléphone dans Paramètres › Filiales, et sa borne continue d'imprimer ceux
 * inscrits dans un fichier de configuration — ceux du Cayenne. Sur un document remis
 * au client, avec l'identité d'un établissement qui n'est pas le sien.
 *
 * Le ticket de CAISSE faisait déjà correctement le repli depuis le début :
 * `optional($branch)->address ?: config(...)` (OrderReceiptEscPosRenderer.php:74).
 * Les deux surfaces divergeaient ; on aligne la borne sur la caisse.
 *
 * Ce banc lit le shell rendu, pas le fichier source : c'est la charge réellement
 * envoyée au pont d'impression qui compte.
 */
class TicketBorneImprimeSonIdentiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // [ONB-01 2026-08-28] L'identite de la borne est desormais mise en cache 5 min
        // (elle etait lue a chaque chargement de page du SPA). On vide entre les tests
        // pour qu'ils mesurent la base, pas un reste du test precedent.
        //
        // Consequence a connaitre en exploitation : apres modification de l'adresse
        // dans Filiales, la borne imprime l'ancienne pendant au plus 5 minutes.
        \Illuminate\Support\Facades\Cache::flush();
    }

    private function shellRendu(): string
    {
        // `/kiosk/idle` est servi par le catch-all du SPA, donc par `master.blade.php`.
        $reponse = $this->get('/kiosk/idle');
        $reponse->assertOk();

        return (string) $reponse->getContent();
    }

    public function test_la_borne_imprime_l_adresse_de_la_filiale_pas_celle_de_la_configuration(): void
    {
        config([
            'printing.receipt.phone'   => '03 65 67 82 91',
            'printing.receipt.address' => "437 Rue Élie Gruyelle, 62110 Hénin-Beaumont",
        ]);

        $filiale = Branch::factory()->create([
            'phone'   => '0102030405',
            'address' => '12 avenue des Nouveaux Commerçants, 75011 Paris',
        ]);
        Settings::group('site')->set(['site_default_branch' => $filiale->id]);

        $shell = $this->shellRendu();

        $this->assertStringContainsString(
            '12 avenue des Nouveaux Commer',
            $shell,
            "Le ticket borne doit porter l'adresse SAISIE par le commerçant dans\n"
            . "Filiales. S'il porte celle de la configuration, il imprime l'identité\n"
            . "d'un autre établissement sur un document remis au client."
        );
        $this->assertStringContainsString('0102030405', $shell);

        $this->assertStringNotContainsString(
            'Gruyelle',
            $shell,
            "L'adresse de repli ne doit apparaître QUE si la filiale n'en a pas."
        );
    }

    /**
     * Contrôle négatif : le repli sur la configuration doit rester. Sans lui, une
     * installation dont la filiale n'a pas encore d'adresse imprimerait un ticket
     * sans en-tête du tout — on remplacerait un mauvais en-tête par aucun.
     */
    public function test_la_configuration_reste_le_repli_quand_la_filiale_est_vide(): void
    {
        config([
            'printing.receipt.phone'   => '0999999999',
            'printing.receipt.address' => 'Adresse de repli',
        ]);

        $filiale = Branch::factory()->create(['phone' => '', 'address' => '']);
        Settings::group('site')->set(['site_default_branch' => $filiale->id]);

        $shell = $this->shellRendu();

        $this->assertStringContainsString('Adresse de repli', $shell);
        $this->assertStringContainsString('0999999999', $shell);
    }

    /**
     * Second contrôle négatif : ce shell sert TOUTES les pages du SPA. Une filiale
     * introuvable ne doit jamais le faire tomber — sinon la caisse, la borne et
     * l'administration deviendraient inaccessibles d'un coup.
     */
    public function test_une_filiale_introuvable_ne_casse_pas_le_shell(): void
    {
        Settings::group('site')->set(['site_default_branch' => 999999]);

        $this->get('/kiosk/idle')->assertOk();
    }
}
