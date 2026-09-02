<?php

namespace Tests\Feature\Observability;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Sub 5.2 · Codex P1-K]
 *
 * La commande `foodking:outbox:retry-failed` tient un verrou (`outbox.retry-failed.lock`)
 * et écrit une ligne `audit_logs` NF525 `outbox.replay` AVANT chaque re-dispatch
 * (`OutboxConcurrentRetryLockTest`, `OutboxReplayAuditTest`). Les boutons web du cockpit
 * faisaient la même chose SANS verrou ni audit, et « Purger » supprimait TOUTES les lignes
 * `failed_jobs` de plus de 24 h, quel que soit le job, sans export ni trace.
 *
 * Ces tests imposent : même verrou (conflit → 409 explicite), même audit (acteur humain
 * identifié), attempts/last_error conservés (trace forensique, cf. heal B.1 2026-05-19),
 * purge limitée aux jobs outbox, exportée puis auditée — et rien n'est supprimé si l'audit
 * ne peut pas s'écrire.
 */
class OutboxWebActionsAreAuditedTest extends TestCase
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

    private function failedEvent(int $aggregateId, int $attempts = 5, string $error = 'broker unreachable'): int
    {
        return (int) DB::table('domain_events')->insertGetId([
            'event_type' => 'OrderUpdated',
            'aggregate_type' => 'order',
            'aggregate_id' => $aggregateId,
            'branch_id' => 1,
            'payload' => json_encode([]),
            'channel' => 'orders',
            'broadcast_as' => 'order.updated',
            'correlation_id' => 'corr-'.$aggregateId,
            'occurred_at' => now()->subMinutes(5),
            'dispatched_at' => null,
            'broadcast_at' => null,
            'attempts' => $attempts,
            'last_error' => $error,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);
    }

    private function failedJob(string $uuid, string $displayName, \DateTimeInterface $failedAt): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'high',
            'payload' => json_encode(['uuid' => $uuid, 'displayName' => $displayName, 'data' => []]),
            'exception' => 'RuntimeException: boom',
            'failed_at' => $failedAt,
        ]);
    }

    public function test_la_relance_web_ecrit_une_ligne_d_audit_signee_par_l_acteur(): void
    {
        Bus::fake();
        $admin = $this->admin();
        $eventId = $this->failedEvent(99);
        $before = (int) DB::table('audit_logs')->count();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/observability/outbox/retry-failed')
            ->assertOk()
            ->assertJsonPath('requeued', 1);

        $this->assertSame($before + 1, (int) DB::table('audit_logs')->count(), 'exactement une ligne d\'audit par événement rejoué');
        $row = DB::table('audit_logs')->orderByDesc('id')->first();
        $this->assertSame('outbox.replay', (string) $row->action);
        $this->assertSame($admin->id, (int) $row->user_id, 'l\'acteur HUMAIN est identifié, pas « Système »');
        $payload = json_decode((string) $row->payload, true);
        $this->assertSame('admin:outbox:retry-failed', $payload['command'] ?? null);
        $this->assertSame($eventId, (int) ($payload['event_id'] ?? -1));

        Bus::assertDispatched(DispatchDomainEventsJob::class, fn ($job) => $job->domainEventId === $eventId);
    }

    public function test_la_relance_web_conserve_attempts_et_last_error_comme_la_commande(): void
    {
        Bus::fake();
        $this->failedEvent(100, attempts: 5, error: 'broker unreachable');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/observability/outbox/retry-failed')
            ->assertOk();

        // Heal B.1 (2026-05-19) : attempts monotone, last_error = trace forensique.
        // Le job efface last_error lui-même quand la diffusion réussit (Phase 3a).
        $this->assertDatabaseHas('domain_events', ['aggregate_id' => 100, 'attempts' => 5, 'last_error' => 'broker unreachable']);
    }

    public function test_la_relance_web_est_refusee_quand_le_verrou_est_tenu(): void
    {
        Bus::fake();
        $this->failedEvent(101);

        $lock = Cache::lock('outbox.retry-failed.lock', 60);
        $this->assertTrue($lock->get(), 'setup : verrou pris comme le ferait la commande cron');

        try {
            $this->actingAs($this->admin(), 'sanctum')
                ->postJson('/api/admin/observability/outbox/retry-failed')
                ->assertStatus(409);
        } finally {
            $lock->release();
        }

        Bus::assertNothingDispatched();
        $this->assertSame(0, (int) DB::table('audit_logs')->where('action', 'outbox.replay')->count());
    }

    public function test_la_purge_ne_supprime_que_les_jobs_outbox_et_les_exporte_avant(): void
    {
        $admin = $this->admin();
        $this->failedJob('aaaaaaaa-0000-0000-0000-000000000001', DispatchDomainEventsJob::class, now()->subDays(3));
        $this->failedJob('bbbbbbbb-0000-0000-0000-000000000002', 'App\\Listeners\\ReverseRawMaterialsOnOrderCanceled', now()->subDays(3));
        $this->failedJob('cccccccc-0000-0000-0000-000000000003', DispatchDomainEventsJob::class, now()->subMinutes(5));

        $r = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/observability/outbox/drain-failed', ['older_than_hours' => 24])
            ->assertOk()
            ->assertJsonPath('deleted', 1)
            ->json();

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'aaaaaaaa-0000-0000-0000-000000000001']);
        // un job étranger à l'outbox n'est jamais purgé d'ici
        $this->assertDatabaseHas('failed_jobs', ['uuid' => 'bbbbbbbb-0000-0000-0000-000000000002']);
        // trop récent
        $this->assertDatabaseHas('failed_jobs', ['uuid' => 'cccccccc-0000-0000-0000-000000000003']);

        $this->assertNotEmpty($r['exported_to'] ?? null, 'la preuve supprimée est exportée');
        Storage::disk('local')->assertExists($r['exported_to']);
        $this->assertStringContainsString('aaaaaaaa-0000-0000-0000-000000000001', Storage::disk('local')->get($r['exported_to']));

        $row = DB::table('audit_logs')->where('action', 'outbox.drain')->orderByDesc('id')->first();
        $this->assertNotNull($row, 'la purge est auditée');
        $this->assertSame($admin->id, (int) $row->user_id);
        $payload = json_decode((string) $row->payload, true);
        $this->assertSame(1, (int) ($payload['deleted'] ?? -1));
        $this->assertSame($r['exported_to'], $payload['exported_to'] ?? null);
    }

    public function test_sans_audit_possible_la_purge_ne_supprime_rien(): void
    {
        $this->failedJob('dddddddd-0000-0000-0000-000000000004', DispatchDomainEventsJob::class, now()->subDays(3));

        $this->mock(AuditLogService::class)
            ->shouldReceive('write')
            ->once()
            ->andThrow(new \RuntimeException('chain lock unavailable'));

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/observability/outbox/drain-failed', ['older_than_hours' => 24])
            ->assertStatus(500);

        // pas d'audit → pas de suppression
        $this->assertDatabaseHas('failed_jobs', ['uuid' => 'dddddddd-0000-0000-0000-000000000004']);
    }

    public function test_une_purge_sans_candidat_n_ecrit_pas_d_audit_inutile(): void
    {
        $this->failedJob('eeeeeeee-0000-0000-0000-000000000005', 'App\\Listeners\\ReverseRawMaterialsOnOrderCanceled', now()->subDays(3));

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/observability/outbox/drain-failed', ['older_than_hours' => 24])
            ->assertOk()
            ->assertJsonPath('deleted', 0);

        $this->assertSame(0, (int) DB::table('audit_logs')->where('action', 'outbox.drain')->count());
    }
}
