<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiosk Design V1 — Phase 8.1
 *
 * Flags diététiques et de disponibilité côté `items` pour se conformer au
 * DATA_CONTRACT §3.3 (design folder `borne (Remix)`) et au Règlement
 * (UE) n° 1169/2011 FIC (affichage allergènes + régime).
 *
 * Tous par défaut à false / true pour ne pas régresser les items existants.
 *
 * Colonnes ajoutées :
 *   - is_new            : mise en avant "Nouveau" (admin statique)
 *   - is_available      : toggle 86 rapide (inverse de "en rupture")
 *   - is_spicy          : piquant (halal-kebab classique)
 *   - is_vegetarian     : régime végétarien (sans viande ni poisson)
 *   - is_pork_free      : sans porc (marchés halal — séparé de is_halal)
 *   - is_halal          : certifié halal
 *   - is_gluten_free    : sans gluten
 *   - chef_pick_order   : ordre d'apparition en tête de grille si is_chef_pick
 *
 * AUCUN algorithme (stats, ML, best-seller) n'alimente ces champs — seul
 * l'admin les définit (invariant §1.5 master prompt : interdiction DGCCRF).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            if (!Schema::hasColumn('items', 'is_new')) {
                $table->boolean('is_new')->default(false)->after('is_chef_pick');
            }
            if (!Schema::hasColumn('items', 'is_available')) {
                $table->boolean('is_available')->default(true)->after('is_new');
            }
            if (!Schema::hasColumn('items', 'is_spicy')) {
                $table->boolean('is_spicy')->default(false)->after('is_available');
            }
            if (!Schema::hasColumn('items', 'is_vegetarian')) {
                $table->boolean('is_vegetarian')->default(false)->after('is_spicy');
            }
            if (!Schema::hasColumn('items', 'is_pork_free')) {
                $table->boolean('is_pork_free')->default(false)->after('is_vegetarian');
            }
            if (!Schema::hasColumn('items', 'is_halal')) {
                $table->boolean('is_halal')->default(false)->after('is_pork_free');
            }
            if (!Schema::hasColumn('items', 'is_gluten_free')) {
                $table->boolean('is_gluten_free')->default(false)->after('is_halal');
            }
            if (!Schema::hasColumn('items', 'chef_pick_order')) {
                $table->unsignedInteger('chef_pick_order')->nullable()->after('is_gluten_free');
            }
        });

        // Index composite pour les filtres du front (DATA_CONTRACT §9.3).
        // try/catch : la ré-exécution sur une base déjà migrée ne casse pas.
        try {
            Schema::table('items', function (Blueprint $table): void {
                $table->index(['is_available', 'is_halal', 'is_vegetarian'], 'items_kiosk_filters_idx');
            });
        } catch (\Throwable $e) {
            // Index déjà présent — silencieux (idempotent).
        }
    }

    public function down(): void
    {
        try {
            Schema::table('items', function (Blueprint $table): void {
                $table->dropIndex('items_kiosk_filters_idx');
            });
        } catch (\Throwable $e) {
            // Index inexistant — silencieux.
        }
        Schema::table('items', function (Blueprint $table): void {
            foreach (['chef_pick_order', 'is_gluten_free', 'is_halal', 'is_pork_free', 'is_vegetarian', 'is_spicy', 'is_available', 'is_new'] as $col) {
                if (Schema::hasColumn('items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
