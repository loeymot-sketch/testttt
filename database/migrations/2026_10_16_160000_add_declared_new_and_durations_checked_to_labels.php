<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [ETIQUETTES 2026-10-16 · revue HACCP, 3e tour] Ce qui a été DÉCLARÉ se garde au journal :
 *
 *   · label_print_logs.declared_new   — « produit neuf » déclaré à l'impression d'une préparation (fiche « fait à partir
 *                                       d'un produit étiqueté ») : la personne a dit qu'il n'y avait pas d'origine ;
 *   · dlc_label_products.durations_checked_at / _by — qui a confirmé une durée inhabituelle (« Durées vérifiées »), et quand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('label_print_logs')) {
            Schema::table('label_print_logs', function (Blueprint $table) {
                if (! Schema::hasColumn('label_print_logs', 'declared_new')) $table->boolean('declared_new')->default(false)->after('parent_log_id');
            });
        }
        if (Schema::hasTable('dlc_label_products')) {
            Schema::table('dlc_label_products', function (Blueprint $table) {
                if (! Schema::hasColumn('dlc_label_products', 'durations_checked_at')) $table->timestamp('durations_checked_at')->nullable()->after('default_copies');
                if (! Schema::hasColumn('dlc_label_products', 'durations_checked_by')) $table->unsignedBigInteger('durations_checked_by')->nullable()->after('durations_checked_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('label_print_logs') && Schema::hasColumn('label_print_logs', 'declared_new')) {
            Schema::table('label_print_logs', fn (Blueprint $table) => $table->dropColumn('declared_new'));
        }
        if (Schema::hasTable('dlc_label_products')) {
            Schema::table('dlc_label_products', function (Blueprint $table) {
                foreach (['durations_checked_at', 'durations_checked_by'] as $c) {
                    if (Schema::hasColumn('dlc_label_products', $c)) $table->dropColumn($c);
                }
            });
        }
    }
};
