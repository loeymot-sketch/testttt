<?php

namespace Tests\Feature\Sentinels;

use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * [abuse-heal 2026-06-20 W6r3 phantom-route-action class] Systemic guard.
 *
 * A route declared as `[Controller::class, 'method']` whose method does NOT exist on the controller
 * does not fail at boot — it hard-500s (BadMethodCallException from the ControllerDispatcher) when
 * the verb is hit, and can sit behind an under-gated verb (broken access control masked by the
 * crash). This bug class produced THREE live 500s missed by every functional test:
 * OnlineOrderController@destroy, AdminTableOrderController@destroy, Frontend\MessageController@update.
 *
 * This sentinel walks the entire registered route table and asserts every controller-action method
 * exists, so a future dead route (or a renamed/removed handler) fails CI loudly instead of 500ing
 * in production. Complements ControllerMiddlewareOnlyMethodsExistSentinelTest (which guards the
 * ->only()/->except() GATE method names, a different surface).
 *
 * @group sentinel
 * @group security
 */
class RouteActionMethodsExistSentinelTest extends TestCase
{
    public function test_every_route_controller_action_references_a_real_method(): void
    {
        $phantoms = [];
        $checked = 0;

        /** @var IlluminateRoute $route */
        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            // Skip closures and invokable single-action controllers (no @method form).
            if (! is_string($action) || ! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);
            $verb = implode('|', $route->methods());
            $uri = $route->uri();

            if (! class_exists($class)) {
                $phantoms[] = "{$verb} {$uri} -> {$class} (class does not exist)";
                continue;
            }

            $checked++;
            if (! method_exists($class, $method)) {
                $phantoms[] = "{$verb} {$uri} -> {$class}@{$method} (method does not exist)";
            }
        }

        $this->assertGreaterThan(100, $checked, 'Sentinel inspected too few routes — harness broken.');
        $this->assertSame(
            [],
            $phantoms,
            "A route action references a NON-EXISTENT controller method — the route hard-500s "
            . "(BadMethodCallException) on dispatch, and may sit behind an under-gated verb. "
            . 'Phantoms: ' . implode(' | ', $phantoms)
        );
    }
}
