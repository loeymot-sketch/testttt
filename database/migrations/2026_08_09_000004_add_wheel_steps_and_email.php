<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [ROUE — PARCOURS EN ÉTAPES 2026-08-09] Le propriétaire a arbitré le parcours réel :
 *   1. le client scanne le QR de la tablette ;
 *   2. il laisse un avis — le bouton se débloque après un temps de rédaction ;
 *   3. « une dernière petite étape » : il s'abonne sur Instagram et Snapchat ;
 *   4. alors seulement il tourne.
 *
 * Ce que ces colonnes portent, et POURQUOI chacune :
 *
 * · `email` — deuxième clé d'identité, à la demande explicite du propriétaire (« un e-mail, ça
 *   rentre pas deux fois ; le numéro de téléphone, ça rentre pas deux fois »). Deux clés valent
 *   mieux qu'une : un numéro se change plus facilement qu'une adresse, et l'adresse sert AUSSI à
 *   envoyer les conditions du lot. L'unicité est posée en BASE, séparément du téléphone : franchir
 *   l'une ne suffit pas, il faut franchir les deux.
 *
 * · `review_clicked_at` / `follow_clicked_at` — l'HORODATAGE des gestes. On ne peut pas vérifier
 *   qu'un avis a été écrit (aucune API ne le permet), mais on peut prouver que le lien a été ouvert
 *   et mesurer le temps passé. C'est la seule donnée honnête sur ce point, et elle sert à repérer
 *   celui qui enchaîne les étapes en deux secondes.
 *
 * · `followers_before` / `followers_after` — le CONTRÔLE DE COHÉRENCE, invisible du client (le
 *   propriétaire ne veut surtout pas le lui annoncer). Le nombre d'abonnés est un TOTAL, pas une
 *   identité : il ne prouve rien individuellement. Mais au comptoir une personne joue à la fois, et
 *   sur une journée l'écart entre « tours accordés » et « abonnés gagnés » dit la vérité.
 *
 * · `notified_at` — la trace de l'e-mail des conditions. Sans elle, on ne saurait pas distinguer
 *   « pas envoyé » de « envoyé et perdu », et on renverrait en boucle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wheel_spins', function (Blueprint $table) {
            $table->string('email', 190)->nullable()->after('phone');

            $table->timestamp('review_clicked_at')->nullable()->after('unlock_token_hash');
            $table->timestamp('follow_clicked_at')->nullable()->after('review_clicked_at');
            $table->unsignedInteger('steps_seconds')->nullable()->after('follow_clicked_at');

            $table->unsignedInteger('followers_before')->nullable()->after('steps_seconds');
            $table->unsignedInteger('followers_after')->nullable()->after('followers_before');

            $table->timestamp('notified_at')->nullable()->after('delivered_by_user_id');

            // Unicité de l'ADRESSE, distincte de celle du téléphone : il faut franchir les DEUX.
            // Nullable, donc N lignes sans adresse restent possibles (MySQL admet N NULL) — utile
            // pour les tours créés avant cette étape.
            $table->unique(['branch_id', 'campaign_key', 'email'], 'wheel_spins_one_per_email');
        });
    }

    public function down(): void
    {
        Schema::table('wheel_spins', function (Blueprint $table) {
            $table->dropUnique('wheel_spins_one_per_email');
            $table->dropColumn([
                'email', 'review_clicked_at', 'follow_clicked_at', 'steps_seconds',
                'followers_before', 'followers_after', 'notified_at',
            ]);
        });
    }
};
