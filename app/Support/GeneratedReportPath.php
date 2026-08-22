<?php

namespace App\Support;

/**
 * [SUPERVISION 2026-08-22] Où atterrit un rapport GÉNÉRÉ.
 *
 * POURQUOI CE FICHIER EXISTE
 * --------------------------
 * Trois écrivains de rapports (`raw-materials:fiche`, `raw-materials:food-cost`, le seeder
 * `RepairMultiVariationFixturesSeeder`) écrivaient directement dans `base_path('reports/…')`.
 * Or ces trois-là sont appelés PAR LA SUITE DE TESTS. Conséquence mesurée le 2026-08-22 :
 * un simple `php artisan test` réécrivait dans le dépôt
 *   · `reports/goal-mega-2026-07-22/FICHE_PARAMETRES_INGREDIENTS.md` (fichier TRACKÉ, et dont
 *     l'en-tête dit « Owner : corrige les quantités » — donc un fichier que le propriétaire
 *     ÉDITE À LA MAIN, et qu'un lancement de tests écrasait sans un mot) ;
 *   · `reports/goal-mega-2026-07-22/FOOD_COST_REPORT.md`, rempli des données de FIXTURE
 *     (« 1 produits actifs — Cayenne 10,00 € ») alors que le vrai catalogue en compte 57 ;
 *   · `reports/data-repair/MULTI_VARIATION_AUDIT_<date>.md`, un fichier NEUF par jour
 *     calendaire, portant l'en-tête « **Mode: FORCED (DB MUTATED)** » — une phrase qui, relue
 *     dans six mois, se lit comme la preuve qu'on a forcé une mutation de la base
 *     opérationnelle. C'était un test.
 *
 * DEUX DÉGÂTS DISTINCTS
 * 1. FAUSSE PREUVE. `reports/` est la mémoire d'audit du projet (CLAUDE.md §13) ; y déposer
 *    des sorties de fixture empoisonne les décisions futures.
 * 2. ARBRE SALE. `scripts/deploy/deploy.sh:103` REFUSE de déployer si `git status` n'est pas
 *    vide. Un arbre sali par un lancement de tests pousse l'opérateur vers `--force`, qui
 *    écrase les hot-patches SCP — exactement ce que cette garde existait pour empêcher.
 *
 * LA RÈGLE
 * En environnement `testing`, un rapport généré va sous `storage/framework/testing/` (déjà
 * ignoré par git). Partout ailleurs il va où il a toujours été. `--out=` reste prioritaire :
 * c'est ce qui permet à un test d'affirmer sur le CONTENU sans toucher au dépôt.
 */
class GeneratedReportPath
{
    /**
     * @param  string      $relative chemin relatif à la racine du dépôt (ex. 'reports/x/y.md')
     * @param  string|null $out      surcharge explicite (option `--out=`), absolue ou relative
     */
    public static function resolve(string $relative, ?string $out = null): string
    {
        $out = is_string($out) ? trim($out) : '';
        if ($out !== '') {
            return str_starts_with($out, DIRECTORY_SEPARATOR) ? $out : base_path($out);
        }

        // Défensif : un seeder peut être instancié hors d'une application bootée.
        try {
            $testing = app()->environment('testing');
        } catch (\Throwable $e) {
            $testing = false;
        }

        return $testing
            ? storage_path('framework/testing/' . ltrim($relative, '/'))
            : base_path($relative);
    }
}
