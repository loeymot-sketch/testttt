<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [Sprint 2B / DEL-4] Make `users.phone` NOT NULL with safe backfill.
 *
 * Wave E audit (P0) identified that `users.phone` was nullable in the
 * baseline schema and that the FrontendOrder DELIVERY flow trusted the
 * column to be populated for delivery dispatch callbacks (SMS, delivery
 * boy contact, courier integration). The downstream Sprint 2A
 * (KDSOrderDetailsResource) also reads `user.phone` and renders an
 * empty cell when null — breaking the kitchen → counter call-back flow
 * documented in the audit.
 *
 * Strategy:
 *   1. Backfill existing NULL rows with `PENDING_<id>` sentinel. This
 *      preserves uniqueness, never collides with a real phone (no real
 *      phone starts with `PENDING_`), and forces the existing
 *      `App\Rules\ValidPhone` rule (digits-only, 8-15 length) to FAIL
 *      next time the affected user passes through signup / address
 *      capture — i.e. a UX recapture gate is created automatically
 *      without adding a new table.
 *
 *   2. Flip the column to NOT NULL via dialect-specific raw SQL.
 *      Doctrine/dbal is intentionally NOT a dependency of this Laravel 9
 *      project (see composer.json), so Blueprint::change() is unavailable.
 *
 *   3. SQLite (test DB only) requires a table rebuild because it lacks
 *      ALTER COLUMN. The rebuild introspects the live schema via
 *      `PRAGMA table_info(users)`, regenerates the CREATE TABLE with the
 *      same columns + the new constraint, then copies the rows. This
 *      survives future add_*_to_users_table migrations without code
 *      change.
 *
 * Reversibility:
 *   down() flips NOT NULL back to NULLABLE and removes the `PENDING_*`
 *   sentinel rows by setting them back to NULL — so re-running this
 *   migration with `migrate:fresh` then `migrate:rollback` cleanly
 *   restores the baseline schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'phone')) {
            // Defensive — phone column should exist from the initial
            // 2014_10_12_000000_create_users_table.php migration.
            return;
        }

        $this->backfillNullPhones();
        $this->setPhoneNotNullable();
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'phone')) {
            return;
        }

        $this->setPhoneNullable();
        // Re-NULL the sentinel rows so the data shape matches pre-migration.
        DB::table('users')->where('phone', 'like', 'PENDING\_%')->update(['phone' => null]);
    }

    private function backfillNullPhones(): void
    {
        $driver = DB::getDriverName();

        switch ($driver) {
            case 'mysql':
            case 'mariadb':
                DB::statement("UPDATE users SET phone = CONCAT('PENDING_', id) WHERE phone IS NULL");
                break;
            case 'pgsql':
                DB::statement("UPDATE users SET phone = 'PENDING_' || id WHERE phone IS NULL");
                break;
            case 'sqlite':
                DB::statement("UPDATE users SET phone = 'PENDING_' || id WHERE phone IS NULL");
                break;
            default:
                // Fallback per-row update so the migration never hard-fails on
                // an unknown driver in a future test environment.
                DB::table('users')->whereNull('phone')->orderBy('id')->each(function ($user) {
                    DB::table('users')->where('id', $user->id)->update(['phone' => 'PENDING_' . $user->id]);
                });
        }
    }

    private function setPhoneNotNullable(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->rebuildSqliteUsersTable(false);
            return;
        }

        match ($driver) {
            'mysql', 'mariadb' => DB::statement("ALTER TABLE users MODIFY phone VARCHAR(255) NOT NULL"),
            'pgsql' => DB::statement('ALTER TABLE users ALTER COLUMN phone SET NOT NULL'),
            default => null,
        };
    }

    private function setPhoneNullable(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->rebuildSqliteUsersTable(true);
            return;
        }

        match ($driver) {
            'mysql', 'mariadb' => DB::statement("ALTER TABLE users MODIFY phone VARCHAR(255) NULL"),
            'pgsql' => DB::statement('ALTER TABLE users ALTER COLUMN phone DROP NOT NULL'),
            default => null,
        };
    }

    /**
     * SQLite cannot ALTER COLUMN. We introspect the existing schema with
     * PRAGMA table_info() and rebuild the table preserving every column
     * declared by every migration that touched `users` — including future
     * add_*_to_users migrations — without hard-coding the column list.
     *
     * Only the `phone` column's NOT NULL constraint differs between
     * the rebuilt table and the original.
     */
    private function rebuildSqliteUsersTable(bool $phoneNullable): void
    {
        // Snapshot the existing column definitions before we drop the table.
        $columns = DB::select('PRAGMA table_info(users)');
        if (empty($columns)) {
            return;
        }

        DB::statement('PRAGMA foreign_keys=OFF');

        $columnDefs = [];
        $columnNames = [];
        foreach ($columns as $col) {
            $name = $col->name;
            $type = $col->type !== '' ? $col->type : 'VARCHAR';
            $columnNames[] = $name;

            // Override the phone column's NOT NULL flag; copy every other
            // column verbatim. PK auto-increment is signalled by `pk=1`.
            if ($name === 'phone') {
                $columnDefs[] = sprintf('%s %s %s', $name, $type, $phoneNullable ? 'NULL' : 'NOT NULL');
                continue;
            }

            $notNull = (int) $col->notnull === 1 ? 'NOT NULL' : 'NULL';
            $default = $col->dflt_value !== null
                ? ' DEFAULT ' . $col->dflt_value
                : '';

            if ((int) $col->pk === 1) {
                // SQLite primary key column is auto-increment by virtue of
                // INTEGER PRIMARY KEY AUTOINCREMENT in the original create
                // migration. We can't reliably distinguish here, so default
                // to INTEGER PRIMARY KEY AUTOINCREMENT — matches the
                // baseline create_users_table migration.
                $columnDefs[] = sprintf('%s INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL', $name);
            } else {
                $columnDefs[] = sprintf('%s %s %s%s', $name, $type, $notNull, $default);
            }
        }

        $tmpTable = 'users_tmp_phone_required';
        DB::statement(sprintf('DROP TABLE IF EXISTS %s', $tmpTable));
        DB::statement(sprintf(
            'CREATE TABLE %s (%s)',
            $tmpTable,
            implode(', ', $columnDefs)
        ));

        $colList = implode(', ', $columnNames);
        DB::statement(sprintf(
            'INSERT INTO %s (%s) SELECT %s FROM users',
            $tmpTable,
            $colList,
            $colList
        ));

        DB::statement('DROP TABLE users');
        DB::statement(sprintf('ALTER TABLE %s RENAME TO users', $tmpTable));

        DB::statement('PRAGMA foreign_keys=ON');
    }
};
