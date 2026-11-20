<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [ETIQUETTES 2026-10-16] « Jeté » : le personnel accuse réception d'un lot DLC échu (il l'a retiré / jeté). Sans cela,
 * l'alerte « EXPIRÉE » d'un lot resterait affichée indéfiniment, même après le geste d'hygiène — et finirait par ne
 * plus être regardée. La ligne de journal n'est jamais supprimée : seul `discarded_at` est posé (traçabilité).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('label_print_logs') && ! Schema::hasColumn('label_print_logs', 'discarded_at')) {
            Schema::table('label_print_logs', function (Blueprint $table) {
                $table->timestamp('discarded_at')->nullable()->after('finished_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('label_print_logs', 'discarded_at')) {
            Schema::table('label_print_logs', function (Blueprint $table) {
                $table->dropColumn('discarded_at');
            });
        }
    }
};
