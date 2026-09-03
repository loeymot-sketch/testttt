<?php

namespace Tests\Feature\Observability;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [G2 2026-09-03 · T2.5 · défaut V-06] La sonde du worker regardait la mauvaise file.
 *
 * `SyncOverviewController::probeHealth` acceptait N'IMPORTE QUELLE ligne `jobs` réservée :
 * `DB::table('jobs')->whereNotNull('reserved_at')`, sans condition sur `queue`. Or le job
 * de diffusion publie sur une file précise (`DispatchDomainEventsJob::__construct` →
 * `onQueue('high')`), et le projet a d'autres files vivantes — `config('queue.monitored_queues')`
 * liste `default`, `high`, `notifications`, et 1 490 travaux dormaient sur `notifications`
 * le 2026-08-25.
 *
 * Conséquence : un worker de notifications bien vivant suffisait à afficher le worker
 * OUTBOX « en service » alors que la file de diffusion était morte — exactement le cas
 * où l'on ouvre cet écran.
 *
 * La sonde doit être bornée à la file que le job utilise réellement, LUE SUR LE JOB
 * (pas recopiée), pour qu'un `onQueue()` déplacé ne laisse pas la sonde derrière lui.
 */
class SondeWorkerBorneeFileHighTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Cache::forget('ws:heartbeat');
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Admin');

        return $u;
    }

    /** La file réelle du job de diffusion — source unique, jamais recopiée. */
    private function fileOutbox(): string
    {
        return (string) ((new DispatchDomainEventsJob(0))->queue ?? 'high');
    }

    private function travailReserve(string $file, int $ageSecondes = 5): void
    {
        DB::table('jobs')->insert([
            'queue' => $file,
            'payload' => json_encode(['displayName' => 'App\\Jobs\\SendFcmNotificationJob', 'data' => []]),
            'attempts' => 1,
            'reserved_at' => now()->subSeconds($ageSecondes)->getTimestamp(),
            'available_at' => now()->subSeconds($ageSecondes + 1)->getTimestamp(),
            'created_at' => now()->subSeconds($ageSecondes + 1)->getTimestamp(),
        ]);
    }

    private function apercu(): array
    {
        return $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/observability/outbox')
            ->assertOk()
            ->json();
    }

    public function test_un_worker_vivant_sur_une_autre_file_ne_rend_pas_le_worker_outbox_vivant(): void
    {
        // Un worker de notifications tourne : il réserve un travail il y a 5 s.
        $this->travailReserve('notifications');
        // Rien sur la file outbox, aucune diffusion récente, aucun battement websocket.

        $r = $this->apercu();

        $this->assertSame(
            'down',
            $r['health']['queue_work']['status'],
            'un travail réservé sur `notifications` ne prouve RIEN sur la file de diffusion'
        );
    }

    public function test_la_file_default_non_plus(): void
    {
        $this->travailReserve('default');

        $r = $this->apercu();

        $this->assertSame('down', $r['health']['queue_work']['status']);
    }

    public function test_un_travail_reserve_sur_la_file_outbox_est_bien_un_signal_positif(): void
    {
        $this->travailReserve($this->fileOutbox());

        $r = $this->apercu();

        $this->assertSame('up', $r['health']['queue_work']['status']);
        $this->assertNotNull($r['health']['queue_work']['last_signal_age_seconds']);
    }

    public function test_l_age_du_dernier_signal_ignore_les_autres_files(): void
    {
        // Signal frais sur une file étrangère, signal ANCIEN (donc down) sur la file outbox.
        $this->travailReserve('notifications', 2);
        $this->travailReserve($this->fileOutbox(), 600);

        $r = $this->apercu();

        $this->assertSame('down', $r['health']['queue_work']['status']);
        $this->assertGreaterThanOrEqual(
            600,
            (int) $r['health']['queue_work']['last_signal_age_seconds'],
            'l\'âge affiché est celui de la file outbox, pas celui du voisin bien portant'
        );
    }

    public function test_la_methode_declaree_nomme_la_file_reellement_sondee(): void
    {
        $this->travailReserve($this->fileOutbox());

        $r = $this->apercu();

        $this->assertStringContainsString(
            $this->fileOutbox(),
            (string) $r['health']['queue_work']['method'],
            'la sonde doit dire QUELLE file elle regarde — sinon on ne peut pas la contredire'
        );
    }

    public function test_le_couloir_de_file_expose_est_celui_du_job(): void
    {
        // Non-régression de la voie de lecture : `queue_high` décrit bien la file du job.
        DB::table('jobs')->insert([
            'queue' => $this->fileOutbox(),
            'payload' => json_encode(['displayName' => DispatchDomainEventsJob::class, 'data' => []]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subSeconds(120)->getTimestamp(),
            'created_at' => now()->subSeconds(120)->getTimestamp(),
        ]);

        $r = $this->apercu();

        $this->assertGreaterThanOrEqual(120, (int) $r['queue_high']['oldest_age_seconds']);
    }
}
