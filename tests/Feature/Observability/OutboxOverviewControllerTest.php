<?php

namespace Tests\Feature\Observability;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * [CV1-OBSERVABILITY-OUTBOX-001] Sentinel for the outbox dashboard surface.
 *
 * The dashboard aggregates GLOBAL pipeline health (all branches) so the gate
 * is `role:Admin|Tenant Admin`, NOT a per-branch permission. These tests
 * lock that contract and the JSON shape consumed by the Vue component.
 */
class OutboxOverviewControllerTest extends TestCase
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
        $this->getJson('/api/admin/observability/outbox')->assertUnauthorized();
    }

    public function test_non_admin_user_is_forbidden(): void
    {
        $branch = Branch::factory()->create();
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');

        $this->actingAs($chef, 'sanctum')
            ->getJson('/api/admin/observability/outbox')
            ->assertForbidden();
    }

    /**
     * Branch Manager is the close-adjacent role: holds pos/dashboard/pos-orders,
     * "admin-flavored" without being Admin. The most likely role to slip
     * through a misconfigured gate. Lock the contract: BM has no access to
     * the global outbox surface (which aggregates ALL branches).
     */
    public function test_branch_manager_is_forbidden(): void
    {
        $branch = Branch::factory()->create();
        $bm = User::factory()->create(['branch_id' => $branch->id]);
        $bm->assignRole('Branch Manager');

        $this->actingAs($bm, 'sanctum')
            ->getJson('/api/admin/observability/outbox')
            ->assertForbidden();

        $this->actingAs($bm, 'sanctum')
            ->postJson('/api/admin/observability/outbox/retry-failed')
            ->assertForbidden();

        $this->actingAs($bm, 'sanctum')
            ->postJson('/api/admin/observability/outbox/drain-failed', ['older_than_hours' => 24])
            ->assertForbidden();
    }

    public function test_admin_user_receives_expected_json_shape(): void
    {
        $admin = $this->makeAdmin();

        DB::table('domain_events')->insert([
            'event_type' => 'OrderUpdated',
            'aggregate_type' => 'order',
            'aggregate_id' => 1,
            'branch_id' => 1,
            'payload' => json_encode([]),
            'channel' => 'orders',
            'broadcast_as' => 'order.updated',
            'correlation_id' => null,
            'occurred_at' => now(),
            'dispatched_at' => null,
            'attempts' => 2,
            'last_error' => 'connection refused',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/observability/outbox')
            ->assertOk()
            ->assertJsonStructure([
                'generated_at',
                'pending' => ['count', 'rows'],
                'dispatched_24h' => ['count', 'latency_p50_ms', 'latency_p95_ms', 'latency_p99_ms', 'samples'],
                'queue_high' => ['available', 'count', 'oldest_age_seconds'],
                'failed_jobs' => ['available', 'count', 'rows'],
                'health' => [
                    'queue_work' => ['status', 'last_signal_age_seconds', 'method'],
                    'websockets_serve' => ['status', 'last_signal_age_seconds', 'method'],
                ],
            ])
            ->assertJsonPath('pending.count', 1)
            ->assertJsonPath('pending.rows.0.last_error', 'connection refused');
    }

    public function test_pending_count_excludes_dispatched_events(): void
    {
        $admin = $this->makeAdmin();

        DB::table('domain_events')->insert([
            [
                'event_type' => 'OrderCreated',
                'aggregate_type' => 'order',
                'aggregate_id' => 10,
                'branch_id' => 1,
                'payload' => json_encode([]),
                'channel' => null,
                'broadcast_as' => null,
                'correlation_id' => null,
                'occurred_at' => now(),
                'dispatched_at' => null,
                'attempts' => 0,
                'last_error' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'event_type' => 'OrderUpdated',
                'aggregate_type' => 'order',
                'aggregate_id' => 11,
                'branch_id' => 1,
                'payload' => json_encode([]),
                'channel' => null,
                'broadcast_as' => null,
                'correlation_id' => null,
                'occurred_at' => now(),
                'dispatched_at' => now(),
                'attempts' => 1,
                'last_error' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/observability/outbox')
            ->assertOk()
            ->assertJsonPath('pending.count', 1)
            ->assertJsonPath('dispatched_24h.count', 1);
    }

    public function test_retry_failed_requeues_failed_events(): void
    {
        Queue::fake();
        $admin = $this->makeAdmin();

        DB::table('domain_events')->insert([
            'event_type' => 'OrderUpdated',
            'aggregate_type' => 'order',
            'aggregate_id' => 99,
            'branch_id' => 1,
            'payload' => json_encode([]),
            'channel' => 'orders',
            'broadcast_as' => 'order.updated',
            'correlation_id' => null,
            'occurred_at' => now(),
            'dispatched_at' => null,
            'attempts' => 5,
            'last_error' => 'broker unreachable',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/observability/outbox/retry-failed')
            ->assertOk()
            ->assertJsonPath('requeued', 1);

        $this->assertDatabaseHas('domain_events', [
            'aggregate_id' => 99,
            'attempts' => 0,
            'last_error' => null,
        ]);
    }

    public function test_retry_failed_is_forbidden_for_non_admin(): void
    {
        $branch = Branch::factory()->create();
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');

        $this->actingAs($chef, 'sanctum')
            ->postJson('/api/admin/observability/outbox/retry-failed')
            ->assertForbidden();
    }

    public function test_drain_failed_refuses_zero_hours(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/observability/outbox/drain-failed', ['older_than_hours' => 0])
            ->assertStatus(422);
    }

    public function test_drain_failed_deletes_only_old_rows(): void
    {
        $admin = $this->makeAdmin();

        DB::table('failed_jobs')->insert([
            [
                'uuid' => 'aaaaaaaa-1111-1111-1111-111111111111',
                'connection' => 'database',
                'queue' => 'high',
                'payload' => '{}',
                'exception' => 'Old failure',
                'failed_at' => now()->subDays(3),
            ],
            [
                'uuid' => 'bbbbbbbb-2222-2222-2222-222222222222',
                'connection' => 'database',
                'queue' => 'high',
                'payload' => '{}',
                'exception' => 'Recent failure',
                'failed_at' => now()->subMinutes(5),
            ],
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/observability/outbox/drain-failed', ['older_than_hours' => 24])
            ->assertOk()
            ->assertJsonPath('deleted', 1);

        $this->assertDatabaseHas('failed_jobs', ['uuid' => 'bbbbbbbb-2222-2222-2222-222222222222']);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'aaaaaaaa-1111-1111-1111-111111111111']);
    }

    private function makeAdmin(): User
    {
        // Same convention as SyncOverviewControllerTest — global admin
        // (branch_id=0) with the production-aligned 'Admin' Spatie role.
        $user = User::factory()->create(['branch_id' => 0]);
        $user->assignRole('Admin');
        return $user;
    }
}
