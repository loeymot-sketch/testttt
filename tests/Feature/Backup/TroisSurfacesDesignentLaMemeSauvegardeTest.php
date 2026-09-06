<?php

namespace Tests\Feature\Backup;

use App\Support\Backup\RestoreDrillResult;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * [GOAL G4 2026-09-03 · suite de T4.1] Trois surfaces lisent `storage/backups/db-daily`,
 * et elles n'y cherchaient pas le même motif.
 *
 * Le cockpit et `/health/ready` prenaient le plus récent `*.sql.gz` ; la commande
 * `backup:verify-restore` restaurait le plus récent `daily-*.sql.gz`. Il suffit donc d'un
 * dump manuel (`pre-*.sql.gz`) plus récent que la dernière sauvegarde quotidienne pour que
 * le rapprochement fichier ↔ drill introduit par T4.1 devienne **structurellement
 * impossible** : l'écran désigne un fichier que le drill ne testera jamais.
 *
 * Le résultat n'est pas un faux vert — c'est pire à l'usage : un rouge permanent, que
 * personne ne regarde plus au bout d'une semaine.
 *
 * La règle retenue : la garantie de santé porte sur **la chaîne de sauvegarde
 * automatique**, pas sur les dumps manuels. Les trois surfaces cherchent donc
 * `daily-*.sql.gz`, depuis un seul endroit.
 */
class TroisSurfacesDesignentLaMemeSauvegardeTest extends TestCase
{
    private string $dossier;

    /** @var list<string> */
    private array $deposes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dossier = storage_path('backups'.DIRECTORY_SEPARATOR.'db-daily');
        File::ensureDirectoryExists($this->dossier);
    }

    protected function tearDown(): void
    {
        foreach ($this->deposes as $chemin) {
            @unlink($chemin);
        }

        parent::tearDown();
    }

    private function deposer(string $nom, int $ageSecondes): string
    {
        $chemin = $this->dossier.DIRECTORY_SEPARATOR.$nom;
        file_put_contents($chemin, gzencode('-- dump de test '.$nom));
        touch($chemin, time() - $ageSecondes);
        $this->deposes[] = $chemin;

        return $chemin;
    }

    /**
     * Un dump manuel frais ne doit pas détourner l'écran de la sauvegarde quotidienne :
     * c'est cette dernière que le drill éprouve, et donc la seule que l'écran peut attester.
     */
    public function test_un_dump_manuel_plus_recent_ne_detourne_pas_la_designation(): void
    {
        $quotidienne = $this->deposer('daily-9999-01-02.sql.gz', 3600);
        $this->deposer('pre-migration-9999-01-03.sql.gz', 60);

        $designee = RestoreDrillResult::cheminSauvegardeCourante();

        $this->assertSame(
            $quotidienne,
            $designee,
            'La surface de santé doit désigner la dernière sauvegarde QUOTIDIENNE, '
            .'pas un dump manuel que `backup:verify-restore` ne restaurera jamais.'
        );
    }

    /**
     * La commande de vérification et la surface de santé doivent choisir le même fichier —
     * sinon le rapprochement introduit en T4.1 ne peut jamais aboutir.
     */
    public function test_la_commande_et_la_surface_choisissent_le_meme_fichier(): void
    {
        $this->deposer('daily-9999-01-01.sql.gz', 7200);
        $attendue = $this->deposer('daily-9999-01-02.sql.gz', 3600);
        $this->deposer('pre-migration-9999-01-03.sql.gz', 60);

        $commande = new \ReflectionMethod(
            \App\Console\Commands\Backup\BackupVerifyRestoreCommand::class,
            'resolveBackupFile'
        );
        $commande->setAccessible(true);

        $instance = app(\App\Console\Commands\Backup\BackupVerifyRestoreCommand::class);
        $instance->setLaravel(app());
        $instance->setInput(new \Symfony\Component\Console\Input\ArrayInput(
            [],
            $instance->getDefinition()
        ));

        $this->assertSame(
            $attendue,
            $commande->invoke($instance),
            'La commande doit restaurer exactement le fichier que la surface de santé affiche.'
        );

        $this->assertSame(
            $attendue,
            RestoreDrillResult::cheminSauvegardeCourante(),
            'La surface de santé doit afficher exactement le fichier que la commande restaure.'
        );
    }

    /**
     * Le motif partagé ne retient pas un dump manuel. Éprouvé sur un dossier temporaire,
     * pour que le banc soit vrai même quand la machine porte de vraies sauvegardes.
     */
    public function test_le_motif_partage_ignore_les_dumps_manuels(): void
    {
        $bac = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g4-motif-'.bin2hex(random_bytes(4));
        mkdir($bac);

        foreach (['daily-9999-01-02.sql.gz', 'pre-migration-9999-01-03.sql.gz', 'not-sql.gz'] as $nom) {
            file_put_contents($bac.DIRECTORY_SEPARATOR.$nom, 'x');
        }

        $retenus = array_map(
            'basename',
            glob($bac.DIRECTORY_SEPARATOR.RestoreDrillResult::MOTIF_SAUVEGARDE) ?: []
        );

        foreach (glob($bac.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($bac);

        $this->assertSame(
            ['daily-9999-01-02.sql.gz'],
            $retenus,
            'Le motif partagé doit retenir la chaîne quotidienne, et elle seule : '
            .'ni dump manuel avant migration, ni fichier qui n\'est pas une sauvegarde.'
        );
    }
}
