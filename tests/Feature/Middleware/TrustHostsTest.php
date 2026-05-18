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
     *
     * [Wave 3c P0 SYNC-ADV3C-01 heal] The whitelist entries MUST be
     * anchored regex strings (^...$). Plain `'127.0.0.1'` /
     * `'localhost'` were exploitable because Symfony wraps each entry
     * as `{%s}i` and uses unanchored `preg_match`, admitting
     * `127X0X0X1`, `attacker-localhost.com`, etc.
     */
    public function test_hosts_whitelists_loopback_with_anchored_regex(): void
    {
        $middleware = $this->app->make(TrustHosts::class);
        $hosts = $middleware->hosts();

        $this->assertContains(
            '^127\.0\.0\.1$',
            $hosts,
            'SYNC-ADV3C-01: TrustHosts::hosts() must whitelist '
            .'127.0.0.1 with anchors `^...$`. Plain `127.0.0.1` is '
            .'wrapped by Symfony as unanchored `{127.0.0.1}i` (dot = '
            .'any char) and admits attacker hosts like `127X0X0X1`.'
        );

        $this->assertContains(
            '^localhost$',
            $hosts,
            'SYNC-ADV3C-01: TrustHosts::hosts() must whitelist '
            .'localhost with anchors `^...$`. Plain `localhost` is '
            .'wrapped by Symfony as unanchored `{localhost}i` and '
            .'admits attacker hosts like `attacker-localhost.com` or '
            .'`evil.localhost-bypass.io`.'
        );

        $this->assertContains(
            '^::1$',
            $hosts,
            'SYNC-ADV3C-03: TrustHosts::hosts() must whitelist '
            .'`::1` (IPv6 loopback) for dual-stack PHP-FPM deployment.'
        );

        $this->assertContains(
            '^0\.0\.0\.0$',
            $hosts,
            'SYNC-ADV3C-03: TrustHosts::hosts() must whitelist '
            .'`0.0.0.0` for `php artisan serve --host=0.0.0.0` and '
            .'container 0.0.0.0 binds.'
        );

        // Negative assertion: no plain unanchored loopback entry leaks.
        $this->assertNotContains(
            '127.0.0.1',
            $hosts,
            'SYNC-ADV3C-01: plain `127.0.0.1` must NOT appear in '
            .'hosts() — Symfony unanchored wrap admits spoofed hosts.'
        );

        $this->assertNotContains(
            'localhost',
            $hosts,
            'SYNC-ADV3C-01: plain `localhost` must NOT appear in '
            .'hosts() — Symfony unanchored wrap admits spoofed hosts.'
        );
    }

    /**
     * Test D: explicit Symfony-shape regression — wrap each pattern
     * as `{%s}i` (per vendor/symfony/http-foundation/Request.php:652)
     * and verify the EXACT same preg_match() Symfony will execute
     * REJECTS the empirical spoof payloads from the Wave 3c
     * adversarial report and ACCEPTS the legitimate loopback hosts.
     *
     * This is the test that would have caught SYNC-ADV3C-01 in the
     * first place: it does not stub the vendor, it reproduces
     * `setTrustedHosts()`'s wrap on the live `hosts()` return value
     * and asserts the runtime behavior.
     */
    public function test_runtime_regex_rejects_spoof_payloads(): void
    {
        $middleware = $this->app->make(TrustHosts::class);
        $hosts = $middleware->hosts();

        // Reproduce vendor/symfony/http-foundation/Request.php:652.
        $patterns = array_map(
            fn ($h) => sprintf('{%s}i', $h),
            $hosts
        );

        $matchesAny = static function (string $host) use ($patterns): bool {
            foreach ($patterns as $pattern) {
                if (@preg_match($pattern, $host) === 1) {
                    return true;
                }
            }

            return false;
        };

        // === MUST REJECT (would have been admitted by the un-anchored bug) ===
        $spoofPayloads = [
            '127X0X0X1',                  // dot-glob bypass
            'attacker-localhost.com',     // substring bypass
            'evil.localhost-bypass.io',   // subdomain substring bypass
            'real-127a0a0a1.com',         // dot-glob substring bypass
            'localhost.attacker.com',     // suffix-of-substring bypass
            'attacker.com',               // unrelated
            '',                           // empty (Symfony's empty-host fallback)
        ];

        foreach ($spoofPayloads as $spoof) {
            $this->assertFalse(
                $matchesAny($spoof),
                sprintf(
                    'SYNC-ADV3C-01: TrustHosts whitelist must REJECT spoof '
                    .'payload "%s" — would have been admitted by the '
                    .'unanchored regex bug.',
                    $spoof
                )
            );
        }

        // === MUST ACCEPT (legitimate loopback) ===
        $legitHosts = [
            '127.0.0.1',
            'localhost',
            '::1',
            '0.0.0.0',
        ];

        foreach ($legitHosts as $legit) {
            $this->assertTrue(
                $matchesAny($legit),
                sprintf(
                    'TrustHosts whitelist must ACCEPT legitimate '
                    .'loopback host "%s".',
                    $legit
                )
            );
        }
    }

    /**
     * Test E: legitimate APP_URL host still resolves under the
     * runtime wrap, ensuring the heal didn't accidentally break the
     * `allSubdomainsOfApplicationUrl()` path.
     */
    public function test_runtime_regex_accepts_app_url_hosts(): void
    {
        config(['app.url' => 'http://lecayenne.local']);

        $middleware = $this->app->make(TrustHosts::class);
        $hosts = $middleware->hosts();

        $patterns = array_map(
            fn ($h) => sprintf('{%s}i', $h),
            $hosts
        );

        $matchesAny = static function (string $host) use ($patterns): bool {
            foreach ($patterns as $pattern) {
                if (@preg_match($pattern, $host) === 1) {
                    return true;
                }
            }

            return false;
        };

        $this->assertTrue(
            $matchesAny('lecayenne.local'),
            'APP_URL host must match.'
        );

        $this->assertTrue(
            $matchesAny('admin.lecayenne.local'),
            'Subdomain of APP_URL host must match.'
        );

        $this->assertFalse(
            $matchesAny('attacker-lecayenne.local'),
            'Adjacent label of APP_URL host must NOT match — anchors '
            .'enforce subdomain boundary.'
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
