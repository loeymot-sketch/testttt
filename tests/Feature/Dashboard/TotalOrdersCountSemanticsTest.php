<?php

namespace Tests\Feature\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [DASH-T02 — DASH-01 count-all semantics LOCK]
 *
 * Regression teeth for DashboardService::totalOrders() (heal DASH-01,
 * commit ~2026-06-01). The "Total commandes" KPI must reflect REAL placed
 * order VOLUME across ALL statuses — it must NEVER silently revert to the
 * old status=DELIVERED-only count (which read 3 instead of 1755/day).
 *
 * Invariants locked here:
 *   1. totalOrders() counts orders across MULTIPLE statuses, not just
 *      DELIVERED. Seeded mix: PENDING + ACCEPT + PREPARING + DELIVERED.
 *   2. Refund counter-entry mirrors (rows with non-null parent_order_id)
 *      are EXCLUDED — a mirror is a fiscal counter-entry, not a placed order.
 *   3. (teeth) The asserted count is strictly greater than the DELIVERED-only
 *      count, so a revert to DELIVERED-only would make this test FAIL.
 *
 * Entry point mirrors DashboardBranchScopeMatrixTest: a Branch Manager calling
 * the dashboard endpoint, plus a direct service-method assertion for precision.
 */
class TotalOrdersCountSemanticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        // Same TZ pin as DashboardBranchScopeMatrixTest: totalOrders() is not
        // date-bounded, but realtime/statistics helpers are — pin to Paris
        // midday so the shared setup stays consistent and deterministic.
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

    public function test_total_orders_counts_all_placed_statuses_and_excludes_refund_mirrors(): void
    {
        $branch = Branch::factory()->create();
        $manager = User::factory()->create(['branch_id' => $branch->id]);
        $manager->assignRole('Branch Manager');

        // Placed orders across a MULTI-STATUS mix. Only ONE of these is
        // DELIVERED — a regression to DELIVERED-only would count just 1.
        $this->createPlacedOrder($branch, OrderStatus::PENDING, 'A-PENDING');
        $this->createPlacedOrder($branch, OrderStatus::ACCEPT, 'A-ACCEPT');
        $this->createPlacedOrder($branch, OrderStatus::PREPARING, 'A-PREPARING');
        $delivered = $this->createPlacedOrder($branch, OrderStatus::DELIVERED, 'A-DELIVERED');

        // Refund counter-entry MIRROR: non-null parent_order_id pointing at the
        // DELIVERED order. RETURNED + negated total = fiscal counter-entry, NOT
        // a placed order. Must be EXCLUDED from totalOrders().
        Order::factory()->create([
            'branch_id' => $branch->id,
            'parent_order_id' => $delivered->id,
            'status' => OrderStatus::RETURNED,
            'payment_status' => PaymentStatus::REFUNDED,
            'total' => -25.00,
            'source' => Source::POS,
            'queue_number' => 'A-DELIVERED-R',
            'order_datetime' => now(),
        ]);

        // Self-check on the seeded fixture so the teeth are explicit:
        $placedCount = 4;        // PENDING + ACCEPT + PREPARING + DELIVERED
        $deliveredOnlyCount = 1; // a DELIVERED-only regression would read this
        $this->assertGreaterThan(
            $deliveredOnlyCount,
            $placedCount,
            'Fixture must include >=1 non-DELIVERED placed order so a DELIVERED-only revert FAILS.'
        );

        // --- Direct service-method assertion (precise) ---
        $count = $this->actingAs($manager, 'sanctum')->callService();
        $this->assertSame(
            $placedCount,
            $count,
            'totalOrders() must count ALL placed statuses (4), excluding the refund mirror — '
            .'NOT DELIVERED-only (would be 1), NOT placed+mirror (would be 5).'
        );

        // Teeth: a DELIVERED-only revert (count === 1) must NOT satisfy this.
        $this->assertNotSame($deliveredOnlyCount, $count, 'totalOrders() must not be DELIVERED-only.');
        // Mirror-exclusion: placed (4) + mirror (1) = 5 must NOT be the answer.
        $this->assertNotSame($placedCount + 1, $count, 'Refund mirror must be excluded from the count.');

        // --- Endpoint assertion (matches matrix-test entrypoint) ---
        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/admin/dashboard/total-orders')
            ->assertOk()
            ->assertJsonPath('data.total_orders', $placedCount);
    }

    /**
     * Invoke the service method under the currently authenticated user so the
     * branch scope (dashboardBranchId) resolves exactly as in production.
     */
    private function callService(): int
    {
        return app(DashboardService::class)->totalOrders();
    }

    private function createPlacedOrder(Branch $branch, int $status, string $queueNumber): Order
    {
        return Order::factory()->create([
            'branch_id' => $branch->id,
            'parent_order_id' => null,
            'status' => $status,
            'payment_status' => PaymentStatus::PAID,
            'total' => 25.00,
            'source' => Source::POS,
            'queue_number' => $queueNumber,
            'order_datetime' => now(),
        ]);
    }
}
