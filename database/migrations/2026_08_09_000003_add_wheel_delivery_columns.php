<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [ROUE — LIVRAISON 2026-08-09] Trois audits adversaires ont montré que la roue TIRAIT bien mais ne
 * LIVRAIT rien : les lots en points ne créditaient personne, les produits offerts passaient par un
 * coupon à 0,00 € qui brûlait son usage unique (le client payait plein tarif), et aucun écran ne
 * disait au comptoir qu'un client avait un lot à recevoir.
 *
 * La remise devient donc un GESTE TRACÉ : l'équipe saisit le numéro, voit le lot, appuie sur
 * « remis ». Ces colonnes portent ce geste.
 *
 * `delivered_at` est la seule marque qui compte : sans elle, un client pourrait réclamer son lot à
 * chaque service et l'équipe n'aurait aucun moyen de savoir qu'il l'a déjà eu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wheel_spins', function (Blueprint $table) {
            // Remise physique au comptoir (produit offert) ou crédit effectif (points).
            $table->timestamp('delivered_at')->nullable()->after('claimed_at');
            $table->unsignedBigInteger('delivered_by_user_id')->nullable()->after('delivered_at');
            // Compte credité, quand le numéro correspond à un client existant. NUL = les points
            // attendent que la personne crée son compte avec ce numéro.
            $table->unsignedBigInteger('points_credited_user_id')->nullable()->after('points_awarded');

            // Index de recherche du comptoir : on cherche TOUJOURS par numéro dans une branche.
            $table->index(['branch_id', 'phone'], 'wheel_spins_lookup');
            // Le rescan de la charge ne doit pas balayer toute la table (défaut relevé en audit).
            $table->index(['branch_id', 'cost_outflow_id'], 'wheel_spins_cost_scan');
        });
    }

    public function down(): void
    {
        Schema::table('wheel_spins', function (Blueprint $table) {
            $table->dropIndex('wheel_spins_lookup');
            $table->dropIndex('wheel_spins_cost_scan');
            $table->dropColumn(['delivered_at', 'delivered_by_user_id', 'points_credited_user_id']);
        });
    }
};
