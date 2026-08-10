<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [ROUE — LE TOUR AVANT L'IDENTITÉ 2026-08-10] Arbitrage du propriétaire : le client TOURNE
 * d'abord, et ne donne son numéro et son adresse qu'ENSUITE, pour débloquer le code qu'il vient de
 * gagner.
 *
 * POURQUOI C'EST MEILLEUR, et pourquoi ça change l'architecture. Demander deux champs AVANT le tour,
 * c'est demander un effort contre une promesse. Les demander APRÈS, c'est les demander contre un lot
 * déjà visible : l'écart d'acceptation est énorme. Mais du coup le tirage se produit alors qu'on ne
 * sait pas encore QUI joue — et l'unicité « un tour par personne » ne peut plus être vérifiée au
 * moment du tirage.
 *
 * D'où ces colonnes : le lot tiré est mis EN ATTENTE sur la progression (clé = empreinte du jeton de
 * la tablette, déjà à usage unique), et il ne devient une participation — ligne `wheel_spins`,
 * coupon, points — qu'au moment de la RÉCLAMATION, quand l'identité est connue et son unicité
 * vérifiée en base.
 *
 * Conséquence voulue : un lot non réclamé n'existe pas. Pas de coupon émis, aucune charge, rien à
 * nettoyer. Celui qui tourne et s'en va n'a rien coûté.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wheel_step_progress', function (Blueprint $table) {
            $table->string('prize_key', 48)->nullable()->after('follow_opened_at');
            $table->string('prize_label', 80)->nullable()->after('prize_key');
            $table->string('prize_type', 24)->nullable()->after('prize_label');
            $table->decimal('prize_value', 10, 2)->nullable()->after('prize_type');
            // L'heure du tirage : elle sert à expirer un lot resté en attente trop longtemps (voir
            // le service). Un lot en attente indéfiniment serait réclamable des semaines plus tard,
            // hors de tout plafond journalier.
            $table->timestamp('spun_at')->nullable()->after('prize_value');
        });
    }

    public function down(): void
    {
        Schema::table('wheel_step_progress', function (Blueprint $table) {
            $table->dropColumn(['prize_key', 'prize_label', 'prize_type', 'prize_value', 'spun_at']);
        });
    }
};
