<?php

namespace Tests\Feature\Onboarding;

use App\Services\Wheel\WheelSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [ONB-05 2026-08-28] Un réglage enregistré doit être celui qui s'applique.
 *
 * ═══ LE DÉFAUT ═══
 *
 * L'exploitant enregistre le minimum de commande de la roue depuis
 * `/admin/roue-reglages`. `WheelSettingsService::minOrder()` le relit — et n'avait
 * qu'UN SEUL appelant (`WheelPrizeController:242`).
 *
 * Cinq autres endroits lisaient `config('wheel.min_order_amount')` EN DIRECT :
 *
 *     WheelController:89     ce que la borne AFFICHE
 *     WheelController:303    ce que la validation ANNONCE
 *     WheelController:332    ce qui est APPLIQUÉ au client
 *     WheelCounterController:57
 *     WheelService:785
 *
 * L'exploitant réglait « minimum 15 € », un écran affichait 15 €, et la roue
 * continuait d'appliquer la valeur du fichier. **Un réglage qui ment coûte plus cher
 * qu'un réglage absent : il donne la certitude fausse d'avoir agi.** Les valeurs de
 * repli divergeaient en prime — 10 côté service, 0 côté lecteurs directs.
 *
 * ═══ CE QUI REND CE DÉFAUT REMARQUABLE ═══
 *
 * Le principe est écrit, dans le même fichier, trois cents lignes plus haut. Docblock
 * de `WheelService::segments()` :
 *
 *     « Lire `config('wheel.segments')` en direct ailleurs, ce serait ignorer les
 *       réglages du propriétaire sur une surface et pas sur l'autre : la roue
 *       montrerait des probabilités qu'elle n'applique pas. C'est le motif du
 *       "jumeau oublié", et il a déjà coûté deux fois ici. »
 *
 * Écrit, appliqué aux segments, pas appliqué au minimum de commande. Troisième fois.
 */
class UnReglageEnregistreEstCeluiQuiSAppliqueTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ VERSION 2 — la premiere listait TROIS fichiers en dur.
     *
     * Un audit adverse l'a releve : un nouveau lecteur direct de
     * `config('wheel.min_order_amount')` dans un QUATRIEME fichier n'aurait pas ete
     * vu. Or c'est exactement ainsi que le defaut est ne — un lecteur ajoute d'un
     * cote et pas de l'autre.
     *
     * On balaie donc tout `app/`, avec une liste blanche NOMMEE. Le banc devient un
     * cliquet : toute nouvelle lecture directe le fait rougir, ou qu'elle soit.
     */
    public function test_aucune_lecture_directe_de_la_config_ne_subsiste_nulle_part(): void
    {
        $racine = app_path();
        $fautifs = [];

        $iterateur = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine));

        foreach ($iterateur as $fichier) {
            if (! $fichier->isFile() || $fichier->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($fichier->getPathname());

            if ($source === false || ! str_contains($source, "config('wheel.min_order_amount'")) {
                continue;
            }

            $relatif = 'app/' . ltrim(str_replace($racine, '', $fichier->getPathname()), '/');

            if (in_array($relatif, self::LECTURES_LEGITIMES, true)) {
                continue;
            }

            $fautifs[] = $relatif;
        }

        sort($fautifs);

        $this->assertSame(
            [],
            $fautifs,
            "Ces fichiers lisent le minimum dans le FICHIER de configuration au lieu de\n"
            . "passer par `WheelSettingsService::minOrder()`. L'exploitant regle « minimum\n"
            . "15 € » et cette surface applique autre chose — un reglage qui ment coute\n"
            . "plus cher qu'un reglage absent.\n"
            . implode("\n", $fautifs)
        );
    }

    /**
     * Les seules lectures directes admises, chacune pour une raison ecrite.
     *
     * `WheelSettingsService` est la porte : il a le droit de lire le fichier, c'est
     * meme son role — poser la valeur de depart quand l'exploitant n'a rien enregistre.
     */
    private const LECTURES_LEGITIMES = [
        'app/Services/Wheel/WheelSettingsService.php',
    ];

    /**
     * Contrôle de perimetre : sans lui, le balayage ci-dessus serait vert le jour ou
     * la chaine cherchee change de forme (guillemets doubles, constante extraite),
     * et il ne mesurerait plus rien.
     */
    public function test_le_balayage_mord_bien_sur_la_porte_elle_meme(): void
    {
        $porte = file_get_contents(app_path('Services/Wheel/WheelSettingsService.php'));

        $this->assertStringContainsString(
            "config('wheel.min_order_amount'",
            $porte,
            "La forme cherchee par le balayage n'existe plus : il ne mesure plus rien."
        );
    }

    public function test_le_reglage_enregistre_prime_sur_le_fichier(): void
    {
        // Le fichier dit 10, l'exploitant dit 15 : c'est 15 qui doit sortir.
        config(['wheel.min_order_amount' => 10]);

        $reglages = app(WheelSettingsService::class);
        $reglages->save(['min_order' => '15']);

        $this->assertEqualsWithDelta(
            15.0,
            $reglages->minOrder(),
            0.001,
            "Ce que l'exploitant enregistre doit primer sur le fichier — sinon la page\n"
            . 'de réglages est un formulaire décoratif.'
        );
    }

    public function test_sans_reglage_enregistre_le_fichier_fait_foi(): void
    {
        // Le repli, mesuré : sans valeur enregistrée, on retombe sur le fichier. Sans
        // ce contrôle, un `minOrder()` qui renverrait toujours 0 passerait le banc
        // précédent dès que l'exploitant enregistre — et casserait tout le reste.
        config(['wheel.min_order_amount' => 12]);

        $this->assertEqualsWithDelta(
            12.0,
            app(WheelSettingsService::class)->minOrder(),
            0.001,
            'Sans réglage enregistré, la valeur du fichier doit sortir.'
        );
    }
}
