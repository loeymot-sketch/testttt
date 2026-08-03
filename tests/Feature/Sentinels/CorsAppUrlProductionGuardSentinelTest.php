<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * @FK-ID FK-CORS-APP-URL-PROD-GUARD
 * @source Foundation Audit F-3 SYNC P0 #1 (2026-05-18)
 *
 * Sentinel: AppServiceProvider MUST refuse to boot when APP_URL is empty
 * in production. config/cors.php derives `allowed_origins` from
 * array_filter([APP_URL, KIOSK_DOMAIN, ADMIN_DOMAIN]) — if APP_URL is
 * empty and kiosk browsers hit the server via an unexpected host alias
 * (localhost vs 127.0.0.1 vs FQDN), CORS rejects /api/broadcasting/auth
 * silently. Polling fallback masks the failure → real-time push died.
 *
 * Anti-régression: if anyone removes the guard or condition this sentinel
 * breaks immediately.
 */
class CorsAppUrlProductionGuardSentinelTest extends TestCase
{
    public function test_production_env_with_empty_app_url_throws(): void
    {
        $this->app['env'] = 'production';
        config(['daily_book.pin' => '9137']); // [TEST-E2E-HEAL 2026-07-15] guard PIN Carnet ne préempte pas
        config(['app.url' => null]);

        // Neutralize sibling prod guards so we only test our own.
        config(['idempotency.enabled' => true]);
        config(['pos.simulation_hardware' => false]);
        config(['payment.bypass.enabled' => false]);
        config(['printing.bypass.enabled' => false]);
        config(['app.debug' => false]);
        config(['broadcasting.default' => 'pusher']);
        config(['queue.default' => 'redis']);
        config(['cache.default' => 'redis']);
        // [LCS-S-001 / 2026-05-19] Loyalty QR secret guard fires before
        // APP_URL guard — neutralize it so we keep testing our own.
        config(['loyalty.qr.secret' => str_repeat('a', 32)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_URL must be set in production/');

        $provider = new \App\Providers\AppServiceProvider($this->app);
        $provider->boot();
    }

    public function test_production_env_with_app_url_set_does_not_throw_cors_guard(): void
    {
        $this->app['env'] = 'production';
        config(['daily_book.pin' => '9137']); // [TEST-E2E-HEAL 2026-07-15] guard PIN Carnet ne préempte pas
        config(['app.url' => 'https://lecayenne.example.com']);

        config(['idempotency.enabled' => true]);
        config(['pos.simulation_hardware' => false]);
        config(['payment.bypass.enabled' => false]);
        config(['printing.bypass.enabled' => false]);
        config(['app.debug' => false]);
        config(['broadcasting.default' => 'pusher']);
        config(['queue.default' => 'redis']);
        config(['cache.default' => 'redis']);
        config(['loyalty.qr.secret' => str_repeat('a', 32)]);

        $provider = new \App\Providers\AppServiceProvider($this->app);
        try {
            $provider->boot();
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString(
                'APP_URL must be set',
                $e->getMessage(),
                'AppServiceProvider must NOT throw APP_URL guard when set'
            );
        }
    }

    public function test_local_env_with_empty_app_url_boots_clean(): void
    {
        $this->app['env'] = 'local';
        config(['app.url' => null]);

        $provider = new \App\Providers\AppServiceProvider($this->app);
        $provider->boot();
        $this->assertTrue(true);
    }

    public function test_cors_allowed_origins_filters_empty_safely(): void
    {
        // Lock the cors.php behaviour: array_filter drops empty values.
        // If someone changes the filter logic, this sentinel catches.
        $fresh = require base_path('config/cors.php');

        $this->assertArrayHasKey('allowed_origins', $fresh);
        $this->assertIsArray($fresh['allowed_origins']);
        // In test env (env() returns null for APP_URL/KIOSK/ADMIN), the
        // result should be empty array (filter dropped all nulls).
        foreach ($fresh['allowed_origins'] as $origin) {
            $this->assertNotEmpty($origin, 'allowed_origins must not contain empty/null entries');
        }
    }
}
