<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [iter15-P0-09] UNIQUE partial index on cash_drawer_sessions.
 *
 * Final-tier defense for the TOCTOU race in `CashDrawerService::openSession()`.
 *
 * Layer-1: `Cache::lock('cash_drawer_open_b{branch}_u{user}')` serialises at
 *          the application tier.
 * Layer-2: `DB::transaction()` + `lockForUpdate()` blocks concurrent SELECT
 *          probes inside the same DB connection.
 * Layer-3: UNIQUE partial index `(branch_id, opened_by_user_id) WHERE
 *          status='open'` — the storage-tier guarantee that survives even
 *          if the lock or transaction is bypassed (multi-pod deploy, manual
 *          INSERT, lost cache backend).
 *
 * Driver matrix:
 *   - MySQL 8.0.13+ supports functional indexes but NOT real partial
 *     indexes. Workaround: use a generated virtual column that materialises
 *     `opened_by_user_id` only when status='open', then UNIQUE(branch_id, vcol).
 *     -> Closed sessions get NULL in the vcol → MySQL skips them in UNIQUE.
 *   - PostgreSQL: native `CREATE UNIQUE INDEX … WHERE status='open'`.
 *   - SQLite (testing): also supports partial indexes natively. We add it
 *     under SQLite too so concurrency sentinels can verify the invariant.
 *
 * Idempotent: checked Schema::hasTable + does nothing if already applied.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('cash_drawer_sessions')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite supports CREATE UNIQUE INDEX … WHERE … natively.
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uk_branch_user_open '
                . 'ON cash_drawer_sessions (branch_id, opened_by_user_id) '
                . "WHERE status = 'open'");
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uk_branch_user_open '
                . 'ON cash_drawer_sessions (branch_id, opened_by_user_id) '
                . "WHERE status = 'open'");
            return;
        }

        if ($driver === 'mysql') {
            // MySQL has no partial indexes. Use a STORED generated column that
            // is NULL when status != 'open', then UNIQUE(branch_id, vcol).
            // Idempotency: skip if column already present.
            $hasColumn = Schema::hasColumn('cash_drawer_sessions', 'open_user_lock');
            if (! $hasColumn) {
                DB::statement(
                    "ALTER TABLE cash_drawer_sessions "
                    . "ADD COLUMN open_user_lock BIGINT UNSIGNED AS "
                    . "(CASE WHEN status = 'open' THEN opened_by_user_id ELSE NULL END) STORED"
                );
                DB::statement(
                    "ALTER TABLE cash_drawer_sessions "
                    . "ADD UNIQUE INDEX uk_branch_user_open (branch_id, open_user_lock)"
                );
            }
            return;
        }

        // Other drivers: best-effort no-op.
    }

    public function down(): void
    {
        if (! Schema::hasTable('cash_drawer_sessions')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS uk_branch_user_open');
            return;
        }

        if ($driver === 'mysql') {
            // Drop in reverse order: UNIQUE index then generated column.
            try {
                DB::statement('ALTER TABLE cash_drawer_sessions DROP INDEX uk_branch_user_open');
            } catch (\Throwable $e) {
                // best-effort
            }
            if (Schema::hasColumn('cash_drawer_sessions', 'open_user_lock')) {
                DB::statement('ALTER TABLE cash_drawer_sessions DROP COLUMN open_user_lock');
            }
        }
    }
};
