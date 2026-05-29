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
     * [GOAL-2026-05-29 F5] "Retry failed" must NOT infinitely resurrect
     * chronically-failing rows: only recent, not-yet-exhausted failures are
     * retried; rows at/above the attempts ceiling (escalation territory) OR older
     * than the age window are left for the staleness/prune lane (attempts + error
     * preserved, not reset).
     */
    public function test_retry_failed_caps_exhausted_and_ancient_rows(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        $admin = $this->makeAdmin();
        $branchId = $admin->branch_id ?: 1;

        $retryable = $this->insertFailedEvent($branchId, 2, now(), '11111111-1111-1111-1111-111111111111');       // recent + low attempts -> retried
        $exhausted = $this->insertFailedEvent($branchId, 5, now(), '22222222-2222-2222-2222-222222222222');       // at ceiling -> escalate, NOT retried
        $ancient   = $this->insertFailedEvent($branchId, 1, now()->subDays(8), '33333333-3333-3333-3333-333333333333'); // too old -> NOT retried

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/observability/outbox/retry-failed')
            ->assertOk()
            ->assertJsonPath('requeued', 1);

        // Retryable row was reset; the other two are untouched (still escalate-able).
        $this->assertSame(0, (int) DB::table('domain_events')->where('id', $retryable)->value('attempts'));
        $this->assertNull(DB::table('domain_events')->where('id', $retryable)->value('last_error'));
        $this->assertSame(5, (int) DB::table('domain_events')->where('id', $exhausted)->value('attempts'));
        $this->assertSame('boom', DB::table('domain_events')->where('id', $exhausted)->value('last_error'));
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
