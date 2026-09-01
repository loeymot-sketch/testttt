<?php

namespace Tests\Feature\Grok;

use App\Http\Controllers\HealthzController;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Audit dashboard/contrôle 2026-08-29 — P1-01 à P1-05.
 */
class DashboardControlAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Cache::forget('healthz:last');
        Cache::forget('scheduler:last_tick');
    }

    public function test_pos_operator_cannot_read_system_health_or_interrupteurs(): void
    {
        $pos = $this->posWithDashboard();

        $this->actingAs($pos, 'sanctum')
            ->getJson('/api/admin/observability/system-health')
            ->assertForbidden();

        $this->actingAs($pos, 'sanctum')
            ->getJson('/api/admin/observability/interrupteurs')
            ->assertForbidden();
    }

    public function test_admin_can_still_read_system_health(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/observability/system-health')
            ->assertOk();
    }

    public function test_non_admin_without_branch_cannot_read_global_dashboard(): void
    {
        $user = User::factory()->create(['branch_id' => 0]);
        $user->assignRole('POS Operator');
        $user->givePermissionTo('dashboard');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/dashboard/total-orders')
            ->assertForbidden();
    }

    public function test_inverted_sales_dates_return_422_not_500(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/dashboard/sales-summary?first_date=2026-08-30&last_date=2026-08-01')
            ->assertStatus(422);
    }

    public function test_system_health_backup_uses_sql_gz_and_26h_threshold(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Cache::forever('scheduler:last_tick', now()->timestamp);
        Cache::forever('healthz:last', [
            'status' => 'ok',
            'checks' => ['db' => 'ok', 'redis' => 'ok', 'websocket' => 'ok', 'fiscal_chain' => 'ok', 'queue_pending' => 0],
            'timestamp' => now()->toIso8601String(),
        ]);

        $dir = storage_path('backups/db-daily');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $decoy = $dir.'/not-sql.gz';
        file_put_contents($decoy, 'x');
        touch($decoy, time() - 3600);

        $r = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/observability/system-health')
            ->assertOk()
            ->json();

        $this->assertSame(26, (int) $r['sauvegarde']['attendu_max_h']);
        $fichier = $r['sauvegarde']['dernier_fichier'];
        if ($fichier !== null) {
            $this->assertStringEndsWith('.sql.gz', (string) $fichier);
            $this->assertNotSame('not-sql.gz', $fichier);
        }

        @unlink($decoy);
    }

    public function test_queue_probe_does_not_return_zero_when_every_driver_throws(): void
    {
        Queue::shouldReceive('size')->andThrow(new \RuntimeException('driver down'));

        try {
            HealthzController::probeQueuePending();
            $this->fail('Une file illisible ne doit pas renvoyer 0 healthy.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('queue_unreadable', $e->getMessage());
        }

        $payload = app(HealthzController::class)();
        $json = $payload->getData(true);
        $this->assertSame('unknown', $json['checks']['queue_pending']);
        $this->assertNotSame('ok', $json['status']);
    }

    private function posWithDashboard(): User
    {
        $user = User::factory()->create([
            'branch_id' => Branch::factory()->create()->id,
        ]);
        $user->assignRole('POS Operator');
        $user->givePermissionTo('dashboard');

        return $user;
    }
}
