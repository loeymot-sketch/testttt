<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roue Le Cayenne — journal des participations.
 *
 * Cette table est la SOURCE DE VÉRITÉ du jeu : un tour n'existe que s'il y a une ligne ici. Le
 * résultat y est écrit AU MOMENT DU TIRAGE, avant que le navigateur ne l'apprenne — c'est ce qui
 * rend impossible de « re-tourner jusqu'à gagner » en rechargeant la page.
 *
 * Trois choix structurants :
 *
 * 1. UNIQUE (branch_id, campaign_key, phone) — la garde « un tour par personne » vit en BASE, pas
 *    dans le code. Une garde applicative se contourne par une course entre deux requêtes
 *    simultanées ; une contrainte d'unicité, non. Changer `campaign_key` rouvre le jeu à tous sans
 *    vider la table, donc sans perdre l'historique.
 *
 * 2. `phone` est stocké NORMALISÉ (chiffres seuls). « 06 12 34 56 78 » et « +33612345678 » sont la
 *    même personne : sans normalisation, l'unicité ne protège rien.
 *
 * 3. Le lot est figé en clair (`prize_key`, `prize_label`, `prize_value`) EN PLUS de la référence
 *    au coupon. Si demain la configuration des segments change, on doit toujours pouvoir dire ce
 *    qui a réellement été promis à ce client-là, ce jour-là. Même principe que le
 *    `rendered_payload` du ticket promo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wheel_spins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->index();
            $table->string('campaign_key', 64)->index();

            // Identité : le téléphone est déjà la clé invité du système.
            $table->string('phone', 32);
            $table->string('customer_name', 120)->nullable();

            // Le lot, figé au tirage.
            $table->string('prize_key', 48);
            $table->string('prize_label', 80);
            $table->string('prize_type', 24);
            $table->decimal('prize_value', 10, 2)->default(0);

            // Ce qui a été réellement attribué.
            $table->unsignedBigInteger('coupon_id')->nullable()->index();
            $table->unsignedInteger('points_awarded')->nullable();

            // Comment le tour a été ouvert : 'staff' (validation humaine au comptoir) ou 'order'
            // (commande réelle payée). Jamais déclaratif — voir config/wheel.php.
            $table->string('unlock_method', 16);
            $table->unsignedBigInteger('unlocked_by_user_id')->nullable();
            $table->unsignedBigInteger('unlock_order_id')->nullable();
            // Empreinte du jeton consommé : permet de refuser un rejeu sans stocker le jeton.
            $table->string('unlock_token_hash', 64)->nullable()->unique();

            // Traçabilité d'abus (pas d'identification : l'IP est hachée).
            $table->string('device_id', 64)->nullable();
            $table->string('ip_hash', 64)->nullable();

            // Récupération : le lot ne vaut que consommé sur une commande.
            $table->unsignedBigInteger('claimed_order_id')->nullable()->index();
            $table->timestamp('claimed_at')->nullable();
            // Coût réel d'un produit offert, une fois consommé (décharge).
            $table->unsignedBigInteger('cost_outflow_id')->nullable();

            $table->timestamps();

            $table->unique(['branch_id', 'campaign_key', 'phone'], 'wheel_spins_one_per_phone');
            $table->index(['branch_id', 'created_at'], 'wheel_spins_daily_cap');
            $table->index(['branch_id', 'prize_key', 'created_at'], 'wheel_spins_prize_cap');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wheel_spins');
    }
};
