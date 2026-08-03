<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [Sprint 1D / F-5 — 2026-05-16] cash_movements + cash_drawer_sessions
 * DELETE immutability — SQLite parity.
 *
 * Context
 * -------
 * Migration 2026_05_10_010000 (P0-FIX-4 / NF525) already installs:
 *   - FK cash_movements.cash_drawer_session_id → restrictOnDelete()
 *   - MySQL/MariaDB triggers cash_movements_no_delete + cash_drawer_sessions_no_delete
 *
 * That migration explicitly skips SQLite (test runner default). PHPUnit
 * tests therefore cannot verify the immutability invariant the way they
 * verify the audit_logs / z_reports chain. This migration closes the gap
 * by adding the equivalent SQLite triggers (`RAISE(ABORT, ...)` pattern,
 * same as audit_logs in 2026_04_22_000002).
 *
 * MySQL prod is unchanged (the triggers already exist; we no-op there).
 *
 * Edge cases
 * ----------
 *   - Test setup with RefreshDatabase + SQLite memory:
 *       trigger fires correctly on DELETE attempts.
 *   - Production MySQL:
 *       this migration is a no-op (driver guard).
 *   - Re-run safe: `DROP TRIGGER IF EXISTS` first.
 *
 * Rollback drops the SQLite triggers only — never touches MySQL.
 */
return new class extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'sqlite') {
            return;
        }

        $this->dropTriggers();

        if (Schema::hasTable('cash_movements')) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER cash_movements_no_delete
                BEFORE DELETE ON cash_movements
                BEGIN
                    SELECT RAISE(ABORT, 'cash_movements is immutable (NF525 / Sprint 1D / F-5) - DELETE forbidden');
                END;
            SQL);
        }

        if (Schema::hasTable('cash_drawer_sessions')) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER cash_drawer_sessions_no_delete
                BEFORE DELETE ON cash_drawer_sessions
                BEGIN
                    SELECT RAISE(ABORT, 'cash_drawer_sessions is immutable (NF525 / Sprint 1D / F-5) - DELETE forbidden');
                END;
            SQL);
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'sqlite') {
            return;
        }
        $this->dropTriggers();
    }

    private function dropTriggers(): void
    {
        foreach (['cash_movements_no_delete', 'cash_drawer_sessions_no_delete'] as $t) {
            try {
                DB::unprepared("DROP TRIGGER IF EXISTS {$t}");
            } catch (\Throwable $e) {
                // best-effort
            }
        }
    }
};
