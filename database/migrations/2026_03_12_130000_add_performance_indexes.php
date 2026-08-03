<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : Ajout d'index de performance pour optimisation requêtes
 * [PERF-02] Index sur tables fréquemment interrogées (orders, items, users)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        // Index sur orders (table la plus critique pour les requêtes filtrées)
        Schema::table('orders', function (Blueprint $table) {
            if (!$this->indexExists('orders', 'idx_orders_branch_status')) {
                $table->index(['branch_id', 'status'], 'idx_orders_branch_status');
            }
            if (!$this->indexExists('orders', 'idx_orders_user_id')) {
                $table->index('user_id', 'idx_orders_user_id');
            }
            if (!$this->indexExists('orders', 'idx_orders_datetime')) {
                $table->index('order_datetime', 'idx_orders_datetime');
            }
            if (!$this->indexExists('orders', 'idx_orders_status')) {
                $table->index('status', 'idx_orders_status');
            }
        });

        // Index sur items (pour les requêtes POS)
        Schema::table('items', function (Blueprint $table) {
            if (!$this->indexExists('items', 'idx_items_status_category')) {
                $table->index(['status', 'item_category_id'], 'idx_items_status_category');
            }
            if (!$this->indexExists('items', 'idx_items_id_price')) {
                $table->index(['id', 'price'], 'idx_items_id_price');
            }
        });

        // Index sur users (pour les lookups auth et soft deletes)
        Schema::table('users', function (Blueprint $table) {
            if (!$this->indexExists('users', 'idx_users_deleted_at')) {
                $table->index('deleted_at', 'idx_users_deleted_at');
            }
            if (!$this->indexExists('users', 'idx_users_email')) {
                $table->index('email', 'idx_users_email');
            }
        });

        // Index sur order_items (pour les jointures)
        Schema::table('order_items', function (Blueprint $table) {
            if (!$this->indexExists('order_items', 'idx_order_items_order_id')) {
                $table->index('order_id', 'idx_order_items_order_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $this->dropIndexIfExists($table, 'orders', 'idx_orders_branch_status');
            $this->dropIndexIfExists($table, 'orders', 'idx_orders_user_id');
            $this->dropIndexIfExists($table, 'orders', 'idx_orders_datetime');
            $this->dropIndexIfExists($table, 'orders', 'idx_orders_status');
        });

        Schema::table('items', function (Blueprint $table) {
            $this->dropIndexIfExists($table, 'items', 'idx_items_status_category');
            $this->dropIndexIfExists($table, 'items', 'idx_items_id_price');
        });

        Schema::table('users', function (Blueprint $table) {
            $this->dropIndexIfExists($table, 'users', 'idx_users_deleted_at');
            $this->dropIndexIfExists($table, 'users', 'idx_users_email');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $this->dropIndexIfExists($table, 'order_items', 'idx_order_items_order_id');
        });
    }

    /**
     * Vérifier si un index existe sur une table
     */
    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'sqlite') {
                $indexes = \Illuminate\Support\Facades\DB::select("PRAGMA index_list('" . str_replace("'", "''", $table) . "')");

                return collect($indexes)->pluck('name')->contains($indexName);
            }

            $indexes = \Illuminate\Support\Facades\DB::select('SHOW INDEX FROM `' . str_replace('`', '``', $table) . '`');

            return collect($indexes)->pluck('Key_name')->contains($indexName);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function dropIndexIfExists(Blueprint $table, string $tableName, string $indexName): void
    {
        if (! $this->indexExists($tableName, $indexName)) {
            return;
        }

        try {
            $table->dropIndex($indexName);
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'needed in a foreign key constraint')) {
                return;
            }

            throw $e;
        }
    }
};
