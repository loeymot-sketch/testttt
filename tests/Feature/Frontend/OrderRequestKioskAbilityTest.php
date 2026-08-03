<?php

namespace Tests\Feature\Frontend;

use App\Http\Requests\OrderRequest;
use App\Models\Branch;
use App\Models\User;
use App\Enums\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * [Sprint H1 K-002 2026-05-17] OrderRequest::authorize() — tighten the
 * "tokenless" fallback so it cannot be reused at an unintended endpoint.
 *
 * Context (cf. iter15-P0-08 comment block in OrderRequest.php):
 *   Production HTTP requests to /api/frontend/order are wrapped by Sanctum's
 *   guard. The "no current access token" path effectively only fires for
 *   test fixtures using $this->actingAs($user, 'sanctum') — Sanctum's
 *   __invoke is bypassed when the user is pre-set on the guard, so
 *   currentAccessToken() returns null. The historical fail-open `return true`
 *   was too broad for defense-in-depth: it allowed *any* authenticated
 *   guard caller on *any* route bound to OrderRequest to pass.
 *
 * The new gate requires BOTH:
 *   - An authenticated session via web OR sanctum guard
 *   - The route name starts with `frontend.order.` (the only legitimate
 *     mount point of OrderRequest).
 *
 * These unit tests call authorize() directly with stubbed user/route
 * resolvers — the HTTP path is already covered by OrderRouteAbilityTest.
 * Coverage rationale:
 *   - case 1: session-auth on frontend.order.* route → true (backward compat)
 *   - case 2: session-auth on a non-frontend.order.* route → false (defense)
 *   - case 3: no user at all → false (already covered, sanity check)
 *
 * CLAUDE.md §9 (multi-tenant + auth invariants).
 */
class OrderRequestKioskAbilityTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function test_tokenless_session_user_on_frontend_order_route_passes_authorize(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);

        $request = $this->makeRequestFor('frontend.order.store');
        $request->setUserResolver(fn () => $user);

        // Simulate $this->actingAs($user, 'sanctum') — user is on the
        // sanctum guard but no PersonalAccessToken minted; the production
        // SPA path (TransientToken) is impossible here because Sanctum's
        // __invoke wraps web-guard users with TransientToken (non-null).
        auth()->guard('sanctum')->setUser($user);

        $this->assertTrue(
            $request->authorize(),
            'Tokenless session-auth on frontend.order.* must pass (backward-compat with test fixtures).'
        );
    }

    /** @test */
    public function test_tokenless_session_user_on_unrelated_route_is_rejected(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);

        // Simulate hypothetical reuse of OrderRequest at a non-frontend.order
        // endpoint — the route-namespace check denies the fail-open scope.
        $request = $this->makeRequestFor('admin.report.export');
        $request->setUserResolver(fn () => $user);

        auth()->guard('sanctum')->setUser($user);

        $this->assertFalse(
            $request->authorize(),
            'Tokenless session-auth on a non-frontend.order.* route must NOT fail-open.'
        );
    }

    /** @test */
    public function test_no_user_is_rejected(): void
    {
        $request = $this->makeRequestFor('frontend.order.store');
        $request->setUserResolver(fn () => null);

        $this->assertFalse(
            $request->authorize(),
            'No authenticated user must always be rejected (regression guard).'
        );
    }

    /**
     * Build an OrderRequest with a route resolver returning a route whose
     * name matches $routeName. We don't go through the HTTP kernel — the
     * goal is to exercise authorize() in isolation.
     */
    private function makeRequestFor(string $routeName): OrderRequest
    {
        $request = OrderRequest::create('/api/frontend/order', 'POST');
        $route = (new Route(['POST'], '/api/frontend/order', []))->name($routeName);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }
}
