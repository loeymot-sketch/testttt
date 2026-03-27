<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [AUDIT-P2-D] Unique (user_id, order_id, type) sur loyalty_transactions.
 * [FIX-CI] Doit s'exécuter APRÈS create_loyalty_transactions_table (2026_03_26_075918).
 * L'ancienne date 2026_03_24 faisait échouer RefreshDatabase (table absente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('loyalty_transactions')) {
            return;
        }

        $this->dropIndexIfExists('loyalty_transactions', 'loyalty_transactions_order_type_unique');
        $this->dropIndexIfExists('loyalty_transactions', 'loyalty_transactions_user_order_type_unique');

        if ($this->indexExists('loyalty_transactions', 'loyalty_transactions_user_order_type_unique')) {
            return;
        }

        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->unique(['user_id', 'order_id', 'type'], 'loyalty_transactions_user_order_type_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('loyalty_transactions')) {
            return;
        }

        $this->dropIndexIfExists('loyalty_transactions', 'loyalty_transactions_user_order_type_unique');
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select(
                'SELECT 1 FROM sqlite_master WHERE type = ? AND name = ? LIMIT 1',
                ['index', $indexName]
            );

            return count($rows) > 0;
        }

        $database = Schema::getConnection()->getDatabaseName();
        $result = DB::selectOne(
            'SELECT COUNT(*) as c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $indexName]
        );

        return $result && (int) $result->c > 0;
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (!Schema::hasTable($table) || !$this->indexExists($table, $indexName)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            try {
                DB::statement('DROP INDEX IF EXISTS "'.str_replace('"', '""', $indexName).'"');
            } catch (\Throwable) {
                // ignore
            }

            return;
        }

        try {
            DB::statement('ALTER TABLE `'.str_replace('`', '``', $table).'` DROP INDEX `'.str_replace('`', '``', $indexName).'`');
        } catch (\Throwable) {
            // ignore — idempotent
        }
    }
};
