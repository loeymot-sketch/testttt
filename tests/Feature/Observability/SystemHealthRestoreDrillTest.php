<?php

namespace Tests\Feature\Observability;

use App\Models\User;
use App\Support\Backup\RestoreDrillResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Sub 4.1 · Codex P1-A]
 *
 * Une sauvegarde fraîche mais NON RESTAURABLE ne vaut rien. `backup:verify-restore`
 * tourne à 5 h, restaure dans une base jetable, compte les lignes et vérifie la chaîne
 * NF525 — puis écrivait son verdict dans un fichier de log que personne ne lit. Le
 * cockpit et la sonde de readiness ne regardaient que la DATE du fichier `.sql.gz` : un
 * `.sql.gz` de 2 h corrompu affichait « Tout va bien ».
 *
 * Ces tests imposent que le RÉSULTAT du drill soit persisté, lu par le cockpit, et
 * qu'un drill échoué ou périmé empêche le verdict global d'être « ok ».
 *
 * [Codex P1-H] Ces tests couvrent aussi les faux verts de fraîcheur : horodatage de
 * mesure illisible, horodatage dans le futur, et l'arrondi de l'âge de sauvegarde qui
 * rendait 26,4 h vert au cockpit et dégradé côté readiness.
 */
class SystemHealthRestoreDrillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Cache::forget('healthz:last');
        Cache::forget('scheduler:last_tick');
        Cache::forget(RestoreDrillResult::CACHE_KEY);
        // Le résultat du drill est persisté en cache ET en fichier. Sans disque
        // simulé, un test écrirait un faux verdict dans `storage/app/backups/` du poste
        // — c'est-à-dire fabriquerait sur le VRAI cockpit le faux vert qu'on corrige ici.
        Storage::fake('local');
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

    private function lire(): array
    {
        return $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/observability/system-health')
            ->assertOk()
            ->json();
    }

    public function test_un_drill_echoue_interdit_le_verdict_tout_va_bien(): void
    {
        $this->santeSaine();
        RestoreDrillResult::store([
            'status' => 'failed',
            'verified_at' => now()->subHours(2)->toIso8601String(),
            'file' => 'daily-2026-09-01.sql.gz',
            'sha256' => str_repeat('a', 64),
            'duration_s' => 42.5,
            'reasons' => ['restored schema has 40 tables but live has 88 (partial restore?)'],
        ]);

        $r = $this->lire();

        $this->assertSame('attention', $r['verdict']);
        $this->assertNotEmpty(array_filter($r['alertes'], fn ($a) => str_contains($a, 'restauration')));
        $this->assertSame('failed', $r['sauvegarde']['restauration']['status']);
        $this->assertSame(
            ['restored schema has 40 tables but live has 88 (partial restore?)'],
            $r['sauvegarde']['restauration']['reasons']
        );
    }

    public function test_un_drill_jamais_execute_est_dit_et_non_tu(): void
    {
        $this->santeSaine();

        $r = $this->lire();

        $this->assertSame('unknown', $r['sauvegarde']['restauration']['status']);
        $this->assertNotEmpty(array_filter($r['alertes'], fn ($a) => str_contains($a, 'restauration')));
    }

    public function test_un_drill_vert_mais_perime_est_signale(): void
    {
        $this->santeSaine();
        RestoreDrillResult::store([
            'status' => 'green',
            'verified_at' => now()->subHours(60)->toIso8601String(),
            'file' => 'daily-2026-08-30.sql.gz',
            'sha256' => str_repeat('b', 64),
            'duration_s' => 30.0,
            'reasons' => [],
        ]);

        $r = $this->lire();

        $this->assertSame('stale', $r['sauvegarde']['restauration']['status']);
        $this->assertNotEmpty(array_filter($r['alertes'], fn ($a) => str_contains($a, 'restauration')));
    }

    public function test_un_drill_vert_et_frais_ne_declenche_aucune_alerte_de_restauration(): void
    {
        $this->santeSaine();
        RestoreDrillResult::store([
            'status' => 'green',
            'verified_at' => now()->subHours(3)->toIso8601String(),
            'file' => 'daily-2026-09-02.sql.gz',
            'sha256' => str_repeat('c', 64),
            'duration_s' => 28.0,
            'reasons' => [],
        ]);

        $r = $this->lire();

        $this->assertSame('green', $r['sauvegarde']['restauration']['status']);
        $this->assertEmpty(array_filter($r['alertes'], fn ($a) => str_contains($a, 'restauration')));
    }

    public function test_un_horodatage_de_mesure_illisible_ne_passe_pas_pour_une_mesure_fraiche(): void
    {
        Cache::forever('healthz:last', [
            'status' => 'ok',
            'checks' => ['db' => 'ok', 'redis' => 'ok'],
            'timestamp' => 'pas-une-date',
        ]);
        Cache::forever('scheduler:last_tick', now()->timestamp);

        $r = $this->lire();

        $this->assertSame('attention', $r['verdict']);
        $this->assertNotEmpty(array_filter($r['alertes'], fn ($a) => str_contains($a, 'horodatage')));
    }

    public function test_un_horodatage_de_mesure_dans_le_futur_est_signale(): void
    {
        Cache::forever('healthz:last', [
            'status' => 'ok',
            'checks' => ['db' => 'ok', 'redis' => 'ok'],
            'timestamp' => now()->addHours(2)->toIso8601String(),
        ]);
        Cache::forever('scheduler:last_tick', now()->timestamp);

        $r = $this->lire();

        $this->assertSame('attention', $r['verdict']);
        $this->assertNotEmpty(array_filter($r['alertes'], fn ($a) => str_contains($a, 'horodatage')));
    }

    public function test_l_age_de_sauvegarde_est_compare_avant_arrondi(): void
    {
        // 26,4 h : `(int) round(26.4) = 26`, donc « 26 > 26 » était FAUX et la carte
        // restait verte — pendant que HealthController, qui compare en décimal,
        // déclarait `degraded`. Deux écrans, deux vérités, pour le même fichier.
        $dossier = storage_path('backups'.DIRECTORY_SEPARATOR.'db-daily');
        if (! is_dir($dossier)) {
            mkdir($dossier, 0775, true);
        }
        $fichier = $dossier.DIRECTORY_SEPARATOR.'daily-test-arrondi.sql.gz';
        file_put_contents($fichier, 'x');
        touch($fichier, time() - (int) (26.4 * 3600));

        try {
            $this->santeSaine();
            $r = $this->lire();

            $this->assertNotEmpty(
                array_filter($r['alertes'], fn ($a) => str_contains($a, 'sauvegarde')),
                '26,4 h dépasse le seuil de 26 h : la carte doit le dire, comme la sonde de readiness'
            );
        } finally {
            @unlink($fichier);
        }
    }

    /**
     * La moitié « sonde » du même défaut : `/health/ready` publie `backup_age`, qui ne
     * regarde QUE la date du fichier. Une box dont la dernière restauration a échoué
     * répondait 200 « ok ». La restauration devient un sous-système à part entière —
     * consultatif hors production, comme `scheduler` et `backup_age`, parce qu'un poste
     * de développement ne lance pas le planificateur.
     */
    public function test_readiness_publie_le_sous_systeme_restauration(): void
    {
        RestoreDrillResult::store([
            'status' => 'green',
            'verified_at' => now()->subHours(3)->toIso8601String(),
            'file' => 'daily-2026-09-02.sql.gz',
            'sha256' => str_repeat('a', 64),
            'duration_s' => 41.0,
            'reasons' => [],
        ]);

        $this->getJson('/api/health/ready')
            ->assertJsonPath('subsystems.restore_drill.status', 'ok');
    }

    public function test_readiness_degrade_quand_la_restauration_a_echoue(): void
    {
        RestoreDrillResult::store([
            'status' => 'failed',
            'verified_at' => now()->subHours(1)->toIso8601String(),
            'file' => 'daily-2026-09-02.sql.gz',
            'sha256' => null,
            'duration_s' => 12.0,
            'reasons' => ['audit_logs chain broken at seq 41'],
        ]);

        $r = $this->getJson('/api/health/ready')->json();

        $this->assertSame('degraded', $r['subsystems']['restore_drill']['status']);
        $this->assertStringContainsString('audit_logs chain broken', $r['subsystems']['restore_drill']['detail']);
    }

    public function test_readiness_degrade_quand_la_restauration_na_jamais_tourne(): void
    {
        Cache::forget(RestoreDrillResult::CACHE_KEY);

        $this->getJson('/api/health/ready')
            ->assertJsonPath('subsystems.restore_drill.status', 'degraded');
    }

    /**
     * Hors production, une restauration jamais jouée ne doit PAS faire basculer /ready en
     * 503 : un poste de développement n'a pas de planificateur, et un 503 permanent finit
     * par être ignoré — c'est ainsi qu'on cesse de regarder une sonde.
     */
    public function test_la_restauration_est_consultative_hors_production(): void
    {
        $this->assertFalse(app()->environment('production'));
        Cache::forget(RestoreDrillResult::CACHE_KEY);

        $r = $this->getJson('/api/health/ready');

        $this->assertSame('degraded', $r->json('subsystems.restore_drill.status'));
        $this->assertNotSame(
            503,
            $r->getStatusCode(),
            'restore_drill ne doit pas seul faire tomber /ready hors production'
        );
    }
}
