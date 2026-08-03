<?php

namespace Tests\Feature\Sentinels;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * @FK-ID FK-BLUE-2026-05-08-B3-S5-P1 | @source HEAL-B mission B3 SECURITY P1 (récurrent 3×)
 * @reason
 *   /api/admin/menu/availability/toggle partageait le bucket throttle:admin-mutation
 *   (30 req/min) avec POST /api/admin/pos. Pendant un rush, un caissier qui toggle
 *   un item OOS puis submit une commande peut atteindre la limite et hit 429
 *   (self-DoS). On extrait UNIQUEMENT le toggle availability dans un groupe
 *   sibling avec une limite dédiée — bucket dédié, indépendant du bucket
 *   admin-mutation.
 *
 *   IMPORTANT : le groupe doit être SIBLING, pas imbriqué. Si on imbrique
 *   `->middleware('throttle:menu-availability')` à l'intérieur du groupe
 *   `throttle:admin-mutation`, Laravel additionne les deux et la limite effective
 *   devient min(admin-mutation-cap, menu-availability-cap). Cette assertion
 *   négative est la garantie load-bearing.
 *
 *   [GOAL Phase F.1 2026-05-23] Bucket renommé `throttle:60,1` →
 *   `throttle:menu-availability` (named limiter, env-driven via
 *   MENU_AVAILABILITY_RATE_LIMIT). Default 60/min préservé pour
 *   backwards-compat ; local dev raise à 1000/min pour absorber le bulk-86
 *   fan-out depuis /admin/stock-rupture-dashboard.
 *
 * Récurrent RED-R3 → ORCHESTRATOR → B3 — sentinel anti-régression obligatoire.
 */
class AvailabilityToggleSeparateThrottleSentinelTest extends TestCase
{
    public function test_availability_toggle_route_uses_dedicated_throttle_60_per_minute(): void
    {
        $route = collect(Route::getRoutes())
            ->first(fn ($candidate) => $candidate->uri() === 'api/admin/menu/availability/toggle'
                && in_array('POST', $candidate->methods(), true));

        $this->assertNotNull(
            $route,
            'Expected /api/admin/menu/availability/toggle to be registered as a POST route.'
        );

        $middleware = $route->gatherMiddleware();

        // [GOAL Phase F.1 2026-05-23] Accept either the legacy hardcoded
        // `throttle:60,1` or the new named limiter `throttle:menu-availability`.
        // Either form keeps the sibling bucket isolated from admin-mutation
        // (the load-bearing property covered by the negative assertion below).
        $hasDedicatedBucket = in_array('throttle:60,1', $middleware, true)
            || in_array('throttle:menu-availability', $middleware, true);

        $this->assertTrue(
            $hasDedicatedBucket,
            'Expected the toggle route to declare a dedicated throttle bucket — '
            . 'either the legacy `throttle:60,1` form or the named `throttle:menu-availability` form.'
        );
    }

    public function test_availability_toggle_route_does_not_share_admin_mutation_throttle(): void
    {
        // Load-bearing negative assertion : si la route reste sous throttle:admin-mutation
        // (groupe imbriqué au lieu de sibling), Laravel stack les buckets et la limite
        // effective devient min(30, 60) = 30/min — le self-DoS n'est PAS résolu.
        $route = collect(Route::getRoutes())
            ->first(fn ($candidate) => $candidate->uri() === 'api/admin/menu/availability/toggle'
                && in_array('POST', $candidate->methods(), true));

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

        $this->assertNotContains(
            'throttle:admin-mutation',
            $middleware,
            'The toggle route MUST NOT share the admin-mutation bucket — '
            . 'placer le groupe en sibling (pas imbriqué) pour que la limite effective '
            . 'soit bien 60/min et pas min(30, 60) = 30/min.'
        );
    }

    public function test_pos_store_route_still_uses_admin_mutation_throttle(): void
    {
        // Anti-régression positive : /api/admin/pos doit garder admin-mutation
        // (autres mutations admin sensibles non concernées par ce fix).
        $route = collect(Route::getRoutes())
            ->first(fn ($candidate) => $candidate->uri() === 'api/admin/pos'
                && in_array('POST', $candidate->methods(), true));

        if ($route === null) {
            // Si la route n'existe pas dans cette config (ex. désactivée par un flag),
            // on ne fait pas échouer — l'assertion principale reste suffisante.
            $this->markTestSkipped('POST /api/admin/pos route not registered in this app config.');
        }

        $middleware = $route->gatherMiddleware();
        $this->assertContains(
            'throttle:admin-mutation',
            $middleware,
            'POST /api/admin/pos must keep its admin-mutation bucket — '
            . 'le fix B3 ne doit toucher QUE le toggle availability.'
        );
    }
}
