<?php

namespace Tests\Unit\Backup;

use App\Console\Commands\Backup\BackupVerifyRestoreCommand;
use PHPUnit\Framework\TestCase;

/**
 * [OPS-1 restore drill — supervisor campaign 2026-06-04]
 *
 * Unit coverage for the PURE decision logic of `backup:verify-restore`:
 *   - newest-backup selection (mtime, robust to filename clock drift)
 *   - scratch-name derivation + the scratch != live safety refusal
 *   - GREEN/RED verdict aggregation from injected (scratch, live) counts
 *
 * The restore + chain-check path itself drives the real `mysql` /
 * `mysqldump` clients and a live MySQL server, so it CANNOT run on the
 * `:memory:` sqlite test connection (you cannot replay a mysqldump into
 * sqlite). That path is proven by running the command live against the
 * real latest backup (`php artisan backup:verify-restore` → GREEN); see
 * scripts/db/RESTORE_DRILL_2026-05-21.md for the manual round-trip proof
 * this command automates. This test pins the logic that decides the
 * verdict so a regression there is caught without a DB.
 *
 * Extends PHPUnit\Framework\TestCase (not the Laravel TestCase) — the
 * methods under test are static and pure, so no application container is
 * needed and the suite stays fast.
 */
class BackupVerifyRestoreLogicTest extends TestCase
{
    // ---- pickNewest -------------------------------------------------------

    public function test_pick_newest_returns_null_for_empty_list(): void
    {
        $this->assertNull(BackupVerifyRestoreCommand::pickNewest([]));
    }

    public function test_pick_newest_selects_highest_mtime_not_filename_order(): void
    {
        // Filename order would pick 2026-05-30, but mtime says the
        // 2026-05-28 file is actually the freshest (clock drift / re-touch).
        $candidates = [
            '/b/daily-2026-05-29.sql.gz',
            '/b/daily-2026-05-30.sql.gz',
            '/b/daily-2026-05-28.sql.gz',
        ];
        $mtimes = [
            '/b/daily-2026-05-29.sql.gz' => 1000,
            '/b/daily-2026-05-30.sql.gz' => 1100,
            '/b/daily-2026-05-28.sql.gz' => 9999, // freshest by mtime
        ];

        $this->assertSame(
            '/b/daily-2026-05-28.sql.gz',
            BackupVerifyRestoreCommand::pickNewest($candidates, $mtimes)
        );
    }

    public function test_pick_newest_single_candidate(): void
    {
        $this->assertSame(
            '/b/only.sql.gz',
            BackupVerifyRestoreCommand::pickNewest(['/b/only.sql.gz'], ['/b/only.sql.gz' => 1])
        );
    }

    // ---- deriveScratchName + safety refusal -------------------------------

    public function test_derive_scratch_default_suffix(): void
    {
        $this->assertSame('foodking_restore_scratch', BackupVerifyRestoreCommand::deriveScratchName('foodking'));
    }

    public function test_derive_scratch_honours_override(): void
    {
        $this->assertSame('my_scratch', BackupVerifyRestoreCommand::deriveScratchName('foodking', 'my_scratch'));
    }

    public function test_derive_scratch_refuses_when_override_equals_live(): void
    {
        // This is the data-loss guard: overriding scratch to the live DB
        // name must return null so handle() refuses before any DROP DATABASE.
        $this->assertNull(BackupVerifyRestoreCommand::deriveScratchName('foodking', 'foodking'));
    }

    public function test_derive_scratch_default_can_never_equal_live(): void
    {
        // The default suffix path can never collide with the live name.
        $this->assertNotNull(BackupVerifyRestoreCommand::deriveScratchName('foodking'));
        $this->assertNotSame('foodking', BackupVerifyRestoreCommand::deriveScratchName('foodking'));
    }

    // ---- evaluateCounts (verdict core) ------------------------------------

    public function test_evaluate_counts_green_when_all_nonzero_and_below_live(): void
    {
        // Stale-but-valid backup: every scratch count > 0 and <= live.
        $scratch = ['orders' => 191, 'audit_logs' => 126, 'z_reports' => 5];
        $live = ['orders' => 3443, 'audit_logs' => 2556, 'z_reports' => 5];

        $this->assertSame([], BackupVerifyRestoreCommand::evaluateCounts($scratch, $live, false));
    }

    public function test_evaluate_counts_red_when_a_table_is_empty(): void
    {
        $scratch = ['orders' => 0, 'audit_logs' => 126, 'z_reports' => 5];
        $live = ['orders' => 3443, 'audit_logs' => 2556, 'z_reports' => 5];

        $reasons = BackupVerifyRestoreCommand::evaluateCounts($scratch, $live, false);
        $this->assertNotEmpty($reasons);
        $this->assertStringContainsString('orders', $reasons[0]);
        $this->assertStringContainsString('below floor', $reasons[0]);
    }

    public function test_evaluate_counts_red_when_table_missing(): void
    {
        // null = COUNT(*) failed (table absent from the restored schema).
        $scratch = ['orders' => 191, 'audit_logs' => null, 'z_reports' => 5];
        $live = ['orders' => 3443, 'audit_logs' => 2556, 'z_reports' => 5];

        $reasons = BackupVerifyRestoreCommand::evaluateCounts($scratch, $live, false);
        $this->assertCount(1, $reasons);
        $this->assertStringContainsString('audit_logs', $reasons[0]);
        $this->assertStringContainsString('missing', $reasons[0]);
    }

    public function test_evaluate_counts_red_when_scratch_exceeds_live(): void
    {
        // Impossible for append-only/growing tables — signals a corrupt
        // or mismatched restore.
        $scratch = ['orders' => 5000, 'audit_logs' => 126, 'z_reports' => 5];
        $live = ['orders' => 3443, 'audit_logs' => 2556, 'z_reports' => 5];

        $reasons = BackupVerifyRestoreCommand::evaluateCounts($scratch, $live, false);
        $this->assertNotEmpty($reasons);
        $this->assertStringContainsString('impossible', $reasons[0]);
    }

    public function test_evaluate_counts_zreports_zero_is_red_by_default(): void
    {
        $scratch = ['orders' => 191, 'audit_logs' => 126, 'z_reports' => 0];
        $live = ['orders' => 3443, 'audit_logs' => 2556, 'z_reports' => 0];

        $reasons = BackupVerifyRestoreCommand::evaluateCounts($scratch, $live, false);
        $this->assertNotEmpty($reasons);
        $this->assertStringContainsString('z_reports', $reasons[0]);
    }

    public function test_evaluate_counts_zreports_zero_allowed_with_flag(): void
    {
        // Dev boxes that never closed a Z report: --allow-empty-zreports
        // relaxes the floor for z_reports ONLY.
        $scratch = ['orders' => 191, 'audit_logs' => 126, 'z_reports' => 0];
        $live = ['orders' => 3443, 'audit_logs' => 2556, 'z_reports' => 0];

        $this->assertSame([], BackupVerifyRestoreCommand::evaluateCounts($scratch, $live, true));
    }

    public function test_evaluate_counts_flag_does_not_relax_other_tables(): void
    {
        // The relaxation must NOT bleed into orders/audit_logs.
        $scratch = ['orders' => 0, 'audit_logs' => 126, 'z_reports' => 0];
        $live = ['orders' => 3443, 'audit_logs' => 2556, 'z_reports' => 0];

        $reasons = BackupVerifyRestoreCommand::evaluateCounts($scratch, $live, true);
        $this->assertCount(1, $reasons);
        $this->assertStringContainsString('orders', $reasons[0]);
    }
}
