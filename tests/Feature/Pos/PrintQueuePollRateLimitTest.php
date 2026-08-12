<?php

namespace Tests\Feature\Pos;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [429 EN SERVICE 2026-08-13 · owner « beaucoup d'erreur trop de request »] Le sondage des files
 * d'impression ne doit plus vider le seau du CRUD administrateur.
 *
 * CE QUI ARRIVAIT EN SERVICE
 * --------------------------
 * Deux files d'impression (ticket promo, ticket cuisine) demandent leur travail toutes les 5 s,
 * soit 12 POST/min chacune, et elles tournent sur TOUS les écrans d'administration ouverts — le PC
 * caisse en garde souvent plusieurs. Ces POST tombaient dans `admin-mutation`, plafonné à 60/min
 * et prévu pour du CRUD. Deux onglets = 48 POST/min de sondage PUR, trois = 72/min : le plafond
 * était vidé avant qu'un caissier ne touche à quoi que ce soit.
 *
 * Mesuré en production le 2026-08-13 : 1130 refus sur 4746 appels de `promo-flyer/pending`.
 * L'exploitant l'a vécu comme « trop de requêtes, réessayez plus tard » pendant le service.
 *
 * CE QUE CE TEST VERROUILLE
 * -------------------------
 * On abaisse volontairement le seau CRUD à une valeur ridicule. Si les routes de sondage y étaient
 * encore rattachées, elles seraient refusées immédiatement. Le test échoue donc si quelqu'un les
 * y remet — c'est exactement la régression à empêcher.
 */
class PrintQueuePollRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $branch = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $branch->id]);
        $this->cashier->assignRole('POS Operator');
    }

    private function sonder(string $uri): int
    {
        return $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson($uri)
            ->getStatusCode();
    }

    /**
     * @test
     * La file cuisine ne doit plus EMPILER le seau du CRUD administrateur.
     *
     * Ce test regarde la FORME des intergiciels, et non le comportement, pour une raison précise
     * mesurée pendant l'audit : `admin-mutation` contient une exemption par chemin
     * (`if ($request->is('api/admin/pos/*')) return Limit::perMinute(120)`). Cette route vivant
     * sous `api/admin/pos/`, elle était déjà relevée à 120/min — un test comportemental qui
     * abaisse le seau CRUD ne pouvait donc RIEN prouver ici : il passait quelle que soit la
     * configuration des routes. Vérifié par mutation, il ne détectait pas sa propre suppression.
     *
     * Ce qu'on verrouille réellement, c'est qu'un sondage à 12 requêtes/minute par écran ouvert
     * n'ait plus aucune raison de traverser le seau du CRUD. L'assertion sur la liste des
     * intergiciels échoue si quelqu'un l'y remet — ce qui est la régression à empêcher.
     */
    public function la_file_cuisine_n_empile_plus_le_seau_du_crud_administrateur(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/admin/pos/kitchen-tickets/pending');

        $this->assertNotNull($route, 'la route de la file cuisine doit exister');

        // `gatherMiddleware()` liste encore les intergiciels EXCLUS : `withoutMiddleware()`
        // enregistre une exclusion que le routeur applique au moment de la répartition. La seule
        // source de vérité est donc `gatherRouteMiddleware()`, qui rend ce qui S'EXÉCUTE — c'est
        // aussi ce qu'affiche `route:list -v`. Assertion faite sur la mauvaise liste au premier
        // jet : elle a échoué bruyamment, ce qui est la bonne façon de se tromper.
        // …et cette liste rend des NOMS DE CLASSE résolus, pas les alias écrits dans les routes.
        $executes = app('router')->gatherRouteMiddleware($route);

        $this->assertNotContains(
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':admin-mutation',
            $executes,
            'le sondage de la file cuisine ne doit plus traverser le seau du CRUD administrateur'
        );
        $this->assertContains(
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':print-queue-poll',
            $executes,
            'il doit rester borné par sa mesure dédiée'
        );
    }

    /** @test */
    public function la_file_promo_ne_consomme_plus_le_seau_du_crud_administrateur(): void
    {
        // C'est la route qui encaissait les 1130 refus mesurés en production.
        Config::set('app.admin_mutation_rate_limit', 1);

        $codes = [];
        for ($i = 0; $i < 6; $i++) {
            $codes[] = $this->sonder('/api/admin/promo-flyer/pending');
        }

        $this->assertNotContains(
            429,
            $codes,
            'le sondage du ticket promo ne doit PAS être borné par le seau du CRUD administrateur'
        );
    }

    /**
     * @test
     * …mais un plafond demeure : on déplace la borne, on ne la supprime pas.
     *
     * Sans cette moitié, « sortir du seau CRUD » pourrait se faire en retirant toute mesure — et
     * une boucle emballée (pont d'impression en panne, écran qui redemande sans fin) taperait
     * alors sans aucune limite.
     */
    public function le_sondage_reste_borne_par_sa_propre_mesure(): void
    {
        Config::set('pos.rate_limit.print_queue_poll', 3);

        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = $this->sonder('/api/admin/pos/kitchen-tickets/pending');
        }

        $this->assertContains(
            429,
            $codes,
            'la mesure dédiée doit rester une VRAIE borne — sinon une boucle emballée tape sans fin'
        );
    }
}
