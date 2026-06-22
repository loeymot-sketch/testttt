<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * [P0-FIX-3 NF525 / iter15 — 2026-05-10]
 *
 * Skip a test whose assertions only make sense under MySQL/MariaDB.
 *
 * Rationale: NF525 fiscal immutability is enforced by BEFORE-DELETE
 * triggers (z_reports, audit_logs, cash_movements, cash_drawer_sessions,
 * order_payments). Those trigger SQL bodies use `SIGNAL SQLSTATE`
 * which is MySQL/MariaDB-specific. PHPUnit defaults to SQLite
 * (`:memory:`), so most CI runs cannot exercise the trigger payload —
 * a purely-Eloquent test would silently report green even if the
 * trigger SQL was deleted.
 *
 * This trait gives a single canonical way to opt a test into the
 * MySQL-only execution path without polluting the rest of the
 * fiscal suite. Tests that need the trigger semantics call
 * `requiresMysqlDriver()` (or `markTestSkippedIfNotMysql()`) at the
 * top of their test method or in `setUp()`. CI matrices that include
 * a MySQL job run them ; SQLite-only runners skip cleanly.
 *
 * Reusable from any test class — not coupled to z_reports.
 */
trait MysqlOnly
{
    /**
     * Skip the current test if the active DB connection is not
     * MySQL/MariaDB. Returns the resolved driver name for callers
     * that want to log it.
     */
    protected function requiresMysqlDriver(): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            $this->markTestSkipped(
                "MysqlOnly: this test requires MySQL/MariaDB to exercise SIGNAL-based triggers; current driver = {$driver}."
            );
        }

        return $driver;
    }

    /**
     * Alias kept for fluency at the call-site (`$this->markTestSkippedIfNotMysql();`).
     */
    protected function markTestSkippedIfNotMysql(): void
    {
        $this->requiresMysqlDriver();
    }
}
