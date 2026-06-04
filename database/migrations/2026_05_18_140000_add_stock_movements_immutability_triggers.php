<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [Foundation Audit F-6 P0 / 2026-05-18]
 *
 * stock_movements DB-level immutability — close the raw-query bypass.
 *
 * CONTEXT
 * -------
 * StockMovement model already enforces append-only at the Eloquent layer
 * via `updating()` + `deleting()` callbacks throwing `LogicException`
 * (cf. app/Models/StockMovement.php:46-55, iter12 P0). But raw queries
 * via `DB::table('stock_movements')->...->delete()` or `->update()`
 * silently bypass Eloquent guards. `CleanupTestFixturesCommand` already
 * uses raw `DB::table('stock_movements')` for test cleanup — the pattern
 * exists, so the bypass is one line away from production.
 *
 * NF525 stock_movements is NOT a fiscal table (no HMAC chain like
 * audit_logs / z_reports), but it IS audit evidence for stock decisions
 * (rupture, refund) and tenant-isolated. The same DB-level trigger
 * pattern as cash_movements / order_payments / audit_logs / z_reports
 * gives consistent NF525-grade defence in depth.
 *
 * BEFORE (vulnerable to raw queries):
 *   - stock_movements.stock_level_id  → cascadeOnDelete
 *   - stock_movements.branch_id        → cascadeOnDelete
 *   No DB-level DELETE/UPDATE guard. Raw DB::table delete/update succeed.
 *
 * AFTER (this migration):
 *   1. Drop the two cascadeOnDelete FKs.
 *   2. Recreate them with restrictOnDelete() so the parent DELETE fails
 *      at the DB layer (cleaner than silently wiping movements when a
 *      stock_level or branch is hard-deleted).
 *   3. Add MySQL BEFORE DELETE + BEFORE UPDATE triggers (SQLSTATE 45000).
 *   4. Add SQLite BEFORE DELETE + BEFORE UPDATE triggers (RAISE ABORT).
 *
 * Driver-conditional: MySQL/MariaDB get FK + triggers ; SQLite (PHPUnit
 * default) gets triggers only (FK enforcement is OFF unless PRAGMA
 * foreign_keys=ON). Same pattern as cash_movements + delivery_boy_cash
 * + z_reports migrations.
 *
 * EDGE CASES
 * ----------
 *   - CleanupTestFixturesCommand uses raw DB::table('stock_movements')
 *     for test fixture cleanup. After this migration, that command will
 *     fail by design — fixture cleanup must go through a TRUNCATE under
 *     `DB::unprepared('SET foreign_key_checks=0')` block, OR drop+migrate.
 *     Test-only impact, prod safe.
 *   - TRUNCATE TABLE stock_movements bypasses MySQL triggers — mitigation
 *     = REVOKE TRUNCATE on prod DB user (deploy doc scope, not migration).
 *     Same caveat documented in 2026_05_10_010000_secure_fiscal_audit_trail.
 *   - Existing rows protected immediately on up() — trigger fires on ALL
 *     future DELETE/UPDATE attempts.
 *   - Re-run safe: DROP TRIGGER IF EXISTS first.
 *
 * ROLLBACK (down): restore cascadeOnDelete FKs + drop triggers.
 * Production should normally NEVER rollback this migration.
 */
return new class extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! Schema::hasTable('stock_movements')) {
            return;
        }

        // -----------------------------------------------------------
        // 1. stock_level_id FK — drop cascade, recreate as RESTRICT.
        // -----------------------------------------------------------
        $this->dropForeignIfExists('stock_movements', 'stock_movements_stock_level_id_foreign');

        if ($driver === 'mysql' || $driver === 'mariadb') {
            Schema::table('stock_movements', function (Blueprint $table): void {
                $table->foreign('stock_level_id')
                    ->references('id')
                    ->on('stock_levels')
                    ->restrictOnDelete();
            });
        }

        // -----------------------------------------------------------
        // 2. branch_id FK — drop cascade, recreate as RESTRICT.
        // -----------------------------------------------------------
        $this->dropForeignIfExists('stock_movements', 'stock_movements_branch_id_foreign');

        if ($driver === 'mysql' || $driver === 'mariadb') {
            Schema::table('stock_movements', function (Blueprint $table): void {
                $table->foreign('branch_id')
                    ->references('id')
                    ->on('branches')
                    ->restrictOnDelete();
            });
        }

        // -----------------------------------------------------------
        // 3. Triggers — MySQL/MariaDB.
        // -----------------------------------------------------------
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $this->dropMysqlTriggers();

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_movements_no_delete
                BEFORE DELETE ON stock_movements
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'stock_movements is append-only (Foundation F-6 P0 / NF525-aligned) - DELETE forbidden';
                END
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_movements_no_update
                BEFORE UPDATE ON stock_movements
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'stock_movements is append-only (Foundation F-6 P0 / NF525-aligned) - UPDATE forbidden';
                END
            SQL);
        }

        // -----------------------------------------------------------
        // 4. Triggers — SQLite parity for PHPUnit.
        // -----------------------------------------------------------
        if ($driver === 'sqlite') {
            $this->dropSqliteTriggers();

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_movements_no_delete
                BEFORE DELETE ON stock_movements
                BEGIN
                    SELECT RAISE(ABORT, 'stock_movements is append-only (Foundation F-6 P0 / NF525-aligned) - DELETE forbidden');
                END;
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_movements_no_update
                BEFORE UPDATE ON stock_movements
                BEGIN
                    SELECT RAISE(ABORT, 'stock_movements is append-only (Foundation F-6 P0 / NF525-aligned) - UPDATE forbidden');
                END;
            SQL);
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! Schema::hasTable('stock_movements')) {
            return;
        }

        // Drop triggers first.
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $this->dropMysqlTriggers();
        }
        if ($driver === 'sqlite') {
            $this->dropSqliteTriggers();
        }

        // Restore cascadeOnDelete FKs (MySQL only — SQLite was no-op on up()).
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $this->dropForeignIfExists('stock_movements', 'stock_movements_stock_level_id_foreign');
            $this->dropForeignIfExists('stock_movements', 'stock_movements_branch_id_foreign');

            Schema::table('stock_movements', function (Blueprint $table): void {
                $table->foreign('stock_level_id')
                    ->references('id')
                    ->on('stock_levels')
                    ->cascadeOnDelete();
                $table->foreign('branch_id')
                    ->references('id')
                    ->on('branches')
                    ->cascadeOnDelete();
            });
        }
    }

    private function dropMysqlTriggers(): void
    {
        foreach (['stock_movements_no_delete', 'stock_movements_no_update'] as $t) {
            try {
                DB::unprepared("DROP TRIGGER IF EXISTS {$t}");
            } catch (\Throwable $e) {
                // best-effort, idempotent
            }
        }
    }

    private function dropSqliteTriggers(): void
    {
        foreach (['stock_movements_no_delete', 'stock_movements_no_update'] as $t) {
            try {
                DB::unprepared("DROP TRIGGER IF EXISTS {$t}");
            } catch (\Throwable $e) {
                // best-effort, idempotent
            }
        }
    }

    private function dropForeignIfExists(string $table, string $foreignKey): void
    {
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($foreignKey) {
                $blueprint->dropForeign($foreignKey);
            });
        } catch (\Throwable $e) {
            // FK may not exist (SQLite or already dropped) — tolerate.
        }
    }
};
