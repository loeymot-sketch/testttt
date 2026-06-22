<?php

namespace Tests\Feature\Cash;

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [Sprint 1D / F-5 — 2026-05-16] DELETE trigger immutability for cash tables.
 *
 * NF525 mandates 6-year retention of fiscal evidence. We verify the DB
 * layer rejects any DELETE attempt on cash_movements and cash_drawer_sessions
 * — independently of application logic (Eloquent / raw SQL / cascade).
 *
 * MySQL/MariaDB triggers from migration 2026_05_10_010000 (P0-FIX-4).
 * SQLite triggers from migration 2026_05_16_130000 (this sprint).
 * Other drivers (pgsql, sqlsrv) skip with a guard — production targets MySQL.
 */
class CashMovementsDeleteForbiddenTest extends TestCase
{
    use RefreshDatabase;

    private CashDrawerService $service;
    private Branch $branch;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();

        $this->service = new CashDrawerService();
        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->actingAs($this->cashier);

        Config::set('cash.variance_threshold_eur', 999.0);
        Config::set('cash.variance_manager_approval_required', false);
    }

    private function assertDriverSupported(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            $this->markTestSkipped("DELETE trigger not installed for driver={$driver}");
        }
    }

    public function test_eloquent_delete_on_cash_movement_is_forbidden(): void
    {
        $this->assertDriverSupported();

        $session = $this->service->openSession($this->branch->id, $this->cashier->id, 100.00);
        $movement = $this->service->recordMovement(
            $session->id,
            CashMovement::TYPE_ORDER_PAYMENT,
            10.00,
            CashMovement::DIRECTION_IN,
        );
        $this->assertNotNull($movement);

        $threw = false;
        try {
            $movement->delete();
        } catch (\Throwable $e) {
            $threw = true;
            $this->assertMatchesRegularExpression(
                '/cash_movements.*(?:immutable|DELETE forbidden|RAISE\(ABORT)/i',
                $e->getMessage(),
            );
        }
        $this->assertTrue($threw, 'DELETE on cash_movements should have raised an SQL error');

        // Row must still exist after the failed delete.
        $this->assertSame(1, CashMovement::query()->where('id', $movement->id)->count());
    }

    public function test_raw_sql_delete_on_cash_movement_is_forbidden(): void
    {
        $this->assertDriverSupported();

        $session = $this->service->openSession($this->branch->id, $this->cashier->id, 100.00);
        $movement = $this->service->recordMovement(
            $session->id,
            CashMovement::TYPE_ORDER_PAYMENT,
            10.00,
            CashMovement::DIRECTION_IN,
        );

        $threw = false;
        try {
            DB::delete('DELETE FROM cash_movements WHERE id = ?', [$movement->id]);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Raw DELETE on cash_movements should have raised an SQL error');
        $this->assertSame(1, CashMovement::query()->where('id', $movement->id)->count());
    }

    public function test_delete_on_cash_drawer_session_is_forbidden(): void
    {
        $this->assertDriverSupported();

        $session = $this->service->openSession($this->branch->id, $this->cashier->id, 100.00);

        $threw = false;
        try {
            $session->delete();
        } catch (\Throwable $e) {
            $threw = true;
            $this->assertMatchesRegularExpression(
                '/cash_drawer_sessions.*(?:immutable|DELETE forbidden|RAISE\(ABORT)/i',
                $e->getMessage(),
            );
        }
        $this->assertTrue($threw, 'DELETE on cash_drawer_sessions should have raised an SQL error');

        $this->assertSame(
            1,
            DB::table('cash_drawer_sessions')->where('id', $session->id)->count(),
            'Session row must persist after rejected DELETE',
        );
    }
}
