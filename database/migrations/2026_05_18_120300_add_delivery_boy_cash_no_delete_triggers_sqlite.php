<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [V1.0.2 Sub-6.3 — 2026-05-18] delivery_boy_cash_movements + delivery_boy_cash_sessions
 * DELETE immutability — SQLite parity (NF525 backstop).
 *
 * Mirror de `2026_05_16_130000_add_cash_movements_delete_trigger_sqlite.php` :
 * la production MySQL doit séparer GRANT/REVOKE TRUNCATE au niveau deploy doc
 * (cf. CLAUDE.md §8). Ici on garantit l'invariant pour PHPUnit/SQLite memory.
 *
 * Edge cases :
 *   - Test setup avec RefreshDatabase + SQLite memory → trigger fires sur DELETE.
 *   - Production MySQL → no-op (driver guard) ; les triggers MySQL devront être
 *     ajoutés via une migration MySQL-only dans une wave 6b ultérieure ou par
 *     deploy doc.
 *   - Re-run safe : `DROP TRIGGER IF EXISTS` d'abord.
 *
 * Rollback drop les triggers SQLite uniquement — jamais MySQL.
 */
return new class extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'sqlite') {
            return;
        }

        $this->dropTriggers();

        if (Schema::hasTable('delivery_boy_cash_movements')) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER delivery_boy_cash_movements_no_delete
                BEFORE DELETE ON delivery_boy_cash_movements
                BEGIN
                    SELECT RAISE(ABORT, 'delivery_boy_cash_movements is immutable (NF525 / V1.0.2 Sub-6.3) - DELETE forbidden');
                END;
            SQL);
        }

        if (Schema::hasTable('delivery_boy_cash_sessions')) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER delivery_boy_cash_sessions_no_delete
                BEFORE DELETE ON delivery_boy_cash_sessions
                BEGIN
                    SELECT RAISE(ABORT, 'delivery_boy_cash_sessions is immutable (NF525 / V1.0.2 Sub-6.3) - DELETE forbidden');
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
        foreach ([
            'delivery_boy_cash_movements_no_delete',
            'delivery_boy_cash_sessions_no_delete',
        ] as $t) {
            try {
                DB::unprepared("DROP TRIGGER IF EXISTS {$t}");
            } catch (\Throwable $e) {
                // best-effort
            }
        }
    }
};
