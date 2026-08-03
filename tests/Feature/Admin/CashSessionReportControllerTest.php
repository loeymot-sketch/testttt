<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [Wave O — O4 2026-05-20] Admin daily cash sessions report — read-only view.
 *
 * Owner request : « Je veux que toutes les caisses de chaque jour soient
 * enregistrées pour le profil admin. Quand on va sur le profil admin, on
 * verra les caisses chaque jour, c'est-à-dire le début et la fin. »
 *
 * Endpoint : GET /api/admin/cash-sessions-report
 * Permission : `cash-sessions-report` (Admin + Branch Manager). Permission
 * dédiée pour que la sidebar admin auto-display/auto-hide le menu item.
 * Branch isolation : Admin voit toutes les branches (branch_id=0 bypass
 * BranchScope), Branch Manager voit uniquement la sienne (BranchScope
 * filtrage applique sur CashDrawerSession).
 */
class CashSessionReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;
    private Branch $branchB;
    private User $admin;
    private User $managerA;
    private User $cashierA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branchA = Branch::factory()->create();
        $this->branchB = Branch::factory()->create();

        // Admin = branch_id=0, voit cross-branch.
        $this->admin = User::factory()->create(['branch_id' => 0]);
        $this->admin->assignRole('Admin');

        // Branch Manager scoped to branch A.
        $this->managerA = User::factory()->create(['branch_id' => $this->branchA->id]);
        $this->managerA->assignRole('Branch Manager');

        // POS Operator (cashier) — no fiscal permission.
        $this->cashierA = User::factory()->create(['branch_id' => $this->branchA->id]);
        $this->cashierA->assignRole('POS Operator');
    }

    private function makeSession(Branch $branch, User $cashier, Carbon $openedAt, ?Carbon $closedAt = null, float $opening = 100.0, ?float $closing = null): CashDrawerSession
    {
        return CashDrawerSession::create([
            'branch_id'        => $branch->id,
            'opened_by_user_id'=> $cashier->id,
            'opened_at'        => $openedAt,
            'closed_at'        => $closedAt,
            'opening_amount'   => $opening,
            'closing_amount'   => $closing,
            'status'           => $closedAt ? CashDrawerSession::STATUS_CLOSED : CashDrawerSession::STATUS_OPEN,
        ]);
    }

    private function makeMovement(CashDrawerSession $session, float $amount = 25.0): CashMovement
    {
        return CashMovement::create([
            'cash_drawer_session_id' => $session->id,
            'branch_id'              => $session->branch_id,
            'order_id'               => null,
            'type'                   => CashMovement::TYPE_ORDER_PAYMENT,
            'amount'                 => $amount,
            'direction'              => CashMovement::DIRECTION_IN,
            'notes'                  => null,
        ]);
    }

    public function test_admin_sees_sessions_across_all_branches(): void
    {
        $today = Carbon::now()->startOfDay()->addHours(10);
        $sA = $this->makeSession($this->branchA, $this->cashierA, $today, (clone $today)->addHours(8), 100.0, 580.5);
        $sB = $this->makeSession($this->branchB, $this->cashierA, $today, (clone $today)->addHours(8), 200.0, 750.0);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/cash-sessions-report');

        $response->assertOk();
        $response->assertJsonPath('status', true);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($sA->id, $ids);
        $this->assertContains($sB->id, $ids);
        $this->assertCount(2, $ids, 'Admin must see sessions from both branches');
    }

    public function test_branch_manager_only_sees_own_branch(): void
    {
        $today = Carbon::now()->startOfDay()->addHours(10);
        $sA = $this->makeSession($this->branchA, $this->cashierA, $today, (clone $today)->addHours(8));
        $sB = $this->makeSession($this->branchB, $this->cashierA, $today, (clone $today)->addHours(8));

        $response = $this->actingAs($this->managerA, 'sanctum')
            ->getJson('/api/admin/cash-sessions-report');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($sA->id, $ids);
        $this->assertNotContains($sB->id, $ids, 'Branch manager must NOT see other branch');
    }

    public function test_rejects_unauthenticated(): void
    {
        $this->getJson('/api/admin/cash-sessions-report')->assertStatus(401);
    }

    public function test_rejects_user_without_fiscal_permission(): void
    {
        $response = $this->actingAs($this->cashierA, 'sanctum')
            ->getJson('/api/admin/cash-sessions-report');

        $response->assertStatus(403);
    }

    public function test_response_payload_shape_per_session(): void
    {
        $opened = Carbon::now()->startOfDay()->addHours(9)->addMinutes(15);
        $closed = (clone $opened)->addHours(7);
        $session = $this->makeSession($this->branchA, $this->cashierA, $opened, $closed, 150.0, 825.50);

        // Add 2 cash movements to confirm count.
        $this->makeMovement($session, 25.50);
        $this->makeMovement($session, 12.00);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/cash-sessions-report');

        $response->assertOk();
        $first = $response->json('data.0');
        $this->assertSame($session->id, $first['id']);
        $this->assertSame($this->branchA->id, $first['branch_id']);
        // Floats compared via assertEquals — JSON encoding renders 150.0 as 150.
        $this->assertEquals(150.0, $first['opening_amount']);
        $this->assertEquals(825.50, $first['closing_amount']);
        $this->assertSame(2, $first['transactions_count']);
        $this->assertSame($this->cashierA->id, $first['opened_by_user_id']);
        $this->assertNotNull($first['opened_at']);
        $this->assertNotNull($first['closed_at']);
        $this->assertSame($opened->toDateString(), $first['business_date']);
    }

    public function test_sessions_ordered_by_opened_at_descending(): void
    {
        $today = Carbon::now()->startOfDay()->addHours(10);
        $yesterday = (clone $today)->subDay();

        $sYesterday = $this->makeSession($this->branchA, $this->cashierA, $yesterday, (clone $yesterday)->addHours(7));
        $sToday = $this->makeSession($this->branchA, $this->cashierA, $today, (clone $today)->addHours(7));

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/cash-sessions-report');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        // Recent first.
        $this->assertSame([$sToday->id, $sYesterday->id], $ids);
    }

    public function test_query_count_bounded_no_n_plus_1(): void
    {
        $today = Carbon::now()->startOfDay()->addHours(10);
        for ($i = 0; $i < 5; $i++) {
            $opened = (clone $today)->subHours($i);
            $session = $this->makeSession($this->branchA, $this->cashierA, $opened, (clone $opened)->addHours(2));
            for ($j = 0; $j < 3; $j++) {
                $this->makeMovement($session, 10.0);
            }
        }

        DB::enableQueryLog();
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/cash-sessions-report');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertLessThan(
            20,
            $queries,
            'Query count must be bounded (no N+1) — actual: '.$queries
        );
    }

    public function test_filter_by_date_range_applied(): void
    {
        $todayMorning = Carbon::now()->startOfDay()->addHours(8);
        $twoDaysAgo = (clone $todayMorning)->subDays(2);
        $sNow = $this->makeSession($this->branchA, $this->cashierA, $todayMorning, (clone $todayMorning)->addHours(6));
        $sOld = $this->makeSession($this->branchA, $this->cashierA, $twoDaysAgo, (clone $twoDaysAgo)->addHours(6));

        $from = Carbon::now()->startOfDay()->subDay()->toDateString();
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/cash-sessions-report?from='.$from);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($sNow->id, $ids);
        $this->assertNotContains($sOld->id, $ids, 'Sessions before `from` must be filtered out');
    }
}
