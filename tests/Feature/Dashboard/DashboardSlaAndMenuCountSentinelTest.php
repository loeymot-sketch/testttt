<?php

/**
 * [WAVE1 CENTRAL heal 2026-06-06 — DASH-05 + DASH-06]
 *
 * Two integrity defects in DashboardService surfaced by the all-systems
 * read-only audit (reports/test-e2e/all-systems-2026-06-06/WAVE1_POS_AUDIT_FINDINGS.md):
 *
 *  • DASH-05 (DashboardService::slaAlerts) — the "cuisine > 15 min" SLA clock
 *    measured elapsed time from `orders.updated_at`. ANY later row write
 *    (payment flip, loyalty award, broadcast bookkeeping) resets updated_at →
 *    a genuinely-late ticket appears fresh and drops off the alert list
 *    (false-negative — the kitchen is told everything is fine while a ticket
 *    is 40 min old). The REAL "entered PREPARING" instant lives in
 *    `order_status_transitions` (to_status=PREPARING, occurred_at), written by
 *    every status-change path (OrderService / KDS service / OrderStateMachine).
 *    FIX: clock from MAX(occurred_at of the PREPARING transition), with a
 *    COALESCE fallback to updated_at for rows that predate transition-recording.
 *
 *  • DASH-06 (DashboardService::totalMenuItems) — counted ALL Item rows incl.
 *    INACTIVE / unpublished drafts, so the "Total articles menu" KPI drifts
 *    above the customer-facing 45-item SSOT. FIX: customer-facing semantic →
 *    count status=ACTIVE only.
 *
 * @group sentinel
 * @group dashboard
 */

namespace Tests\Feature\Dashboard;

use App\Domain\Order\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderStatusTransition;
use App\Models\User;
use App\Services\DashboardService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class DashboardSlaAndMenuCountSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // Pin "now" to a deterministic Paris instant so the 15-min SLA window
        // and order_datetime "today" bounds are stable across runs.
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

    private function actAsAdmin(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');
    }

    private function makePreparingOrder(Branch $branch, string $queue, $updatedAt, $orderDatetime = null): Order
    {
        return Order::factory()->create([
            'branch_id'      => $branch->id,
            'status'         => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PAID,
            'total'          => 10.00,
            'source'         => Source::POS,
            'queue_number'   => $queue,
            'order_datetime' => $orderDatetime ?? now(),
            'updated_at'     => $updatedAt,
        ]);
    }

    private function recordPreparingTransition(Order $order, $occurredAt): void
    {
        OrderStatusTransition::query()->create([
            'order_id'    => $order->id,
            'order_type'  => Order::class,
            'from_status' => OrderStatus::ACCEPT,
            'to_status'   => OrderStatus::PREPARING,
            'occurred_at' => $occurredAt,
        ]);
    }

    // -------------------------------------------------------------------------
    // DASH-05 — SLA clock must read the real PREPARING transition, not updated_at
    // -------------------------------------------------------------------------

    /**
     * RED before fix: an order that ENTERED PREPARING 40 min ago but whose
     * updated_at was bumped 1 min ago (late row write) is hidden by the old
     * updated_at-based query. The transition row proves it is 40 min late and
     * MUST surface as an SLA alert with ~40 min elapsed.
     */
    public function test_sla_alert_uses_real_preparing_transition_not_updated_at(): void
    {
        $this->actAsAdmin();
        $branch = Branch::factory()->create();

        // Entered PREPARING 40 min ago, but updated_at bumped 1 min ago.
        $order = $this->makePreparingOrder($branch, 'LATE-1', now()->subMinute());
        $this->recordPreparingTransition($order, now()->subMinutes(40));

        $alerts = collect(app(DashboardService::class)->slaAlerts());

        $alert = $alerts->firstWhere('queue_number', 'LATE-1');
        $this->assertNotNull(
            $alert,
            'A ticket that entered PREPARING 40 min ago must surface as an SLA alert even when updated_at was bumped recently.'
        );
        // Elapsed must reflect the transition instant (~40 min), not updated_at (~1 min).
        $this->assertGreaterThanOrEqual(
            39,
            (int) $alert['time_preparing'],
            'SLA elapsed minutes must be measured from the PREPARING transition (~40), not the recent updated_at (~1).'
        );
    }

    /**
     * A ticket that entered PREPARING only 5 min ago (transition row) must NOT
     * surface even if updated_at is old — the real clock says it is fresh.
     */
    public function test_sla_alert_excludes_recent_preparing_even_when_updated_at_is_old(): void
    {
        $this->actAsAdmin();
        $branch = Branch::factory()->create();

        // updated_at is 30 min old (stale write), but PREPARING started 5 min ago.
        $order = $this->makePreparingOrder($branch, 'FRESH-1', now()->subMinutes(30));
        $this->recordPreparingTransition($order, now()->subMinutes(5));

        $alerts = collect(app(DashboardService::class)->slaAlerts());

        $this->assertNull(
            $alerts->firstWhere('queue_number', 'FRESH-1'),
            'A ticket that entered PREPARING 5 min ago must NOT be flagged as a >15 min SLA breach.'
        );
    }

    /**
     * BACK-COMPAT: a PREPARING order that predates transition-recording (no
     * transition row) must still be evaluated via the updated_at fallback, so
     * it does not silently vanish. updated_at 20 min ago → alert.
     */
    public function test_sla_alert_falls_back_to_updated_at_when_no_transition_row(): void
    {
        $this->actAsAdmin();
        $branch = Branch::factory()->create();

        $this->makePreparingOrder($branch, 'LEGACY-1', now()->subMinutes(20));
        // No transition row recorded.

        $alerts = collect(app(DashboardService::class)->slaAlerts());

        $alert = $alerts->firstWhere('queue_number', 'LEGACY-1');
        $this->assertNotNull(
            $alert,
            'A legacy PREPARING order (no transition row) 20 min stale must still surface via the updated_at fallback.'
        );
    }

    /**
     * MULTIPLE transitions (e.g. KDS recall re-enters PREPARING) → clock must
     * use the MOST RECENT PREPARING transition, restarting the timer.
     */
    public function test_sla_alert_uses_latest_preparing_transition_on_recall(): void
    {
        $this->actAsAdmin();
        $branch = Branch::factory()->create();

        $order = $this->makePreparingOrder($branch, 'RECALL-1', now()->subMinutes(50));
        // First PREPARING entry 50 min ago, then a recall re-entered 3 min ago.
        $this->recordPreparingTransition($order, now()->subMinutes(50));
        $this->recordPreparingTransition($order, now()->subMinutes(3));

        $alerts = collect(app(DashboardService::class)->slaAlerts());

        $this->assertNull(
            $alerts->firstWhere('queue_number', 'RECALL-1'),
            'After a recall re-enters PREPARING 3 min ago, the SLA clock must restart (latest transition) → no breach.'
        );
    }

    // -------------------------------------------------------------------------
    // DASH-06 — total menu items must count ACTIVE (customer-facing) only
    // -------------------------------------------------------------------------

    public function test_total_menu_items_counts_active_only(): void
    {
        // 3 active (customer-visible) + 2 inactive drafts.
        Item::factory()->count(3)->create(['status' => Status::ACTIVE]);
        Item::factory()->count(2)->create(['status' => Status::INACTIVE]);

        $count = app(DashboardService::class)->totalMenuItems();

        $this->assertSame(
            3,
            $count,
            'Total articles menu must reflect the customer-facing (ACTIVE) catalogue, excluding INACTIVE/unpublished drafts.'
        );
    }
}
