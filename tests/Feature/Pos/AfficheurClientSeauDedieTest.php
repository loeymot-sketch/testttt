<?php

namespace Tests\Feature\Pos;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * L'AFFICHEUR CLIENT NE DOIT PAS PARTAGER LE PLAFOND DE L'ENCAISSEMENT.
 *
 * Mesuré à la ronde 3 de l'audit superviseur (2026-08-25) : 2 réponses 429 sur
 * `POST /api/admin/pos/customer-display`, seules requêtes en échec de tout le jeu de
 * captures de la caisse.
 *
 * Cause : la route tombait dans le seau `api/admin/pos/*` (120/min), PARTAGÉ avec toutes
 * les mutations de la caisse. Chaque changement de panier émet deux requêtes — le calcul
 * du prix et cette poussée — donc à un article par seconde le plafond est atteint.
 *
 * Conséquence côté client : l'échec est avalé en silence (`.catch(() => {})`), le caissier
 * ne voit rien, la trace fiscale est intacte — mais l'AFFICHEUR reste sur le montant
 * précédent. Si c'est la dernière poussée de la vente qui est refusée, le client lit un
 * total qui n'est pas le sien pendant qu'il paie.
 *
 * Le piège que ces tests gardent : un `throttle:` ajouté sur une route d'un groupe qui en
 * porte déjà un les EMPILE — le plus strict gagne, et le seau dédié ne sert à rien. C'est
 * exactement l'erreur que j'ai commise au premier jet ; `route:list -v` montrait les deux.
 */
class AfficheurClientSeauDedieTest extends TestCase
{
    private function limiteursDeLaRoute(string $nom): array
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->getName() === $nom
        );
        $this->assertNotNull($route, "route « {$nom} » introuvable");

        // On passe par le RÉSOLVEUR du routeur, pas par `gatherMiddleware()` : ce dernier
        // rend le middleware DÉCLARÉ, sans appliquer les exclusions. Un premier jet de ce
        // test lisait `gatherMiddleware()` et rougissait sur un correctif pourtant correct
        // — `route:list -v`, lui, montrait bien un seul limiteur. C'est le résolveur qui
        // dit la vérité de l'exécution.
        $resolus = app('router')->gatherRouteMiddleware($route);

        $limiteurs = [];
        foreach ($resolus as $m) {
            if (! is_string($m)) {
                continue;
            }
            // Après résolution, les alias sont remplacés par la classe : « ...ThrottleRequests:nom ».
            if (preg_match('/ThrottleRequests(?:WithRedis)?:(.+)$/', $m, $c)) {
                $limiteurs[] = $c[1];
            } elseif (str_starts_with($m, 'throttle:')) {
                $limiteurs[] = substr($m, strlen('throttle:'));
            }
        }

        return $limiteurs;
    }

    /** @test */
    public function la_route_porte_son_seau_dedie(): void
    {
        $this->assertContains(
            'customer-display',
            $this->limiteursDeLaRoute('admin.pos.customer-display.update'),
            'la poussée vers l\'afficheur client ne porte plus son seau dédié'
        );
    }

    /**
     * LE PIÈGE : sans `withoutMiddleware`, les deux seaux s'empilent et le plus strict gagne.
     *
     * @test
     */
    public function le_seau_des_mutations_n_est_pas_empile_par_dessus(): void
    {
        $limiteurs = $this->limiteursDeLaRoute('admin.pos.customer-display.update');

        $this->assertNotContains(
            'admin-mutation',
            $limiteurs,
            'RÉGRESSION : `admin-mutation` est de retour SUR la route. Empilé avec le seau '
            . 'dédié, c\'est le plus strict qui gagne (120/min) — le seau dédié devient '
            . 'décoratif et le 429 revient exactement comme avant. Il faut '
            . '`->withoutMiddleware(\'throttle:admin-mutation\')`.'
        );

        // `api` — le plafond de plateforme, par utilisateur ET par appareil — s'applique
        // légitimement à TOUTE route d'API et n'a pas à être retiré : c'est le dernier
        // rempart. Ce qu'on interdit ici, c'est le seau CRUD, dont la branche « caisse »
        // rendait 120/min et coupait avant tous les autres.
        $this->assertSame(
            ['api', 'customer-display'],
            collect($limiteurs)->sort()->values()->all(),
            'les limiteurs appliqués ont changé — attendus : le plafond de plateforme et le '
            . 'seau dédié, rien d\'autre. Vus : ' . implode(', ', $limiteurs)
        );
    }

    /** @test */
    public function le_plafond_est_reglable_et_couvre_la_cadence_reelle(): void
    {
        $plafond = (int) config('pos.rate_limit.customer_display');

        // La caisse freine l'émission à 350 ms (anti-rebond dans PosComponent), soit 171/min
        // au grand maximum théorique pour un écran. Le plafond doit couvrir ça avec de la
        // marge — sinon on a déplacé le défaut, pas réparé.
        $this->assertGreaterThanOrEqual(
            171,
            $plafond,
            'le plafond dédié est SOUS le maximum théorique d\'un seul écran (171/min à '
            . '350 ms d\'anti-rebond) : le 429 retombera en coup de feu.'
        );

        // Et il doit rester UN plafond : une boucle emballée doit rester bornée.
        $this->assertLessThanOrEqual(1000, $plafond, 'plafond trop haut pour border une boucle emballée');
    }

    /** @test */
    public function les_routes_d_encaissement_gardent_leur_propre_plafond(): void
    {
        // On retire l'afficheur du seau des mutations — on ne doit RIEN avoir desserré
        // pour le paiement, qui est la vraie raison d'être de ce plafond.
        foreach (['admin.pos.orders.print-receipt', 'admin.pos.orders.print-kitchen'] as $nom) {
            $route = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === $nom);
            if ($route === null) {
                continue;
            }
            $this->assertContains(
                'admin-mutation',
                $this->limiteursDeLaRoute($nom),
                "« {$nom} » a perdu son plafond de mutation — le retrait a débordé de sa cible."
            );
        }
    }
}
