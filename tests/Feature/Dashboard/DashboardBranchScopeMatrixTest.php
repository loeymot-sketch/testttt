<?php

namespace Tests\Feature\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardBranchScopeMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        // [Wave 3c KDS-ADV3C-01 P0 2026-05-18] DashboardService is now
        // TZ-aware: `Carbon::today($appTz)->setTimezone('UTC')->toDateString()`.
        // On SQLite test driver (no session TZ) `order_datetime` is stored
        // as Paris-formatted string (Eloquent serializes Carbon in app TZ).
        // If the test runs at Paris-morning, UTC-today yields yesterday's
        // Y-m-d while order_datetime rows show today-Paris → mismatch.
        // Pin "now" to Paris midday so Paris-today and UTC-today agree.
        $pinned = CarbonImmutable::parse('2026-05-18 12:00:00', 'Europe/Paris');
        Carbon::setTestNow($pinned);
        CarbonImmutable::setTestNow($pinned);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_permission_is_required(): void
    {
        $user = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/dashboard/total-orders')
            ->assertForbidden();
    }

    public function test_branch_dashboard_reads_only_own_branch_runtime_orders(): void
    {
        [$branchA, $branchB] = [Branch::factory()->create(), Branch::factory()->create()];
        $manager = User::factory()->create(['branch_id' => $branchA->id]);
        $manager->assignRole('Branch Manager');

        $this->createDashboardOrder($branchA, OrderStatus::DELIVERED, 12.50, Source::POS, 'A0001');
        $this->createDashboardOrder($branchA, OrderStatus::PREPARING, 7.00, Source::APP, 'A0002', now()->subMinutes(20));
        $this->createDashboardOrder($branchB, OrderStatus::DELIVERED, 99.00, Source::WEB, 'B0001');
        $this->createDashboardOrder($branchB, OrderStatus::PREPARING, 50.00, Source::WEB, 'B0002', now()->subMinutes(20));
        $this->createCustomerOrder($branchA, 'branch-a-customer@example.test');
        $this->createCustomerOrder($branchB, 'branch-b-customer@example.test');

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/admin/dashboard/total-orders')
            ->assertOk()
            // [DASH-01 2026-06-01] total_orders now = all PLACED branchA orders (DELIVERED
            // A0001 + PREPARING A0002 + customer order), not DELIVERED-only. Branch scope
            // intact: branchA = 3, NOT branchA+branchB = 6.
            ->assertJsonPath('data.total_orders', 3);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/admin/dashboard/realtime-report')
            ->assertOk()
            ->assertJsonPath('data.daily_orders', 3);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/admin/dashboard/total-customers')
            ->assertOk()
            ->assertJsonPath('data.total_customers', 1);

        $alerts = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/admin/dashboard/sla-alerts')
            ->assertOk()
            ->json('data');

        $this->assertSame(['A0002'], collect($alerts)->pluck('queue_number')->all());

        $channels = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/admin/dashboard/channel-statistics')
            ->assertOk()
            ->json('data');

        $this->assertSame(0.0, (float) collect($channels)->firstWhere('name', 'Web')['value']);
        $this->assertSame(33.33, (float) collect($channels)->firstWhere('name', 'Kiosk/App')['value']);
        $this->assertSame(66.67, (float) collect($channels)->firstWhere('name', 'POS')['value']);
    }

    public function test_admin_dashboard_keeps_global_visibility(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        $this->createDashboardOrder(Branch::factory()->create(), OrderStatus::DELIVERED, 12.50, Source::POS, 'A0001');
        $this->createDashboardOrder(Branch::factory()->create(), OrderStatus::DELIVERED, 99.00, Source::WEB, 'B0001');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/dashboard/total-orders')
            ->assertOk()
            ->assertJsonPath('data.total_orders', 2);
    }

    private function createDashboardOrder(
        Branch $branch,
        int $status,
        float $total,
        int $source,
        string $queueNumber,
        $updatedAt = null
    ): void {
        Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => $status,
            'payment_status' => PaymentStatus::PAID,
            'total' => $total,
            'source' => $source,
            'queue_number' => $queueNumber,
            'order_datetime' => now(),
            'updated_at' => $updatedAt ?? now(),
        ]);
    }

    private function createCustomerOrder(Branch $branch, string $email): void
    {
        $customer = User::factory()->create([
            'branch_id' => null,
            'email' => $email,
        ]);
        $customer->assignRole('Customer');

        Order::factory()->create([
            'user_id' => $customer->id,
            'branch_id' => $branch->id,
            'status' => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'total' => 10.00,
            'source' => Source::POS,
            'queue_number' => $branch->id.'-CUST',
            'order_datetime' => now(),
        ]);
    }
}
