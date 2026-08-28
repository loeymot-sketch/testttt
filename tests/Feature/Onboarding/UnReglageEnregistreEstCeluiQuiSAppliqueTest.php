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
     * Les fichiers qui doivent passer par la porte unique, avec le nombre de lectures
     * directes que chacun avait.
     *
     * @return array<string, array{0:string, 1:int}>
     */
    public function fichiersQuiLisaientLaConfigEnDirect(): array
    {
        return [
            'roue publique · affichage et application' => ['app/Http/Controllers/Frontend/WheelController.php', 3],
            'écran de contrôle caisse'                 => ['app/Http/Controllers/Admin/Wheel/WheelCounterController.php', 1],
            'service de la roue'                       => ['app/Services/Wheel/WheelService.php', 1],
        ];
    }

    /**
     * @dataProvider fichiersQuiLisaientLaConfigEnDirect
     */
    public function test_aucune_lecture_directe_de_la_config_ne_subsiste(string $fichier, int $lecturesAvant): void
    {
        $source = file_get_contents(base_path($fichier));

        $this->assertNotFalse($source, "{$fichier} est introuvable.");

        $this->assertStringNotContainsString(
            "config('wheel.min_order_amount'",
            $source,
            "{$fichier} lit encore le minimum dans le FICHIER de configuration.\n"
            . "Ce fichier en avait {$lecturesAvant}. L'exploitant règle « minimum 15 € »,\n"
            . "et cette surface continue d'appliquer la valeur du fichier — un réglage\n"
            . "qui ment coûte plus cher qu'un réglage absent."
        );

        $this->assertStringContainsString(
            'minOrder()',
            $source,
            "{$fichier} doit passer par `WheelSettingsService::minOrder()`, la porte unique."
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
