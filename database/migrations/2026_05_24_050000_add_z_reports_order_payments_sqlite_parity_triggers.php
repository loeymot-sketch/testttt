<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [GOAL-L2.5 SQLite/MySQL PARITY 2026-05-24] NF525 trigger parity backstop.
 *
 * CONTEXT
 * -------
 * Two NF525 DELETE-immutability triggers exist on MySQL/MariaDB but were never
 * mirrored on SQLite, leaving the PHPUnit test runner unable to catch raw-DELETE
 * bypass attempts at the DB layer:
 *
 *   1. `z_reports_no_delete`           (migration 2026_05_09_160000) — MySQL-only.
 *   2. `order_payments_no_delete`      (migration 2026_05_10_010000) — MySQL-only.
 *
 * Other NF525 tables already have full SQLite parity:
 *   - audit_logs                        (2026_04_22_000002) — both branches.
 *   - cash_movements                    (2026_05_16_130000) — SQLite parity.
 *   - cash_drawer_sessions              (2026_05_16_130000) — SQLite parity.
 *   - delivery_boy_cash_movements       (2026_05_18_120300) — SQLite parity.
 *   - delivery_boy_cash_sessions        (2026_05_18_120300) — SQLite parity.
 *   - stock_movements (DEL + UPD)       (2026_05_18_140000) — both branches.
 *   - order_items.composition_snapshot  (2026_05_24_040211) — both branches.
 *
 * This migration closes the remaining 2 gaps (`z_reports`, `order_payments`)
 * with `RAISE(ABORT, ...)` triggers under the SQLite driver guard, mirroring
 * the cash_movements pattern verbatim.
 *
 * PRODUCTION IMPACT
 * -----------------
 * Zero. MySQL/MariaDB driver branch returns early on up() — production schema
 * is unchanged. Existing MySQL triggers (z_reports_no_delete, order_payments_no_delete)
 * continue to enforce the invariant in production. This migration only adds the
 * SQLite-side parity so PHPUnit can exercise the same invariant against raw
 * `DB::table(...)->delete()` calls.
 *
 * WHY THIS MATTERS
 * ----------------
 * Without these triggers, a PHPUnit Feature test could call
 * `DB::table('z_reports')->where('id', $zId)->delete()` and silently succeed,
 * giving a false GREEN signal — production MySQL would reject the same query
 * with SQLSTATE 45000. The audit-chain integrity tests (chain_signature_smoke
 * + ZReportNoDeleteSentinel) need to assert the rejection happens; that
 * assertion requires the SQLite trigger to exist.
 *
 * EDGE CASES
 * ----------
 *   - MySQL prod: no-op (driver guard at top of up()).
 *   - SQLite test: trigger fires on every DELETE attempt; INSERT/UPDATE paths
 *     unaffected (z_reports has a legitimate state machine open → closed →
 *     archived; order_payments updates statuses for retry/clawback).
 *   - Re-run safe: `DROP TRIGGER IF EXISTS` first.
 *   - Tables may not yet exist at migration time on a partial schema (fresh
 *     install); `Schema::hasTable` guards both blocks.
 *
 * ROLLBACK
 * --------
 * down() is a hard refuse in production (NF525 immutability once installed
 * must NOT be reversible). Non-prod environments may drop for local iteration.
 */
return new class extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'sqlite') {
            return;
        }

        $this->dropTriggers();

        if (Schema::hasTable('z_reports')) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER z_reports_no_delete
                BEFORE DELETE ON z_reports
                BEGIN
                    SELECT RAISE(ABORT, 'z_reports is immutable post-close (NF525 / POS-9.4.6) — DELETE forbidden');
                END;
            SQL);
        }

        if (Schema::hasTable('order_payments')) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER order_payments_no_delete
                BEFORE DELETE ON order_payments
                BEGIN
                    SELECT RAISE(ABORT, 'order_payments is immutable (NF525 / P0-FIX-4) — DELETE forbidden');
                END;
            SQL);
        }
    }

    public function down(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'NF525: cannot drop z_reports / order_payments DELETE triggers in production (GOAL-L2.5)'
            );
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'sqlite') {
            return;
        }

        $this->dropTriggers();
    }

    private function dropTriggers(): void
    {
        foreach (['z_reports_no_delete', 'order_payments_no_delete'] as $t) {
            try {
                DB::unprepared("DROP TRIGGER IF EXISTS {$t}");
            } catch (\Throwable $e) {
                // best-effort
            }
        }
    }
};
