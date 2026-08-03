<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [C4-CAISSE-TELEPHONE 2026-07-07] Owner : mode « Commande téléphone » à la caisse.
 * Le caissier prend une commande par téléphone : elle est enregistrée + envoyée en cuisine
 * à l'avance mais N'EST PAS encaissée maintenant (paiement différé au comptoir à l'arrivée
 * du client). On note le TÉLÉPHONE du client (le nom vit déjà dans pos_customer_name,
 * C2-CAISSE) pour rappeler/reconnaître la commande. Colonne nullable additive,
 * rétro-compatible, non gelée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'pos_customer_phone')) {
                $table->string('pos_customer_phone', 30)->nullable()->after('pos_customer_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'pos_customer_phone')) {
                $table->dropColumn('pos_customer_phone');
            }
        });
    }
};
