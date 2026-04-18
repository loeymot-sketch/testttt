<?php

namespace Tests\Feature\Fiscal;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * [POS-9-H.3.1 / F-C7]
 *
 * Mutating fiscal endpoints (`POST /admin/fiscal/z-report/{open,close}`)
 * must be rate-limited to `throttle:10,1` per authenticated user.
 *
 * Rationale: each successful `open` allocates a new
 * `z_reports.sequence_no` that is monotonic, gap-free, and signed into
 * the HMAC chain. A retry-storm (or a hostile actor) calling `open` in
 * a loop would permanently burn sequence numbers and leave audit
 * artefacts that cannot be rolled back (INSERT-only tables).
 *
 * This is a structural test — we verify the route carries the throttle
 * middleware. We deliberately do NOT hammer the endpoint with 11
 * requests because the controller has heavy side-effects (DB + HMAC)
 * that would couple the rate-limit behaviour to unrelated internals.
 */
class FiscalRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('fiscal.z_report_secret', 'unit-test-rate-limit-secret');

        $branch        = Branch::factory()->create();
        $this->manager = User::factory()->create([
            'branch_id' => $branch->id,
            'password'  => Hash::make('pwd'),
        ]);
        $this->manager->givePermissionTo('pos-manage-fiscal');
    }

    public function test_z_report_open_route_declares_throttle_middleware(): void
    {
        $route = collect(app('router')->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/admin/fiscal/z-report/open');

        $this->assertNotNull($route, 'Route /admin/fiscal/z-report/open must exist.');
        $this->assertContains('throttle:10,1', $route->gatherMiddleware(),
            'Z-report open must carry throttle:10,1 to block sequence-number burn attacks.');
    }

    public function test_z_report_close_route_declares_throttle_middleware(): void
    {
        $route = collect(app('router')->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/admin/fiscal/z-report/close');

        $this->assertNotNull($route, 'Route /admin/fiscal/z-report/close must exist.');
        $this->assertContains('throttle:10,1', $route->gatherMiddleware(),
            'Z-report close must carry throttle:10,1.');
    }

    public function test_read_endpoints_are_not_rate_limited(): void
    {
        // X-report and the Z list are GET/read-only — they must NOT
        // carry a dedicated rate-limit so a legitimate dashboard that
        // polls the latest numbers isn't throttled.
        $readRoutes = [
            'api/admin/fiscal/x-report',
            'api/admin/fiscal/z-report',
        ];

        foreach ($readRoutes as $uri) {
            $route = collect(app('router')->getRoutes())
                ->first(fn ($r) => $r->uri() === $uri);

            $this->assertNotNull($route, "Route {$uri} must exist.");
            foreach ($route->gatherMiddleware() as $mw) {
                $this->assertStringNotContainsString('throttle:10,1', (string) $mw,
                    "Read route {$uri} must not carry the mutation throttle.");
            }
        }
    }

    public function test_eleventh_open_call_is_throttled(): void
    {
        // Safety net integration: 10 consecutive open attempts by the
        // same user must receive a 429 on the 11th within a 60s window.
        // We don't need the call to succeed — 403/422/500 doesn't
        // matter, what matters is that the throttle middleware runs
        // BEFORE the controller, so even failing attempts count.
        RateLimiter::clear('throttle:10,1|' . $this->manager->id);
        $this->actingAs($this->manager, 'sanctum');

        for ($i = 1; $i <= 10; $i++) {
            $r = $this->withHeaders(['x-api-key' => config('app.api_key')])
                ->postJson('/api/admin/fiscal/z-report/open');
            $this->assertNotSame(429, $r->status(),
                "Request #{$i} should not yet be throttled.");
        }

        $r11 = $this->withHeaders(['x-api-key' => config('app.api_key')])
            ->postJson('/api/admin/fiscal/z-report/open');
        $this->assertSame(429, $r11->status(),
            'The 11th open within 60s must be throttled to 429.');
    }
}
