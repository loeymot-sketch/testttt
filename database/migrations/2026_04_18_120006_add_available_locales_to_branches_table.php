<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kiosk Design V1 — Phase 1.1 (6/8)
 *
 * Liste des locales actives par branche (fr/en/ar par défaut, configurable).
 * Utilisée par le kiosk pour afficher/masquer le bouton de langue.
 *
 * Backfill : toutes les branches existantes reçoivent ["fr","en","ar"].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            if (!Schema::hasColumn('branches', 'available_locales')) {
                $table->json('available_locales')->nullable();
            }
        });

        // Backfill — toutes les branches existantes héritent du trilingue FoodKing.
        // On évite ORM/Eloquent pour éviter les scopes globaux indésirables.
        DB::table('branches')
            ->whereNull('available_locales')
            ->update(['available_locales' => json_encode(['fr', 'en', 'ar'])]);
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            if (Schema::hasColumn('branches', 'available_locales')) {
                $table->dropColumn('available_locales');
            }
        });
    }
};
