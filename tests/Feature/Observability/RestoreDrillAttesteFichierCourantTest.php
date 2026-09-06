<?php

namespace Tests\Feature\Observability;

use App\Models\User;
use App\Support\Backup\RestoreDrillResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * [GOAL G4 2026-09-03 · T4.1 · défaut V-08]
 *
 * Le cockpit publiait côte à côte DEUX faits sans jamais les rapprocher :
 *   - `sauvegarde.dernier_fichier` — le `.sql.gz` le plus récent sur le disque ;
 *   - `sauvegarde.restauration`    — le verdict du dernier drill de restauration.
 *
 * Rien ne vérifiait qu'ils parlent du MÊME fichier. Le scénario réel : le drill de 5 h
 * remonte A et écrit « vert » ; la sauvegarde de 3 h du lendemain produit B, corrompu.
 * Jusqu'à la nuit suivante, l'écran affiche « restauration de vérification réussie »
 * au-dessus du nom de B — c'est-à-dire qu'il affirme qu'un fichier jamais restauré l'a
 * été. C'est le pire des faux verts : il porte précisément sur la seule chose qui
 * protège d'une perte de données.
 *
 * Le nom ET l'empreinte SHA-256 du fichier restauré sont pourtant persistés tous les
 * deux par `backup:verify-restore` (`RestoreDrillResult::store`). Il ne manquait que la
 * comparaison.
 *
 * Ce banc l'exige sur les DEUX surfaces (cockpit et `/health/ready`), et sur les deux
 * façons de différer : un autre NOM, et le même nom avec une autre EMPREINTE (un fichier
 * réécrit sous le même nom).
 *
 * Le dernier cas — même nom, même empreinte — doit rester VERT : un correctif qui
 * rendrait tout rouge ne serait pas un correctif, seulement une autre façon de ne rien
 * dire.
 */
