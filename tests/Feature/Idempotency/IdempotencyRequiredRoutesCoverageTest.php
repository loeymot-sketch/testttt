<?php

namespace Tests\Feature\Idempotency;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * [GOAL-CMS-2026-05-18 C-P0-H sentinel — Round 3 T-1.4.2 Sec S-1]
 *
 * Header-Omission Bypass sentinel: every route declared with `idempotency`
 * middleware MUST also appear in `config('idempotency.required_routes')`,
 * otherwise the middleware silently pass-throughs when the X-Idempotency-Key
 * header is omitted (`IdempotencyKeyMiddleware:58 isRouteRequired` check).
 *
 * Fail mode if missing: replay-as-retry (double charge / double refund /
 * double cash-drawer-open).
 *
 * Acceptance: 0 routes wired to `idempotency` middleware but not in the
 * required_routes list.
 *
 * @group idempotency
 * @group sentinel
 */
class IdempotencyRequiredRoutesCoverageTest extends TestCase
{
    public function test_every_idempotency_wired_route_appears_in_required_routes(): void
    {
        $required = config('idempotency.required_routes', []);
        $this->assertNotEmpty($required, 'config(idempotency.required_routes) must be populated');

        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (! in_array('idempotency', $middleware, true)) {
                continue;
            }
            // Only POST/PUT/PATCH/DELETE — read methods don't need idempotency
            $methods = array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods());
            if (empty($methods)) {
                continue;
            }

            $uri = $route->uri();
            if (! $this->matchesAnyPattern($uri, $required)) {
                $missing[] = $uri;
            }
        }

        $this->assertEmpty(
            $missing,
            sprintf(
                "Routes with `idempotency` middleware NOT in config('idempotency.required_routes'):\n  - %s\n"
                . "If a route is intentionally opt-in (back-compat), add it to the allow-list explicitly.\n"
                . "Otherwise add it to config('idempotency.required_routes').",
                implode("\n  - ", $missing)
            )
        );
    }

    private function matchesAnyPattern(string $uri, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $regex = '#^' . str_replace('\*', '[^/]+', preg_quote($pattern, '#')) . '(/.*)?$#';
            // Laravel route URIs use {param} placeholders, e.g. 'api/admin/orders/{order}/print-receipt'
            // The pattern 'api/admin/orders/*/print-receipt' should match. Convert URI {param} → *
            $normalized = preg_replace('/\{[^}]+\}/', '*', $uri);
            $regex2 = '#^' . str_replace('\*', '[^/]+', preg_quote($pattern, '#')) . '$#';
            if (preg_match($regex2, $normalized)) {
                return true;
            }
        }
        return false;
    }
}
