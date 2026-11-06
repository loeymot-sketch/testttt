<?php

namespace Tests\Feature\Deploy;

use Tests\TestCase;

/**
 * [2026-09-03 · superviseur] Trois documents annonçaient trois versions de PHP, et aucune
 * n'était celle de la machine qui encaisse.
 *
 *   `composer.json`                → `php: ^8.1.0`   (l'exigence réelle du projet)
 *   `scripts/deploy/deploy.sh`     → PHP 8.4+, avec REFUS au pré-vol
 *   la procédure de mise en prod   → `php8.2 artisan ...`
 *   la production, mesurée         → PHP 8.1.2, `php8.1-fpm` actif, aucun autre binaire
 *
 * Conséquence concrète : `deploy.sh` REFUSE de tourner sur la machine de production. Ce n'est
 * pas une panne silencieuse — le garde fait son travail — mais cela rend le script officiel
 * inutilisable, et les déploiements se font donc à la main, hors procédure. Un chemin manuel
 * non documenté est exactement là où les étapes se perdent : c'est ainsi que `config:cache` a
 * fini par être exécuté en production.
 *
 * `composer.json` fait autorité : c'est la seule des trois sources que le gestionnaire de
 * dépendances fait respecter. Ce banc l'attache au socle du script de déploiement pour que la
 * divergence ne puisse pas revenir en silence.
 */
class SocleDePhpUniqueTest extends TestCase
{
    /**
     * Le socle exigé par le script de déploiement doit être celui de `composer.json`.
     */
    public function test_le_script_de_deploiement_exige_le_socle_de_composer(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $contrainte = (string) ($composer['require']['php'] ?? '');

        $this->assertMatchesRegularExpression(
            '/^\^(\d+)\.(\d+)/',
            $contrainte,
            'composer.json doit exprimer un socle PHP lisible (ex. ^8.1.0).'
        );
        preg_match('/^\^(\d+)\.(\d+)/', $contrainte, $m);
        $attendu = (int) $m[1] * 10000 + (int) $m[2] * 100;

        $script = (string) file_get_contents(base_path('scripts/deploy/deploy.sh'));

        $this->assertMatchesRegularExpression(
            '/PHP_VERSION_ID >= (\d+)/',
            $script,
            'deploy.sh doit comparer PHP_VERSION_ID à un socle explicite.'
        );
        preg_match('/PHP_VERSION_ID >= (\d+)/', $script, $s);

        $this->assertSame(
            $attendu,
            (int) $s[1],
            "deploy.sh exige PHP {$s[1]} alors que composer.json exige {$contrainte}. "
            .'Un script de déploiement plus exigeant que le projet lui-même REFUSE de tourner '
            .'sur la machine de production, et les déploiements repassent à la main, hors procédure.'
        );
    }

    /**
     * Aucune procédure ne doit prescrire un binaire PHP versionné qui n'existe pas sur l'hôte.
     * La production n'a que `php` et `php8.1`.
     */
    public function test_aucune_procedure_ne_prescrit_un_binaire_php_inexistant(): void
    {
        $interdits = ['php8.2', 'php8.3', 'php8.4'];
        $fautes = [];

        $procedures = array_merge(
            glob(base_path('scripts/deploy/*.sh')) ?: [],
            glob(base_path('reports/goal-dashboard-pilotable-2026-09-02/MISE_EN_PRODUCTION.md')) ?: []
        );

        foreach ($procedures as $chemin) {
            $texte = (string) file_get_contents($chemin);
            foreach ($interdits as $binaire) {
                // `systemctl reload php8.x-fpm` est toléré : le script y prévoit déjà un repli
                // sur `php-fpm`. Ce qui est interdit, c'est d'APPELER un binaire absent.
                if (preg_match('/(?<!-)\b'.preg_quote($binaire, '/').'\s+(artisan|-r|-v)/', $texte) === 1) {
                    $fautes[] = basename($chemin).' → '.$binaire;
                }
            }
        }

        $this->assertSame(
            [],
            $fautes,
            "Ces procédures appellent un binaire PHP absent de la production (php8.1 uniquement) :\n"
            .implode("\n", $fautes)
        );
    }
}
