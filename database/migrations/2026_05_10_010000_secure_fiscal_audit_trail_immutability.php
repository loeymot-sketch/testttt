<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [P0-FIX-4 NF525 / iter15 — 2026-05-10]
 *
 * Secure fiscal audit trail immutability for cash & payment tables.
 *
 * BEFORE (vulnerable):
 *   - cash_movements.cash_drawer_session_id  → cascadeOnDelete
 *   - order_payments.order_id                → cascadeOnDelete
 *   No DB-level DELETE guard on cash_movements / cash_drawer_sessions
 *   / order_payments.
 *
 *   Consequence: deleting a cash_drawer_session silently wiped its
 *   cash_movements; deleting an order silently wiped its
 *   order_payments. Both rows are mandatory NF525 fiscal evidence
 *   (6y retention) and must outlive any application-level delete.
 *
 * AFTER (this migration):
 *   1. Drop the two cascadeOnDelete FKs.
 *   2. Recreate them with restrictOnDelete() so the parent DELETE
 *      fails fast at the DB layer (cleaner than orphaning rows).
 *   3. Add BEFORE DELETE triggers on cash_movements,
 *      cash_drawer_sessions, and order_payments — same pattern as
 *      audit_logs (2026_04_22_000002) and z_reports
 *      (2026_05_09_160000). Trigger fires SQLSTATE 45000 so any
 *      raw DELETE / Eloquent delete / FK CASCADE coming from a
 *      future schema change is rejected.
 *
 * Driver-conditional: MySQL/MariaDB get FKs + triggers ; SQLite
 * (PHPUnit default) skips the trigger SQL silently — pure Eloquent
 * tests still pass on the FK migration alone. Same defensive pattern
 * as the z_reports trigger migration.
 *
 * Edge cases :
 *   - cash_movements has THREE possible parent links (drawer session,
 *     branch, order). Only the drawer-session FK was previously
 *     cascading; we replace just that one. branch_id and order_id
 *     remain index-only (no FK), preserving current behaviour.
 *   - order_payments belongs to a soft-deletable Order (iter6 Q2=B):
 *     soft-delete is fine (FK only fires on hard DELETE).
 *   - TRUNCATE bypasses MySQL triggers — mitigation = revoke
 *     TRUNCATE permission on the prod DB user (deploy doc, not
 *     migration scope). Same caveat documented in the z_reports
 *     trigger migration.
 *   - Existing rows are protected immediately on up() — trigger
 *     applies to ALL future DELETE attempts.
 *
 * Rollback (down()): restores cascadeOnDelete FKs + drops triggers.
 * Production should NEVER rollback (NF525 6y retention mandate).
 */
return new class extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // -----------------------------------------------------------
        // 1. cash_movements — drop cascade FK, recreate as RESTRICT.
        // -----------------------------------------------------------
        if (Schema::hasTable('cash_movements')) {
            $this->dropForeignIfExists('cash_movements', 'cash_movements_cash_drawer_session_id_foreign');

            // SQLite cannot ALTER FK in-place; tolerate failure quietly so
            // the test runner keeps the original FK definition (which is
            // already non-cascading in SQLite by default — FK enforcement
            // is OFF unless `PRAGMA foreign_keys = ON`).
            if ($driver === 'mysql' || $driver === 'mariadb') {
                Schema::table('cash_movements', function (Blueprint $table): void {
                    $table->foreign('cash_drawer_session_id')
                        ->references('id')
                        ->on('cash_drawer_sessions')
                        ->restrictOnDelete();
                });
            }
        }

        // -----------------------------------------------------------
        // 2. order_payments — drop cascade FK, recreate as RESTRICT.
        // -----------------------------------------------------------
        if (Schema::hasTable('order_payments')) {
            $this->dropForeignIfExists('order_payments', 'order_payments_order_id_foreign');

            if ($driver === 'mysql' || $driver === 'mariadb') {
                Schema::table('order_payments', function (Blueprint $table): void {
                    $table->foreign('order_id')
                        ->references('id')
                        ->on('orders')
                        ->restrictOnDelete();
                });
            }
        }

        // -----------------------------------------------------------
        // 3. DELETE triggers — MySQL/MariaDB only.
        // -----------------------------------------------------------
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        $this->dropTriggers();

        if (Schema::hasTable('cash_movements')) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER cash_movements_no_delete
                BEFORE DELETE ON cash_movements
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'cash_movements is immutable (NF525 / P0-FIX-4) — DELETE forbidden';
                END
            SQL);
        }

        if (Schema::hasTable('cash_drawer_sessions')) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER cash_drawer_sessions_no_delete
                BEFORE DELETE ON cash_drawer_sessions
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'cash_drawer_sessions is immutable (NF525 / P0-FIX-4) — DELETE forbidden';
                END
            SQL);
        }

        if (Schema::hasTable('order_payments')) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER order_payments_no_delete
                BEFORE DELETE ON order_payments
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'order_payments is immutable (NF525 / P0-FIX-4) — DELETE forbidden';
                END
            SQL);
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // 1. Drop triggers first (MySQL only).
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $this->dropTriggers();
        }

        // 2. Restore cascadeOnDelete FKs (best-effort; non-MySQL skips).
        if ($driver === 'mysql' || $driver === 'mariadb') {
            if (Schema::hasTable('cash_movements')) {
                $this->dropForeignIfExists('cash_movements', 'cash_movements_cash_drawer_session_id_foreign');
                Schema::table('cash_movements', function (Blueprint $table): void {
                    $table->foreign('cash_drawer_session_id')
                        ->references('id')
                        ->on('cash_drawer_sessions')
                        ->cascadeOnDelete();
                });
            }

            if (Schema::hasTable('order_payments')) {
                $this->dropForeignIfExists('order_payments', 'order_payments_order_id_foreign');
                Schema::table('order_payments', function (Blueprint $table): void {
                    $table->foreign('order_id')
                        ->references('id')
                        ->on('orders')
                        ->cascadeOnDelete();
                });
            }
        }
    }

    private function dropTriggers(): void
    {
        foreach ([
            'cash_movements_no_delete',
            'cash_drawer_sessions_no_delete',
            'order_payments_no_delete',
        ] as $trigger) {
            try {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            } catch (\Throwable $e) {
                // best-effort: migration must remain re-runnable in dev
            }
        }
    }

    private function dropForeignIfExists(string $table, string $foreignKey): void
    {
        try {
            Schema::table($table, function (Blueprint $tbl) use ($foreignKey): void {
                $tbl->dropForeign($foreignKey);
            });
        } catch (\Throwable $e) {
            // best-effort: FK may not exist (fresh schema, partial rollback, SQLite, ...)
        }
    }
};
