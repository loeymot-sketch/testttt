<?php

namespace Tests\Feature\Backup;

use App\Support\Backup\RestoreDrillResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * [GOAL G4 2026-09-03 · T4.2 · défaut V-09]
 *
 * `backup:verify-restore` rendait `FAILURE` par NEUF chemins différents. Trois seulement
 * persistaient un verdict (`RestoreDrillResult::store`). Les six autres — pilote non
 * mysql, nom de base absent, refus de sécurité base jetable == base vive, fichier
 * illisible, base vive injoignable, base jetable impossible à créer — sortaient en
 * silence.
 *
 * Conséquence exacte, et elle est grave : le succès de la veille RESTE le « dernier
 * résultat connu ». Le cockpit continue d'afficher « restauration de vérification
 * réussie » et `/health/ready` continue de répondre `ok` sur `restore_drill`, pendant
 * jusqu'à `RestoreDrillResult::MAX_AGE_HOURS`. Autrement dit : le drill échoue chaque
 * nuit, et l'écran dit que tout va bien.
 *
 * Un échec doit TOUJOURS effacer le vert précédent. Ce banc pose un vert de la veille,
 * déclenche chacune des six sorties, et exige un verdict rouge après chacune.
 *
 * Ancres des six sorties dans `BackupVerifyRestoreCommand` (état 2026-09-03, avant
 * correctif) : lignes 108, 116, 126, 149, 181, 207.
 */
class RestoreDrillPersisteTousLesEchecsTest extends TestCase
{
    /** @var list<string> */
    private array $fichiersTemporaires = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(RestoreDrillResult::CACHE_KEY);
        // Le verdict va en cache ET en fichier : sans disque simulé, ce banc écrirait un
        // faux verdict dans le `storage/app/backups/` du poste.
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach ($this->fichiersTemporaires as $chemin) {
            @unlink($chemin);
        }
        $this->fichiersTemporaires = [];
        parent::tearDown();
    }

    /** Le succès d'hier — celui qui ne doit JAMAIS survivre à l'échec d'aujourd'hui. */
    private function vertDeLaVeille(): void
    {
        RestoreDrillResult::store([
            'status' => 'green',
            'verified_at' => now()->subHours(2)->toIso8601String(),
            'file' => 'daily-hier.sql.gz',
            'sha256' => str_repeat('a', 64),
            'duration_s' => 40.0,
            'reasons' => [],
        ]);
        $this->assertSame('green', RestoreDrillResult::current()['status'], 'préalable du banc');
    }

    private function assertVerdictRouge(string $chemin): void
    {
        $etat = RestoreDrillResult::current();

        $this->assertSame(
            'failed',
            $etat['status'],
            "sortie « {$chemin} » : l'échec doit effacer le vert de la veille, pas le laisser en place"
        );
        $this->assertNotEmpty(
            $etat['reasons'],
            "sortie « {$chemin} » : un verdict rouge sans raison n'est pas exploitable"
        );
        $this->assertNotSame(
            'daily-hier.sql.gz',
            $etat['file'],
            "sortie « {$chemin} » : le verdict ne doit plus décrire la sauvegarde d'hier"
        );
    }

    private function fichierLisible(): string
    {
        $chemin = (string) tempnam(sys_get_temp_dir(), 'g4-drill-').'.sql.gz';
        file_put_contents($chemin, 'contenu factice');
        $this->fichiersTemporaires[] = $chemin;

        return $chemin;
    }

    /** Ligne 108 — le pilote de base par défaut n'est pas `mysql`. */
    public function test_ligne_108_pilote_non_mysql_persiste_un_echec(): void
    {
        $this->vertDeLaVeille();
        config(['database.default' => 'sqlite']);

        $code = Artisan::call('backup:verify-restore');

        $this->assertSame(1, $code);
        $this->assertVerdictRouge('pilote non mysql (108)');
    }

    /** Ligne 116 — le nom de la base vive manque dans la configuration. */
    public function test_ligne_116_nom_de_base_vive_absent_persiste_un_echec(): void
    {
        $this->vertDeLaVeille();
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => '',
        ]);

        $code = Artisan::call('backup:verify-restore');

        $this->assertSame(1, $code);
        $this->assertVerdictRouge('nom de base vive absent (116)');
    }

    /** Ligne 126 — refus de sécurité : la base jetable serait la base vive. */
    public function test_ligne_126_refus_base_jetable_egale_base_vive_persiste_un_echec(): void
    {
        $this->vertDeLaVeille();
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'foodking_factice',
        ]);

        $code = Artisan::call('backup:verify-restore', ['--scratch-db' => 'foodking_factice']);

        $this->assertSame(1, $code);
        $this->assertVerdictRouge('base jetable == base vive (126)');
    }

    /** Ligne 149 — le fichier de sauvegarde désigné est illisible. */
    public function test_ligne_149_fichier_illisible_persiste_un_echec(): void
    {
        $this->vertDeLaVeille();
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'foodking_factice',
        ]);

        $code = Artisan::call('backup:verify-restore', [
            '--file' => '/chemin/qui/n/existe/pas/daily-fantome.sql.gz',
        ]);

        $this->assertSame(1, $code);
        $this->assertVerdictRouge('fichier illisible (149)');
    }

    /** Ligne 181 — impossible de lire les comptes de la base vive (base injoignable). */
    public function test_ligne_181_base_vive_injoignable_persiste_un_echec(): void
    {
        $this->vertDeLaVeille();
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'foodking_factice',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => '1',
        ]);

        DB::shouldReceive('table')
            ->andThrow(new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused'));
        DB::shouldReceive('purge')->andReturnNull();

        $code = Artisan::call('backup:verify-restore', ['--file' => $this->fichierLisible()]);

        $this->assertSame(1, $code);
        $this->assertVerdictRouge('base vive injoignable (181)');
    }

    /** Ligne 207 — la base jetable n'a pas pu être (re)créée. */
    public function test_ligne_207_base_jetable_non_creee_persiste_un_echec(): void
    {
        $this->vertDeLaVeille();
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'foodking_factice',
            // Rien n'écoute sur le port 1 : le client `mysql` du DROP/CREATE échoue
            // immédiatement, sans attente ni effet de bord.
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => '1',
            'database.connections.mysql.username' => 'utilisateur-factice',
            'database.connections.mysql.password' => '',
        ]);

        // Les comptes de la base vive doivent RÉUSSIR pour atteindre la création de la
        // base jetable : c'est la seule chose qu'on simule ici.
        $requete = Mockery::mock();
        $requete->shouldReceive('count')->andReturn(5);
        DB::shouldReceive('table')->andReturn($requete);
        DB::shouldReceive('purge')->andReturnNull();

        $code = Artisan::call('backup:verify-restore', ['--file' => $this->fichierLisible()]);

        $this->assertSame(1, $code);
        $this->assertVerdictRouge('base jetable non créée (207)');
    }

    /**
     * Garde-fou : le correctif ne doit pas se contenter d'écrire « failed » à chaque
     * appel. Un drill qui n'a jamais tourné reste `unknown`, et un vert légitime reste
     * vert tant qu'aucune exécution ne l'a contredit.
     */
    public function test_le_vert_legitime_survit_a_l_absence_d_execution(): void
    {
        $this->vertDeLaVeille();

        $this->assertSame('green', RestoreDrillResult::current()['status']);
    }
}
