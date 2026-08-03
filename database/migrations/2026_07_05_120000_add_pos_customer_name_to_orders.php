<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [C2-CAISSE 2026-07-05] Owner : pouvoir saisir le NOM DU CLIENT sur une commande caisse
 * (emporter/sur place) — optionnel, mais imprimé sur le ticket (client + cuisine) pour
 * appeler la commande par le nom. Colonne nullable additive, rétro-compatible, non gelée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'pos_customer_name')) {
                $table->string('pos_customer_name', 60)->nullable()->after('pos_payment_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'pos_customer_name')) {
                $table->dropColumn('pos_customer_name');
            }
        });
    }
};
