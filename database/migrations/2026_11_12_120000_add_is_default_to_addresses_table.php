<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [PROFIL APP 2026-11-12] Adresse PAR DÉFAUT du carnet d'adresses du client (profil de l'application et du site).
 *
 * Ajout seul : une colonne booléenne, faux par défaut. Aucune ligne existante n'est modifiée — un client qui n'a
 * encore jamais choisi d'adresse par défaut n'en a simplement aucune (l'écran le dit). Idempotente : rejouée sur une
 * base qui a déjà la colonne, elle ne fait rien. `down()` retire la colonne (aucune autre donnée n'en dépend).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('addresses') || Schema::hasColumn('addresses', 'is_default')) {
            return;
        }

        Schema::table('addresses', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('apartment');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('addresses') && Schema::hasColumn('addresses', 'is_default')) {
            Schema::table('addresses', function (Blueprint $table) {
                $table->dropColumn('is_default');
            });
        }
    }
};
