<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [ROUE — ÉTAPES 2026-08-09] Progression des étapes, HORODATÉE PAR LE SERVEUR.
 *
 * POURQUOI CETTE TABLE EXISTE. Le compteur de 20 secondes vit dans le navigateur : il se contourne
 * en deux clics dans les outils de développement, ou simplement en rejouant la requête. Un client
 * qui annonce lui-même « j'ai attendu 20 s » n'annonce rien.
 *
 * Le serveur horodate donc LUI-MÊME l'ouverture de chaque lien, et vérifie le délai écoulé au
 * moment du tour. Le client ne peut pas mentir sur l'horloge du serveur — c'est la seule version de
 * cette garde qui tienne.
 *
 * POURQUOI PAS SUR `wheel_spins`. La ligne de tour n'existe qu'AU tirage : il n'y a rien à
 * horodater avant. Et la créer d'avance mêlerait « en cours de parcours » et « a joué », deux états
 * qu'on doit pouvoir distinguer — notamment pour dire « tu as déjà tourné » sans se tromper.
 *
 * La clé est l'EMPREINTE DU JETON, déjà à usage unique : la progression ne peut donc pas être
 * réutilisée d'un client à l'autre, et elle disparaît avec la validation qui l'a créée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wheel_step_progress', function (Blueprint $table) {
            $table->id();
            // Empreinte du jeton de validation du comptoir : une progression par validation.
            $table->string('unlock_token_hash', 64)->unique();
            $table->unsignedBigInteger('branch_id')->index();

            // Horodatages POSÉS PAR LE SERVEUR, jamais reçus du client.
            $table->timestamp('review_opened_at')->nullable();
            $table->timestamp('follow_opened_at')->nullable();

            // Le nombre d'abonnés relevé AVANT les étapes, pour comparer après. Contrôle global,
            // invisible du client.
            $table->unsignedInteger('followers_before')->nullable();

            $table->timestamps();

            // Le ménage se fait par date : une progression n'a de sens que le temps d'un parcours.
            $table->index('created_at', 'wheel_step_progress_cleanup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wheel_step_progress');
    }
};
