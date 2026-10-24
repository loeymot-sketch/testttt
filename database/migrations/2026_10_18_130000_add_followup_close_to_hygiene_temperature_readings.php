<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [CAYENNE HYGIÈNE 2026-10-18 · revue adverse] Un suivi après écart qu'il est IMPOSSIBLE de faire (bain-marie éteint en fin de service, équipement
 * en panne) ne doit pas laisser pour seule sortie un faux relevé : le responsable du geste le CLÔT en disant pourquoi, et cela reste au journal et au
 * dossier de contrôle. Additif : trois colonnes annulables sur le journal des relevés ; aucune donnée existante touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hygiene_temperature_readings')) {
            return;
        }
        Schema::table('hygiene_temperature_readings', function (Blueprint $table) {
            if (! Schema::hasColumn('hygiene_temperature_readings', 'followup_closed_at')) $table->timestamp('followup_closed_at')->nullable();
            if (! Schema::hasColumn('hygiene_temperature_readings', 'followup_closed_by')) $table->unsignedBigInteger('followup_closed_by')->nullable();
            if (! Schema::hasColumn('hygiene_temperature_readings', 'followup_close_reason')) $table->string('followup_close_reason', 120)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hygiene_temperature_readings')) {
            return;
        }
        foreach (['followup_closed_at', 'followup_closed_by', 'followup_close_reason'] as $col) {
            if (Schema::hasColumn('hygiene_temperature_readings', $col)) {
                Schema::table('hygiene_temperature_readings', fn (Blueprint $table) => $table->dropColumn($col));
            }
        }
    }
};
