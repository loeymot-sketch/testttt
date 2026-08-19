<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [APPS 2026-08-19] Numéro de contact DÉCLARÉ, séparé du numéro qui sert d'identité.
 *
 * POURQUOI UNE COLONNE À PART, ET PAS `users.phone`
 * -------------------------------------------------
 * Dans ce système, `users.phone` n'est pas une simple coordonnée : c'est une CLÉ. Le
 * parcours d'inscription retrouve un compte par son numéro, et la garde anti-confusion de
 * canal décide vers quelle adresse envoyer le code de connexion en fonction du compte qui
 * porte ce numéro. Tout cela repose sur une hypothèse implicite : le numéro présent dans
 * `users.phone` y a été mis par quelqu'un qui l'avait prouvé.
 *
 * Une connexion Apple ou Google n'apporte aucun téléphone, et ce projet n'envoie pas de SMS :
 * le numéro demandé juste après est DÉCLARÉ, jamais prouvé. L'écrire dans `users.phone`
 * cassait l'hypothèse — et le vecteur a été REPRODUIT par un test avant d'être corrigé :
 * quelqu'un pouvait déclarer le numéro d'un tiers encore sans compte, puis, ce numéro
 * paraissant désormais « appartenir » à son compte, capter le code de connexion destiné au
 * vrai titulaire (la garde anti-confusion l'envoie à l'adresse du compte qui porte le
 * numéro). Résultat : le vrai titulaire ne pouvait plus créer de compte avec son propre
 * numéro, et le squatteur recevait ses codes.
 *
 * La correction ne consiste pas à ajouter une garde de plus au parcours d'authentification —
 * il en compte déjà plusieurs, durement acquises. Elle consiste à ne PAS créer l'ambiguïté :
 * un numéro déclaré sans preuve ne donne accès à rien, ne sert à aucune recherche, et ne
 * décide d'aucun envoi. Il ne sert qu'à ce pour quoi l'exploitant l'a demandé — POUVOIR
 * APPELER LE CLIENT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('contact_phone', 32)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('contact_phone');
        });
    }
};
