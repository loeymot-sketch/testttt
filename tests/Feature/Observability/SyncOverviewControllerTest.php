<?php

namespace Tests\Feature\Observability;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncOverviewControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_unauthenticated_user_receives_401(): void
    {
        $this->getJson('/api/admin/observability/sync-overview')->assertUnauthorized();
    }

    public function test_admin_user_can_query_sync_overview_for_their_branch(): void
    {
        $admin = $this->makeAdmin();
        $branchId = $admin->branch_id ?: 1;

        DB::table('sync_metrics')->insert([
            'metric_type' => 'ws.auth_failure',
            'branch_id' => $branchId,
            'value' => 1,
            'labels' => null,
            'correlation_id' => null,
            'occurred_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/observability/sync-overview?branch_id=' . $branchId)
            ->assertOk()
            ->assertJsonPath('branch_id', $branchId)
            ->assertJsonPath('summary.ws_auth_failures_count', 1);
    }

    public function test_overview_returns_p50_p95_p99_for_dispatch_latency_metric(): void
    {
        $admin = $this->makeAdmin();
        $branchId = $admin->branch_id ?: 1;

        $rows = [];
        foreach (range(1, 100) as $value) {
            $rows[] = [
                'metric_type' => 'outbox.dispatch_latency_ms',
                'branch_id' => $branchId,
                'value' => $value,
                'labels' => json_encode(['event_type' => 'OrderUpdated']),
                'correlation_id' => null,
                'occurred_at' => now(),
            ];
        }
        DB::table('sync_metrics')->insert($rows);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/observability/sync-overview?branch_id=' . $branchId)
            ->assertOk()
            ->assertJsonPath('summary.outbox_dispatch_latency_ms_p50', 50)
            ->assertJsonPath('summary.outbox_dispatch_latency_ms_p95', 95)
            ->assertJsonPath('summary.outbox_dispatch_latency_ms_p99', 99)
            ->assertJsonPath('by_event_type.0.event_type', 'OrderUpdated')
            ->assertJsonPath('by_event_type.0.latency_p95_ms', 95);
    }

    public function test_overview_filters_by_since_query_param(): void
    {
        $admin = $this->makeAdmin();
        $branchId = $admin->branch_id ?: 1;

        DB::table('sync_metrics')->insert([
            'metric_type' => 'ws.auth_failure',
            'branch_id' => $branchId,
            'value' => 1,
            'labels' => null,
            'correlation_id' => null,
            'occurred_at' => now()->subHours(2),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/observability/sync-overview?branch_id=' . $branchId . '&since=' . urlencode(now()->subMinutes(10)->toISOString()))
            ->assertOk()
            ->assertJsonPath('summary.ws_auth_failures_count', 0);
    }

    public function test_overview_includes_recent_failures_from_domain_events_table(): void
    {
        $admin = $this->makeAdmin();
        $branchId = $admin->branch_id ?: 1;

        DB::table('domain_events')->insert([
            'event_type' => 'OrderUpdated',
            'aggregate_type' => 'order',
            'aggregate_id' => 1,
            'branch_id' => $branchId,
            'payload' => json_encode([]),
            'channel' => 'orders',
            'broadcast_as' => 'order.updated',
            'correlation_id' => '33333333-3333-3333-3333-333333333333',
            'occurred_at' => now(),
            'dispatched_at' => null,
            'attempts' => 1,
            'last_error' => 'boom',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/observability/sync-overview?branch_id=' . $branchId)
            ->assertOk()
            ->assertJsonPath('recent_failures.0.last_error', 'boom');
    }

    private function insertFailedEvent(int $branchId, int $attempts, \Illuminate\Support\Carbon $createdAt, string $corr): int
    {
        return (int) DB::table('domain_events')->insertGetId([
            'event_type'     => 'OrderUpdated',
            'aggregate_type' => 'order',
            'aggregate_id'   => 1,
            'branch_id'      => $branchId,
            'payload'        => json_encode([]),
            'channel'        => 'orders',
            'broadcast_as'   => 'order.updated',
            'correlation_id' => $corr,
            'occurred_at'    => $createdAt,
            'dispatched_at'  => null,
            'attempts'       => $attempts,
            'last_error'     => 'boom',
            'created_at'     => $createdAt,
            'updated_at'     => $createdAt,
        ]);
    }

    /**
     * [GOAL-2026-05-29 F5 / v2] "Retry failed" bounds infinite resurrection by an
     * AGE window (not an attempts cap): recent failures — INCLUDING exhausted ones
     * (attempts=5), which is the legitimate "admin fixed the infra, retry now" case
     * locked by OutboxOverviewControllerTest — are reset+retried; rows older than
     * the window age out and are left for the prune/escalation lane (untouched).
     */
    public function test_retry_failed_retries_recent_including_exhausted_but_ages_out_old(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        $admin = $this->makeAdmin();
        $branchId = $admin->branch_id ?: 1;

        $recentLow  = $this->insertFailedEvent($branchId, 2, now(), '11111111-1111-1111-1111-111111111111');       // recent low-attempts -> retried
        $recentExh  = $this->insertFailedEvent($branchId, 5, now(), '22222222-2222-2222-2222-222222222222');       // recent EXHAUSTED -> retried (legit)
        $ancient    = $this->insertFailedEvent($branchId, 1, now()->subDays(8), '33333333-3333-3333-3333-333333333333'); // too old -> aged out, NOT retried

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/observability/outbox/retry-failed')
            ->assertOk()
            ->assertJsonPath('requeued', 2);

        // [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Codex P1-K] CONTRAT CHANGÉ, VOLONTAIREMENT.
        // Ce test attendait `attempts=0` et `last_error=null` : le bouton web remettait le
        // compteur à zéro à chaque clic. C'est exactement le flapping que le heal B.1
        // (2026-05-19) avait supprimé côté commande — une ligne qui échoue en boucle ne
        // franchissait jamais le seuil de PruneOutboxCommand (`attempts>=6`) et perdait sa
        // trace d'erreur à chaque cycle. Deux sémantiques divergentes sur la même table
        // sont la classe de défaut qui a produit P1-J ; les deux chemins passent désormais
        // par OutboxReplayService. Ce qui rendait le bouton utile est préservé : la
        // sélection ne regarde PAS `attempts` (un événement épuisé après réparation de
        // l'infra reste rejouable), seulement `last_error` et la fenêtre de 7 jours.
        // Ce qui est retiré : seul `dispatched_at` repasse à NULL, pour que la Phase 1 du
        // job puisse re-claimer la ligne.
        $this->assertSame(2, (int) DB::table('domain_events')->where('id', $recentLow)->value('attempts'));
        $this->assertSame('boom', DB::table('domain_events')->where('id', $recentLow)->value('last_error'));
        $this->assertSame(5, (int) DB::table('domain_events')->where('id', $recentExh)->value('attempts'));
        $this->assertSame('boom', DB::table('domain_events')->where('id', $recentExh)->value('last_error'));
        $this->assertNull(DB::table('domain_events')->where('id', $recentLow)->value('dispatched_at'));
        // Et la relance laisse une trace fiscale nominative — c'était le défaut P1-K.
        $this->assertSame(2, (int) DB::table('audit_logs')->where('action', 'outbox.replay')->count());
        $this->assertSame(
            $admin->id,
            (int) DB::table('audit_logs')->where('action', 'outbox.replay')->orderByDesc('id')->value('user_id')
        );
        $this->assertSame(1, (int) DB::table('domain_events')->where('id', $ancient)->value('attempts'));
        $this->assertSame('boom', DB::table('domain_events')->where('id', $ancient)->value('last_error'));
    }

    public function test_client_metrics_endpoint_accepts_valid_batch_returns_202(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/observability/client-metrics', [
                'metrics' => [[
                    'type' => 'ws.reconnect_storm',
                    'value' => 1000,
                    'labels' => ['attempts_in_window' => 5],
                    'occurred_at' => now()->toISOString(),
                ]],
            ], ['X-Correlation-ID' => '44444444-4444-4444-4444-444444444444'])
            ->assertAccepted()
            ->assertJsonPath('received', 1);

        $this->assertDatabaseHas('sync_metrics', [
            'metric_type' => 'ws.reconnect_storm',
            'value' => 1000,
            'correlation_id' => '44444444-4444-4444-4444-444444444444',
        ]);
    }

    public function test_client_metrics_endpoint_rejects_unknown_metric_type_with_422(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/observability/client-metrics', [
                'metrics' => [[
                    'type' => 'unknown.metric',
                    'value' => 1,
                    'occurred_at' => now()->toISOString(),
                ]],
            ])->assertUnprocessable();
    }

    public function test_client_metrics_endpoint_rejects_batch_over_200_with_422(): void
    {
        $admin = $this->makeAdmin();

        $metrics = array_map(fn () => [
            'type' => 'ws.disconnect_event',
            'value' => 1,
            'occurred_at' => now()->toISOString(),
        ], range(1, 201));

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/observability/client-metrics', ['metrics' => $metrics])
            ->assertUnprocessable();
    }

    /**
     * T-MISS-B (P0) — Cross-branch leak guard.
     *
     * A branch-scoped operator (POS Operator or Chef) MUST NOT be able to
     * read another branch's metrics by passing ?branch_id=other in the URL.
     * Returns 403, not silent down-scoping (which would mask the probe).
     */
    public function test_branch_scoped_operator_cannot_read_another_branch_metrics(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $opA = User::factory()->create(['branch_id' => $branchA->id]);
        $opA->assignRole('Chef');

        DB::table('sync_metrics')->insert([
            'metric_type' => 'ws.auth_failure',
            'branch_id' => $branchB->id,
            'value' => 1,
            'labels' => null,
            'correlation_id' => null,
            'occurred_at' => now(),
        ]);

        $this->actingAs($opA, 'sanctum')
            ->getJson('/api/admin/observability/sync-overview?branch_id=' . $branchB->id)
            ->assertForbidden();
    }

    /**
     * T-MISS-B follow-up — Omitting ?branch_id= MUST scope to the user's own
     * branch (not aggregate across all branches). Otherwise a Chef/POS
     * Operator could call without a query param and see every branch's data.
     */
    public function test_branch_scoped_operator_omitting_branch_id_is_force_scoped_to_own_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $opA = User::factory()->create(['branch_id' => $branchA->id]);
        $opA->assignRole('Chef');

        DB::table('sync_metrics')->insert([
            ['metric_type' => 'ws.auth_failure', 'branch_id' => $branchA->id, 'value' => 1, 'labels' => null, 'correlation_id' => null, 'occurred_at' => now()],
            ['metric_type' => 'ws.auth_failure', 'branch_id' => $branchB->id, 'value' => 1, 'labels' => null, 'correlation_id' => null, 'occurred_at' => now()],
        ]);

        $this->actingAs($opA, 'sanctum')
            ->getJson('/api/admin/observability/sync-overview')
            ->assertOk()
            ->assertJsonPath('branch_id', $branchA->id)
            ->assertJsonPath('summary.ws_auth_failures_count', 1);
    }

    /**
     * T-MISS-B follow-up — Global admin (branch_id === 0) can omit the
     * filter and see ALL branches in aggregate.
     */
    public function test_global_admin_can_aggregate_across_branches_when_omitting_branch_id(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $globalAdmin = User::factory()->create(['branch_id' => 0]);
        $globalAdmin->assignRole('Admin');

        DB::table('sync_metrics')->insert([
            ['metric_type' => 'ws.auth_failure', 'branch_id' => $branchA->id, 'value' => 1, 'labels' => null, 'correlation_id' => null, 'occurred_at' => now()],
            ['metric_type' => 'ws.auth_failure', 'branch_id' => $branchB->id, 'value' => 1, 'labels' => null, 'correlation_id' => null, 'occurred_at' => now()],
        ]);

        $this->actingAs($globalAdmin, 'sanctum')
            ->getJson('/api/admin/observability/sync-overview')
            ->assertOk()
            ->assertJsonPath('branch_id', null)
            ->assertJsonPath('summary.ws_auth_failures_count', 2);
    }

    /**
     * T-CLA-1 (P0) — Branch Manager has NO 'kitchen-display-system' permission
     * (see TestCase::seedSpatieRoles + RolePermissionTableSeeder). They MUST
     * receive 403 from the observability surface — both GET (read) and POST
     * (ingest) are now uniformly gated by `permission:kitchen-display-system`
     * (Audit Claude A3 — symmetric gating).
     */
    public function test_branch_manager_without_kds_permission_cannot_read_sync_overview(): void
    {
        $branch = Branch::factory()->create();
        $bm = User::factory()->create(['branch_id' => $branch->id]);
        $bm->assignRole('Branch Manager');

        $this->actingAs($bm, 'sanctum')
            ->getJson('/api/admin/observability/sync-overview?branch_id=' . $branch->id)
            ->assertForbidden();
    }

    /**
     * T-CLA-4 (P2 → promoted to assertion of A3 fix) — Symmetric permission
     * gate: Branch Manager also cannot POST /client-metrics. Without this,
     * a BM session could poison telemetry that nobody on the same role can
     * ever observe (write-only blind spot).
     */
    public function test_branch_manager_cannot_post_client_metrics_either(): void
    {
        $branch = Branch::factory()->create();
        $bm = User::factory()->create(['branch_id' => $branch->id]);
        $bm->assignRole('Branch Manager');

        $this->actingAs($bm, 'sanctum')
            ->postJson('/api/admin/observability/client-metrics', [
                'metrics' => [[
                    'type' => 'ws.reconnect_storm',
                    'value' => 1000,
                    'occurred_at' => now()->toISOString(),
                ]],
            ])->assertForbidden();
    }

    /**
     * T-CLA-2 (P1) — POS Operator has 'kitchen-display-system' permission
     * (cashier flow needs to glance at KDS) but is branch-scoped. Cross-branch
     * probe MUST 403, NOT silently down-scope. This is the POS-Operator-flavored
     * equivalent of the existing Chef-flavored test_branch_scoped_operator_*.
     */
    public function test_pos_operator_cannot_read_another_branch_metrics(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $cashier = User::factory()->create(['branch_id' => $branchA->id]);
        $cashier->assignRole('POS Operator');

        $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/admin/observability/sync-overview?branch_id=' . $branchB->id)
            ->assertForbidden();
    }

    /**
     * T-CLA-3 (P0 → blocks A1 regression) — User with branch_id = NULL/0
     * but WITHOUT the Admin role MUST 403. Otherwise a misconfigured Chef or
     * POS Operator (factory forgot branch_id) silently sees ALL branches'
     * metrics. resolveBranchScope() now requires hasRole('Admin') before it
     * accepts the global-aggregate path.
     */
    public function test_null_branch_id_without_admin_role_cannot_aggregate_globally(): void
    {
        // Chef with branch_id = 0 (misconfigured; should NEVER happen in prod
        // but the gate must stop it deterministically if it does).
        $chef = User::factory()->create(['branch_id' => 0]);
        $chef->assignRole('Chef');

        $branchOther = Branch::factory()->create();
        DB::table('sync_metrics')->insert([
            'metric_type' => 'ws.auth_failure',
            'branch_id' => $branchOther->id,
            'value' => 1,
            'labels' => null,
            'correlation_id' => null,
            'occurred_at' => now(),
        ]);

        $this->actingAs($chef, 'sanctum')
            ->getJson('/api/admin/observability/sync-overview')
            ->assertForbidden();
    }

    private function makeAdmin(): User
    {
        // Global admin (branch_id = 0) — this matches the production
        // KdsSyncController convention and lets the existing tests use the
        // explicit ?branch_id= override path that the controller validates
        // against the resolveBranchScope() gate.
        $user = User::factory()->create(['branch_id' => 0]);
        $user->assignRole('Admin');
        return $user;
    }
}
