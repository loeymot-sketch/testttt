<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [ETIQUETTES 2026-10-16 · revue HACCP, 2e tour] Trois règles de fiche produit :
 *
 *   · thawed_storage — conservation APRÈS décongélation, choisie produit par produit : une viande hachée décongelée se
 *                      garde à +2 °C au plus, un poisson entre 0 et +2 °C (arrêté du 21 décembre 2009, annexe I), un pain
 *                      décongelé à température ambiante. Jamais une valeur unique imposée à tous ;
 *   · needs_parent   — « fait à partir d'un produit déjà étiqueté » : l'impression exige le produit d'origine (sa DLC ne
 *                      repart pas à zéro) ou la mention explicite « produit neuf ». Un appui ne peut plus remettre
 *                      l'horloge à zéro par oubli ;
 *   · no_allergens   — « aucun des 14 allergènes » DÉCLARÉ, distinct de « non renseigné ».
 *
 * Et au journal : `anomaly` — étiquette imprimée HORS LIGNE que le serveur n'aurait pas acceptée telle quelle (fiche
 * modifiée entre-temps, heure incohérente…). Elle est journalisée QUAND MÊME (le papier existe, il est sur un bac) et
 * signalée « à vérifier », au lieu d'être rejetée en silence.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dlc_label_products')) {
            return;
        }

        Schema::table('dlc_label_products', function (Blueprint $table) {
            if (! Schema::hasColumn('dlc_label_products', 'thawed_storage')) $table->string('thawed_storage', 60)->nullable()->after('storage');
            if (! Schema::hasColumn('dlc_label_products', 'needs_parent')) $table->boolean('needs_parent')->default(false)->after('ask_original_dlc');
            if (! Schema::hasColumn('dlc_label_products', 'no_allergens')) $table->boolean('no_allergens')->default(false)->after('allergens');
        });

        if (Schema::hasTable('label_print_logs')) {
            Schema::table('label_print_logs', function (Blueprint $table) {
                if (! Schema::hasColumn('label_print_logs', 'anomaly')) $table->boolean('anomaly')->default(false)->after('offline');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dlc_label_products')) {
            return;
        }

        Schema::table('dlc_label_products', function (Blueprint $table) {
            foreach (['thawed_storage', 'needs_parent', 'no_allergens'] as $c) {
                if (Schema::hasColumn('dlc_label_products', $c)) $table->dropColumn($c);
            }
        });
        if (Schema::hasTable('label_print_logs') && Schema::hasColumn('label_print_logs', 'anomaly')) {
            Schema::table('label_print_logs', fn (Blueprint $table) => $table->dropColumn('anomaly'));
        }
    }
};
