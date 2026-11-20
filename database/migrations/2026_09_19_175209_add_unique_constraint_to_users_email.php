<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [Root cause 2026-09-19, propriétaire : « je peux créer 2 compte avec meme email ! c trop
 * reducul ! corrige deep »]
 *
 * `users.email` ne portait AUCUNE contrainte unique en base — seulement un index simple (MUL),
 * vérifié via `SHOW INDEX FROM users`. Toute garantie d'unicité reposait donc entièrement sur
 * des vérifications applicatives dispersées dans au moins 5 chemins de création de compte
 * (SignupController, GuestSignupController, SocialAuthController, CustomerAccountProvisioner,
 * CustomerService) — et UN seul avait un trou (SignupController::register(), corrigé dans le
 * même commit) suffisait à produire deux comptes, deux soldes de fidélité, pour un même client.
 *
 * Mesuré avant migration (production, 2026-09-18) : 0 groupe d'e-mails dupliqués, 0 e-mail en
 * chaîne vide, 13 e-mails NULL — la migration est donc sûre à cette date : `UNIQUE` sur MySQL
 * autorise plusieurs NULL sur une même colonne, seules les VALEURS concrètes dupliquées
 * feraient échouer la contrainte. La collation existante (`utf8mb4_unicode_ci`) est déjà
 * insensible à la casse : l'index unique traite donc nativement "a@x.com" et "A@X.com" comme
 * identiques, sans transformation supplémentaire nécessaire.
 *
 * Défense en profondeur : cette contrainte n'a pas vocation à remplacer les vérifications
 * applicatives (qui restent nécessaires pour un message d'erreur clair) — elle garantit qu'un
 * SIXIÈME chemin de création de compte, aujourd'hui inconnu ou futur, ne puisse plus jamais
 * silencieusement dupliquer un e-mail.
 */
return new class extends Migration
{
    public function up()
    {
        // Re-vérifie qu'aucun doublon n'a été introduit entre la mesure et l'exécution
        // (fenêtre entre développement et déploiement) — échoue fort et lisible plutôt que de
        // laisser MySQL renvoyer une erreur de contrainte générique sur une ligne arbitraire.
        $doublons = DB::table('users')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->selectRaw('LOWER(email) as e, COUNT(*) as c')
            ->groupBy('e')
            ->having('c', '>', 1)
            ->get();

        if ($doublons->isNotEmpty()) {
            throw new \RuntimeException(
                'Migration refusée : '.$doublons->count().' e-mail(s) dupliqué(s) existent déjà '
                .'en base — les fusionner ou les corriger manuellement avant de poser la '
                .'contrainte unique. Exemples : '
                .$doublons->take(5)->pluck('e')->implode(', ')
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email', 'users_email_unique');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
        });
    }
};
