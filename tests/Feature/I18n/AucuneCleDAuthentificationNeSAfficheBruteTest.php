<?php

namespace Tests\Feature\I18n;

use Tests\TestCase;

/**
 * [ONB-06 2026-08-28] Aucun message d'authentification ne doit s'afficher BRUT.
 *
 * ═══ LE DÉFAUT ═══
 *
 * `lang/en/auth.php` n'avait ni `old_password_mismatch` ni
 * `password_confirmation_mismatch`. `lang/ar/auth.php` n'avait pas non plus
 * `trop_de_tentatives`. Ces clés sont émises par **huit** FormRequests — toutes les
 * créations de compte (Administrateur, Employé, Chef, Serveur, Livreur, Client) et le
 * changement de mot de passe.
 *
 * Quand une clé manque, Laravel ne se rabat PAS sur le français : il renvoie la clé
 * elle-même. Le commerçant lisait, dans son formulaire d'embauche :
 *
 *     auth.password_confirmation_mismatch
 *
 * ═══ POURQUOI CE N'EST PAS THÉORIQUE ═══
 *
 * On pourrait croire le produit à l'abri puisque ADR-007 fige la locale en français.
 * Mais `App\Http\Middleware\localization` détecte la langue à partir de l'en-tête
 * **`Accept-Language` du navigateur**, et déclare `['fr', 'en', 'ar']` supportées.
 * Un patron dont le navigateur est réglé en anglais — courant — bascule en `en` sans
 * rien avoir demandé, et c'est précisément lui qui crée les comptes de son équipe.
 *
 * Défaut trouvé par accident : le parcours de bout en bout d'ONB-14 a heurté ces clés
 * en créant son premier employé. Un banc écrit pour prouver autre chose.
 */
class AucuneCleDAuthentificationNeSAfficheBruteTest extends TestCase
{
    private const LANGUES_SERVIES = ['fr', 'en', 'ar'];

    public function test_chaque_langue_servie_traduit_toutes_les_cles_du_francais(): void
    {
        $reference = require lang_path('fr/auth.php');

        $this->assertNotEmpty($reference, 'Le fichier de référence est vide.');

        foreach (self::LANGUES_SERVIES as $langue) {
            $chemin = lang_path($langue . '/auth.php');

            $this->assertFileExists(
                $chemin,
                "`{$langue}` est déclarée servie par le middleware mais n'a pas de fichier."
            );

            $traduit = require $chemin;
            $absentes = array_diff(array_keys($reference), array_keys($traduit));

            $this->assertSame(
                [],
                array_values($absentes),
                "En `{$langue}`, ces clés s'afficheraient BRUTES à l'écran :\n  - "
                . implode("\n  - ", $absentes)
                . "\n\nLaravel ne se rabat pas sur le français : il rend la clé elle-même."
            );
        }
    }

    public function test_les_langues_servies_sont_bien_celles_que_ce_banc_verifie(): void
    {
        // Contrôle de périmètre. Le jour où quelqu'un ajoute une langue au middleware
        // sans l'ajouter ici, ce banc resterait vert en ne la regardant pas — c'est la
        // faute la plus fréquente d'un banc de couverture.
        $middleware = file_get_contents(app_path('Http/Middleware/Localization.php'));

        preg_match('/\$supported\s*=\s*\[([^\]]*)\]/', $middleware, $trouve);

        $this->assertNotEmpty($trouve, 'La liste des langues supportées est introuvable.');

        preg_match_all("/'([a-z]{2})'/", $trouve[1], $codes);

        $this->assertEqualsCanonicalizing(
            self::LANGUES_SERVIES,
            $codes[1],
            "Le middleware sert des langues que ce banc ne vérifie pas — ou l'inverse.\n"
            . 'Mettre à jour LANGUES_SERVIES, sinon la couverture est un trompe-l\'œil.'
        );
    }

    public function test_les_huit_emetteurs_utilisent_toujours_ces_cles(): void
    {
        // Si un jour on remplace `__('auth.…')` par une chaîne en dur, ce banc doit le
        // dire : il n'aurait plus rien à protéger, et son vert deviendrait trompeur.
        $emetteurs = glob(app_path('Http/Requests/*.php'));
        $trouves = 0;

        foreach ($emetteurs as $fichier) {
            if (str_contains(file_get_contents($fichier), 'auth.password_confirmation_mismatch')) {
                $trouves++;
            }
        }

        $this->assertGreaterThanOrEqual(
            6,
            $trouves,
            "Presque plus personne n'émet `auth.password_confirmation_mismatch`.\n"
            . 'Soit les règles ont changé, soit un message a été écrit en dur : relire ce banc.'
        );
    }
}
