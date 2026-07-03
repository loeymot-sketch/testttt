<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [KITCHEN-TIMING 2026-07-03] Instrumente le TEMPS RÉEL de préparation en cuisine.
 *
 * Contexte : `orders.preparation_time` est un ESTIMÉ de configuration (Settings order_setup, défaut 15 min),
 * PAS une mesure. Il n'existait aucun horodatage du parcours réel d'une commande en cuisine → impossible de
 * répondre à « combien de temps prend réellement un Tacos ? », de détecter un poste qui sature, ou d'alimenter
 * une vraie analytique de productivité.
 *
 * Ce socle ajoute 3 horodatages nullable, remplis UNE fois au franchissement de chaque statut clé
 * (ACCEPT → PREPARING → PREPARED). Additif, non destructif, rétro-compatible (les anciennes commandes restent
 * NULL). Aucune zone gelée touchée. Débloque : temps de prépa réel, heatmap horaire, performance cuisine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'accepted_at')) {
                $table->timestamp('accepted_at')->nullable()->after('preparation_time');
            }
            if (! Schema::hasColumn('orders', 'preparing_at')) {
                $table->timestamp('preparing_at')->nullable()->after('accepted_at');
            }
            if (! Schema::hasColumn('orders', 'prepared_at')) {
                $table->timestamp('prepared_at')->nullable()->after('preparing_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['accepted_at', 'preparing_at', 'prepared_at'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
