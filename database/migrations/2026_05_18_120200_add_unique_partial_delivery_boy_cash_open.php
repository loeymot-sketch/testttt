<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [V1.0.2 Sub-6.3 — 2026-05-18] UNIQUE partial index on delivery_boy_cash_sessions.
 *
 * Final-tier defense for the TOCTOU race in `DeliveryBoyCashSessionService::openSession()` :
 * jamais 2 sessions OPEN pour la même branch + livreur (Planner H plan §9 Risk R4).
 *
 * Layer-1 : `Cache::lock('delivery_boy_cash_open_b{branch}_dbu{livreur}')` serialises
 *           at the application tier.
 * Layer-2 : `DB::transaction()` + `lockForUpdate()` blocks concurrent SELECT probes
 *           inside the same DB connection.
 * Layer-3 : UNIQUE partial index `(branch_id, delivery_boy_id) WHERE status='open'` —
 *           the storage-tier guarantee that survives even if Cache::lock backend
 *           is down or the transaction is bypassed (multi-pod deploy).
 *
 * Mirror of iter15-P0-09 `2026_05_10_020000_add_unique_partial_cash_drawer_open.php` —
 * driver matrix identique :
 *   - MySQL 8.0.13+ : generated stored column → UNIQUE(branch_id, vcol). Closed sessions
 *     get NULL → MySQL skips them in UNIQUE.
 *   - PostgreSQL    : native `CREATE UNIQUE INDEX … WHERE status='open'`.
 *   - SQLite (tests) : also supports partial indexes natively.
 *
 * Idempotent : checked Schema::hasTable + Schema::hasColumn before applying.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('delivery_boy_cash_sessions')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uk_branch_livreur_open '
                .'ON delivery_boy_cash_sessions (branch_id, delivery_boy_id) '
                ."WHERE status = 'open'");
            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // MySQL has no partial indexes. Use a STORED generated column that
            // is NULL when status != 'open', then UNIQUE(branch_id, vcol).
            // Idempotency : skip if column already present.
            $hasColumn = Schema::hasColumn('delivery_boy_cash_sessions', 'open_livreur_lock');
            if (! $hasColumn) {
                DB::statement(
                    'ALTER TABLE delivery_boy_cash_sessions '
                    .'ADD COLUMN open_livreur_lock BIGINT UNSIGNED AS '
                    ."(CASE WHEN status = 'open' THEN delivery_boy_id ELSE NULL END) STORED"
                );
                DB::statement(
                    'ALTER TABLE delivery_boy_cash_sessions '
                    .'ADD UNIQUE INDEX uk_branch_livreur_open (branch_id, open_livreur_lock)'
                );
            }
            return;
        }

        // Other drivers : best-effort no-op.
    }

    public function down(): void
    {
        if (! Schema::hasTable('delivery_boy_cash_sessions')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS uk_branch_livreur_open');
            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // Drop in reverse order : UNIQUE index then generated column.
            try {
                DB::statement('ALTER TABLE delivery_boy_cash_sessions DROP INDEX uk_branch_livreur_open');
            } catch (\Throwable $e) {
                // best-effort
            }
            if (Schema::hasColumn('delivery_boy_cash_sessions', 'open_livreur_lock')) {
                DB::statement('ALTER TABLE delivery_boy_cash_sessions DROP COLUMN open_livreur_lock');
            }
        }
    }
};
