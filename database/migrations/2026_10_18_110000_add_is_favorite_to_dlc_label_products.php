<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [CAYENNE HYGIÈNE 2026-10-18 · demande du propriétaire n°1] « Je marque les produits que j'utilise tout le temps et j'imprime ma liste
 * sans rien retaper » : un produit peut être mis en favori (partagé par toutes les tablettes du restaurant).
 * Additif : une colonne booléenne, faux par défaut ; aucune donnée existante touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dlc_label_products')) {
            return;
        }
        Schema::table('dlc_label_products', function (Blueprint $table) {
            if (! Schema::hasColumn('dlc_label_products', 'is_favorite')) $table->boolean('is_favorite')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('dlc_label_products') && Schema::hasColumn('dlc_label_products', 'is_favorite')) {
            Schema::table('dlc_label_products', fn (Blueprint $table) => $table->dropColumn('is_favorite'));
        }
    }
};
