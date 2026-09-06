<?php

namespace Tests\Feature\Observability;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * [G2 2026-09-03 · T2.7 · défaut V-11] La purge se laissait affamer par des travaux étrangers.
 *
 * `outboxDrainFailed` sélectionnait les 500 plus anciennes lignes `failed_jobs`
 * (`->orderBy('id')->limit(DRAIN_BATCH_CAP)`) PUIS filtrait en PHP sur la classe du job.
 * Le plafond s'appliquait donc AVANT le filtre : 500 travaux en échec étrangers plus
 * anciens (un listener stock, une notification) consommaient tout le lot et le candidat
 * outbox n'était jamais atteint. Le bouton répondait « 0 supprimé », indéfiniment, sans
 * dire pourquoi — et le compteur du bouton affichait la même famine.
 *
 * Le filtre doit précéder la borne : c'est un lot de 500 CANDIDATS OUTBOX, pas un lot de
 * 500 lignes dont il restera peut-être un candidat.
 */
class PurgeNeSeLaissePasAffamerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Storage::fake('local');
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Admin');

        return $u;
    }

    /**
     * 500 travaux en échec ÉTRANGERS, tous plus vieux que le candidat outbox — donc
     * servis les premiers par `orderBy('id')`.
     */
    private function cinqCentsEtrangersPlusAnciens(): void
    {
        $lignes = [];
        for ($i = 1; $i <= 500; $i++) {
            $uuid = sprintf('eeeeeeee-0000-0000-0000-%012d', $i);
            $lignes[] = [
                'uuid' => $uuid,
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode([
                    'uuid' => $uuid,
                    'displayName' => 'App\\Listeners\\ReverseRawMaterialsOnOrderCanceled',
                    'data' => [],
                ]),
                'exception' => 'RuntimeException: stock listener boom',
                'failed_at' => now()->subDays(10),
            ];
        }
        foreach (array_chunk($lignes, 100) as $lot) {
            DB::table('failed_jobs')->insert($lot);
        }
    }

    private function candidatOutbox(string $uuid): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'high',
            'payload' => json_encode(['uuid' => $uuid, 'displayName' => DispatchDomainEventsJob::class, 'data' => []]),
            'exception' => 'RuntimeException: broadcast boom',
            'failed_at' => now()->subDays(3),
        ]);
    }

    public function test_le_candidat_outbox_est_atteint_malgre_500_travaux_etrangers_plus_anciens(): void
    {
        $this->cinqCentsEtrangersPlusAnciens();
        $this->candidatOutbox('aaaaaaaa-1111-1111-1111-111111111111');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/observability/outbox/drain-failed', ['older_than_hours' => 24])
            ->assertOk()
            ->assertJsonPath('deleted', 1);

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'aaaaaaaa-1111-1111-1111-111111111111']);
        // Les 500 étrangers restent intacts : la purge n'élargit pas son périmètre pour
        // se dégager de la famine.
        $this->assertSame(500, (int) DB::table('failed_jobs')->count());
    }

    public function test_le_compteur_du_bouton_ne_se_laisse_pas_affamer_non_plus(): void
    {
        $this->cinqCentsEtrangersPlusAnciens();
        $this->candidatOutbox('aaaaaaaa-2222-2222-2222-222222222222');

        $r = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/observability/outbox')
            ->assertOk()
            ->json();

        $this->assertSame(
            1,
            (int) $r['purgeable_failed_jobs']['count'],
            'le bouton doit annoncer le travail outbox purgeable, pas zéro à cause des voisins'
        );
        $this->assertSame(24, (int) $r['purgeable_failed_jobs']['older_than_hours']);
        // Le compteur d'ÉTAT `failed_jobs.count` reste, lui, le total de la table.
        $this->assertSame(501, (int) $r['failed_jobs']['count']);
    }

    public function test_un_travail_outbox_trop_recent_n_est_ni_compte_ni_purge(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => 'aaaaaaaa-3333-3333-3333-333333333333',
            'connection' => 'database',
            'queue' => 'high',
            'payload' => json_encode(['displayName' => DispatchDomainEventsJob::class, 'data' => []]),
            'exception' => 'RuntimeException: boom',
            'failed_at' => now()->subMinutes(5),
        ]);

        $r = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/observability/outbox')
            ->assertOk()
            ->json();

        $this->assertSame(0, (int) $r['purgeable_failed_jobs']['count']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/observability/outbox/drain-failed', ['older_than_hours' => 24])
            ->assertOk()
            ->assertJsonPath('deleted', 0);
    }

    public function test_un_travail_etranger_dont_le_texte_evoque_le_job_outbox_n_est_pas_purge(): void
    {
        // Le pré-filtre SQL est un filet grossier ; la classe reste vérifiée exactement.
        DB::table('failed_jobs')->insert([
            'uuid' => 'aaaaaaaa-4444-4444-4444-444444444444',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\AutreJob',
                'data' => ['note' => 'ressemble à DispatchDomainEventsJob mais ne l\'est pas'],
            ]),
            'exception' => 'RuntimeException: boom',
            'failed_at' => now()->subDays(3),
        ]);

        $r = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/observability/outbox')
            ->assertOk()
            ->json();

        $this->assertSame(0, (int) $r['purgeable_failed_jobs']['count']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/observability/outbox/drain-failed', ['older_than_hours' => 24])
            ->assertOk()
            ->assertJsonPath('deleted', 0);

        $this->assertDatabaseHas('failed_jobs', ['uuid' => 'aaaaaaaa-4444-4444-4444-444444444444']);
    }
}
