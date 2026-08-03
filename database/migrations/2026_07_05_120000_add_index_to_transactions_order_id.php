<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [SELF-AUDIT R4 P2 2026-07-05] transactions.order_id n'avait AUCUN index (create_transactions_table:18)
 * → full-table scan à chaque `Transaction::where('order_id', ...)` : write-path paiement (payment/cashBack
 * existing-check), relation Order::transaction() hasOne, historique admin, file d'encaissement. Cliff de
 * perf qui s'aggrave avec le volume. On ajoute l'index (additif, non destructif, aucune donnée touchée).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'order_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->index('order_id', 'transactions_order_id_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropIndex('transactions_order_id_index');
            });
        }
    }
};
