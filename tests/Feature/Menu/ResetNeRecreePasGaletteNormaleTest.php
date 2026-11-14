<?php

namespace Tests\Feature\Menu;

use Tests\TestCase;

/**
 * [GOAL REMARQUES 2026-10-03 · T-3.8 R-078] Propriétaire, 25/09 : « même dans la caisse galette normal galette
 * Cayenne y a pas de normal ». « Galette Normale » a été retirée de la vente le 2026-09-25, mais
 * `menu:reset-le-cayenne` la gardait dans son spec : une réinitialisation FORCÉE l'aurait ressuscitée
 * (la garde de dérive fbe045524 ne protège que le chemin non forcé). Seules « Galette Cayenne » et
 * « Galette Classique » existent.
 *
 * Banc de SOURCE (même méthode que MenuResetDriftGuardTest) : la commande ne doit plus pouvoir l'écrire.
 */
class ResetNeRecreePasGaletteNormaleTest extends TestCase
{
    public function test_la_reinitialisation_ne_cree_plus_galette_normale(): void
    {
        $src = (string) file_get_contents(app_path('Console/Commands/MenuResetLeCayenneCommand.php'));

        $this->assertStringNotContainsString("'galette-normale'", $src, 'aucun slug galette-normale dans le spec ni dans step 9');
        $this->assertStringNotContainsString("'Galette Normale'", $src, 'aucun article « Galette Normale » écrit');
        $this->assertStringContainsString("'galette-cayenne'", $src, 'la Galette Cayenne reste dans la réinitialisation');
    }

    public function test_plus_aucune_vignette_de_repli_pour_galette_normale(): void
    {
        $this->assertArrayNotHasKey('galette-normale', (array) config('menu_images.items', require config_path('menu_images.php')));
        $this->assertStringNotContainsString("'galette-normale'", (string) file_get_contents(config_path('menu_images.php')));
    }
}
