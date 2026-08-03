<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [SEC MISSION-12 2026-07-31] `stock_outflows` (repas personnel / pertes) = trace comptable de tout ce
 * qui sort du stock HORS-VENTE. L'append-only ne vivait QUE dans le modèle Eloquent (StockOutflow::booted)
 * → un `DB::table('stock_outflows')->delete()/update()` en requête BRUTE le contournait : un caissier
 * pouvait effacer ses `staff_meal` couvrant un vol. On pose les MÊMES triggers DB que `stock_movements`
 * (F-6 P0) : BEFORE DELETE/UPDATE → SIGNAL 45000 (MySQL) / RAISE ABORT (SQLite parité PHPUnit).
 * Additif, idempotent (DROP IF EXISTS d'abord), 0 frozen §7, 0 logique métier.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_outflows')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $this->dropMysqlTriggers();

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_outflows_no_delete
                BEFORE DELETE ON stock_outflows
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'stock_outflows is append-only (Mission-12 trace integrity) - DELETE forbidden';
                END
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_outflows_no_update
                BEFORE UPDATE ON stock_outflows
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'stock_outflows is append-only (Mission-12 trace integrity) - UPDATE forbidden';
                END
            SQL);
        }

        if ($driver === 'sqlite') {
            $this->dropSqliteTriggers();

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_outflows_no_delete
                BEFORE DELETE ON stock_outflows
                BEGIN
                    SELECT RAISE(ABORT, 'stock_outflows is append-only (Mission-12 trace integrity) - DELETE forbidden');
                END;
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_outflows_no_update
                BEFORE UPDATE ON stock_outflows
                BEGIN
                    SELECT RAISE(ABORT, 'stock_outflows is append-only (Mission-12 trace integrity) - UPDATE forbidden');
                END;
            SQL);
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $this->dropMysqlTriggers();
        }
        if ($driver === 'sqlite') {
            $this->dropSqliteTriggers();
        }
    }

    private function dropMysqlTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_outflows_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS stock_outflows_no_update');
    }

    private function dropSqliteTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_outflows_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS stock_outflows_no_update');
    }
};
