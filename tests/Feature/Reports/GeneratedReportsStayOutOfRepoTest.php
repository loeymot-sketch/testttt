<?php

namespace Tests\Feature\Reports;

use App\Console\Commands\RawMaterialFicheCommand;
use App\Console\Commands\RawMaterialFoodCostCommand;
use App\Support\GeneratedReportPath;
use Database\Seeders\RepairMultiVariationFixturesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * [SUPERVISION 2026-08-22] LA SUITE DE TESTS NE DOIT RIEN ÉCRIRE DANS `reports/`.
 *
 * CE QUI S'EST PASSÉ (mesuré, pas supposé)
 * `php artisan test` réécrivait trois fichiers du dépôt :
 *   · `reports/goal-mega-2026-07-22/FICHE_PARAMETRES_INGREDIENTS.md` — fichier TRACKÉ, dont
 *     l'en-tête dit « Owner : corrige les quantités ». Le propriétaire l'édite À LA MAIN ;
 *     un lancement de tests l'écrasait, sans un mot, avec la sortie d'une fixture.
 *   · `reports/goal-mega-2026-07-22/FOOD_COST_REPORT.md` — rempli de « 1 produits actifs,
 *     Cayenne 10,00 € » là où le catalogue réel en compte 57 et vend le Cayenne 7,40 €.
 *   · `reports/data-repair/MULTI_VARIATION_AUDIT_<date>.md` — un fichier NEUF par jour
 *     calendaire, en-tête « **Mode: FORCED (DB MUTATED)** ».
 *
 * POURQUOI UN TEST ET PAS UN COMMENTAIRE
 * Un commentaire n'a pas de fil de détente. La prochaine commande qui écrira un rapport
 * refera `base_path('reports/…')` par mimétisme, et personne ne le verra : le symptôme est un
 * `git status` sale, que tout le monde attribue à autre chose.
 *
 * ET CE N'EST PAS QU'UNE QUESTION DE PROPRETÉ
 * `scripts/deploy/deploy.sh:103` REFUSE de déployer quand `git status` n'est pas vide. Un
 * arbre sali par un lancement de tests pousse l'opérateur vers `--force`, qui écrase les
 * hot-patches SCP — précisément ce que cette garde existait pour empêcher.
 */
class GeneratedReportsStayOutOfRepoTest extends TestCase
{
    use RefreshDatabase;

    /** Empreinte d'un chemin : absent, ou présent avec sa taille + son contenu. */
    private function fingerprint(string $absolutePath): ?string
    {
        return File::exists($absolutePath) ? md5_file($absolutePath) : null;
    }

    public function test_la_fiche_ingredients_du_depot_n_est_jamais_touchee_par_les_tests(): void
    {
        $repoPath = base_path(RawMaterialFicheCommand::FICHE_PATH);
        $before = $this->fingerprint($repoPath);

        $this->artisan('raw-materials:fiche')->assertExitCode(0);

        $this->assertSame(
            $before,
            $this->fingerprint($repoPath),
            'La suite de tests a réécrit un fichier du dépôt que le propriétaire édite à la main.'
        );
    }

    public function test_le_rapport_food_cost_du_depot_n_est_jamais_touche_par_les_tests(): void
    {
        $repoPath = base_path(RawMaterialFoodCostCommand::REPORT_PATH);
        $before = $this->fingerprint($repoPath);

        $this->artisan('raw-materials:food-cost')->assertExitCode(0);

        $this->assertSame(
            $before,
            $this->fingerprint($repoPath),
            'La suite de tests a réécrit le rapport food-cost du dépôt avec des données de fixture.'
        );
    }

    public function test_l_audit_multi_variation_n_atterrit_jamais_dans_le_depot(): void
    {
        $relative = 'reports/data-repair/MULTI_VARIATION_AUDIT_' . now()->toDateString() . '.md';
        $repoPath = base_path($relative);
        $before = $this->fingerprint($repoPath);

        (new RepairMultiVariationFixturesSeeder())->run(false);

        $this->assertSame(
            $before,
            $this->fingerprint($repoPath),
            'Le seeder a déposé un audit « FORCED (DB MUTATED) » dans le dépôt.'
        );
    }

    /**
     * Le rapport doit tout de même être ÉCRIT quelque part — sinon on aurait « réparé » le
     * problème en cassant la fonctionnalité, et les tests de contenu passeraient sur du vide.
     */
    public function test_le_rapport_est_bien_ecrit_ailleurs_et_non_perdu(): void
    {
        $out = GeneratedReportPath::resolve(RawMaterialFoodCostCommand::REPORT_PATH);

        $this->assertStringNotContainsString(
            base_path('reports'),
            $out,
            'En testing, un rapport généré ne doit pas résoudre vers reports/ du dépôt.'
        );

        File::delete($out);
        $this->artisan('raw-materials:food-cost')->assertExitCode(0);

        $this->assertTrue(File::exists($out), 'Le rapport doit exister à son chemin de test.');
        $this->assertStringContainsString('Food Cost', File::get($out));
    }

    /** `--out=` reste prioritaire : c'est ce qui permet d'affirmer sur un chemin choisi. */
    public function test_l_option_out_a_la_priorite(): void
    {
        $chosen = storage_path('framework/testing/out-explicite-food-cost.md');
        File::ensureDirectoryExists(dirname($chosen));
        File::delete($chosen);

        $this->artisan('raw-materials:food-cost', ['--out' => $chosen])->assertExitCode(0);

        $this->assertTrue(File::exists($chosen), '--out= doit décider du chemin final.');
    }
}
