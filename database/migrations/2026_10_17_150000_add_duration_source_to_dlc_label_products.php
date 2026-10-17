<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · méga-fichier P0.6] D'où vient chaque durée de conservation ?
 *
 *   · dlc_label_products.duration_source — texte libre court : « Valeur de départ Cayenne » (catalogue installé, À VALIDER),
 *                                          « GBPH restauration », « Plan de maîtrise sanitaire », « Fabricant »… Chaque ligne
 *                                          du catalogue dit d'où sort sa durée ; la validation par le responsable
 *                                          (`durations_checked_at / _by`, déjà présentes) dit qui l'a confirmée et quand.
 *
 * Additif : une colonne nullable, aucune donnée existante touchée (NULL = « saisie par le restaurant »).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dlc_label_products')) {
            return;
        }

        Schema::table('dlc_label_products', function (Blueprint $table) {
            if (! Schema::hasColumn('dlc_label_products', 'duration_source')) $table->string('duration_source', 80)->nullable()->after('durations_checked_by');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('dlc_label_products') && Schema::hasColumn('dlc_label_products', 'duration_source')) {
            Schema::table('dlc_label_products', fn (Blueprint $table) => $table->dropColumn('duration_source'));
        }
    }
};
