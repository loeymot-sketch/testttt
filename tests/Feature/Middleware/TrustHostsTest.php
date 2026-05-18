<?php

namespace Tests\Feature\Middleware;

use App\Http\Kernel;
use App\Http\Middleware\TrustHosts;
use ReflectionClass;
use Tests\TestCase;

/**
 * [Wave 2c P1 SYNC-ADV3B-01 heal 2026-05-18]
 *
 * Asserts the TrustHosts whitelist defense vs Host header spoof.
 *
 * BACKGROUND: Wave 2b commit `79e214542` set
 * `TrustProxies::$proxies = '*'` to fix per-IP throttle isolation
 * behind nginx. That change also opens X-Forwarded-Host /
 * X-Forwarded-Proto to be trusted from ANY upstream. The companion
 * defense — Laravel's TrustHosts middleware — was commented out in
 * `Kernel::$middleware`, leaving `Request::getHost()` and
 * `Request::getScheme()` poisonable by an attacker who can reach the
 * proxy. Poisoned host propagates into `URL::route()`, password reset
 * links, signed URLs, and `abort_unless($request->getHost() == ...)`
 * checks.
 *
 * The fix:
 *  1) Uncomments `\App\Http\Middleware\TrustHosts::class` in
 *     `Kernel::$middleware` (runs as global middleware before routing).
 *  2) Extends `TrustHosts::hosts()` to return the APP_URL subdomain
 *     regex plus loopback (`127.0.0.1`, `localhost`) for local
 *     single-restaurant deployment.
 *
 * Why a unit-test on `hosts()` rather than an integration test on
 * spoofed Host headers: vendor `Illuminate\Http\Middleware\TrustHosts`
 * short-circuits via `shouldSpecifyTrustedHosts()` whenever
 * `app()->runningUnitTests()` is true (and under `local` env), so the
 * `Request::setTrustedHosts()` call never fires in PHPUnit. Testing
 * the closure-list directly defends the intent without re-testing
 * Symfony's `HttpFoundation\Request::isFromTrustedProxy()` chain.
 */
class TrustHostsTest extends TestCase
{
    /**
     * SYNC-ADV3B-01: hosts() returns APP_URL regex + loopback whitelist.
     *
     * Test A: app-url-derived regex is present (defends against
     *         a generic vendor regression).
     */
    public function test_hosts_includes_app_url_subdomain_pattern(): void
    {
        config(['app.url' => 'http://lecayenne.local']);

        $middleware = $this->app->make(TrustHosts::class);
        $hosts = $middleware->hosts();

        // Vendor builds: ^(.+\.)?lecayenne\.local$
        $appUrlRegex = collect($hosts)
            ->filter(fn ($h) => is_string($h) && str_starts_with($h, '^'))
            ->first();

        $this->assertNotNull(
            $appUrlRegex,
            'TrustHosts::hosts() must include the APP_URL-derived regex '
            .'from allSubdomainsOfApplicationUrl().'
        );

        $this->assertMatchesRegularExpression(
            '/'.$appUrlRegex.'/',
            'lecayenne.local',
            'APP_URL host must match its own subdomain regex.'
        );

        $this->assertMatchesRegularExpression(
            '/'.$appUrlRegex.'/',
            'admin.lecayenne.local',
            'Subdomain of APP_URL host must match.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/'.$appUrlRegex.'/',
            'attacker.com',
            'SYNC-ADV3B-01: arbitrary external host must NOT match the '
            .'APP_URL regex — confirms host spoof defense is wired.'
        );
    }

    /**
     * Test B: loopback hosts are whitelisted for local
     * single-restaurant deployment behind nginx.
     */
    public function test_hosts_whitelists_loopback(): void
    {
        $middleware = $this->app->make(TrustHosts::class);
        $hosts = $middleware->hosts();

        $this->assertContains(
            '127.0.0.1',
            $hosts,
            'TrustHosts::hosts() must whitelist 127.0.0.1 for nginx '
            .'loopback / local PHP-FPM deployment.'
        );

        $this->assertContains(
            'localhost',
            $hosts,
            'TrustHosts::hosts() must whitelist localhost for dev / '
            .'CLI / artisan serve usage.'
        );
    }

    /**
     * Test C: TrustHosts is registered in Kernel::$middleware
     * (proves the uncomment in Kernel.php).
     *
     * Without this assertion, hosts() could be perfect but the
     * middleware would never run.
     */
    public function test_trust_hosts_is_registered_as_global_middleware(): void
    {
        $kernel = new Kernel($this->app, $this->app['router']);

        $reflection = new ReflectionClass($kernel);
        $property = $reflection->getProperty('middleware');
        $property->setAccessible(true);
        $middleware = $property->getValue($kernel);

        $this->assertContains(
            TrustHosts::class,
            $middleware,
            'SYNC-ADV3B-01: \\App\\Http\\Middleware\\TrustHosts::class '
            .'must be uncommented in Kernel::$middleware. Without it, '
            .'the whitelist never executes and X-Forwarded-Host spoof '
            .'reaches Request::getHost() unmediated.'
        );
    }
}
