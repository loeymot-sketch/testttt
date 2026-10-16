<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [ETIQUETTES 2026-10-16] Une réimpression d'UNE étiquette (« Réimprimer celle-ci ») n'est pas l'impression de la
 * commande : sans ce drapeau, elle la marquait « imprimée » et la retirait de « Tout imprimer » alors que trois
 * étiquettes sur quatre n'étaient jamais sorties. Les lignes partielles ne comptent ni comme « imprimée » ni
 * comme verrou de l'impression automatique.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('label_print_logs') && ! Schema::hasColumn('label_print_logs', 'partial')) {
            Schema::table('label_print_logs', function (Blueprint $table) {
                $table->boolean('partial')->default(false)->after('labels_count');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('label_print_logs', 'partial')) {
            Schema::table('label_print_logs', function (Blueprint $table) {
                $table->dropColumn('partial');
            });
        }
    }
};
