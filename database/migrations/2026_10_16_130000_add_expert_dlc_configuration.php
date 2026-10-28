<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [ETIQUETTES 2026-10-16 · owner « comme les concurrents, très configuré selon chaque produit »] Étiquettes d'hygiène
 * au niveau des logiciels du marché (Octopus HACCP, ePack, Traqfood…) et du Plan de Maîtrise Sanitaire :
 *
 * Par PRODUIT :
 *   · category        — famille (légumes, sauces, viandes…) : filtres et sélection « toute la famille » ;
 *   · actions         — plusieurs ÉVÉNEMENTS possibles, chacun avec SA durée : ouverture (entame), décongélation,
 *                       préparation, cuisson, mise sous vide. [{key, value, unit}] ; le premier est l'action par défaut ;
 *   · allergens       — codes des 14 allergènes réglementaires, imprimés sur l'étiquette ;
 *   · note            — mention libre (« Fait maison », « Bac inox n°3 »…) ;
 *   · ask_original_dlc — demander la DLC du FABRICANT à l'impression : la DLC secondaire ne peut jamais la dépasser
 *                       (règle du guide PMS « gestion des entames ») ;
 *   · print_operator  — imprimer le prénom de la personne ;
 *   · default_copies  — nombre d'étiquettes proposé.
 *
 * Par IMPRESSION (journal) : l'action, un numéro de LOT (lien avec l'étiquette d'origine), la DLC d'origine saisie et
 * le fait que la DLC imprimée a été LIMITÉE par elle.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dlc_label_products')) {
            Schema::table('dlc_label_products', function (Blueprint $table) {
                if (! Schema::hasColumn('dlc_label_products', 'category')) $table->string('category', 60)->nullable()->after('name');
                if (! Schema::hasColumn('dlc_label_products', 'actions')) $table->json('actions')->nullable()->after('shelf_life_unit');
                if (! Schema::hasColumn('dlc_label_products', 'allergens')) $table->json('allergens')->nullable()->after('actions');
                if (! Schema::hasColumn('dlc_label_products', 'note')) $table->string('note', 80)->nullable()->after('storage');
                if (! Schema::hasColumn('dlc_label_products', 'ask_original_dlc')) $table->boolean('ask_original_dlc')->default(false)->after('note');
                if (! Schema::hasColumn('dlc_label_products', 'print_operator')) $table->boolean('print_operator')->default(true)->after('ask_original_dlc');
                if (! Schema::hasColumn('dlc_label_products', 'default_copies')) $table->unsignedTinyInteger('default_copies')->default(1)->after('print_operator');
            });
        }

        if (Schema::hasTable('label_print_logs')) {
            Schema::table('label_print_logs', function (Blueprint $table) {
                if (! Schema::hasColumn('label_print_logs', 'action')) $table->string('action', 20)->nullable()->after('product_name');
                if (! Schema::hasColumn('label_print_logs', 'lot')) $table->string('lot', 30)->nullable()->after('action');
                if (! Schema::hasColumn('label_print_logs', 'original_dlc')) $table->date('original_dlc')->nullable()->after('expires_at');
                if (! Schema::hasColumn('label_print_logs', 'capped')) $table->boolean('capped')->default(false)->after('original_dlc');
            });
        }
    }

    public function down(): void
    {
        Schema::table('label_print_logs', function (Blueprint $table) {
            foreach (['action', 'lot', 'original_dlc', 'capped'] as $c) {
                if (Schema::hasColumn('label_print_logs', $c)) $table->dropColumn($c);
            }
        });
        Schema::table('dlc_label_products', function (Blueprint $table) {
            foreach (['category', 'actions', 'allergens', 'note', 'ask_original_dlc', 'print_operator', 'default_copies'] as $c) {
                if (Schema::hasColumn('dlc_label_products', $c)) $table->dropColumn($c);
            }
        });
    }
};
