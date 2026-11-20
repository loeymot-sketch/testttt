<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia « texte d'engagement »] Valider une fiche de nettoyage, c'est dire « je confirme l'exactitude des
 * informations et je comprends qu'un manager pourra les contrôler » : l'engagement est coché par la personne, daté, et reste au journal.
 * Additif : une colonne annulable ; NULL = validation antérieure à l'engagement (aucune donnée existante touchée).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hygiene_task_completions')) {
            return;
        }
        Schema::table('hygiene_task_completions', function (Blueprint $table) {
            if (! Schema::hasColumn('hygiene_task_completions', 'attested_at')) $table->timestamp('attested_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('hygiene_task_completions') && Schema::hasColumn('hygiene_task_completions', 'attested_at')) {
            Schema::table('hygiene_task_completions', fn (Blueprint $table) => $table->dropColumn('attested_at'));
        }
    }
};
