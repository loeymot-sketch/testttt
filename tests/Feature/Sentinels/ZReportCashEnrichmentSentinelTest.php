<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Role as EnumRole;
use App\Models\Branch;
use App\Models\DeliveryBoyCashMovement;
use App\Models\DeliveryBoyCashSession;
use App\Models\User;
use App\Services\Delivery\DeliveryBoyCashSessionService;
use App\Services\Fiscal\ZReportCashEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [V1.0.2 Wave 6b-1.5 — 2026-05-18] Z-Report delivery-cash enrichment sentinel.
 *
 * Locks the contract of `ZReportCashEnrichmentService::enrichClose()`,
 * `getDeliveryMovementsBetween()`, and `verifyConsistencyVsAuditLog()` —
 * the NF525 sign-off BLOCKER for Livreur V1.0.2.
 *
 * Composition-pattern guarantee : these are NEW methods on the existing
 * decorator. They DO NOT modify ZReportService nor the HMAC chain. They
 * DO NOT write to audit_logs (read-only consistency probe).
 *
 * 5 scenarios :
 *   1. Open → collect+collect → change → close → enrichClose returns correct totals.
 *   2. 2 livreurs / same branch / same Z window → aggregated correctly.
 *   3. 2 branches → branch-A's enrichClose returns ONLY branch-A figures.
 *   4. After happy-path lifecycle, verifyConsistencyVsAuditLog returns ok=true.
 *   5. Session opened pre-window, closed in-window → counted in correct period.
 */
class ZReportCashEnrichmentSentinelTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;
    private Branch $branchB;
    private User $livreurA1;
    private User $livreurA2;
    private User $livreurB;
    private DeliveryBoyCashSessionService $sessionService;
    private ZReportCashEnrichmentService $enrichmentService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-'.str_repeat('a', 40));

        Role::firstOrCreate(
            ['id' => EnumRole::DELIVERY_BOY],
            ['name' => 'Delivery Boy', 'guard_name' => 'sanctum']
        );

        $this->branchA = Branch::factory()->create();
        $this->branchB = Branch::factory()->create();

        $this->livreurA1 = $this->makeDeliveryBoy($this->branchA->id);
        $this->livreurA2 = $this->makeDeliveryBoy($this->branchA->id);
        $this->livreurB  = $this->makeDeliveryBoy($this->branchB->id);

        $this->sessionService    = app(DeliveryBoyCashSessionService::class);
        $this->enrichmentService = app(ZReportCashEnrichmentService::class);
    }

    private function makeDeliveryBoy(int $branchId): User
    {
        $user = User::forceCreate([
            'name'              => 'Livreur '.uniqid(),
            'email'             => 'livreur-'.uniqid().'@sentinel.test',
            'username'          => 'livreur_'.uniqid(),
            'password'          => bcrypt('secret-passwd'),
            'branch_id'         => $branchId,
            'email_verified_at' => now(),
            'status'            => 1,
        ]);
        $user->assignRole(EnumRole::DELIVERY_BOY);

        return $user->fresh();
    }

    /**
     * Test 1 — happy-path single session lifecycle :
     *   open 50€ → +10€ + +20€ collects → -1€ change → close 79€ → enrichClose.
     * Expected aggregates :
     *   - delivery_cash_collected_total = 30.00 (sum of order_collect IN)
     *   - delivery_cash_change_given_total = 1.00 (sum of change_given OUT)
     *   - session_count = 1.
     */
    public function test_enrichClose_returns_correct_totals_for_single_session(): void
    {
        $session = $this->sessionService->openSession(
            $this->branchA->id,
            $this->livreurA1->id,
            50.00,
            $this->livreurA1->id,
        );

        $this->sessionService->recordMovement(
            $session->id,
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            10.00,
            DeliveryBoyCashMovement::DIRECTION_IN,
        );
        $this->sessionService->recordMovement(
            $session->id,
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            20.00,
            DeliveryBoyCashMovement::DIRECTION_IN,
        );
        $this->sessionService->recordMovement(
            $session->id,
            DeliveryBoyCashMovement::TYPE_CHANGE_GIVEN,
            1.00,
            DeliveryBoyCashMovement::DIRECTION_OUT,
        );

        $this->sessionService->closeSession($session->id, 79.00);

        $closeAt  = Carbon::now()->addSecond();
        $result   = $this->enrichmentService->enrichClose($this->branchA->id, $closeAt);

        $this->assertEqualsWithDelta(30.00, $result['delivery_cash_collected_total'], 0.01, 'collect IN must aggregate to 10+20=30');
        $this->assertEqualsWithDelta(1.00, $result['delivery_cash_change_given_total'], 0.01, 'change OUT must aggregate to 1');
        $this->assertSame(1, $result['session_count']);
        $this->assertCount(1, $result['sessions']);
        $this->assertSame((int) $session->id, $result['sessions'][0]['id']);
        $this->assertSame((int) $this->livreurA1->id, $result['sessions'][0]['delivery_boy_id']);
        $this->assertEqualsWithDelta(50.00, $result['sessions'][0]['opening_amount'], 0.01);
        $this->assertEqualsWithDelta(79.00, $result['sessions'][0]['closing_amount'], 0.01);
    }

    /**
     * Test 2 — 2 livreurs / same branch / same Z window.
     * Expected : aggregates SUM across both livreurs ; session_count = 2.
     */
    public function test_enrichClose_aggregates_multiple_sessions_same_branch(): void
    {
        // Livreur A1 : open 30€, collect 15€, close 45€.
        $sessionA1 = $this->sessionService->openSession(
            $this->branchA->id,
            $this->livreurA1->id,
            30.00,
            $this->livreurA1->id,
        );
        $this->sessionService->recordMovement(
            $sessionA1->id,
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            15.00,
            DeliveryBoyCashMovement::DIRECTION_IN,
        );
        $this->sessionService->closeSession($sessionA1->id, 45.00);

        // Livreur A2 : open 40€, collect 22€, change 2€, close 60€.
        $sessionA2 = $this->sessionService->openSession(
            $this->branchA->id,
            $this->livreurA2->id,
            40.00,
            $this->livreurA2->id,
        );
        $this->sessionService->recordMovement(
            $sessionA2->id,
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            22.00,
            DeliveryBoyCashMovement::DIRECTION_IN,
        );
        $this->sessionService->recordMovement(
            $sessionA2->id,
            DeliveryBoyCashMovement::TYPE_CHANGE_GIVEN,
            2.00,
            DeliveryBoyCashMovement::DIRECTION_OUT,
        );
        $this->sessionService->closeSession($sessionA2->id, 60.00);

        $result = $this->enrichmentService->enrichClose(
            $this->branchA->id,
            Carbon::now()->addSecond(),
        );

        $this->assertEqualsWithDelta(15.00 + 22.00, $result['delivery_cash_collected_total'], 0.01);
        $this->assertEqualsWithDelta(2.00, $result['delivery_cash_change_given_total'], 0.01);
        $this->assertSame(2, $result['session_count']);
        $this->assertCount(2, $result['sessions']);

        $sessionIds = array_column($result['sessions'], 'id');
        sort($sessionIds);
        $expected = [(int) $sessionA1->id, (int) $sessionA2->id];
        sort($expected);
        $this->assertSame($expected, $sessionIds);
    }

    /**
     * Test 3 — branch isolation :
     *   sessions in branch A + sessions in branch B → enrichClose(A) returns
     *   ONLY branch-A figures.
     *
     * Critical NF525 multi-tenant invariant.
     */
    public function test_enrichClose_isolates_by_branch(): void
    {
        // Branch A session : 50€ float, collect 11€.
        $sessionA = $this->sessionService->openSession(
            $this->branchA->id,
            $this->livreurA1->id,
            50.00,
            $this->livreurA1->id,
        );
        $this->sessionService->recordMovement(
            $sessionA->id,
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            11.00,
            DeliveryBoyCashMovement::DIRECTION_IN,
        );
        $this->sessionService->closeSession($sessionA->id, 61.00);

        // Branch B session : 60€ float, collect 33€.
        $sessionB = $this->sessionService->openSession(
            $this->branchB->id,
            $this->livreurB->id,
            60.00,
            $this->livreurB->id,
        );
        $this->sessionService->recordMovement(
            $sessionB->id,
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            33.00,
            DeliveryBoyCashMovement::DIRECTION_IN,
        );
        $this->sessionService->closeSession($sessionB->id, 93.00);

        $closeAt = Carbon::now()->addSecond();

        $resultA = $this->enrichmentService->enrichClose($this->branchA->id, $closeAt);
        $this->assertSame(1, $resultA['session_count'], 'Branch A must see only A session.');
        $this->assertEqualsWithDelta(11.00, $resultA['delivery_cash_collected_total'], 0.01);
        $this->assertSame((int) $sessionA->id, $resultA['sessions'][0]['id']);

        $resultB = $this->enrichmentService->enrichClose($this->branchB->id, $closeAt);
        $this->assertSame(1, $resultB['session_count'], 'Branch B must see only B session.');
        $this->assertEqualsWithDelta(33.00, $resultB['delivery_cash_collected_total'], 0.01);
        $this->assertSame((int) $sessionB->id, $resultB['sessions'][0]['id']);

        // Cross-check : branch A figures MUST NOT contain B's session.
        $branchASessionIds = array_column($resultA['sessions'], 'id');
        $this->assertNotContains((int) $sessionB->id, $branchASessionIds, 'NF525 multi-tenant breach if sessionB appears in branchA aggregates');
    }

    /**
     * Test 4 — verifyConsistencyVsAuditLog returns ok=true after happy-path.
     *
     * Sum of `cash.delivery.movement.recorded` audit_log signed amounts
     * === sum of DeliveryBoyCashMovement::signedAmount() for the window.
     */
    public function test_verifyConsistencyVsAuditLog_ok_after_happy_path(): void
    {
        $start = Carbon::now()->subSecond();

        $session = $this->sessionService->openSession(
            $this->branchA->id,
            $this->livreurA1->id,
            50.00,
            $this->livreurA1->id,
        );
        $this->sessionService->recordMovement(
            $session->id,
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            12.50,
            DeliveryBoyCashMovement::DIRECTION_IN,
        );
        $this->sessionService->recordMovement(
            $session->id,
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            7.50,
            DeliveryBoyCashMovement::DIRECTION_IN,
        );
        $this->sessionService->recordMovement(
            $session->id,
            DeliveryBoyCashMovement::TYPE_CHANGE_GIVEN,
            2.00,
            DeliveryBoyCashMovement::DIRECTION_OUT,
        );
        $this->sessionService->closeSession($session->id, 68.00);

        $end = Carbon::now()->addSecond();

        $result = $this->enrichmentService->verifyConsistencyVsAuditLog(
            $this->branchA->id,
            $start,
            $end,
        );

        $this->assertTrue(
            $result['ok'],
            'Audit chain consistency MUST hold after happy-path. Discrepancies: '.json_encode($result['discrepancies']),
        );
        // Signed sum : +12.50 + +7.50 - 2.00 = 18.00
        $this->assertEqualsWithDelta(18.00, $result['audit_total'], 0.01);
        $this->assertEqualsWithDelta(18.00, $result['movements_total'], 0.01);
        $this->assertSame(3, $result['audit_count']);
        $this->assertSame(3, $result['movements_count']);
        $this->assertSame([], $result['discrepancies']);
    }

    /**
     * Test 5 — edge case : session opened BEFORE period start, closed DURING
     * period → counted in the correct period (the close window, not the open
     * window). Mirrors ZReportService aggregation semantics.
     *
     * We use Carbon::setTestNow to control the clock deterministically :
     *   - t0 : session opens BEFORE the Z window (relative to the test reference)
     *   - t1 : Z window starts (= simulated "previous Z close")
     *   - t2 : session closes INSIDE the window
     *   - t3 : Z window ends (= current Z close)
     *
     * Expected : enrichClose(closeAt=t3) returns the session (closed_at ∈ (t1, t3]).
     * The session lifecycle predates the window, but its close is the fiscal fact.
     */
    public function test_enrichClose_counts_session_in_period_of_close_not_open(): void
    {
        // t0 = "yesterday" — session opens.
        Carbon::setTestNow(Carbon::parse('2026-05-17 10:00:00'));
        $session = $this->sessionService->openSession(
            $this->branchA->id,
            $this->livreurA1->id,
            40.00,
            $this->livreurA1->id,
        );
        // Movement recorded before window — but it's the session's close that
        // brings it into the window.
        $this->sessionService->recordMovement(
            $session->id,
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            25.00,
            DeliveryBoyCashMovement::DIRECTION_IN,
        );

        // t2 = "today during shift" — session closes inside the window.
        Carbon::setTestNow(Carbon::parse('2026-05-18 14:30:00'));
        $this->sessionService->closeSession($session->id, 65.00);

        // t3 = "today's Z close" — window ends here.
        Carbon::setTestNow(Carbon::parse('2026-05-18 22:00:00'));
        $closeAt = Carbon::now();

        $result = $this->enrichmentService->enrichClose($this->branchA->id, $closeAt);

        $this->assertSame(1, $result['session_count'], 'Session closed in-window must be counted.');
        $this->assertEqualsWithDelta(25.00, $result['delivery_cash_collected_total'], 0.01);
        $this->assertSame((int) $session->id, $result['sessions'][0]['id']);

        Carbon::setTestNow(); // reset
    }
}
