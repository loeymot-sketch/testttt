<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * @FK-ID FK-IDEMPOTENCY-PROD-GUARD
 * @source Foundation Audit F-3 SYNC P0 (2026-05-18)
 *
 * Sentinel: AppServiceProvider MUST refuse to boot when
 * IDEMPOTENCY_MIDDLEWARE_ENABLED is falsy in production. Missing the
 * env var silently disables anti-doublon protection on 23 mutation
 * routes (cash-drawer open/close, refund-with-counter-entry,
 * change-status × 6, kiosk paid order confirm, livreur cash-session
 * open/close/reconcile). A retried POST would re-execute fully :
 * double charge, double cash-drawer-open, double order-status-change.
 *
 * Anti-régression : if anyone removes the prod-guard block, inverts the
 * condition, or relaxes the default in config/idempotency.php, this
 * sentinel breaks immediately.
 */
class IdempotencyMiddlewareProductionGuardSentinelTest extends TestCase
{
    public function test_production_env_with_idempotency_disabled_throws(): void
    {
        $this->app['env'] = 'production';
        config(['idempotency.enabled' => false]);

        // Neutralize sibling prod guards so we only test our own.
        config(['pos.simulation_hardware' => false]);
        config(['payment.bypass.enabled' => false]);
        config(['printing.bypass.enabled' => false]);
        config(['app.debug' => false]);
        config(['broadcasting.default' => 'pusher']);
        config(['queue.default' => 'redis']);
        config(['cache.default' => 'redis']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/IDEMPOTENCY_MIDDLEWARE_ENABLED must be true in production/');

        $provider = new \App\Providers\AppServiceProvider($this->app);
        $provider->boot();
    }

    public function test_production_env_with_idempotency_enabled_does_not_throw_idempotency_guard(): void
    {
        $this->app['env'] = 'production';
        config(['idempotency.enabled' => true]);

        config(['pos.simulation_hardware' => false]);
        config(['payment.bypass.enabled' => false]);
        config(['printing.bypass.enabled' => false]);
        config(['app.debug' => false]);
        config(['broadcasting.default' => 'pusher']);
        config(['queue.default' => 'redis']);
        config(['cache.default' => 'redis']);

        $provider = new \App\Providers\AppServiceProvider($this->app);
        // Should not throw the idempotency guard (may still throw other prod
        // guards depending on test env — assert message absence specifically).
        try {
            $provider->boot();
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString(
                'IDEMPOTENCY_MIDDLEWARE_ENABLED',
                $e->getMessage(),
                'AppServiceProvider must NOT throw idempotency guard when flag is true'
            );
        }
    }

    public function test_local_env_with_idempotency_disabled_boots_clean(): void
    {
        $this->app['env'] = 'local';
        config(['idempotency.enabled' => false]);

        $provider = new \App\Providers\AppServiceProvider($this->app);
        // Should NOT throw — idempotency-off is allowed outside production
        $provider->boot();
        $this->assertTrue(true);
    }

    public function test_config_default_is_false_when_env_unset(): void
    {
        // config/idempotency.php intentionally defaults to false for safe
        // dev/CI roll-out (per PLAN_P11 §2 header comment). The production
        // boot guard above is the production-time check.
        $fresh = require base_path('config/idempotency.php');

        $this->assertArrayHasKey('enabled', $fresh);
        $this->assertIsBool($fresh['enabled']);
        $this->assertFalse(
            $fresh['enabled'],
            'config/idempotency.php must default enabled=false when IDEMPOTENCY_MIDDLEWARE_ENABLED is unset (production must explicitly opt-in via env var).'
        );
    }
}
