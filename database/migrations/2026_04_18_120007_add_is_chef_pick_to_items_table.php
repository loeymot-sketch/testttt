<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiosk Design V1 — Phase 1.1 (7/8)
 *
 * Flag admin statique `is_chef_pick` — seul badge dynamique autorisé côté
 * kiosk (cf. master prompt §1.5 : interdiction des stats « 78% des clients »,
 * « populaire », « best-seller » pour cause de risque DGCCRF).
 *
 * Ce champ est écrit UNIQUEMENT depuis l'UI admin. Aucun algorithme
 * (sales / stats / ML) ne le calcule.
 *
 * Default false → aucun item legacy n'est marqué, pas de régression visuelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            if (!Schema::hasColumn('items', 'is_chef_pick')) {
                $table->boolean('is_chef_pick')->default(false)->after('is_upsell');
                $table->index(['is_chef_pick'], 'items_is_chef_pick_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            if (Schema::hasColumn('items', 'is_chef_pick')) {
                $table->dropIndex('items_is_chef_pick_idx');
                $table->dropColumn('is_chef_pick');
            }
        });
    }
};
