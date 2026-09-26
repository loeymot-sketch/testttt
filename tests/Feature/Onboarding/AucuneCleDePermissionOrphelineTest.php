<?php

namespace Tests\Feature\Onboarding;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [ONB-06 2026-08-28] Une clé de permission sans correspondance ouvre l'entrée à TOUT LE MONDE.
 *
 * `resources/js/shared/permission-match.js` documente un **repli permissif assumé** :
 * quand la clé demandée par une route ou une entrée de menu ne correspond à aucune
 * permission connue, l'accès est accordé côté interface — « le backend reste
 * l'autorité finale via 403 sur l'API ». La doctrine est délibérée ; ce banc ne la
 * change pas, il en borne le coût.
 *
 * Car ce coût a déjà été payé. Le 2026-08-12, trois clés ne correspondaient à rien
 * (`ingredients_manage` et `catalog.compose` créées sans `url`, `items_create`
 * interrogé par son `name`). Résultat mesuré : l'entrée « Ingrédients » était
 * proposée à l'opérateur de caisse et au chef, qui recevaient ensuite un HTTP 403.
 * **Le menu promettait ce que le serveur refusait.**
 *
 * Mesure du 2026-08-28 sur la base de travail : 30 clés demandées, 88 permissions,
 * **0 orpheline**. Le correctif tient. Ce banc empêche qu'il se défasse — une route
 * ajoutée demain avec une clé mal orthographiée rouvrirait l'entrée à tous, et
 * personne ne le verrait avant qu'un employé ne se prenne un 403.
 *
 * MÉTHODE, et pourquoi elle a changé deux fois. J'ai d'abord lu la SOURCE des semoirs
 * à l'expression régulière. Deux fois de suite, l'extraction s'est révélée trop
 * étroite et a produit de fausses orphelines :
 *   · elle ne lisait que `PermissionTableSeeder`, en ignorant les quatre autres
 *     semoirs de permissions que `DatabaseSeeder` appelle également ;
 *   · puis elle ne reconnaissait que la forme `'name' => '…'`, alors que
 *     `ComposerPermissionsMinimalSeeder` déclare ses permissions en chaînes nues
 *     dans une constante `PERMISSIONS`.
 * Lire du code au motif est fragile par nature. Ce banc EXÉCUTE donc les semoirs de
 * permissions dans la base de test et lit la table — la seule réponse qui ne dépend
 * pas de la façon dont un semoir est écrit. C'est aussi la vraie question
 * d'ouverture de compte : un nouveau commerçant démarre avec les semoirs, pas avec
 * la base du Cayenne.
 */
class AucuneCleDePermissionOrphelineTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string[]> clé exigée => fichiers qui la demandent */
    private function clesDemandees(): array
    {
        $sources = array_merge(
            glob(resource_path('js/router/modules/*.js')) ?: [],
            [resource_path('js/components/layouts/backend/BackendMenuComponent.vue')]
        );

        $cles = [];
        foreach ($sources as $fichier) {
            if (! is_file($fichier)) {
                continue;
            }
            $src = (string) file_get_contents($fichier);
            if (preg_match_all("/permissionUrl:\s*['\"]([^'\"]+)['\"]/", $src, $m)) {
                foreach ($m[1] as $cle) {
                    $cles[$cle][basename($fichier)] = true;
                }
            }
        }

        return array_map(fn (array $f) => array_keys($f), $cles);
    }

    /**
     * Exécute les semoirs de permissions qu'une installation NEUVE exécute, dans
     * l'ordre où `DatabaseSeeder` les appelle, puis rend ce que la table contient.
     *
     * @return string[] les `name` ET les `url` connus après amorçage
     */
    private function clesLivreesParLeSemoir(): array
    {
        $chef = database_path('seeders/DatabaseSeeder.php');
        $this->assertFileExists($chef);

        preg_match_all(
            '/\$this->call\(\s*([A-Za-z0-9_]+)::class/',
            (string) file_get_contents($chef),
            $appels
        );

        $this->assertNotEmpty(
            $appels[1],
            'Aucun appel de semoir lu dans DatabaseSeeder : la forme du fichier a changé.'
        );

        $lances = 0;
        foreach ($appels[1] as $classe) {
            $chemin = database_path("seeders/{$classe}.php");
            if (! is_file($chemin)) {
                continue;
            }
            // On n'exécute QUE les semoirs de permissions et de rôles : le reste
            // (menu, articles, images) est hors sujet et coûteux.
            $src = (string) file_get_contents($chemin);
            if (! str_contains($src, 'Permission') && ! str_contains($src, 'Role')) {
                continue;
            }

            $fqcn = "Database\\Seeders\\{$classe}";
            if (! class_exists($fqcn)) {
                continue;
            }

            $this->seed($fqcn);
            $lances++;
        }

        $this->assertGreaterThanOrEqual(
            3,
            $lances,
            "Moins de trois semoirs de permissions exécutés : l'extraction des appels\n"
            . "ne mord plus. Adapter ce banc, surtout pas le supprimer — il deviendrait\n"
            . 'vert en ne mesurant plus rien.'
        );

        $connues = array_values(array_unique(array_merge(
            DB::table('permissions')->pluck('name')->all(),
            DB::table('permissions')->whereNotNull('url')->pluck('url')->all()
        )));

        $this->assertGreaterThan(
            50,
            count($connues),
            'Moins de 50 permissions après amorçage : les semoirs n\'ont pas tourné.'
        );

        return $connues;
    }

    public function test_aucune_cle_du_routeur_ne_tombe_dans_le_repli_permissif(): void
    {
        $connues = $this->clesLivreesParLeSemoir();

        $orphelines = [];
        foreach ($this->clesDemandees() as $cle => $fichiers) {
            if (! in_array($cle, $connues, true)) {
                $orphelines[] = "'{$cle}' demandée par " . implode(', ', $fichiers);
            }
        }

        $this->assertSame(
            [],
            $orphelines,
            "Des clés de permission ne correspondent à AUCUNE permission livrée par les\n"
            . "semoirs. Le repli permissif de permission-match.js accorde alors l'accès :\n"
            . "l'entrée est proposée à TOUS les utilisateurs, qui recevront un 403 sur\n"
            . "l'API. Le menu promet ce que le serveur refuse.\n\n"
            . "Corriger la clé, ou ajouter la permission au semoir — ne PAS durcir le\n"
            . "repli, la doctrine est assumée et documentée dans permission-match.js.\n\n"
            . implode("\n", $orphelines)
        );
    }

    /**
     * Contrôle négatif : le banc doit effectivement distinguer une clé connue d'une
     * clé inventée. Sans lui, une extraction cassée le rendrait vert en ne mesurant
     * plus rien — c'est exactement le piège dans lequel il est tombé deux fois.
     */
    public function test_le_banc_distingue_une_cle_connue_d_une_cle_inventee(): void
    {
        $connues = $this->clesLivreesParLeSemoir();

        $this->assertContains(
            'dashboard',
            $connues,
            "« dashboard » est la permission la plus basique du produit : si elle manque,\n"
            . "les semoirs n'ont pas tourné et le banc ne mesure rien."
        );

        $this->assertNotContains('permission_qui_n_existe_pas', $connues);
        $this->assertNotEmpty($this->clesDemandees());
    }
}