class RestoreDrillAttesteFichierCourantTest extends TestCase
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
        // Le verdict du drill est persisté en cache ET en fichier : sans disque simulé,
        // ce test écrirait un faux verdict dans le `storage/app/backups/` du poste.
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach ($this->fichiersCrees as $chemin) {
            @unlink($chemin);
        }
        $this->fichiersCrees = [];
        parent::tearDown();
    }

    /**
     * Dépose un vrai `.sql.gz` dans le dossier réellement lu par le cockpit et par
     * `/health/ready` (les deux font un `glob()` sur `storage_path()`, pas sur le disque
     * simulé), et le rend le plus récent. Rend [nom, empreinte].
     *
     * @return array{0:string, 1:string}
     */
    private function deposerSauvegarde(string $nom, string $contenu): array
    {
        $dossier = storage_path('backups'.DIRECTORY_SEPARATOR.'db-daily');
        if (! is_dir($dossier)) {
            mkdir($dossier, 0775, true);
        }
        $chemin = $dossier.DIRECTORY_SEPARATOR.$nom;
        file_put_contents($chemin, $contenu);
        touch($chemin, time() - 60); // frais (< 26 h) et le plus récent du dossier
        $this->fichiersCrees[] = $chemin;

        return [$nom, (string) hash_file('sha256', $chemin)];
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Admin');

        return $u;
    }

    private function santeSaine(): void
    {
        Cache::forever('healthz:last', [
            'status' => 'ok',
            'checks' => ['db' => 'ok', 'redis' => 'ok', 'websocket' => 'ok', 'fiscal_chain' => 'ok', 'queue_pending' => 0],
            'timestamp' => now()->toIso8601String(),
        ]);
        Cache::forever('scheduler:last_tick', now()->timestamp);
    }

    private function lireCockpit(): array
    {
        return $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/observability/system-health')
            ->assertOk()
            ->json();
    }

    public function test_un_drill_vert_sur_un_autre_fichier_ne_peut_pas_afficher_vert(): void
    {
        [$nomCourant] = $this->deposerSauvegarde('daily-g4-courant-b.sql.gz', 'contenu du dump B');
        $this->santeSaine();

        // Le drill d'hier a bel et bien réussi — sur A. B est arrivé depuis.
        RestoreDrillResult::store([
            'status' => 'green',
            'verified_at' => now()->subHours(3)->toIso8601String(),
            'file' => 'daily-g4-restaure-a.sql.gz',
            'sha256' => str_repeat('a', 64),
            'duration_s' => 41.0,
            'reasons' => [],
        ]);

        $r = $this->lireCockpit();

        $this->assertNotSame(
            'green',
            $r['sauvegarde']['restauration']['status'],
            "le drill atteste un AUTRE fichier que {$nomCourant} : il ne peut pas rester vert"
        );
        $this->assertSame('attention', $r['verdict']);

        $alerte = implode(' | ', $r['alertes']);
        $this->assertStringContainsString(
            'daily-g4-restaure-a.sql.gz',
            $alerte,
            "l'alerte doit NOMMER le fichier réellement restauré"
        );
        $this->assertStringContainsString(
            $nomCourant,
            $alerte,
            "l'alerte doit NOMMER la sauvegarde courante, pour que l'écart soit lisible"
        );
    }

    public function test_readiness_degrade_quand_le_drill_porte_sur_un_autre_fichier(): void
    {
        $this->deposerSauvegarde('daily-g4-courant-b.sql.gz', 'contenu du dump B');

        RestoreDrillResult::store([
            'status' => 'green',
            'verified_at' => now()->subHours(3)->toIso8601String(),
            'file' => 'daily-g4-restaure-a.sql.gz',
            'sha256' => str_repeat('a', 64),
            'duration_s' => 41.0,
            'reasons' => [],
        ]);

        $r = $this->getJson('/api/health/ready')->json();

        $this->assertSame(
            'degraded',
            $r['subsystems']['restore_drill']['status'],
            '/health/ready doit dégrader comme le cockpit : une seule vérité par fait'
        );
        $this->assertStringContainsString('daily-g4-restaure-a.sql.gz', $r['subsystems']['restore_drill']['detail']);
    }

    public function test_le_meme_nom_avec_une_autre_empreinte_ne_passe_pas_pour_une_preuve(): void
    {
        // Cas réel : la rotation réécrit `daily-<date>.sql.gz` sous le MÊME nom. Comparer
        // les noms seuls laisserait passer un contenu entièrement différent.
        [$nom] = $this->deposerSauvegarde('daily-g4-meme-nom.sql.gz', 'contenu réécrit depuis le drill');
        $this->santeSaine();

        RestoreDrillResult::store([
            'status' => 'green',
            'verified_at' => now()->subHours(3)->toIso8601String(),
            'file' => $nom,
            'sha256' => hash('sha256', 'le contenu que le drill a réellement restauré'),
            'duration_s' => 41.0,
            'reasons' => [],
        ]);

        $r = $this->lireCockpit();

        $this->assertNotSame(
            'green',
            $r['sauvegarde']['restauration']['status'],
            'même nom mais empreinte différente : le fichier a été réécrit depuis le drill'
        );
        $this->assertNotEmpty(array_filter(
            $r['alertes'],
            fn ($a) => str_contains($a, 'empreinte') || str_contains($a, 'restauration')
        ));
    }

    public function test_un_drill_sans_empreinte_enregistree_ne_prouve_pas_le_fichier(): void
    {
        // `@hash_file()` peut échouer (fichier déplacé pendant le drill) : le verdict est
        // alors persisté avec `sha256 = null`. L'absence de preuve n'est pas une preuve.
        [$nom] = $this->deposerSauvegarde('daily-g4-sans-sha.sql.gz', 'contenu');
        $this->santeSaine();

        RestoreDrillResult::store([
            'status' => 'green',
            'verified_at' => now()->subHours(3)->toIso8601String(),
            'file' => $nom,
            'sha256' => null,
            'duration_s' => 41.0,
            'reasons' => [],
        ]);

        $r = $this->lireCockpit();

        $this->assertNotSame('green', $r['sauvegarde']['restauration']['status']);
    }

    /**
     * Le garde-fou du correctif : quand le drill atteste RÉELLEMENT le fichier courant,
     * la surface doit rester verte. Un correctif qui rendrait tout rouge passerait les
     * quatre tests précédents sans rien prouver.
     */
    public function test_le_drill_du_fichier_courant_reste_vert(): void
    {
        [$nom, $sha] = $this->deposerSauvegarde('daily-g4-atteste.sql.gz', 'contenu réellement restauré');
        $this->santeSaine();

        RestoreDrillResult::store([
            'status' => 'green',
            'verified_at' => now()->subHours(3)->toIso8601String(),
            'file' => $nom,
            'sha256' => $sha,
            'duration_s' => 41.0,
            'reasons' => [],
        ]);

        $r = $this->lireCockpit();

        $this->assertSame('green', $r['sauvegarde']['restauration']['status']);
        $this->assertEmpty(
            array_filter($r['alertes'], fn ($a) => str_contains($a, 'restauration')),
            'aucune alerte de restauration quand le drill atteste bien ce fichier'
        );

        $this->getJson('/api/health/ready')
            ->assertJsonPath('subsystems.restore_drill.status', 'ok');
    }
}
