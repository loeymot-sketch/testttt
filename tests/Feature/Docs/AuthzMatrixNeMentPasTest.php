<?php

namespace Tests\Feature\Docs;

use Tests\TestCase;

/**
 * Sentinelle — `docs/AUTHZ_MATRIX.md` ne doit nommer que des rôles et des permissions
 * qui existent.
 *
 * LE DÉFAUT, relevé le 2026-10-01 : le document affirmait depuis des mois
 *   - un rôle « Manager » — inexistant ; les vrais sont `Branch Manager` et `POS Operator` ;
 *   - une permission « pos-apply-discount » — inexistante ; la remise est découpée en trois
 *     paliers (`pos-discount-up-to-10`, `...-over-10-requires-manager`, `...-unlimited`) ;
 *   - « OSS Screen — api-key uniquement — /api/admin/oss-order », qui nommait le chemin ADMIN
 *     en décrivant l'authentification du chemin PUBLIC.
 *
 * C'est le contrat de référence cité pour tout refactoring IAM : on s'y fie pour écrire des
 * gardes. Une garde posée sur un rôle qui n'existe pas ne s'applique à personne et ne lève
 * aucune erreur — elle passe pour une protection.
 *
 * Le contrôle est STATIQUE (aucune base, aucun seeder à jouer) : il lit les noms que le
 * document met entre accents graves dans ses tableaux de permissions et de rôles, et exige
 * que chacun apparaisse dans les seeders. Il ne fige aucune formulation : on peut réécrire
 * le document librement tant qu'on n'y invente pas d'acteur.
 */
class AuthzMatrixNeMentPasTest extends TestCase
{
    private const DOC = 'docs/AUTHZ_MATRIX.md';

    /** Les huit rôles réels, tels que les déclare RoleTableSeeder. */
    private const SEEDERS_ROLES = 'database/seeders/RoleTableSeeder.php';

    /** Répertoire des seeders : une permission peut être créée par plusieurs d'entre eux. */
    private const SEEDERS_DIR = 'database/seeders';

    private function lire(string $chemin): string
    {
        $complet = base_path($chemin);
        $this->assertFileExists($complet, "Fichier attendu absent : {$chemin}");

        return (string) file_get_contents($complet);
    }

    /**
     * Le document sans ses CONTRE-EXEMPLES.
     *
     * Il cite nommément les acteurs qui n'existent pas — « ⛔ Il n'existe pas de permission
     * `pos-apply-discount` » — et c'est précisément ce qui empêchera de les réintroduire.
     * Une sentinelle littérale attrapait donc ses propres avertissements.
     *
     * Convention retenue, parce qu'elle se lit à l'œil nu dans le document : une ligne
     * portant ⛔ énonce ce qui n'existe PAS et n'est pas inspectée. Les tableaux, eux,
     * n'en portent jamais.
     */
    private function lireSansContreExemples(string $chemin): string
    {
        $lignes = array_filter(
            explode("\n", $this->lire($chemin)),
            static fn (string $l): bool => ! str_contains($l, '⛔'),
        );

        return implode("\n", $lignes);
    }

    /** Tout le texte des seeders, concaténé — une permission peut venir de n'importe lequel. */
    private function texteDesSeeders(): string
    {
        $texte = '';
        foreach (glob(base_path(self::SEEDERS_DIR) . '/*.php') ?: [] as $fichier) {
            $texte .= (string) file_get_contents($fichier);
        }

        return $texte;
    }

    /** @test */
    public function il_ne_nomme_aucune_permission_inexistante(): void
    {
        $doc = $this->lireSansContreExemples(self::DOC);
        $seeders = $this->texteDesSeeders();

        // Les noms de permission du document : en accents graves, forme `famille-action`
        // ou `famille.action` ou `famille_action`. On ignore les chemins (« /api/... »),
        // le code PHP et les noms de classe, qui ne sont pas des permissions.
        preg_match_all('/`([a-z][a-z0-9]*(?:[-._][a-z0-9]+)+)`/', $doc, $m);

        $candidats = array_unique($m[1]);
        $inexistantes = [];

        foreach ($candidats as $nom) {
            // On ne juge que ce qui ressemble à une permission du domaine : les familles
            // réellement utilisées. Sinon on attraperait « storage/installed » ou un
            // nom de fichier, et la sentinelle crierait au loup.
            if (! preg_match('/^(pos|catalog|cash|order|items?|ingredients|availability|settings|dining|kiosk)[-._]/', $nom)) {
                continue;
            }
            if (! str_contains($seeders, "'{$nom}'") && ! str_contains($seeders, "\"{$nom}\"")) {
                $inexistantes[] = $nom;
            }
        }

        $this->assertSame(
            [],
            $inexistantes,
            "docs/AUTHZ_MATRIX.md nomme des permissions qu'aucun seeder ne crée : "
            . implode(', ', $inexistantes) . ".\n"
            . "C'est le contrat cité pour écrire les gardes IAM. Une garde posée sur une "
            . "permission inexistante ne protège rien et ne lève aucune erreur.\n"
            . 'Vérifier avec : php artisan tinker --execute="echo implode(chr(10), '
            . 'DB::table(\'permissions\')->pluck(\'name\')->toArray());"',
        );
    }

    /** @test */
    public function il_ne_nomme_aucun_role_inexistant(): void
    {
        $doc = $this->lireSansContreExemples(self::DOC);
        $roles = $this->lire(self::SEEDERS_ROLES);

        // Les rôles du domaine, tels qu'ils peuvent apparaître dans le document.
        $connus = ['Admin', 'Branch Manager', 'POS Operator', 'Chef', 'Waiter', 'Stuff',
            'Customer', 'Delivery Boy', 'Tenant Admin'];

        foreach ($connus as $role) {
            if (! str_contains($doc, "`{$role}`")) {
                continue; // le document n'est pas obligé de tous les citer
            }
            $this->assertStringContainsString(
                $role,
                $roles,
                "Le document cite le rôle « {$role} » : il doit exister dans RoleTableSeeder.",
            );
        }

        // Le piège historique, nommément : « Manager » tout court n'existe pas.
        $this->assertDoesNotMatchRegularExpression(
            '/`Manager`/',
            $doc,
            "docs/AUTHZ_MATRIX.md cite un rôle « Manager » qui n'existe pas. Les deux rôles "
            . "réels sont `Branch Manager` (le gérant) et `POS Operator` (la caisse) — ils "
            . "n'ont pas les mêmes permissions, et les confondre fait écrire des gardes qui "
            . 'ne s\'appliquent à personne.',
        );
    }

    /** @test */
    public function il_ne_confond_pas_la_surface_oss_publique_et_la_surface_admin(): void
    {
        $doc = $this->lire(self::DOC);

        // `/api/admin/oss-order` est gardé (`permission:order-status-screen`) ; c'est
        // `/api/frontend/oss-order` qui est public. Le document doit nommer les deux,
        // sinon il décrit l'un avec l'authentification de l'autre — ce qu'il faisait.
        $this->assertStringContainsString('/api/frontend/oss-order', $doc,
            'Le document doit nommer la surface OSS PUBLIQUE (`/api/frontend/oss-order`), '
            . 'sans quoi il laisse croire que la route admin est ouverte par simple clé d\'API.');
        $this->assertStringContainsString('permission:order-status-screen', $doc,
            'Le document doit dire que `/api/admin/oss-order` est gardé par '
            . '`permission:order-status-screen`.');
    }
}
