<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->makeItemIdNullable();

        Schema::table('order_items', function (Blueprint $table): void {
            // A manual POS supplement is a real immutable sale line, but it has no
            // catalogue Item FK. Existing rows remain catalog lines without backfill.
            $table->string('line_type', 32)->default('catalog')->after('item_id');
            $table->string('manual_label', 80)->nullable()->after('line_type');
            $table->index(['branch_id', 'line_type'], 'order_items_branch_line_type_idx');
        });
    }

    public function down(): void
    {
        // Rollback is intentionally fail-loud BEFORE removing any column: converting
        // item_id back to NOT NULL must never erase an already fiscalised manual line.
        $manualRowsExist = \Illuminate\Support\Facades\DB::table('order_items')
            ->where('line_type', 'manual_supplement')
            ->orWhereNull('item_id')
            ->exists();
        if ($manualRowsExist) {
            throw new \RuntimeException('Cannot rollback manual supplement schema while fiscal manual rows exist.');
        }

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex('order_items_branch_line_type_idx');
            $table->dropColumn(['line_type', 'manual_label']);
        });

        if (Schema::hasColumn('order_items', 'item_id')) {
            $this->makeItemIdRequired();
        }
    }

    /**
     * Laravel 9 needs doctrine/dbal for Blueprint::change(), but FoodKing's
     * deployment and SQLite test runtime intentionally do not depend on it.
     * Use each database's native operation instead; this is a schema-only
     * migration and keeps the existing item FK intact.
     */
    private function makeItemIdNullable(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            $this->rebuildSqliteOrderItems(true);

            return;
        }

        if ($driver === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE order_items ALTER COLUMN item_id DROP NOT NULL');

            return;
        }

        \Illuminate\Support\Facades\DB::statement('ALTER TABLE order_items MODIFY item_id BIGINT UNSIGNED NULL');
    }

    private function makeItemIdRequired(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            $this->rebuildSqliteOrderItems(false);

            return;
        }

        if ($driver === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE order_items ALTER COLUMN item_id SET NOT NULL');

            return;
        }

        \Illuminate\Support\Facades\DB::statement('ALTER TABLE order_items MODIFY item_id BIGINT UNSIGNED NOT NULL');
    }

    /**
     * SQLite cannot alter a NOT NULL column in place. Rebuild from sqlite_master
     * so every later FoodKing column, FK, index and immutability trigger survives
     * without a duplicated, brittle table definition.
     */
    private function rebuildSqliteOrderItems(bool $nullable): void
    {
        $db = \Illuminate\Support\Facades\DB::connection();
        $tableSql = (string) ($db->selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'order_items'")->sql ?? '');
        if ($tableSql === '') {
            throw new \RuntimeException('SQLite order_items schema is unavailable.');
        }

        $replacement = $nullable ? '$1' : '$1 NOT NULL';
        $rebuiltSql = preg_replace('/("item_id"\s+integer)(?:\s+not\s+null)?/iu', $replacement, $tableSql, 1, $count);
        if ($count !== 1 || ! is_string($rebuiltSql)) {
            throw new \RuntimeException('SQLite order_items.item_id definition could not be migrated safely.');
        }
        $rebuiltSql = preg_replace('/CREATE\s+TABLE\s+"?order_items"?/iu', 'CREATE TABLE "order_items_manual_supplement_tmp"', $rebuiltSql, 1);

        $dependentSql = $db->select("SELECT sql FROM sqlite_master WHERE tbl_name = 'order_items' AND type IN ('index', 'trigger') AND sql IS NOT NULL");
        $columns = Schema::getColumnListing('order_items');
        $quotedColumns = implode(', ', array_map(static fn (string $column): string => '"'.$column.'"', $columns));

        $db->statement('PRAGMA foreign_keys = OFF');
        try {
            $db->unprepared($rebuiltSql);
            $db->statement("INSERT INTO \"order_items_manual_supplement_tmp\" ({$quotedColumns}) SELECT {$quotedColumns} FROM \"order_items\"");
            $db->statement('DROP TABLE "order_items"');
            $db->statement('ALTER TABLE "order_items_manual_supplement_tmp" RENAME TO "order_items"');
            foreach ($dependentSql as $dependent) {
                $db->unprepared((string) $dependent->sql);
            }
        } finally {
            $db->statement('PRAGMA foreign_keys = ON');
        }
    }
};
