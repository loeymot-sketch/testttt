<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [SEC E-006 2026-08-25] Demo login credentials must NEVER be serialised into
 * served HTML.
 *
 * `master.blade.php` used to emit the demo block as a JAVASCRIPT ternary:
 *
 *     demo: @json((bool) config('app.demo_mode')) ? { ...credentials... } : null
 *
 * The flag was read server-side, but the GUARD was not: Blade rendered
 * `demo: false ? {…} : null`, so the whole credential object was still written
 * out, verbatim, into the HTML of EVERY page extending the master layout —
 * including `/admin/order-status-screen`, the customer-facing order wall that
 * hangs in the dining room, and `/admin/pos`.
 *
 * A second copy leaked through `LoginComponent.vue`, whose `setupCredit()`
 * carried hardcoded `|| '<literal>'` fallbacks that webpack compiled straight
 * into the public `js/app.js` bundle — defeating the server-side flag entirely.
 *
 * These tests pin the gate at the BLADE level: when demo mode is off (and
 * unconditionally in production), the block must be ABSENT from the markup —
 * not hidden by CSS, not neutralised by JavaScript.
 *
 * NOTE: no credential literal appears in this file on purpose. The expected
 * values are read back from config at runtime, so this test never becomes a
 * new place where the secrets are written down.
 */
class DemoCredentialsNotServedInHtmlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Surfaces rendered through the master layout. `/admin/order-status-screen`
     * is the sharpest of the three: it is the screen customers look at.
     */
    private const SURFACES = [
        '/login',
        '/admin/order-status-screen',
        '/admin/pos',
    ];

    /** Every configured demo secret (non-empty ones only). */
    private function demoSecrets(): array
    {
        return array_values(array_filter(
            array_map(
                static fn (string $key): string => (string) config("app.demo_credentials.{$key}"),
                [
                    'admin_password',
                    'customer_password',
                    'branch_manager_password',
                    'pos_operator_password',
                    'chef_password',
                ]
            ),
            static fn (string $value): bool => $value !== ''
        ));
    }

    /**
     * Assert the response really rendered the master layout before asserting on
     * its contents — otherwise a redirect or a 500 would make this test pass
     * vacuously and the gate would rot silently.
     */
    private function assertSurfaceIsClean(string $surface): void
    {
        $response = $this->get($surface);
        $response->assertStatus(200);

        $html = $response->getContent();

        $this->assertStringContainsString(
            'window.__FOODKING_RUNTIME__',
            $html,
            "{$surface} did not render the master layout runtime block; this test would "
            .'otherwise pass without proving anything.'
        );

        foreach ($this->demoSecrets() as $index => $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $html,
                "A configured demo secret (#{$index}) is served in clear text in the HTML of {$surface}."
            );
        }

        foreach (['adminPassword', 'posOperatorPassword', 'chefPassword', 'customerPassword'] as $key) {
            $this->assertStringNotContainsString(
                $key,
                $html,
                "The demo credential key `{$key}` is serialised into the HTML of {$surface}."
            );
        }
    }

    /** @test Demo mode OFF: no credential may reach any served page. */
    public function demo_credentials_are_absent_from_html_when_demo_mode_is_off(): void
    {
        Config::set('app.demo_mode', false);

        foreach (self::SURFACES as $surface) {
            $this->assertSurfaceIsClean($surface);
        }
    }

    /** @test Production never serves the demo block, even if the flag is left on. */
    public function demo_credentials_are_absent_from_html_in_production_even_with_flag_on(): void
    {
        $this->app['env'] = 'production';
        Config::set('app.demo_mode', true);

        foreach (self::SURFACES as $surface) {
            $this->assertSurfaceIsClean($surface);
        }
    }

    /**
     * @test The legitimate dev demo mode still works when explicitly enabled.
     *
     * The fix must close the leak WITHOUT killing the feature: outside production,
     * with the server-side flag on, the block is expected to render. If this ever
     * fails, the gate has been over-tightened rather than corrected.
     */
    public function demo_block_is_still_emitted_when_flag_is_on_outside_production(): void
    {
        $this->app['env'] = 'local';
        Config::set('app.demo_mode', true);

        $html = $this->get('/login')->getContent();

        $this->assertStringContainsString(
            'adminEmail',
            $html,
            'Demo mode is enabled outside production but the runtime block is missing: '
            .'the gate is too tight and the dev convenience has been destroyed.'
        );
    }

    /**
     * @test The demo block must be gated by Blade, not by a JavaScript ternary.
     *
     * Pins the exact shape of the regression: a ternary leaves both branches in
     * the markup. If someone reintroduces `demo: false ? {` this fails.
     */
    public function demo_block_is_not_emitted_as_a_javascript_ternary(): void
    {
        Config::set('app.demo_mode', false);

        $html = $this->get('/login')->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/demo:\s*(true|false)\s*\?/',
            $html,
            'The demo block is gated by a JavaScript ternary, which still serialises the '
            .'credentials into the HTML. The gate must be a Blade @if so the values are ABSENT.'
        );
    }
}
