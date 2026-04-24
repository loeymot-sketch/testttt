<?php

namespace Tests\Feature\Observability;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureCorrelationIdPropagatesToMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_request_correlation_id_header_is_propagated_to_sync_metrics(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => $branch->id]);
        $admin->assignRole('Admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/observability/client-metrics', [
                'metrics' => [[
                    'type' => 'ws.disconnect_event',
                    'value' => 1,
                    'labels' => ['previous' => 'CONNECTED', 'current' => 'DISCONNECTED'],
                    'occurred_at' => now()->toISOString(),
                ]],
            ], ['X-Correlation-ID' => '55555555-5555-5555-5555-555555555555'])
            ->assertAccepted();

        $this->assertDatabaseHas('sync_metrics', [
            'metric_type' => 'ws.disconnect_event',
            'correlation_id' => '55555555-5555-5555-5555-555555555555',
        ]);
    }
}
