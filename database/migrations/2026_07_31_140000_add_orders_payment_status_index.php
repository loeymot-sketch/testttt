<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [PERF N+1/INDEX 2026-07-31] Index composite pour la file d'encaissement.
 *
 * La route counter-collect/pending (routes/api.php) filtre
 *   WHERE payment_status = PENDING_COUNTER AND branch_id = ? ... ORDER BY created_at
 * et est pollée toutes les 8 s en mode dégradé. Les index existants sur `orders`
 * (branch_id+status, user_id, order_datetime, status — cf. add_performance_indexes)
 * ne couvrent NI `payment_status` NI un tri `created_at` → scan + filesort. Sur une
 * table qui grossit 6 ans (rétention NF525), c'est un scan croissant. Ce composite
 * sert le prédicat sélectif (payment_status) + le scope branche + le tri FIFO.
 *
 * Idempotent (miroir du pattern add_performance_indexes). DATA/DDL only, 0 logique,
 * 0 impact NF525 (aucune colonne fiscale touchée, aucun prix/séquence/chaîne).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! $this->indexExists('orders', 'idx_orders_paystatus_branch_created')) {
                $table->index(['payment_status', 'branch_id', 'created_at'], 'idx_orders_paystatus_branch_created');
            }
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if ($this->indexExists('orders', 'idx_orders_paystatus_branch_created')) {
                try {
                    $table->dropIndex('idx_orders_paystatus_branch_created');
                } catch (\Illuminate\Database\QueryException $e) {
                    if (! str_contains($e->getMessage(), 'needed in a foreign key constraint')) {
                        throw $e;
                    }
                }
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'sqlite') {
                $indexes = DB::select("PRAGMA index_list('" . str_replace("'", "''", $table) . "')");

                return collect($indexes)->pluck('name')->contains($indexName);
            }

            $indexes = DB::select('SHOW INDEX FROM `' . str_replace('`', '``', $table) . '`');

            return collect($indexes)->pluck('Key_name')->contains($indexName);
        } catch (\Exception $e) {
            return false;
        }
    }
};
