<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [APPS 2026-08-19] Rattachement d'une identité Apple / Google à un compte client.
 *
 * POURQUOI UN IDENTIFIANT DÉDIÉ ET PAS L'E-MAIL
 * ----------------------------------------------
 * L'adresse e-mail n'est PAS un identifiant stable chez un fournisseur d'identité :
 * une personne peut la changer chez Apple ou Google, et « Masquer mon adresse » d'Apple
 * fournit une adresse-relais différente de la vraie. Le seul identifiant stable est le
 * `sub` du jeton d'identité — opaque, immuable, propre à NOTRE application. C'est lui
 * qu'on stocke ; l'e-mail reste une donnée d'affichage et de rapprochement, jamais la clé.
 *
 * UNICITÉ
 * -------
 * Index unique sur chaque colonne : un `sub` ne doit jamais désigner deux comptes, sinon
 * une même personne se retrouverait avec deux historiques de commandes et deux soldes de
 * fidélité. MySQL comme SQLite autorisent plusieurs NULL sous un index unique, donc les
 * comptes sans connexion sociale (la grande majorité : clients venus par téléphone) ne
 * sont pas gênés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('apple_sub', 191)->nullable()->after('email_verified_at');
            $table->string('google_sub', 191)->nullable()->after('apple_sub');

            $table->unique('apple_sub', 'users_apple_sub_unique');
            $table->unique('google_sub', 'users_google_sub_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_apple_sub_unique');
            $table->dropUnique('users_google_sub_unique');
            $table->dropColumn(['apple_sub', 'google_sub']);
        });
    }
};
