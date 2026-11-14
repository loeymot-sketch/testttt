<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [GOAL LIVRAISON-TICKETS 2026-11-10 · T-3.1.1] « Design d'un jour » : des thèmes de couleurs PROGRAMMÉS (fêtes, Noël,
 * Ramadan, match…) qui s'appliquent seuls entre deux dates et se défont seuls à la fin, sans nouvelle version de
 * l'application (règle Apple 2.5.2 : des réglages, jamais du code).
 *
 * Additif : une colonne JSON sur le brouillon de la vitrine ; chaque publication en garde une copie dans `snapshot`.
 * Forme d'un élément : { id, name, starts_at, ends_at, priority, active, theme: { jetons #RRGGBB } } — validé et
 * normalisé par StorefrontService (contraste, dates, liste blanche), jamais stocké brut.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('storefront_settings', 'scheduled_themes')) {
            Schema::table('storefront_settings', function (Blueprint $table) {
                $table->json('scheduled_themes')->nullable()->after('theme');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('storefront_settings', 'scheduled_themes')) {
            Schema::table('storefront_settings', function (Blueprint $table) {
                $table->dropColumn('scheduled_themes');
            });
        }
    }
};
