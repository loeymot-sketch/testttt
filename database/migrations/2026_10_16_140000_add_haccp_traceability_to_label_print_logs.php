<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [ETIQUETTES 2026-10-16 · revue HACCP] Le journal d'hygiène dit TOUT ce que l'étiquette a dit, et d'où vient le produit :
 *
 *   · storage        — la conservation IMPRIMÉE (un produit décongelé se garde au froid positif, même si sa fiche dit
 *                      « ≤ −18 °C » : la ligne du journal doit être celle du papier) ;
 *   · cap_source     — ce qui a limité la DLC imprimée : « original » (DLC du fabricant) ou « parent » (étiquette d'origine) ;
 *   · parent_log_id  — l'étiquette du produit d'origine (poulet décongelé → poulet mariné) : la DLC ne repart jamais à
 *                      zéro, sauf cuisson (traitement assainissant, GBPH § 2.7) ;
 *   · supplier_lot   — le lot du FABRICANT recopié de l'emballage d'origine (traçabilité amont) ;
 *   · offline        — étiquette imprimée réseau coupé, journalisée au retour du réseau ;
 *   · client_ref     — identifiant de la tablette pour cette impression hors ligne : rejouer l'envoi ne crée jamais de doublon ;
 *   · discarded_by   — qui a déclaré le lot jeté.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('label_print_logs')) {
            return;
        }

        Schema::table('label_print_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('label_print_logs', 'storage')) $table->string('storage', 60)->nullable()->after('capped');
            if (! Schema::hasColumn('label_print_logs', 'cap_source')) $table->string('cap_source', 10)->nullable()->after('storage');
            if (! Schema::hasColumn('label_print_logs', 'parent_log_id')) $table->unsignedBigInteger('parent_log_id')->nullable()->index()->after('cap_source');
            if (! Schema::hasColumn('label_print_logs', 'supplier_lot')) $table->string('supplier_lot', 40)->nullable()->after('parent_log_id');
            if (! Schema::hasColumn('label_print_logs', 'offline')) $table->boolean('offline')->default(false)->after('supplier_lot');
            if (! Schema::hasColumn('label_print_logs', 'client_ref')) $table->string('client_ref', 64)->nullable()->unique()->after('offline');
            if (! Schema::hasColumn('label_print_logs', 'discarded_by')) $table->unsignedBigInteger('discarded_by')->nullable()->after('discarded_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('label_print_logs')) {
            return;
        }

        Schema::table('label_print_logs', function (Blueprint $table) {
            if (Schema::hasColumn('label_print_logs', 'client_ref')) $table->dropUnique(['client_ref']);
            if (Schema::hasColumn('label_print_logs', 'parent_log_id')) $table->dropIndex(['parent_log_id']);
        });
        Schema::table('label_print_logs', function (Blueprint $table) {
            foreach (['storage', 'cap_source', 'parent_log_id', 'supplier_lot', 'offline', 'client_ref', 'discarded_by'] as $c) {
                if (Schema::hasColumn('label_print_logs', $c)) $table->dropColumn($c);
            }
        });
    }
};
