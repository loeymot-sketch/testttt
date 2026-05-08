<?php

namespace Tests\Feature\Cash;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [PRE-PROD HARDENING / POS-2 / P1-8]
 *
 * Tests for `foodking:cash:close-stale-sessions`.
 *
 * The F-003 migration that creates `cash_drawer_sessions` lives on a
 * separate agent branch and has not been merged here yet. We bootstrap
 * the table inline via `Schema::create` (guarded by `Schema::hasTable`
 * so the test stays a no-op once the real migration ships). This gives
 * us real coverage today without a `markTestSkipped` fence.
 *
 * The inline schema mirrors `plans/PLAN_AUDIT_F003_CASH_RECONCILIATION_2026-05-07.md`
 * §2.1 lines 67-86. Drift = false-green; if the agent's migration
 * diverges, this stub must be updated to match.
 */
class CloseStaleCashDrawerSessionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Inline F-003 schema stub — guarded so post-merge it's a no-op.
        if (! Schema::hasTable('cash_drawer_sessions')) {
            Schema::create('cash_drawer_sessions', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('branch_id');
                $t->unsignedBigInteger('cashier_user_id');
                $t->timestamp('opened_at');
                $t->timestamp('closed_at')->nullable();
                $t->decimal('opening_float', 10, 2)->default(0);
                $t->decimal('expected_cash', 10, 2)->nullable();
                $t->decimal('actual_cash', 10, 2)->nullable();
                $t->decimal('variance', 10, 2)->nullable();
                $t->string('variance_reason', 255)->nullable();
                $t->tinyInteger('status')->default(1); // 1=open, 2=closed, 3=force_closed
                $t->timestamps();

                $t->index(['branch_id', 'status'], 'idx_branch_status');
                $t->index(['cashier_user_id', 'status'], 'idx_cashier_status');
                $t->index('opened_at', 'idx_opened_at');
            });
        }
    }

    /**
     * Insert a cash drawer session row directly via DB::table to avoid
     * coupling to the (frozen-zone) `CashDrawerSession` Eloquent model.
     */
    private function insertSession(array $overrides = []): int
    {
        $defaults = [
            'branch_id' => 1,
            'cashier_user_id' => 1,
            'opened_at' => now()->subHours(2),
            'closed_at' => null,
            'opening_float' => 50.00,
            'expected_cash' => null,
            'actual_cash' => null,
            'variance' => null,
            'variance_reason' => null,
            'status' => 1, // open
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return DB::table('cash_drawer_sessions')->insertGetId(array_merge($defaults, $overrides));
    }

    public function test_closes_open_sessions_older_than_24h(): void
    {
        $staleId = $this->insertSession([
            'opened_at' => now()->subHours(30),
            'status' => 1,
        ]);

        $this->artisan('foodking:cash:close-stale-sessions')
            ->expectsOutputToContain('[CLOSED]')
            ->assertExitCode(0);

        $row = DB::table('cash_drawer_sessions')->where('id', $staleId)->first();
        $this->assertSame(3, (int) $row->status, 'status must flip to force_closed (3)');
        $this->assertNotNull($row->closed_at, 'closed_at must be set');
        $this->assertNull($row->variance, 'variance must remain NULL — actual count requires cashier');
        $this->assertSame('auto_close_stale_24h', $row->variance_reason);
    }

    public function test_does_not_close_recent_open_sessions(): void
    {
        $recentId = $this->insertSession([
            'opened_at' => now()->subHours(4),
            'status' => 1,
        ]);

        $this->artisan('foodking:cash:close-stale-sessions')
            ->expectsOutputToContain('[OK]')
            ->assertExitCode(0);

        $row = DB::table('cash_drawer_sessions')->where('id', $recentId)->first();
        $this->assertSame(1, (int) $row->status, 'recent session must remain open (1)');
        $this->assertNull($row->closed_at);
        $this->assertNull($row->variance_reason);
    }

    public function test_does_not_close_already_closed_sessions(): void
    {
        // Already closed (status=2) but old — must NOT be touched.
        $closedId = $this->insertSession([
            'opened_at' => now()->subHours(48),
            'closed_at' => now()->subHours(36),
            'status' => 2, // closed
            'variance' => 12.34,
            'variance_reason' => 'manual_count_diff',
        ]);

        // Force-closed (status=3) old — also untouched.
        $forceClosedId = $this->insertSession([
            'opened_at' => now()->subHours(72),
            'closed_at' => now()->subHours(48),
            'status' => 3,
            'variance_reason' => 'auto_close_stale_24h',
        ]);

        $this->artisan('foodking:cash:close-stale-sessions')
            ->expectsOutputToContain('[OK]')
            ->assertExitCode(0);

        $closed = DB::table('cash_drawer_sessions')->where('id', $closedId)->first();
        $this->assertSame(2, (int) $closed->status);
        $this->assertSame('12.34', (string) $closed->variance, 'pre-existing variance preserved');
        $this->assertSame('manual_count_diff', $closed->variance_reason, 'pre-existing reason preserved');

        $forceClosed = DB::table('cash_drawer_sessions')->where('id', $forceClosedId)->first();
        $this->assertSame(3, (int) $forceClosed->status);
    }

    public function test_dry_run_does_not_modify_anything(): void
    {
        $staleId = $this->insertSession([
            'opened_at' => now()->subHours(48),
            'status' => 1,
        ]);

        $this->artisan('foodking:cash:close-stale-sessions', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY-RUN]')
            ->assertExitCode(0);

        $row = DB::table('cash_drawer_sessions')->where('id', $staleId)->first();
        $this->assertSame(1, (int) $row->status, 'dry-run must NOT mutate status');
        $this->assertNull($row->closed_at, 'dry-run must NOT set closed_at');
        $this->assertNull($row->variance_reason, 'dry-run must NOT set reason');
    }

    public function test_logs_warning_per_force_close(): void
    {
        $staleId = $this->insertSession([
            'opened_at' => now()->subHours(36),
            'branch_id' => 7,
            'cashier_user_id' => 42,
            'status' => 1,
        ]);

        Log::spy();

        $this->artisan('foodking:cash:close-stale-sessions')
            ->assertExitCode(0);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($staleId) {
                return $message === '[CashDrawer] Force-closed stale session'
                    && (int) $context['session_id'] === $staleId
                    && (int) $context['branch_id'] === 7
                    && (int) $context['cashier_user_id'] === 42
                    && (int) $context['closed_after_hours'] === 24;
            })
            ->once();
    }

    public function test_respects_threshold_hours_option(): void
    {
        // 16h-old session — would be ignored at default 24h threshold,
        // but must close at --threshold-hours=12.
        $sessionId = $this->insertSession([
            'opened_at' => now()->subHours(16),
            'status' => 1,
        ]);

        $this->artisan('foodking:cash:close-stale-sessions', ['--threshold-hours' => 12])
            ->expectsOutputToContain('[CLOSED]')
            ->assertExitCode(0);

        $row = DB::table('cash_drawer_sessions')->where('id', $sessionId)->first();
        $this->assertSame(3, (int) $row->status);
        $this->assertSame('auto_close_stale_12h', $row->variance_reason,
            'reason tag must reflect the runtime threshold, not the 24h default');
    }
}
