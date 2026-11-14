<?php

namespace Tests\Feature\Observability;

use App\Models\User;
use App\Support\Backup\RestoreDrillResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * [GOAL G4 2026-09-03 · T4.4 · défaut N-01]
 *
 * Deux surfaces, un seul fait : l'âge de la dernière sauvegarde. Elles ne comptaient pas
 * pareil pendant 29 minutes par jour.
 *
 * Le serveur compare bien en décimal (`$ageHeuresExact > 26`) pour produire la bande
 * d'alertes — mais il PUBLIE la valeur arrondie à l'heure (`(int) round(26.33) === 26`).
 * L'écran, lui, recalculait son vert sur cette valeur publiée : `26 <= 26` est vrai, donc
 * carte VERTE. Entre 26 h 01 et 26 h 29, le même écran affichait donc « dernière
 * sauvegarde il y a 26 h » en vert, au-dessus d'une bande d'alertes disant que la
 * sauvegarde est en retard. Un écran qui se contredit n'est plus consulté.
 *
 * Ce banc n'inspecte pas une couleur : il vérifie que la valeur PUBLIÉE ne permet aucune
 * autre conclusion que celle de `/health/ready`. C'est le seul niveau où le défaut vit —
 * la règle d'écran, elle, est tenue par `tests/js/systemHealthAgeSauvegardeNonArrondi.spec.js`.
 *
 * Correctif attendu : le serveur publie la valeur décimale ET son verdict (`fraiche`).
 * Le serveur décide, l'écran affiche.
 */
class CockpitEtReadinessMemeAgeSauvegardeTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $fichiersCrees = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Cache::forget('healthz:last');
        Cache::forget('scheduler:last_tick');
        Cache::forget(RestoreDrillResult::CACHE_KEY);
        Storage::fake('local');
        Cache::forever('healthz:last', [
            'status' => 'ok',
            'checks' => ['db' => 'ok', 'redis' => 'ok', 'websocket' => 'ok', 'fiscal_chain' => 'ok', 'queue_pending' => 0],
            'timestamp' => now()->toIso8601String(),
        ]);
        Cache::forever('scheduler:last_tick', now()->timestamp);
    }

    protected function tearDown(): void
    {
        foreach ($this->fichiersCrees as $chemin) {
            @unlink($chemin);
        }
        $this->fichiersCrees = [];
        parent::tearDown();
    }

    /** Dépose la sauvegarde la plus récente du dossier, datée de `$minutes` minutes. */
    private function sauvegardeAgeeDe(int $minutes): void
    {
        $dossier = storage_path('backups'.DIRECTORY_SEPARATOR.'db-daily');
        if (! is_dir($dossier)) {
            mkdir($dossier, 0775, true);
        }
        $chemin = $dossier.DIRECTORY_SEPARATOR.'daily-g4-age.sql.gz';
        file_put_contents($chemin, 'dump');
        touch($chemin, time() - $minutes * 60);
        $this->fichiersCrees[] = $chemin;
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Admin');

        return $u;
    }

    private function cockpit(): array
    {
        return $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/observability/system-health')
            ->assertOk()
            ->json();
    }

    /**
     * Les trois minutes que le GOAL nomme : 26 h 01, 26 h 15, 26 h 29. Toutes arrondissent
     * à 26 — la valeur exacte que l'ancien écran lisait comme « dans les clous ».
     *
     * @dataProvider minutesQuiArrondissentAVingtSix
     */
    public function test_les_deux_surfaces_rendent_le_meme_verdict_au_dessus_du_seuil(int $minutes): void
    {
        $this->sauvegardeAgeeDe($minutes);

        $readiness = $this->getJson('/api/health/ready')->json();
        $this->assertSame(
            'degraded',
            $readiness['subsystems']['backup_age']['status'],
            'readiness compare en décimal : au-delà de 26 h il dégrade'
        );

        $c = $this->cockpit();

        $this->assertFalse(
            (bool) ($c['sauvegarde']['fraiche'] ?? false),
            'le cockpit doit PUBLIER son verdict de fraîcheur, et il doit être le même que celui de readiness'
        );
        $this->assertFalse(
            $c['sauvegarde']['age_heures'] <= $c['sauvegarde']['attendu_max_h'],
            "la valeur publiée ne doit permettre aucune lecture « dans les clous » : "
            ."l'écran appliquait exactement cette comparaison et concluait au vert"
        );
        $this->assertNotEmpty(
            array_filter($c['alertes'], fn ($a) => str_contains($a, 'dernière sauvegarde')),
            'et la bande d’alertes du même écran dit déjà que la sauvegarde est en retard'
        );
    }

    /** @return array<string, array{0:int}> */
    public static function minutesQuiArrondissentAVingtSix(): array
    {
        return [
            '26 h 01' => [26 * 60 + 1],
            '26 h 15' => [26 * 60 + 15],
            '26 h 29' => [26 * 60 + 29],
        ];
    }

    /**
     * Le garde-fou : sous le seuil, les deux surfaces disent OUI. Un correctif qui
     * déclarerait tout périmé passerait les trois cas précédents sans rien prouver.
     */
    public function test_les_deux_surfaces_rendent_le_meme_verdict_sous_le_seuil(): void
    {
        $this->sauvegardeAgeeDe(25 * 60 + 30);

        $readiness = $this->getJson('/api/health/ready')->json();
        $this->assertSame('ok', $readiness['subsystems']['backup_age']['status']);

        $c = $this->cockpit();

        $this->assertTrue(
            (bool) ($c['sauvegarde']['fraiche'] ?? false),
            '25 h 30 est sous le seuil : le cockpit doit le dire aussi'
        );
        $this->assertTrue($c['sauvegarde']['age_heures'] <= $c['sauvegarde']['attendu_max_h']);
        $this->assertEmpty(
            array_filter($c['alertes'], fn ($a) => str_contains($a, 'dernière sauvegarde'))
        );
    }

    /**
     * La valeur publiée doit rester assez précise pour être vérifiable à la main : un
     * arrondi à l'heure efface justement les 29 minutes où les deux surfaces divergeaient.
     */
    public function test_l_age_publie_n_est_pas_arrondi_a_l_heure(): void
    {
        $this->sauvegardeAgeeDe(26 * 60 + 20);

        $age = $this->cockpit()['sauvegarde']['age_heures'];

        $this->assertGreaterThan(
            26.0,
            (float) $age,
            "26 h 20 doit se publier comme strictement supérieur à 26 — l'arrondi le rendait égal"
        );
        $this->assertLessThan(27.0, (float) $age);
    }
}
